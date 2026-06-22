<?php
/**
 * Unit tests for the Core markdown-alternate locator.
 *
 * The cache key and the advertised `.md` URL for a post are the identity of the
 * markdown-alternate kind; Core owns the kind's storage and serving, so it owns
 * the scheme (docs/spec/llms-txt.md §3.2). identity_for() yields the home-relative
 * permalink key ('index' for the slug-less home) and the post ID; url_for()
 * yields the absolute `.md` URL. Both are install-relative so root and
 * subdirectory installs derive the same key.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;

/**
 * Builds a post with the given id.
 */
function kntnt_md_post(int $id = 8): WP_Post
{
    $post     = new WP_Post();
    $post->ID = $id;

    return $post;
}

beforeEach(function (): void {
    Functions\when('wp_parse_url')->alias(fn(string $url, int $component = -1): mixed => parse_url($url, $component));
    Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com' . $path);
});

describe('Markdown_Alternate::identity_for', function (): void {

    it('builds the markdown-alternate identity from the permalink', function (): void {
        Functions\when('get_permalink')->justReturn('https://example.com/news/hello/');

        $identity = (new Markdown_Alternate())->identity_for(kntnt_md_post(8));

        expect($identity)->toBeInstanceOf(Identity::class);
        expect($identity->kind)->toBe('markdown-alternate');
        expect($identity->key)->toBe('news/hello');
        expect($identity->source_id)->toBe(8);
    });

    it('maps the slug-less front page to the index key', function (): void {
        Functions\when('get_permalink')->justReturn('https://example.com/');

        expect((new Markdown_Alternate())->identity_for(kntnt_md_post(2))->key)->toBe('index');
    });

    it('derives a home-relative key on a subdirectory install', function (): void {
        Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com/blog' . $path);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/about/team/');

        expect((new Markdown_Alternate())->identity_for(kntnt_md_post(30))->key)->toBe('about/team');
    });

});

describe('Markdown_Alternate::url_for', function (): void {

    it('appends .md to a permalink minus its trailing slash', function (): void {
        Functions\when('get_permalink')->justReturn('https://example.com/about/team/');

        expect((new Markdown_Alternate())->url_for(kntnt_md_post()))->toBe('https://example.com/about/team.md');
    });

    it('serves the home alternate at /index.md', function (): void {
        Functions\when('get_permalink')->justReturn('https://example.com/');

        expect((new Markdown_Alternate())->url_for(kntnt_md_post(2)))->toBe('https://example.com/index.md');
    });

});

describe('Markdown_Alternate::KIND', function (): void {

    it('names the markdown-alternate kind', function (): void {
        expect(Markdown_Alternate::KIND)->toBe('markdown-alternate');
    });

});
