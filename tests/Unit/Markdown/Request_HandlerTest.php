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
