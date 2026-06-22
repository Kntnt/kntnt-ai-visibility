<?php
/**
 * Unit tests for the Core content-type settings section.
 *
 * Core owns the one settings section now — the content-type matrix and the
 * clear-cache action beside it (docs/spec/llms-txt.md §6). The section is a
 * custom section: its renderer draws the matrix table plus the clear-cache
 * button, and its sanitiser delegates to the matrix (which walks rows x columns
 * and enforces the .md dependency). The pure pieces are pinned here; the
 * side-effecting admin_post handler is exercised through wiring and e2e.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Content_Settings;
use Kntnt\Ai_Visibility\Core\Settings\Section;

/**
 * Builds a Content_Settings over a matrix carrying the three R1/R2 columns.
 */
function kntnt_content_settings(): Content_Settings
{
    $matrix = new Content_Matrix(static fn(): array => []);
    $matrix->register_column(new Capability_Column('md', 'Markdown (.md)', '', static fn(string $t): bool => true));
    $matrix->register_column(new Capability_Column('llms', 'In llms.txt', 'md', static fn(string $t): bool => true));
    $matrix->register_column(new Capability_Column('llms_full', 'In llms-full.txt', 'md', static fn(string $t): bool => $t === 'page'));

    return new Content_Settings($matrix, Mockery::mock(Store::class), Mockery::mock(Cache_Version::class));
}

beforeEach(function (): void {
    Functions\when('__')->returnArg();
    Functions\when('esc_html')->returnArg();
    Functions\when('esc_attr')->returnArg();
    Functions\when('esc_html__')->returnArg();
    Functions\when('esc_url')->returnArg();
    Functions\when('checked')->alias(static fn($a, $b = true, $e = true): string => (bool) $a === (bool) $b ? 'checked' : '');
    Functions\when('get_post_types')->justReturn(['post' => 'post', 'page' => 'page']);
    Functions\when('is_post_type_viewable')->justReturn(true);
    Functions\when('get_post_type_object')->alias(static fn(string $t): object => (object) ['labels' => (object) ['name' => ucfirst($t) . 's']]);
});

describe('Content_Settings::section', function (): void {

    it('builds a custom content_types section whose sanitiser delegates to the matrix', function (): void {
        $section = kntnt_content_settings()->section();

        expect($section)->toBeInstanceOf(Section::class);
        expect($section->id)->toBe(Content_Settings::SECTION_ID);
        expect($section->fields)->toBe([]);
        expect($section->sanitize)->not->toBeNull();

        // The dependency must be enforced: llms on with md off collapses to off.
        $clean = ($section->sanitize)(['post' => ['llms' => '1']]);
        expect($clean['post'])->toBe(['md' => false, 'llms' => false, 'llms_full' => false]);
    });

    it('renders the matrix checkboxes and the clear-cache button', function (): void {
        Functions\when('admin_url')->alias(static fn(string $p = ''): string => 'https://example.com/wp-admin/' . $p);
        Functions\when('wp_nonce_url')->alias(static fn(string $url, string $a): string => $url . '&_wpnonce=NONCE');

        $section = kntnt_content_settings()->section();
        ob_start();
        ($section->render)();
        $html = (string) ob_get_clean();

        expect($html)->toContain('name="kntnt_ai_visibility[content_types][post][md]"');
        expect($html)->toContain('action=' . Content_Settings::CLEAR_CACHE_ACTION);
        expect($html)->toContain('_wpnonce=NONCE');
    });

});
