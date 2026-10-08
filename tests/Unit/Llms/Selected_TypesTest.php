<?php
/**
 * Unit tests for the llms artifact type-set resolver.
 *
 * The llms artifacts own no selection of their own — they read their type sets
 * from the Core matrix (docs/spec/llms-txt.md §4.2): a column through its filter,
 * intersected with the `.md` set after filtering so a filter can never add a type
 * with no alternate, then ordered page, post, then the rest for the sections and
 * the concatenation order.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Llms\Selected_Types;

beforeEach(function (): void {
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
    Functions\when('is_post_type_viewable')->justReturn(true);
});

describe('Selected_Types::resolve', function (): void {

    it('shares a Markdown filter removal with direct eligibility and both aggregate selections', function (): void {
        Functions\when('get_post_types')->justReturn(['page', 'post']);
        Functions\when('is_post_type_viewable')->justReturn(true);
        Functions\when('apply_filters')->alias(
            fn(string $hook, mixed $value): mixed => match ($hook) {
                'kntnt_ai_visibility_eligible_post_types' => ['post'],
                'kntnt_ai_visibility_llms_post_types', 'kntnt_ai_visibility_llms_full_post_types' => ['page', 'post'],
                default => $value,
            },
        );
        $matrix = new Content_Matrix();
        foreach (['md', 'llms', 'llms_full'] as $column) {
            $matrix->register_column(new Capability_Column($column, fn(): string => $column, $column === 'md' ? '' : 'md', fn(): bool => true));
        }
        $eligibility = new Eligibility($matrix, new Exclusions(fn(): string => '', fn(): string => 'https://example.test'));
        $page = new WP_Post();
        $page->post_type = 'page';
        $page->post_status = 'publish';
        $selected = new Selected_Types($matrix, $eligibility);

        expect($eligibility->is_eligible($page))->toBeFalse();
        expect($selected->resolve('llms'))->toBe(['post']);
        expect($selected->resolve('llms_full'))->toBe(['post']);
    });

    it('accepts additive public types while guarding both aggregate filters from non-viewable types and attachments', function (): void {
        Functions\when('get_post_types')->justReturn(['page', 'event', 'internal', 'attachment']);
        Functions\when('is_post_type_viewable')->alias(fn(string $type): bool => in_array($type, ['page', 'event', 'attachment'], true));
        Functions\when('apply_filters')->alias(
            fn(string $hook, mixed $value): mixed => in_array($hook, [
                'kntnt_ai_visibility_eligible_post_types',
                'kntnt_ai_visibility_llms_post_types',
                'kntnt_ai_visibility_llms_full_post_types',
            ], true) ? ['event', 'internal', 'attachment', 'unknown', 12] : $value,
        );
        $matrix = new Content_Matrix(fn(): array => ['event' => ['md' => false]]);
        foreach (['md', 'llms', 'llms_full'] as $column) {
            $matrix->register_column(new Capability_Column($column, fn(): string => $column, $column === 'md' ? '' : 'md', fn(): bool => true));
        }
        $eligibility = new Eligibility($matrix, new Exclusions(fn(): string => '', fn(): string => 'https://example.test'));
        $event = new WP_Post();
        $event->post_type = 'event';
        $event->post_status = 'publish';
        $selected = new Selected_Types($matrix, $eligibility);

        expect($eligibility->is_eligible($event))->toBeTrue();
        expect($eligibility->md_types())->toBe(['event']);
        expect($selected->resolve('llms'))->toBe(['event']);
        expect($selected->resolve('llms_full'))->toBe(['event']);
    });

    it('intersects the column with the md set and orders page, post, then the rest', function (): void {
        $types = Mockery::mock(Content_Types::class);
        $types->shouldReceive('types_for')->with('llms')->andReturn(['review', 'post', 'page']);
        $types->shouldReceive('types_for')->with('md')->andReturn(['page', 'post', 'review']);

        $eligibility = new Eligibility($types, new Exclusions(fn(): string => '', fn(): string => 'https://example.test'));
        $selected = (new Selected_Types($types, $eligibility))->resolve('llms');

        expect($selected)->toBe(['page', 'post', 'review']);
    });

    it('never lets a filter add a type that has no .md', function (): void {
        $types = Mockery::mock(Content_Types::class);
        $types->shouldReceive('types_for')->with('llms_full')->andReturn(['page']);
        $types->shouldReceive('types_for')->with('md')->andReturn(['page', 'post']);
        // A filter tries to add 'event', which is not in the md set.
        Functions\when('apply_filters')->alias(
            fn(string $hook, mixed $value): mixed => $hook === 'kntnt_ai_visibility_llms_full_post_types' ? ['page', 'event'] : $value,
        );

        $eligibility = new Eligibility($types, new Exclusions(fn(): string => '', fn(): string => 'https://example.test'));
        $selected = (new Selected_Types($types, $eligibility))->resolve('llms_full');

        expect($selected)->toBe(['page']);
    });

});
