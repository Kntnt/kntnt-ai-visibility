<?php
/**
 * Unit tests for Markdown eligibility.
 *
 * A request resolves to a Markdown alternate only for a single, public,
 * published entry. Eligibility decides that for a post: published status, a
 * viewable (publicly-queryable) post type that is not an attachment, and
 * membership of the configured / filtered post-type set. The default set is
 * every publicly-queryable type, overridable by setting and filter.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry;
use Kntnt\Ai_Visibility\Markdown\Eligibility;

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

/**
 * Builds an Eligibility whose settings return the given post-type override.
 *
 * @param list<string> $configured
 */
function kntnt_eligibility(array $configured = []): Eligibility
{
    $settings = Mockery::mock(Settings_Registry::class);
    $settings->shouldReceive('value')->with('markdown', 'post_types')->andReturn($configured);

    return new Eligibility($settings);
}

beforeEach(function (): void {
    // Model WordPress reality: pages are public and viewable but NOT
    // publicly_queryable, so a default keyed on publicly_queryable would wrongly
    // exclude them. get_post_types() respects its query so the test catches that.
    Functions\when('get_post_types')->alias(function (array $args = [], string $output = 'names'): array {
        if (($args['publicly_queryable'] ?? null) === true) {
            return ['post' => 'post', 'attachment' => 'attachment'];
        }
        return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment'];
    });
    Functions\when('is_post_type_viewable')->alias(fn(string $type): bool => in_array($type, ['post', 'page', 'attachment'], true));
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
});

describe('Eligibility', function (): void {

    it('accepts a published, publicly-queryable post', function (): void {
        expect(kntnt_eligibility()->is_eligible(kntnt_post('post', 'publish')))->toBeTrue();
    });

    it('accepts a published page', function (): void {
        expect(kntnt_eligibility()->is_eligible(kntnt_post('page', 'publish')))->toBeTrue();
    });

    it('rejects a draft', function (): void {
        expect(kntnt_eligibility()->is_eligible(kntnt_post('post', 'draft')))->toBeFalse();
    });

    it('rejects a private post', function (): void {
        expect(kntnt_eligibility()->is_eligible(kntnt_post('post', 'private')))->toBeFalse();
    });

    it('rejects an attachment even though it is viewable', function (): void {
        Functions\when('is_post_type_viewable')->justReturn(true);

        expect(kntnt_eligibility()->is_eligible(kntnt_post('attachment', 'publish')))->toBeFalse();
    });

    it('rejects a non-viewable post type', function (): void {
        expect(kntnt_eligibility()->is_eligible(kntnt_post('secret', 'publish')))->toBeFalse();
    });

    it('honours a post-type override that excludes the type', function (): void {
        expect(kntnt_eligibility(['page'])->is_eligible(kntnt_post('post', 'publish')))->toBeFalse();
    });

    it('honours a post-type override that includes the type', function (): void {
        expect(kntnt_eligibility(['post'])->is_eligible(kntnt_post('post', 'publish')))->toBeTrue();
    });

    it('lets the eligible-post-types filter remove a type', function (): void {
        Functions\when('apply_filters')->alias(
            fn(string $hook, mixed $value): mixed => $hook === 'kntnt_ai_visibility_eligible_post_types' ? [] : $value,
        );

        expect(kntnt_eligibility()->is_eligible(kntnt_post('post', 'publish')))->toBeFalse();
    });

});
