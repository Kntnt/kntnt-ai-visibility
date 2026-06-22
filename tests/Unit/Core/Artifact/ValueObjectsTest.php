<?php
/**
 * Unit tests for the artifact value objects.
 *
 * These are the small, immutable carriers that flow across the artifact seams:
 * an Identity names one artifact instance, an Artifact carries its bytes, a
 * Request describes an incoming HTTP request, a Serve_Pattern declares the URL
 * shape a provider owns, and a Link_Relation is one advertised discovery link.
 * The tests pin construction and the readonly contract.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Core\Artifact\Artifact;
use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Link_Relation;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;

describe('Identity', function (): void {

    it('carries its kind, key and source id', function (): void {
        $identity = new Identity('markdown-alternate', 'about/team', 42);

        expect($identity->kind)->toBe('markdown-alternate');
        expect($identity->key)->toBe('about/team');
        expect($identity->source_id)->toBe(42);
    });

    it('defaults the source id to zero for singletons', function (): void {
        $identity = new Identity('llms-txt', 'llms');

        expect($identity->source_id)->toBe(0);
    });

    it('is immutable after construction', function (): void {
        $identity = new Identity('markdown-alternate', 'x', 1);

        expect(fn() => $identity->key = 'y')->toThrow(Error::class);
    });

});

describe('Artifact', function (): void {

    it('carries bytes, content type and last-modified time', function (): void {
        $artifact = new Artifact('# Hello', 'text/markdown; charset=utf-8', 1_700_000_000);

        expect($artifact->bytes)->toBe('# Hello');
        expect($artifact->content_type)->toBe('text/markdown; charset=utf-8');
        expect($artifact->last_modified)->toBe(1_700_000_000);
    });

});

describe('Request', function (): void {

    it('exposes the method, path, query and accept header', function (): void {
        $request = new Request('GET', '/about/team.md', ['format' => 'markdown'], 'text/markdown');

        expect($request->method)->toBe('GET');
        expect($request->path)->toBe('/about/team.md');
        expect($request->query)->toBe(['format' => 'markdown']);
        expect($request->accept)->toBe('text/markdown');
    });

});

describe('Serve_Pattern', function (): void {

    it('suffix() declares a suffix-matched, canonical, markdown shape', function (): void {
        $pattern = Serve_Pattern::suffix('markdown-alternate', '.md');

        expect($pattern->match)->toBe('suffix');
        expect($pattern->kind)->toBe('markdown-alternate');
        expect($pattern->suffix)->toBe('.md');
        expect($pattern->content_type)->toBe('text/markdown; charset=utf-8');
        expect($pattern->canonical)->toBeTrue();
        expect($pattern->versioned)->toBeFalse();
    });

    it('exact() declares an exact-path, non-canonical, versioned plain-text singleton', function (): void {
        $pattern = Serve_Pattern::exact('llms-txt', '/llms.txt', 'llms');

        expect($pattern->match)->toBe('exact');
        expect($pattern->kind)->toBe('llms-txt');
        expect($pattern->path)->toBe('/llms.txt');
        expect($pattern->key)->toBe('llms');
        expect($pattern->content_type)->toBe('text/plain; charset=utf-8');
        expect($pattern->canonical)->toBeFalse();
        expect($pattern->versioned)->toBeTrue();
    });

    it('exact() can opt out of version-stamping', function (): void {
        expect(Serve_Pattern::exact('k', '/p', 'key', versioned: false)->versioned)->toBeFalse();
    });

});

describe('Link_Relation', function (): void {

    it('carries an href, rel and type for discovery', function (): void {
        $relation = new Link_Relation('https://example.com/about.md', 'alternate', 'text/markdown');

        expect($relation->href)->toBe('https://example.com/about.md');
        expect($relation->rel)->toBe('alternate');
        expect($relation->type)->toBe('text/markdown');
    });

});

describe('Discovery_Context', function (): void {

    it('carries the post whose HTML page is being decorated', function (): void {
        $post     = new WP_Post();
        $post->ID = 7;
        $context  = new Discovery_Context($post);

        expect($context->post)->toBe($post);
    });

});
