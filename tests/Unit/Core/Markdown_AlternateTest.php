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
    Functions\when('get_option')->justReturn('https://example.com');
    Functions\when('wp_parse_url')->alias(fn(string $url, int $component = -1): mixed => parse_url($url, $component));
    Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com' . $path);
});

describe('Markdown_Alternate::identity_for', function (): void {

    it('keeps plain-permalink sources distinct from the configured front', function (): void {
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'home' => 'https://example.com/blog',
            'permalink_structure' => '',
            'show_on_front' => 'page',
            'page_on_front' => 2,
            default => false,
        });
        Functions\when('get_permalink')->alias(fn(WP_Post $post): string => $post->ID === 2 ? 'https://example.com/blog/' : 'https://example.com/blog/?page_id=' . $post->ID);
        $locator = new Markdown_Alternate();

        expect($locator->identity_for(kntnt_md_post(2))->key)->toBe('plain/2');
        expect($locator->identity_for(kntnt_md_post(8))->key)->toBe('plain/8');
        expect($locator->identity_for(kntnt_md_post(11))->key)->toBe('plain/11');
    });

    it('gives the static home and a literal index page distinct advertised identities', function (): void {
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'home' => 'https://example.com',
            'permalink_structure' => '/%postname%/',
            'show_on_front' => 'page',
            'page_on_front' => 2,
            default => '',
        });
        Functions\when('get_permalink')->alias(fn(WP_Post $post): string => $post->ID === 2 ? 'https://example.com/' : 'https://example.com/index/');
        $locator = new Markdown_Alternate();

        expect($locator->identity_for(kntnt_md_post(2))->key)->toBe('index');
        expect($locator->identity_for(kntnt_md_post(8))->key)->toBe('index/index');
        expect($locator->url_for(kntnt_md_post(2)))->toBe('https://example.com/index.md');
        expect($locator->url_for(kntnt_md_post(8)))->toBe('https://example.com/index/index.md');
    });

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
        Functions\when('get_option')->justReturn('https://example.com/blog');
        Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com/blog' . $path);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/about/team/');

        expect((new Markdown_Alternate())->identity_for(kntnt_md_post(30))->key)->toBe('about/team');
    });

});

describe('Markdown_Alternate::url_for', function (): void {

    it('advertises a composed query alternate for a plain canonical URL', function (): void {
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'home' => 'https://example.com/blog',
            'permalink_structure' => '',
            default => false,
        });
        Functions\when('get_permalink')->justReturn('https://example.com/blog/?page_id=8&lang=sv');
        Functions\when('add_query_arg')->alias(function (string $name, string $value, string $url): string {
            $query = [];
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $query[$name] = $value;
            return explode('?', $url, 2)[0] . '?' . http_build_query($query);
        });

        expect((new Markdown_Alternate())->url_for(kntnt_md_post(8)))->toBe('https://example.com/blog/?page_id=8&lang=sv&format=markdown');
    });

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

it('preserves the language prefix when home_url is translated', function (): void {
    Functions\when('get_option')->alias(fn(string $name): string => $name === 'home' ? 'https://example.com' : '/%postname%/');
    Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com/sv' . $path);
    Functions\when('get_permalink')->justReturn('https://example.com/sv/same-slug/');

    expect((new Markdown_Alternate())->identity_for(kntnt_md_post())->key)->toBe('sv/same-slug');
});

it('keeps the configured installation base separate from the language prefix', function (): void {
    Functions\when('get_option')->alias(fn(string $name): string => $name === 'home' ? 'https://example.com/blog' : '/%postname%/');
    Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com/blog/sv' . $path);
    Functions\when('get_permalink')->justReturn('https://example.com/blog/sv/about/');

    expect((new Markdown_Alternate())->identity_for(kntnt_md_post())->key)->toBe('sv/about');
});
