<?php
/**
 * Unit tests for the llms-full.txt full-text builder.
 *
 * Full_Builder concatenates each selected page's per-page Markdown — never a
 * second render (docs/spec/llms-txt.md §4.4): a minimal site header, then each
 * page materialised through Page_Markdown (served from the per-page cache when
 * warm), joined by a blank line — the per-page `---` front-matter is the record
 * boundary. Password-protected pages are absent (enumerate excludes them) and the
 * loop skips any post that still carries a password as belt-and-braces.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Llms\Full_Builder;
use Kntnt\Ai_Visibility\Llms\Selected_Types;

/**
 * Builds a page with the given id, title and optional password.
 */
function kntnt_full_post(int $id, string $title, string $password = ''): WP_Post
{
    $post                = new WP_Post();
    $post->ID            = $id;
    $post->post_type     = 'page';
    $post->post_title    = $title;
    $post->post_password = $password;

    return $post;
}

/**
 * Wires the site-header stubs and a passthrough apply_filters.
 */
function kntnt_full_stubs(string $tagline = 'We help content sites'): void
{
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
    Functions\when('get_bloginfo')->alias(fn(string $key): string => match ($key) {
        'name'        => 'My Site',
        'description' => $tagline,
        default       => '',
    });
}

/**
 * Builds a Full_Builder whose page-markdown returns the given per-page bytes.
 *
 * @param list<WP_Post>      $posts    The enumerated pages.
 * @param array<int, string> $markdown Post id => its materialised .md bytes.
 */
function kntnt_full_builder(array $posts, array $markdown): Full_Builder
{
    $types = Mockery::mock(Content_Types::class);
    $types->shouldReceive('types_for')->with('llms_full')->andReturn(['page']);
    $types->shouldReceive('types_for')->with('md')->andReturn(['page']);

    $eligibility = Mockery::mock(Eligibility::class);
    $eligibility->shouldReceive('enumerate')->with(['page'])->andReturn($posts);

    $locator = Mockery::mock(Markdown_Alternate::class);
    $locator->shouldReceive('identity_for')->andReturnUsing(fn(WP_Post $p): Identity => new Identity('markdown-alternate', 'k' . $p->ID, $p->ID));

    $page_markdown = Mockery::mock(Page_Markdown::class);
    $page_markdown->shouldReceive('materialise')->andReturnUsing(fn(Identity $i, WP_Post $p): string => $markdown[$p->ID] ?? '');

    return new Full_Builder($eligibility, new Selected_Types($types), $locator, $page_markdown);
}

describe('Full_Builder::build', function (): void {

    it('renders the site header then concatenates each page Markdown', function (): void {
        kntnt_full_stubs();
        $builder = kntnt_full_builder(
            [kntnt_full_post(1, 'About'), kntnt_full_post(2, 'Contact')],
            [
                1 => "---\ntitle: About\n---\n\n# About\n\nA.",
                2 => "---\ntitle: Contact\n---\n\n# Contact\n\nC.",
            ],
        );

        $out = $builder->build();

        expect($out)->toContain('# My Site');
        expect($out)->toContain('> We help content sites');
        expect($out)->toContain("# About\n\nA.");
        expect($out)->toContain("# Contact\n\nC.");
        // The first page's bytes precede the second's (concatenation order).
        expect(strpos($out, '# About'))->toBeLessThan(strpos($out, '# Contact'));
    });

    it('never concatenates a password-protected page even if it reaches the loop', function (): void {
        kntnt_full_stubs();
        // The locked page carries Markdown too: if the builder materialised it, the
        // body would appear. It must not — the loop skips it before materialise.
        $builder = kntnt_full_builder(
            [kntnt_full_post(1, 'Open'), kntnt_full_post(2, 'Locked', 'secret')],
            [
                1 => "---\ntitle: Open\n---\n\n# Open\n\nOPEN BODY.",
                2 => "---\ntitle: Locked\n---\n\n# Locked\n\nLOCKED BODY.",
            ],
        );

        $out = $builder->build();

        expect($out)->toContain('OPEN BODY.');
        expect($out)->not->toContain('LOCKED BODY.');
    });

    it('lets the document filter rewrite the whole assembled string', function (): void {
        kntnt_full_stubs();
        Functions\when('apply_filters')->alias(
            fn(string $hook, mixed $value): mixed => $hook === 'kntnt_ai_visibility_llms_full_txt' ? 'REWRITTEN' : $value,
        );
        $builder = kntnt_full_builder(
            [kntnt_full_post(1, 'About')],
            [1 => "---\ntitle: About\n---\n\n# About\n\nA."],
        );

        expect($builder->build())->toBe('REWRITTEN');
    });

});
