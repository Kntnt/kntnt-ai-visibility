<?php
/**
 * Unit tests for the Core content-type capability matrix.
 *
 * The matrix is one row per front-end-viewable post type and one column per
 * artifact kind the modules register. A cell resolves to its saved value, or
 * the column's zero-config default when unset, and is forced off when the column
 * it requires is off (the subset guarantee). types_for() lists the rows enabled
 * for a column; the sanitiser walks the registered rows x columns; the renderer
 * draws the checkbox table.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;

/**
 * Builds a matrix with the given saved cell values.
 *
 * @param array<string, array<string, bool>> $saved
 */
function kntnt_matrix(array $saved = []): Content_Matrix
{
    return new Content_Matrix(static fn(): array => $saved);
}

/**
 * Builds a matrix carrying the three Release-1/2 columns and given saved values.
 *
 * @param array<string, array<string, bool>> $saved
 */
function kntnt_full_matrix(array $saved = []): Content_Matrix
{
    $matrix = kntnt_matrix($saved);
    $matrix->register_column(new Capability_Column('md', 'Markdown (.md)', '', static fn(string $type): bool => true));
    $matrix->register_column(new Capability_Column('llms', 'In llms.txt', 'md', static fn(string $type): bool => true));
    $matrix->register_column(new Capability_Column('llms_full', 'In llms-full.txt', 'md', static fn(string $type): bool => $type === 'page'));

    return $matrix;
}

beforeEach(function (): void {
    // Model WordPress reality: pages are viewable but NOT publicly_queryable, so
    // a default keyed on publicly_queryable would wrongly exclude them.
    Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page', 'attachment' => 'attachment']);
    Functions\when('is_post_type_viewable')->alias(fn(string $type): bool => in_array($type, ['post', 'page', 'attachment'], true));
});

describe('Capability_Column::label', function (): void {

    // A translatable header must stay a closure until the matrix renders; a column
    // is constructed while a module boots, long before `init`.

    it('does not resolve a closure label at construction', function (): void {
        $calls = 0;
        $column = new Capability_Column('md', function () use (&$calls): string {
            ++$calls;

            return 'Markdown (.md)';
        }, '', static fn(string $type): bool => true);

        expect($calls)->toBe(0);
        expect($column->label())->toBe('Markdown (.md)');
        expect($calls)->toBe(1);
    });

    it('still accepts a plain string label', function (): void {
        $column = new Capability_Column('md', 'Markdown (.md)', '', static fn(string $type): bool => true);

        expect($column->label())->toBe('Markdown (.md)');
    });

});

describe('Content_Matrix::is_enabled', function (): void {

    it('falls back to the column default when the cell is unset', function (): void {
        $matrix = kntnt_matrix();
        $matrix->register_column(new Capability_Column('md', 'Markdown (.md)', '', static fn(string $type): bool => true));

        expect($matrix->is_enabled('post', 'md'))->toBeTrue();
    });

    it('lets a saved cell override the default in either direction', function (): void {
        $on  = kntnt_matrix(['post' => ['md' => true]]);
        $off = kntnt_matrix(['post' => ['md' => false]]);
        $on->register_column(new Capability_Column('md', 'Markdown (.md)', '', static fn(string $type): bool => false));
        $off->register_column(new Capability_Column('md', 'Markdown (.md)', '', static fn(string $type): bool => true));

        expect($on->is_enabled('post', 'md'))->toBeTrue();
        expect($off->is_enabled('post', 'md'))->toBeFalse();
    });

    it('forces a cell off when the column it requires is off, even if saved on', function (): void {
        // .md off for the row, but llms saved on: the subset guarantee wins.
        $matrix = kntnt_full_matrix(['post' => ['md' => false, 'llms' => true]]);

        expect($matrix->is_enabled('post', 'llms'))->toBeFalse();
    });

    it('returns false for an unregistered column', function (): void {
        expect(kntnt_matrix()->is_enabled('post', 'nope'))->toBeFalse();
    });

});

describe('Content_Matrix::columns', function (): void {

    it('returns the columns in registration order', function (): void {
        $keys = array_map(static fn($c): string => $c->key, kntnt_full_matrix()->columns());

        expect($keys)->toBe(['md', 'llms', 'llms_full']);
    });

});

describe('Content_Matrix::rows', function (): void {

    it('lists viewable, non-attachment post types', function (): void {
        expect(kntnt_matrix()->rows())->toBe(['post', 'page']);
    });

});

describe('Content_Matrix::types_for', function (): void {

    it('applies the zero-config defaults: .md all, llms.txt all, llms-full pages only', function (): void {
        $matrix = kntnt_full_matrix();

        expect($matrix->types_for('md'))->toBe(['post', 'page']);
        expect($matrix->types_for('llms'))->toBe(['post', 'page']);
        expect($matrix->types_for('llms_full'))->toBe(['page']);
    });

    it('drops a row from the dependent columns when its .md is off', function (): void {
        // Turning .md off for pages must remove pages from llms and llms_full too.
        $matrix = kntnt_full_matrix(['page' => ['md' => false]]);

        expect($matrix->types_for('md'))->toBe(['post']);
        expect($matrix->types_for('llms'))->toBe(['post']);
        expect($matrix->types_for('llms_full'))->toBe([]);
    });

});

describe('Content_Matrix::sanitize', function (): void {

    it('walks the registered rows x columns and coerces every cell to a bool', function (): void {
        // Checked controls submit '1'; unchecked ones submit nothing (absent => off).
        $clean = kntnt_full_matrix()->sanitize([
            'post' => ['md' => '1', 'llms' => '1'],
            'page' => ['md' => '1', 'llms' => '1', 'llms_full' => '1'],
        ]);

        expect($clean)->toBe([
            'post' => ['md' => true, 'llms' => true, 'llms_full' => false],
            'page' => ['md' => true, 'llms' => true, 'llms_full' => true],
        ]);
    });

    it('drops injected rows and columns that are not registered', function (): void {
        $clean = kntnt_full_matrix()->sanitize([
            'post'  => ['md' => '1', 'inject_col' => '1'],
            'page'  => ['md' => '1'],
            'evil'  => ['md' => '1'],
        ]);

        expect(array_keys($clean))->toBe(['post', 'page']);
        expect($clean['post'])->not->toHaveKey('inject_col');
    });

    it('forces a dependent cell off when its .md cell is off', function (): void {
        // md absent (off) but llms/llms_full submitted on: the dependency wins.
        $clean = kntnt_full_matrix()->sanitize([
            'post' => ['llms' => '1', 'llms_full' => '1'],
            'page' => ['md' => '1', 'llms' => '1', 'llms_full' => '1'],
        ]);

        expect($clean['post'])->toBe(['md' => false, 'llms' => false, 'llms_full' => false]);
        expect($clean['page'])->toBe(['md' => true, 'llms' => true, 'llms_full' => true]);
    });

    it('treats a non-array input as every cell off', function (): void {
        $clean = kntnt_full_matrix()->sanitize('nonsense');

        expect($clean['post'])->toBe(['md' => false, 'llms' => false, 'llms_full' => false]);
    });

});

describe('Content_Matrix::render_table', function (): void {

    beforeEach(function (): void {
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('checked')->alias(static fn($a, $b = true, $echo = true): string => (bool) $a === (bool) $b ? 'checked' : '');
        Functions\when('get_post_type_object')->alias(static function (string $type): object {
            $labels = (object) ['name' => ucfirst($type) . 's'];
            return (object) ['labels' => $labels];
        });
    });

    it('renders a header row of column labels', function (): void {
        ob_start();
        kntnt_full_matrix()->render_table('kntnt_ai_visibility[content_types]');
        $html = (string) ob_get_clean();

        expect($html)->toContain('Markdown (.md)');
        expect($html)->toContain('In llms.txt');
        expect($html)->toContain('In llms-full.txt');
    });

    it('renders one named checkbox per cell, checked to match the enabled state', function (): void {
        ob_start();
        kntnt_full_matrix()->render_table('kntnt_ai_visibility[content_types]');
        $html = (string) ob_get_clean();

        // The defaults: post/md on, post/llms_full off, page/llms_full on.
        expect($html)->toContain('name="kntnt_ai_visibility[content_types][post][md]"');
        expect($html)->toContain('name="kntnt_ai_visibility[content_types][page][llms_full]"');
        expect($html)->toMatch('/name="kntnt_ai_visibility\[content_types\]\[post\]\[md\]"[^>]*checked/');
        expect($html)->not->toMatch('/name="kntnt_ai_visibility\[content_types\]\[post\]\[llms_full\]"[^>]*checked/');
    });

});
