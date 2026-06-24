<?php
/**
 * Unit tests for the Core eligibility predicate and enumeration.
 *
 * is_servable() is the universal hard guard — published, front-end-viewable, not
 * an attachment — the rule that lets the early router serve before WP auth.
 * is_eligible() adds membership of the matrix `.md` set (through the
 * kntnt_ai_visibility_eligible_post_types filter). enumerate() returns the
 * published, non-password-protected posts of the given types, one query per type,
 * grouped in the passed order and ordered within each type for the aggregates.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;

/**
 * Builds an Eligibility whose matrix reports the given `.md` types.
 *
 * @param list<string> $md_types
 */
function kntnt_eligibility(array $md_types = ['post', 'page'], ?Exclusions $exclusions = null): Eligibility
{
    $types = Mockery::mock(Content_Types::class);
    $types->shouldReceive('types_for')->with('md')->andReturn($md_types)->byDefault();

    return new Eligibility($types, $exclusions ?? kntnt_exclusions(''));
}

/**
 * Builds an Exclusions gate over the given newline-separated pattern text.
 *
 * With empty text the gate short-circuits before any permalink lookup, so the
 * default keeps the non-exclusion cases free of get_permalink stubbing.
 */
function kntnt_exclusions(string $text = ''): Exclusions
{
    return new Exclusions(static fn(): string => $text, static fn(): string => 'https://example.test');
}

/**
 * Builds a post with the given type and status.
 */
function kntnt_post(string $type = 'post', string $status = 'publish'): WP_Post
{
    $post              = new WP_Post();
    $post->ID          = 5;
    $post->post_type   = $type;
    $post->post_status = $status;

    return $post;
}

beforeEach(function (): void {
    Functions\when('is_post_type_viewable')->alias(fn(string $type): bool => in_array($type, ['post', 'page', 'attachment'], true));
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
});

describe('Eligibility::is_servable', function (): void {

    it('accepts a published, viewable, non-attachment post', function (): void {
        expect(kntnt_eligibility()->is_servable(kntnt_post('post', 'publish')))->toBeTrue();
    });

    it('rejects a draft', function (): void {
        expect(kntnt_eligibility()->is_servable(kntnt_post('post', 'draft')))->toBeFalse();
    });

    it('rejects a private post', function (): void {
        expect(kntnt_eligibility()->is_servable(kntnt_post('post', 'private')))->toBeFalse();
    });

    it('rejects an attachment even though it is viewable', function (): void {
        expect(kntnt_eligibility()->is_servable(kntnt_post('attachment', 'publish')))->toBeFalse();
    });

    it('rejects a non-viewable post type', function (): void {
        expect(kntnt_eligibility()->is_servable(kntnt_post('secret', 'publish')))->toBeFalse();
    });

});

describe('Eligibility::is_eligible', function (): void {

    it('accepts a servable post whose type has the .md capability', function (): void {
        expect(kntnt_eligibility(['post', 'page'])->is_eligible(kntnt_post('page', 'publish')))->toBeTrue();
    });

    it('rejects a servable post whose type is not in the .md set', function (): void {
        expect(kntnt_eligibility(['page'])->is_eligible(kntnt_post('post', 'publish')))->toBeFalse();
    });

    it('rejects a draft even when its type has the .md capability', function (): void {
        expect(kntnt_eligibility(['post'])->is_eligible(kntnt_post('post', 'draft')))->toBeFalse();
    });

    it('lets the eligible-post-types filter remove a type', function (): void {
        Functions\when('apply_filters')->alias(
            fn(string $hook, mixed $value): mixed => $hook === 'kntnt_ai_visibility_eligible_post_types' ? [] : $value,
        );

        expect(kntnt_eligibility(['post'])->is_eligible(kntnt_post('post', 'publish')))->toBeFalse();
    });

    it('rejects a post whose path matches an exclusion pattern', function (): void {
        Functions\when('wp_parse_url')->alias(fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('get_permalink')->justReturn('https://example.test/cookiepolicy/');

        $eligibility = kntnt_eligibility(['post', 'page'], kntnt_exclusions('/cookiepolicy/'));

        expect($eligibility->is_eligible(kntnt_post('page', 'publish')))->toBeFalse();
    });

    it('keeps a post whose path matches no exclusion pattern', function (): void {
        Functions\when('wp_parse_url')->alias(fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('get_permalink')->justReturn('https://example.test/about/');

        $eligibility = kntnt_eligibility(['post', 'page'], kntnt_exclusions('/cookiepolicy/'));

        expect($eligibility->is_eligible(kntnt_post('page', 'publish')))->toBeTrue();
    });

});

describe('Eligibility::enumerate', function (): void {

    it('queries one type at a time and concatenates them in the passed order', function (): void {
        $page = kntnt_post('page');
        $post = kntnt_post('post');
        $calls = [];
        Functions\when('is_post_type_hierarchical')->alias(fn(string $t): bool => $t === 'page');
        Functions\when('get_posts')->alias(function (array $args) use (&$calls, $page, $post): array {
            $calls[] = $args['post_type'];
            return $args['post_type'] === 'page' ? [$page] : [$post];
        });

        $result = kntnt_eligibility()->enumerate(['page', 'post']);

        expect($result)->toBe([$page, $post]);
        expect($calls)->toBe(['page', 'post']);
    });

    it('queries only published, non-password-protected posts, unbounded and without found-rows', function (): void {
        $captured = [];
        Functions\when('is_post_type_hierarchical')->justReturn(false);
        Functions\when('get_posts')->alias(function (array $args) use (&$captured): array {
            $captured = $args;
            return [];
        });

        kntnt_eligibility()->enumerate(['post']);

        expect($captured['post_status'])->toBe('publish');
        expect($captured['has_password'])->toBeFalse();
        expect($captured['posts_per_page'])->toBe(-1);
        expect($captured['no_found_rows'])->toBeTrue();
    });

    it('orders hierarchical types by menu_order then title, others by date descending', function (): void {
        $args = [];
        Functions\when('is_post_type_hierarchical')->alias(fn(string $t): bool => $t === 'page');
        Functions\when('get_posts')->alias(function (array $a) use (&$args): array {
            $args[$a['post_type']] = $a;
            return [];
        });

        kntnt_eligibility()->enumerate(['page', 'post']);

        expect($args['page']['orderby'])->toBe('menu_order title');
        expect($args['page']['order'])->toBe('ASC');
        expect($args['post']['orderby'])->toBe('date');
        expect($args['post']['order'])->toBe('DESC');
    });

    it('drops an enumerated post whose path matches an exclusion pattern', function (): void {
        $keep = kntnt_post('post');
        $keep->ID = 1;
        $drop = kntnt_post('post');
        $drop->ID = 2;
        Functions\when('is_post_type_hierarchical')->justReturn(false);
        Functions\when('get_posts')->justReturn([$keep, $drop]);
        Functions\when('wp_parse_url')->alias(fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('get_permalink')->alias(fn(WP_Post $post): string => $post->ID === 2 ? 'https://example.test/secret/' : 'https://example.test/keep/');

        $result = kntnt_eligibility(['post'], kntnt_exclusions('/secret/'))->enumerate(['post']);

        expect($result)->toBe([$keep]);
    });

});
