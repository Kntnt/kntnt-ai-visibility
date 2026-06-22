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
use Kntnt\Ai_Visibility\Llms\Selected_Types;

beforeEach(function (): void {
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
});

describe('Selected_Types::resolve', function (): void {

    it('intersects the column with the md set and orders page, post, then the rest', function (): void {
        $types = Mockery::mock(Content_Types::class);
        $types->shouldReceive('types_for')->with('llms')->andReturn(['review', 'post', 'page']);
        $types->shouldReceive('types_for')->with('md')->andReturn(['page', 'post', 'review']);

        $selected = (new Selected_Types($types))->resolve('llms');

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

        $selected = (new Selected_Types($types))->resolve('llms_full');

        expect($selected)->toBe(['page']);
    });

});
