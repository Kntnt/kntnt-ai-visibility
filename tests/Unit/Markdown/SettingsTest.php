<?php
/**
 * Unit tests for the Markdown module's settings section.
 *
 * The section contributes the post-type override (a comma-separated text control
 * the owner narrows the default with) and the clear-cache action button. The
 * pure pieces are pinned here: the sanitiser that splits the text back into
 * sanitize_key'd slugs, the renderer that joins the stored slugs into the text
 * field, the button markup, and the section's identity and fields. The
 * admin_post clear-cache handler is side-effecting (redirect/exit) and is
 * exercised through the Module wiring, not here.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Settings\Section;
use Kntnt\Ai_Visibility\Markdown\Settings;

describe('Settings::sanitize_post_types', function (): void {

    it('splits a comma-separated string into sanitize_key slugs', function (): void {
        Functions\when('sanitize_key')->alias(static fn(string $s): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower($s)) ?? '');

        expect(Settings::sanitize_post_types('post, Page , my-cpt'))->toBe(['post', 'page', 'my-cpt']);
    });

    it('accepts an array, drops blanks and de-duplicates', function (): void {
        Functions\when('sanitize_key')->alias(static fn(string $s): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower($s)) ?? '');

        expect(Settings::sanitize_post_types(['post', '', 'post', 'page']))->toBe(['post', 'page']);
    });

    it('returns an empty list for a non-string, non-array value', function (): void {
        expect(Settings::sanitize_post_types(null))->toBe([]);
    });

});

describe('Settings::render_post_types', function (): void {

    it('renders a text input joining the stored slugs', function (): void {
        Functions\when('esc_attr')->returnArg();

        ob_start();
        Settings::render_post_types(['post', 'page'], 'kntnt_ai_visibility[markdown][post_types]');
        $html = (string) ob_get_clean();

        expect($html)->toContain('type="text"');
        expect($html)->toContain('name="kntnt_ai_visibility[markdown][post_types]"');
        expect($html)->toContain('value="post, page"');
    });

});

describe('Settings::render_clear_cache', function (): void {

    it('renders a nonce-protected admin-post link to the clear-cache action', function (): void {
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('admin_url')->alias(static fn(string $path): string => 'https://example.com/wp-admin/' . $path);
        Functions\when('wp_nonce_url')->alias(static fn(string $url, string $action): string => $url . '&_wpnonce=NONCE');

        ob_start();
        Settings::render_clear_cache('', 'unused');
        $html = (string) ob_get_clean();

        expect($html)->toContain('action=' . Settings::CLEAR_CACHE_ACTION);
        expect($html)->toContain('_wpnonce=NONCE');
        expect($html)->toContain('class="button"');
    });

});

describe('Settings::section', function (): void {

    it('builds a markdown section carrying the post-types and clear-cache fields', function (): void {
        Functions\when('__')->returnArg();

        $section = (new Settings())->section();

        expect($section)->toBeInstanceOf(Section::class);
        expect($section->id)->toBe(Settings::SECTION_ID);
        expect($section->field('post_types'))->not->toBeNull();
        expect($section->field('clear_cache'))->not->toBeNull();
    });

    it('routes the post-types field through the module sanitiser', function (): void {
        Functions\when('__')->returnArg();
        Functions\when('sanitize_key')->alias(static fn(string $s): string => preg_replace('/[^a-z0-9_\-]/', '', strtolower($s)) ?? '');

        $field = (new Settings())->section()->field('post_types');

        expect(($field->sanitize)('post, page'))->toBe(['post', 'page']);
    });

});
