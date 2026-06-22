<?php
/**
 * Unit tests for the Markdown-alternate provider.
 *
 * The provider resolves a request to an eligible post and an Identity (match),
 * produces the artifact bytes through the shared Page-Markdown service
 * (generate), advertises the page's `.md` alternate (advertise) and declares the
 * `.md` serve shape (serve_pattern). Resolution covers the `.md` suffix, the
 * canonical URL form, and the `/index.md` home with its real-page-first
 * precedence.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Markdown\Eligibility;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;

beforeEach(function (): void {
    Functions\when('wp_parse_url')->alias(fn(string $url, int $component = -1): mixed => parse_url($url, $component));
    Functions\when('home_url')->alias(fn(string $path = ''): string => 'https://example.com' . $path);
    Functions\when('trailingslashit')->alias(fn(string $s): string => rtrim($s, '/') . '/');
    Functions\when('untrailingslashit')->alias(fn(string $s): string => rtrim($s, '/'));
    Functions\when('get_page_by_path')->justReturn(null);
    Functions\when('get_option')->justReturn('');

    $this->page_markdown = Mockery::mock(Page_Markdown::class);
    $this->eligibility   = Mockery::mock(Eligibility::class);
    $this->provider      = new Page_Markdown_Provider($this->page_markdown, $this->eligibility);
});

describe('Page_Markdown_Provider::serve_pattern', function (): void {

    it('declares the markdown-alternate .md shape', function (): void {
        $pattern = $this->provider->serve_pattern();

        expect($pattern->kind)->toBe('markdown-alternate');
        expect($pattern->suffix)->toBe('.md');
    });

});

describe('Page_Markdown_Provider::match', function (): void {

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

    it('returns null when the resolved post is ineligible', function (): void {
        $post     = new WP_Post();
        $post->ID = 9;
        Functions\when('url_to_postid')->justReturn(9);
        Functions\when('get_post')->justReturn($post);
        $this->eligibility->shouldReceive('is_eligible')->andReturnFalse();

        expect($this->provider->match(new Request('GET', '/draft.md')))->toBeNull();
    });

    it('resolves /index.md to the static front page', function (): void {
        $front     = new WP_Post();
        $front->ID = 2;
        Functions\when('get_option')->alias(fn(string $name): mixed => match ($name) {
            'show_on_front' => 'page',
            'page_on_front' => 2,
            default         => '',
        });
        Functions\when('get_post')->justReturn($front);
        Functions\when('get_permalink')->justReturn('https://example.com/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/index.md'));

        expect($identity?->key)->toBe('index');
        expect($identity?->source_id)->toBe(2);
    });

    it('prefers a real page slugged index over the front page', function (): void {
        $page     = new WP_Post();
        $page->ID = 11;
        Functions\when('get_page_by_path')->justReturn($page);
        Functions\when('get_permalink')->justReturn('https://example.com/index/');
        $this->eligibility->shouldReceive('is_eligible')->andReturnTrue();

        $identity = $this->provider->match(new Request('GET', '/index.md'));

        expect($identity?->source_id)->toBe(11);
        expect($identity?->key)->toBe('index');
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
