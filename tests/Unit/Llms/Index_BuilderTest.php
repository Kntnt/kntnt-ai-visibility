<?php
/**
 * Unit tests for the llms.txt index builder.
 *
 * Index_Builder assembles the llms.txt Markdown from the eligible posts of the
 * selected types (docs/spec/llms-txt.md §4.3): an H1 site name, an optional
 * tagline blockquote, an intro line referencing /llms-full.txt, then one H2
 * section per type with `- [title](md_url): excerpt` items. Titles are escaped so
 * they cannot break the link syntax; excerpts are stripped and collapsed; every
 * piece is reachable through a filter.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Llms\Index_Builder;
use Kntnt\Ai_Visibility\Llms\Selected_Types;

/**
 * Builds a post with the given type, id and title.
 */
function kntnt_llms_post(string $type, int $id, string $title): WP_Post
{
    $post             = new WP_Post();
    $post->ID         = $id;
    $post->post_type  = $type;
    $post->post_title = $title;

    return $post;
}

/**
 * Wires the common WordPress stubs the builder reads, with the given titles/excerpts.
 *
 * @param array<int, string> $titles   Post id => title.
 * @param array<int, string> $excerpts Post id => excerpt.
 */
function kntnt_index_stubs(array $titles = [], array $excerpts = []): void
{
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
    Functions\when('get_bloginfo')->alias(fn(string $key): string => match ($key) {
        'name'        => 'My Site',
        'description' => 'We help content sites',
        default       => '',
    });
    Functions\when('get_the_title')->alias(fn(WP_Post $p): string => $titles[$p->ID] ?? $p->post_title);
    Functions\when('get_the_excerpt')->alias(fn(WP_Post $p): string => $excerpts[$p->ID] ?? '');
    Functions\when('get_post_type_object')->alias(fn(string $t): object => (object) ['labels' => (object) ['name' => ucfirst($t) . 's']]);
    Functions\when('strip_shortcodes')->returnArg();
    Functions\when('wp_strip_all_tags')->alias(fn(string $s): string => trim(strip_tags($s)));
}

/**
 * Builds an Index_Builder over mocked Core collaborators.
 *
 * @param array<string, list<string>> $type_sets    Column key => types, for types_for().
 * @param array<string, list<WP_Post>> $enumerations Comma-joined type list => posts.
 * @param array<int, string>          $urls         Post id => .md url.
 */
function kntnt_index_builder(array $type_sets, array $enumerations, array $urls): Index_Builder
{
    $types = Mockery::mock(Content_Types::class);
    foreach ($type_sets as $key => $list) {
        $types->shouldReceive('types_for')->with($key)->andReturn($list);
    }

    $eligibility = Mockery::mock(Eligibility::class);
    foreach ($enumerations as $joined => $posts) {
        $eligibility->shouldReceive('enumerate')->with(explode('|', $joined))->andReturn($posts);
    }

    $locator = Mockery::mock(Markdown_Alternate::class);
    $locator->shouldReceive('url_for')->andReturnUsing(fn(WP_Post $p): string => $urls[$p->ID] ?? '');

    return new Index_Builder($eligibility, new Selected_Types($types), $locator);
}

describe('Index_Builder::build', function (): void {

    it('renders the H1, a section and an item linking to the .md alternate', function (): void {
        kntnt_index_stubs(excerpts: [1 => 'A page about us']);
        $about = kntnt_llms_post('page', 1, 'About');
        $builder = kntnt_index_builder(
            ['llms' => ['page'], 'md' => ['page', 'post']],
            ['page' => [$about]],
            [1 => 'https://example.com/about.md'],
        );

        $out = $builder->build();

        expect($out)->toContain('# My Site');
        expect($out)->toContain('## Pages');
        expect($out)->toContain('- [About](https://example.com/about.md): A page about us');
    });

    it('renders the tagline blockquote and the /llms-full.txt intro line', function (): void {
        kntnt_index_stubs();
        $builder = kntnt_index_builder(
            ['llms' => ['page'], 'md' => ['page']],
            ['page' => [kntnt_llms_post('page', 1, 'About')]],
            [1 => 'https://example.com/about.md'],
        );

        $out = $builder->build();

        expect($out)->toContain('> We help content sites');
        expect($out)->toContain('Full text: /llms-full.txt');
    });

    it('omits the blockquote when the site has no tagline', function (): void {
        kntnt_index_stubs();
        Functions\when('get_bloginfo')->alias(fn(string $key): string => $key === 'name' ? 'My Site' : '');
        $builder = kntnt_index_builder(
            ['llms' => ['page'], 'md' => ['page']],
            ['page' => [kntnt_llms_post('page', 1, 'About')]],
            [1 => 'https://example.com/about.md'],
        );

        expect($builder->build())->not->toContain('>');
    });

    it('orders sections page, then post, then the rest in registration order', function (): void {
        kntnt_index_stubs();
        $posts = [
            kntnt_llms_post('page', 1, 'P'),
            kntnt_llms_post('post', 2, 'B'),
            kntnt_llms_post('review', 3, 'R'),
        ];
        $builder = kntnt_index_builder(
            ['llms' => ['review', 'post', 'page'], 'md' => ['review', 'post', 'page']],
            ['page|post|review' => $posts],
            [1 => 'u1', 2 => 'u2', 3 => 'u3'],
        );

        $out = $builder->build();

        expect(strpos($out, '## Pages'))->toBeLessThan(strpos($out, '## Posts'));
        expect(strpos($out, '## Posts'))->toBeLessThan(strpos($out, '## Reviews'));
    });

    it('omits the description when the excerpt is empty', function (): void {
        kntnt_index_stubs();
        $builder = kntnt_index_builder(
            ['llms' => ['page'], 'md' => ['page']],
            ['page' => [kntnt_llms_post('page', 1, 'About')]],
            [1 => 'https://example.com/about.md'],
        );

        expect($builder->build())->toContain("- [About](https://example.com/about.md)\n");
    });

    it('escapes Markdown-significant characters in the title so it cannot break the link', function (): void {
        kntnt_index_stubs(titles: [1 => 'A [test] `code`']);
        $builder = kntnt_index_builder(
            ['llms' => ['page'], 'md' => ['page']],
            ['page' => [kntnt_llms_post('page', 1, 'x')]],
            [1 => 'https://example.com/x.md'],
        );

        expect($builder->build())->toContain('- [A \[test\] \`code\`](https://example.com/x.md)');
    });

    it('strips tags and shortcodes from the description and collapses it to one line', function (): void {
        kntnt_index_stubs(excerpts: [1 => "<p>Hello [gallery] <b>world</b></p>\n\nmore"]);
        Functions\when('strip_shortcodes')->alias(fn(string $s): string => (string) preg_replace('/\[[^\]]*\]/', '', $s));
        $builder = kntnt_index_builder(
            ['llms' => ['page'], 'md' => ['page']],
            ['page' => [kntnt_llms_post('page', 1, 'About')]],
            [1 => 'https://example.com/about.md'],
        );

        $out = $builder->build();

        expect($out)->toContain(': Hello world more');
        expect($out)->not->toContain('[gallery]');
        expect($out)->not->toContain('<b>');
    });

    it('lets the entry filter substitute the title, url and description', function (): void {
        kntnt_index_stubs();
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value, mixed $post = null): mixed {
            if ($hook === 'kntnt_ai_visibility_llms_entry') {
                return ['title' => 'SEO Title', 'url' => 'https://example.com/seo.md', 'description' => 'SEO description'];
            }
            return $value;
        });
        $builder = kntnt_index_builder(
            ['llms' => ['page'], 'md' => ['page']],
            ['page' => [kntnt_llms_post('page', 1, 'About')]],
            [1 => 'https://example.com/about.md'],
        );

        expect($builder->build())->toContain('- [SEO Title](https://example.com/seo.md): SEO description');
    });

    it('lets the document filter rewrite the whole assembled string', function (): void {
        kntnt_index_stubs();
        Functions\when('apply_filters')->alias(function (string $hook, mixed $value): mixed {
            return $hook === 'kntnt_ai_visibility_llms_txt' ? "REWRITTEN" : $value;
        });
        $builder = kntnt_index_builder(
            ['llms' => ['page'], 'md' => ['page']],
            ['page' => [kntnt_llms_post('page', 1, 'About')]],
            [1 => 'https://example.com/about.md'],
        );

        expect($builder->build())->toBe('REWRITTEN');
    });

});
