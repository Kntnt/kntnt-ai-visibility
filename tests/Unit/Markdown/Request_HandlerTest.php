<?php
/**
 * Unit tests for the Markdown request handler's decision logic.
 *
 * The serving glue (headers, readfile, exit) is exercised end-to-end; here the
 * pure decisions are pinned: content negotiation with strict precedence
 * (.md > ?format=markdown > Accept), Accept parsing, the trailing-slash 301
 * target, and the inline (Accept) response shape with Vary, the steering
 * alternate link and conditional 304.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;
use Kntnt\Ai_Visibility\Markdown\Request_Handler;

beforeEach(function (): void {
    $this->handler = new Request_Handler(
        Mockery::mock(Page_Markdown_Provider::class),
        Mockery::mock(Page_Markdown::class),
        Mockery::mock(Store::class),
        Mockery::mock(Serve_Router::class),
        Mockery::mock(Logger::class)->shouldIgnoreMissing(),
    );
});

describe('Request_Handler::register_rewrite_rules', function (): void {

    it('registers the index and catch-all .md rewrite rules', function (): void {
        $rules = [];
        Functions\when('add_rewrite_rule')->alias(function (string $regex, string $query, string $after) use (&$rules): void {
            $rules[$regex] = $query;
        });

        Request_Handler::register_rewrite_rules();

        expect($rules)->toHaveKey('^index\.md$');
        expect($rules)->toHaveKey('^(.+?)\.md$');
        expect($rules['^(.+?)\.md$'])->toBe('index.php?markdown_request=1');
    });

});

describe('Request_Handler::negotiate', function (): void {

    it('keeps the canonical HTML representation when Markdown and HTML tie', function (): void {
        $request = new Request('GET', '/about/', [], 'text/html, text/markdown');

        expect($this->handler->negotiate($request))->toBeNull();
    });

    it('uses the HTML quality supplied by a text wildcard', function (): void {
        $request = new Request('GET', '/about/', [], 'text/*;q=1, text/markdown;q=0.1');

        expect($this->handler->negotiate($request))->toBeNull();
    });

    it('uses the HTML quality supplied by the universal wildcard', function (): void {
        $request = new Request('GET', '/about/', [], 'text/markdown;q=0.5, */*;q=0.9');

        expect($this->handler->negotiate($request))->toBeNull();
    });

    it('keeps HTML when a browser prefers XHTML', function (): void {
        $request = new Request('GET', '/about/', [], 'text/markdown;q=0.5, application/xhtml+xml');

        expect($this->handler->negotiate($request))->toBeNull();
    });

    it('uses the XHTML quality supplied by an application wildcard', function (): void {
        $request = new Request('GET', '/about/', [], 'text/markdown;q=0.5, application/*');

        expect($this->handler->negotiate($request))->toBeNull();
    });

    it('does not promote an out-of-range Markdown quality into acceptance', function (): void {
        $request = new Request('GET', '/about/', [], 'text/markdown;q=2, text/html;q=0.5');

        expect($this->handler->negotiate($request))->toBeNull();
    });

    it('rejects an ambiguous repeated quality parameter regardless of order', function (string $accept): void {
        $request = new Request('GET', '/about/', [], $accept);

        expect($this->handler->negotiate($request))->toBeNull();
    })->with([
        'text/markdown;q=0;q=1',
        'text/markdown;q=1;q=0',
    ]);

    it('does not treat a missing quality value as the default quality', function (): void {
        $request = new Request('GET', '/about/', [], 'text/markdown;q');

        expect($this->handler->negotiate($request))->toBeNull();
    });

    it('keeps canonical HTML for rejected or non-preferred Markdown', function (string $accept): void {
        expect($this->handler->negotiate(new Request('GET', '/about/', [], $accept)))->toBeNull();
    })->with([
        'lower quality' => ['text/html, text/markdown;q=0.1'],
        'zero quality' => ['text/markdown;q=0'],
        'similar unsupported type' => ['text/markdownish'],
        'reversed tie' => ['text/markdown, text/html'],
        'text wildcard only' => ['text/*'],
        'universal wildcard tie' => ['text/markdown, */*'],
        'specific Markdown rejection wins over wildcard' => ['text/*, text/markdown;q=0'],
        'specific HTML overrides lower wildcard' => ['text/*;q=0.1, text/html;q=0.9, text/markdown;q=0.5'],
        'XHTML overrides text wildcard' => ['text/*;q=0, application/xhtml+xml, text/markdown;q=0.8'],
        'universal fallback for XHTML' => ['text/html;q=0, text/markdown;q=0.8, */*'],
        'malformed quality' => ['text/markdown;q=banana'],
        'negative quality' => ['text/markdown;q=-0.1'],
        'excess precision' => ['text/markdown;q=0.9999'],
        'scientific notation' => ['text/markdown;q=1e0'],
        'missing leading zero' => ['text/markdown;q=.8'],
        'empty quality' => ['text/markdown;q='],
    ]);

    it('serves explicitly preferred Markdown independently of order case and whitespace', function (string $accept): void {
        expect($this->handler->negotiate(new Request('GET', '/about/', [], $accept)))->toBe('inline');
    })->with([
        'explicit preference' => ['text/html;q=0.2, text/markdown;q=0.9'],
        'reversed order' => ['text/markdown;q=0.9, text/html;q=0.2'],
        'alias' => ['text/html;q=0.5, text/x-markdown'],
        'case and whitespace' => [" TEXT/HTML ; Q = 0.2 ,\tTEXT/X-MARKDOWN ; Q = 0.900 "],
        'specific HTML overrides higher text wildcard' => ['text/*, text/html;q=0.2, text/markdown;q=0.5'],
        'specific HTML rejection overrides text wildcard' => ['text/*, text/html;q=0, text/markdown;q=0.1'],
        'specific HTML alternatives override universal wildcard' => ['*/*, text/html;q=0.2, application/xhtml+xml;q=0.3, text/markdown;q=0.4'],
        'specific XHTML overrides application wildcard' => ['application/*, application/xhtml+xml;q=0.2, text/markdown;q=0.5'],
        'alias preference wins' => ['text/markdown;q=0, text/x-markdown;q=0.8, text/html;q=0.4'],
        'lowest positive quality' => ['text/markdown;q=0.001, text/html;q=0'],
    ]);

    it('retains explicit path and query precedence when Accept rejects Markdown', function (): void {
        expect($this->handler->negotiate(new Request('GET', '/about.md', [], 'text/markdown;q=0')))->toBe('cache');
        expect($this->handler->negotiate(new Request('GET', '/about/', ['format' => 'markdown'], 'text/html')))->toBe('cache');
    });

    it('honours a zero quality and exact media types', function (): void {
        foreach (['text/markdown;q=0', 'text/markdown-fake', 'text/markdown;q=0.2, text/html;q=1'] as $accept) {
            expect($this->handler->negotiate(new Request('GET', '/about/', [], $accept)))->toBeNull();
        }
    });

    it('picks the cache mode for a .md path', function (): void {
        expect($this->handler->negotiate(new Request('GET', '/about/team.md')))->toBe('cache');
    });

    it('picks the cache mode for ?format=markdown', function (): void {
        expect($this->handler->negotiate(new Request('GET', '/about/team/', ['format' => 'markdown'])))->toBe('cache');
    });

    it('picks the inline mode for an Accept: text/markdown request', function (): void {
        expect($this->handler->negotiate(new Request('GET', '/about/team/', [], 'text/markdown')))->toBe('inline');
    });

    it('prefers .md over ?format and Accept', function (): void {
        $request = new Request('GET', '/about/team.md', ['format' => 'markdown'], 'text/markdown');

        expect($this->handler->negotiate($request))->toBe('cache');
    });

    it('prefers ?format over Accept', function (): void {
        $request = new Request('GET', '/about/team/', ['format' => 'markdown'], 'text/markdown');

        expect($this->handler->negotiate($request))->toBe('cache');
    });

    it('returns null for an ordinary HTML request', function (): void {
        expect($this->handler->negotiate(new Request('GET', '/about/team/', [], 'text/html')))->toBeNull();
    });

    it('does not match an uppercase .MD path', function (): void {
        expect($this->handler->negotiate(new Request('GET', '/about/team.MD')))->toBeNull();
    });

    it('does not treat */* as accepting markdown', function (): void {
        expect($this->handler->negotiate(new Request('GET', '/about/team/', [], '*/*')))->toBeNull();
    });

});

describe('Request_Handler::trailing_slash_target', function (): void {

    it('returns the de-slashed .md path for a trailing-slash request', function (): void {
        expect($this->handler->trailing_slash_target('/about/team.md/'))->toBe('/about/team.md');
    });

    it('collapses several trailing slashes', function (): void {
        expect($this->handler->trailing_slash_target('/about/team.md///'))->toBe('/about/team.md');
    });

    it('returns null for a clean .md path', function (): void {
        expect($this->handler->trailing_slash_target('/about/team.md'))->toBeNull();
    });

    it('returns null for a non-.md path', function (): void {
        expect($this->handler->trailing_slash_target('/about/team/'))->toBeNull();
    });

});

describe('Request_Handler::inline_response', function (): void {

    it('builds a 200 with Vary, canonical and steering alternate links', function (): void {
        $request = new Request('GET', '/about/team/', [], 'text/markdown');

        $response = $this->handler->inline_response(
            "# Team\n",
            1_700_000_000,
            $request,
            'https://example.com/about/team/',
            'https://example.com/about/team.md',
        );

        expect($response['status'])->toBe(200);
        expect($response['send_body'])->toBeTrue();
        expect($response['headers']['Content-Type'])->toBe('text/markdown; charset=utf-8');
        expect($response['headers']['Vary'])->toBe('Accept');
        expect($response['headers']['X-Content-Type-Options'])->toBe('nosniff');
        expect($response['headers']['Content-Length'])->toBe((string) strlen("# Team\n"));
        expect($response['headers']['Link'])->toContain('<https://example.com/about/team/>; rel="canonical"');
        expect($response['headers']['Link'])->toContain('<https://example.com/about/team.md>; rel="alternate"; type="text/markdown"');
    });

    it('answers a matching If-None-Match inline request with a bodyless 304', function (): void {
        $etag    = '"' . md5("# Team\n") . '"';
        $request = new Request('GET', '/about/team/', [], 'text/markdown', $etag);

        $response = $this->handler->inline_response("# Team\n", 1_700_000_000, $request, 'https://example.com/about/team/', 'https://example.com/about/team.md');

        expect($response['status'])->toBe(304);
        expect($response['send_body'])->toBeFalse();
        expect($response['headers'])->not->toHaveKey('Content-Length');
    });

});
