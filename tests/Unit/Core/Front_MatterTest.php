<?php
/**
 * Unit tests for the YAML front-matter builder.
 *
 * The front-matter carries parity metadata plus the canonical URL, in a fixed
 * key order: title, canonical_url, date, author, featured_image, categories,
 * tags. featured_image, categories and tags are conditional — omitted when
 * empty. Category and tag URLs point at the term's `.md` path. Double-quoted
 * scalars escape quotes. The title is metadata only; it never enters the body.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Front_Matter;

beforeEach(function (): void {
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
    Functions\when('get_the_title')->justReturn('Hello &amp; Welcome');
    Functions\when('get_permalink')->justReturn('https://example.com/hello/');
    Functions\when('get_the_date')->justReturn('2024-01-15');
    Functions\when('get_the_author_meta')->justReturn('Jane Doe');
    Functions\when('get_the_post_thumbnail_url')->justReturn(false);
    Functions\when('get_the_terms')->justReturn(false);
    Functions\when('get_term_link')->justReturn('');

    $this->post = new WP_Post();
    $this->post->ID = 1;
    $this->post->post_author = 3;
});

describe('Front_Matter', function (): void {

    it('builds the required keys in order, fenced, with entities decoded', function (): void {
        $yaml = (new Front_Matter())->build($this->post);

        expect($yaml)->toBe(
            "---\n"
            . "title: \"Hello & Welcome\"\n"
            . "canonical_url: \"https://example.com/hello/\"\n"
            . "date: 2024-01-15\n"
            . "author: \"Jane Doe\"\n"
            . "---\n",
        );
    });

    it('omits featured_image, categories and tags when empty', function (): void {
        $yaml = (new Front_Matter())->build($this->post);

        expect($yaml)->not->toContain('featured_image');
        expect($yaml)->not->toContain('categories');
        expect($yaml)->not->toContain('tags');
    });

    it('includes the featured image when present', function (): void {
        Functions\when('get_the_post_thumbnail_url')->justReturn('https://example.com/img.jpg');

        $yaml = (new Front_Matter())->build($this->post);

        expect($yaml)->toContain("featured_image: \"https://example.com/img.jpg\"\n");
    });

    it('renders categories and tags as name/.md-url lists', function (): void {
        $news = new WP_Term();
        $news->name = 'News';
        $tip = new WP_Term();
        $tip->name = 'Tips';
        Functions\when('get_the_terms')->alias(fn($post, string $taxonomy) => $taxonomy === 'category' ? [$news] : [$tip]);
        Functions\when('get_term_link')->alias(
            fn($term): string => $term->name === 'News' ? 'https://example.com/category/news/' : 'https://example.com/tag/tips/',
        );

        $yaml = (new Front_Matter())->build($this->post);

        expect($yaml)->toContain(
            "categories:\n"
            . "  - name: \"News\"\n"
            . "    url: \"https://example.com/category/news.md\"\n",
        );
        expect($yaml)->toContain(
            "tags:\n"
            . "  - name: \"Tips\"\n"
            . "    url: \"https://example.com/tag/tips.md\"\n",
        );
    });

    it('escapes double quotes in a title', function (): void {
        Functions\when('get_the_title')->justReturn('The "Best" Guide');

        $yaml = (new Front_Matter())->build($this->post);

        expect($yaml)->toContain('title: "The \\"Best\\" Guide"');
    });

});
