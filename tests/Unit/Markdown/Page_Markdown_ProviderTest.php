<?php
/**
 * Unit tests for the Markdown-alternate provider.
 *
 * The provider resolves a request to an eligible post and an Identity (match),
 * produces the artifact bytes through the shared Page-Markdown service
 * (generate), advertises the page's `.md` alternate (advertise) and declares the
 * `.md` serve shape (serve_pattern). Resolution covers the `.md` suffix, the
 * canonical URL form and the reserved `/index.md` static home. Ordinary index
 * pages use a distinct escaped dedicated path.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;

beforeEach(function (): void {
    Functions\when('wp_parse_url')->alias(fn(string $url, int $component = -1): mixed => parse_url($url, $component));
    Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com' . $path);
    Functions\when('trailingslashit')->alias(fn(string $s): string => rtrim($s, '/') . '/');
    Functions\when('untrailingslashit')->alias(fn(string $s): string => rtrim($s, '/'));
    Functions\when('get_page_by_path')->justReturn(null);
    Functions\when('get_posts')->justReturn([]);
    Functions\when('get_option')->alias(fn(string $name): string => $name === 'home' ? 'https://example.com' : '/%postname%/');

    $this->page_markdown = Mockery::mock(Page_Markdown::class);
    $this->eligibility   = Mockery::mock(Eligibility::class);
    $this->provider      = new Page_Markdown_Provider($this->page_markdown, $this->eligibility, new Markdown_Alternate());
});

describe('Page_Markdown_Provider::serve_pattern', function (): void {

    it('declares no early suffix path for query-only plain alternates', function (): void {
        Functions\when('get_option')->alias(fn(string $name): string => $name === 'permalink_structure' ? '' : 'https://example.com');

        expect($this->provider->serve_pattern())->toBeNull();
    });

    it('declares the markdown-alternate .md shape', function (): void {
        $pattern = $this->provider->serve_pattern();

        expect($pattern->kind)->toBe('markdown-alternate');
        expect($pattern->suffix)->toBe('.md');
    });

});

describe('Page_Markdown_Provider::match', function (): void {

    it('rejects an old plain selector after switching to pretty permalinks', function (): void {
        $front = new WP_Post();
        $front->ID = 2;
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'home' => 'https://example.com',
            'permalink_structure' => '/%postname%/',
            'show_on_front' => 'page',
            'page_on_front' => 2,
            default => '',
        });
        Functions\when('get_post')->justReturn($front);
        Functions\when('get_permalink')->justReturn('https://example.com/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        expect($this->provider->match(new Request('GET', '/', ['page_id' => '16', 'format' => 'markdown'])))->toBeNull();
    });

    it('resolves a plain canonical query to its own source rather than the front', function (): void {
        $front = new WP_Post();
        $front->ID = 2;
        $front->post_type = 'page';
        $page = new WP_Post();
        $page->ID = 16;
        $page->post_type = 'page';
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'home' => 'https://example.com',
            'permalink_structure' => '',
            'show_on_front' => 'page',
            'page_on_front' => 2,
            default => false,
        });
        Functions\when('get_post')->alias(fn(int $id): WP_Post => $id === 2 ? $front : $page);
        Functions\when('get_permalink')->alias(fn(WP_Post $post): string => $post->ID === 2 ? 'https://example.com/' : 'https://example.com/?page_id=16');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/', ['page_id' => '16', 'format' => 'markdown']));

        expect($identity?->source_id)->toBe(16);
        expect($identity?->key)->toBe('plain/16');
    });

    it('negotiates the configured front even when another page has the index slug', function (): void {
        $front = new WP_Post();
        $front->ID = 2;
        $index = new WP_Post();
        $index->ID = 11;
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'home' => 'https://example.com',
            'permalink_structure' => '/%postname%/',
            'show_on_front' => 'page',
            'page_on_front' => 2,
            default => '',
        });
        Functions\when('get_page_by_path')->justReturn($index);
        Functions\when('get_post')->justReturn($front);
        Functions\when('get_permalink')->alias(fn(WP_Post $post): string => $post->ID === 2 ? 'https://example.com/' : 'https://example.com/index/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        expect($this->provider->match(new Request('GET', '/'))?->source_id)->toBe(2);
        expect($this->provider->match(new Request('GET', '/index.md'))?->source_id)->toBe(2);
    });

    it('rejects a real canonical leaf path outside its configured installation', function (): void {
        $post = new WP_Post();
        $post->ID = 30;
        Functions\when('get_option')->alias(fn(string $name): string => $name === 'home' ? 'https://example.com/blog' : '/%postname%/');
        Functions\when('url_to_postid')->justReturn(30);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/about/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        expect($this->provider->match(new Request('GET', '/about.md')))->toBeNull();
        expect($this->provider->match(new Request('GET', '/about/')))->toBeNull();
    });

    it('resolves a .md request to an eligible post identity', function (): void {
        $post     = new WP_Post();
        $post->ID = 42;
        Functions\when('url_to_postid')->alias(fn(string $url): int => $url === 'https://example.com/about/team/' ? 42 : 0);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/about/team/');
        $this->eligibility->shouldReceive('is_eligible')->with($post)->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/about/team.md'));

        expect($identity)->toBeInstanceOf(Identity::class);
        expect($identity->kind)->toBe('markdown-alternate');
        expect($identity->key)->toBe('about/team');
        expect($identity->source_id)->toBe(42);
    });

    it('resolves the canonical URL form (no .md suffix)', function (): void {
        $post     = new WP_Post();
        $post->ID = 7;
        Functions\when('url_to_postid')->alias(fn(string $url): int => str_contains($url, '/hello') ? 7 : 0);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/hello/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/hello/'));

        expect($identity?->key)->toBe('hello');
    });

    it('returns null when no post resolves', function (): void {
        Functions\when('url_to_postid')->justReturn(0);

        expect($this->provider->match(new Request('GET', '/nope.md')))->toBeNull();
    });

    it('falls back to the page hierarchy when url_to_postid misses a page', function (): void {
        // The .md rewrite steers the main query to the front page, where
        // url_to_postid can shadow a page slug with the post-name rule and return
        // 0; the hierarchical page lookup resolves it regardless.
        $page     = new WP_Post();
        $page->ID = 13;
        Functions\when('url_to_postid')->justReturn(0);
        Functions\when('get_page_by_path')->alias(fn(string $path): ?WP_Post => $path === 'about' ? $page : null);
        Functions\when('get_post')->justReturn($page);
        Functions\when('get_permalink')->justReturn('https://example.com/about/');
        $this->eligibility->shouldReceive('is_eligible')->with($page)->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/about.md'));

        expect($identity?->source_id)->toBe(13);
        expect($identity?->key)->toBe('about');
    });

    it('falls back to a published-post slug lookup when url_to_postid and the page tree miss', function (): void {
        // A post in the steered .md context: url_to_postid misses and the page
        // tree has no such page, so the final slug lookup resolves the post.
        $post     = new WP_Post();
        $post->ID = 21;
        Functions\when('url_to_postid')->justReturn(0);
        Functions\when('get_page_by_path')->justReturn(null);
        Functions\when('get_posts')->alias(fn(array $args): array => ($args['name'] ?? '') === 'hello-md' ? [$post] : []);
        Functions\when('get_permalink')->justReturn('https://example.com/hello-md/');
        $this->eligibility->shouldReceive('is_eligible')->with($post)->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/hello-md.md'));

        expect($identity?->source_id)->toBe(21);
        expect($identity?->key)->toBe('hello-md');
    });

    it('returns null when the resolved post is ineligible', function (): void {
        $post     = new WP_Post();
        $post->ID = 9;
        Functions\when('url_to_postid')->justReturn(9);
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_permalink')->justReturn('https://example.com/draft/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnFalse();

        expect($this->provider->match(new Request('GET', '/draft.md')))->toBeNull();
    });

    it('derives a home-relative key on a subdirectory install', function (): void {
        Functions\when('get_option')->alias(fn(string $name): string => $name === 'home' ? 'https://example.com/blog' : '/%postname%/');
        // WordPress at /blog/: keys must be relative to the home so the router
        // (which strips the same base) and the provider agree.
        $page     = new WP_Post();
        $page->ID = 30;
        Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com/blog' . $path);
        Functions\when('url_to_postid')->justReturn(0);
        Functions\when('get_page_by_path')->alias(fn(string $p): ?WP_Post => $p === 'about' ? $page : null);
        Functions\when('get_post')->justReturn($page);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/about/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/blog/about.md'));

        expect($identity?->key)->toBe('about');
        expect($identity?->source_id)->toBe(30);
    });

    it('resolves /blog/index.md to the home on a subdirectory install', function (): void {
        $front     = new WP_Post();
        $front->ID = 31;
        Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com/blog' . $path);
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'permalink_structure' => '/%postname%/',
            'show_on_front' => 'page',
            'page_on_front' => 31,
            'home' => 'https://example.com/blog',
            default         => '',
        });
        Functions\when('get_post')->justReturn($front);
        Functions\when('get_permalink')->justReturn('https://example.com/blog/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/blog/index.md'));

        expect($identity?->key)->toBe('index');
        expect($identity?->source_id)->toBe(31);
    });

    it('resolves /index.md to the static front page', function (): void {
        $front     = new WP_Post();
        $front->ID = 2;
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'permalink_structure' => '/%postname%/',
            'show_on_front' => 'page',
            'page_on_front' => 2,
            'home' => 'https://example.com',
            default         => '',
        });
        Functions\when('get_post')->justReturn($front);
        Functions\when('get_permalink')->justReturn('https://example.com/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/index.md'));

        expect($identity?->key)->toBe('index');
        expect($identity?->source_id)->toBe(2);
    });

    it('keeps the actual index page addressable independently from the home', function (): void {
        $page     = new WP_Post();
        $page->ID = 11;
        Functions\when('get_page_by_path')->justReturn($page);
        Functions\when('get_permalink')->justReturn('https://example.com/index/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        Functions\when('url_to_postid')->justReturn(0);
        $identity = $this->provider->match(new Request('GET', '/index/index.md'));

        expect($identity?->source_id)->toBe(11);
        expect($identity?->key)->toBe('index/index');
        expect($this->provider->match(new Request('GET', '/index/'))?->source_id)->toBe(11);
    });

});

describe('Page_Markdown_Provider::generate', function (): void {


    it('builds an artifact from the page-markdown service and post metadata', function (): void {
        $post     = new WP_Post();
        $post->ID = 42;
        Functions\when('get_post')->justReturn($post);
        Functions\when('get_post_modified_time')->justReturn(1_700_000_000);
        $this->page_markdown->shouldReceive('for_post')->with($post)->andReturn("---\ntitle: \"X\"\n---\n\n# X\n");

        $artifact = $this->provider->generate(new Identity('markdown-alternate', 'about/team', 42));

        expect($artifact->bytes)->toBe("---\ntitle: \"X\"\n---\n\n# X\n");
        expect($artifact->content_type)->toBe('text/markdown; charset=utf-8');
        expect($artifact->last_modified)->toBe(1_700_000_000);
    });

});

describe('Page_Markdown_Provider::advertise', function (): void {

    it('advertises the page .md alternate as a markdown link relation', function (): void {
        $post     = new WP_Post();
        $post->ID = 42;
        Functions\when('get_permalink')->justReturn('https://example.com/about/team/');
        $this->eligibility->shouldReceive('is_eligible')->with($post)->andReturnTrue();

        $relations = $this->provider->advertise(new Discovery_Context($post));

        expect($relations)->toHaveCount(1);
        expect($relations[0]->href)->toBe('https://example.com/about/team.md');
        expect($relations[0]->rel)->toBe('alternate');
        expect($relations[0]->type)->toBe('text/markdown');
    });

    it('advertises nothing for an ineligible page', function (): void {
        $post = new WP_Post();
        $this->eligibility->shouldReceive('is_eligible')->andReturnFalse();

        expect($this->provider->advertise(new Discovery_Context($post)))->toBe([]);
    });

    it('advertises nothing for the site-wide (null-post) context', function (): void {
        expect($this->provider->advertise(new Discovery_Context(null)))->toBe([]);
    });

});

describe('Page_Markdown_Provider::identity_for_post', function (): void {

    it('builds the markdown-alternate identity from the permalink', function (): void {
        $post     = new WP_Post();
        $post->ID = 8;
        Functions\when('get_permalink')->justReturn('https://example.com/news/hello/');

        $identity = $this->provider->identity_for_post($post);

        expect($identity->kind)->toBe('markdown-alternate');
        expect($identity->key)->toBe('news/hello');
        expect($identity->source_id)->toBe(8);
    });

});

it('does not serve a post under an unrelated path with the same final slug', function (): void {
    $post = new WP_Post();
    $post->ID = 77;
    Functions\when('url_to_postid')->justReturn(0);
    Functions\when('get_posts')->justReturn([$post]);
    Functions\when('get_permalink')->justReturn('https://example.com/news/item/');
    $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

    expect($this->provider->match(new Request('GET', '/nonexistent/item.md')))->toBeNull();
});

it('rejects a wrong-language URL lookup and selects the matching translated permalink', function (): void {
    $en = new WP_Post();
    $en->ID = 80;
    $sv = new WP_Post();
    $sv->ID = 81;
    Functions\when('get_option')->justReturn('https://example.com');
    Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com/sv' . $path);
    Functions\when('url_to_postid')->justReturn(80);
    Functions\when('get_post')->justReturn($en);
    Functions\when('get_posts')->justReturn([$en, $sv]);
    Functions\when('get_permalink')->alias(fn(WP_Post $post): string => $post->ID === 80 ? 'https://example.com/team/' : 'https://example.com/sv/team/');
    $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

    expect($this->provider->match(new Request('GET', '/sv/team.md'))?->source_id)->toBe(81);
});
