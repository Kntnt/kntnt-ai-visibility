<?php
/**
 * Unit tests for the settings registry.
 *
 * The registry resolves a field's effective value (saved value, else the code
 * default, then the developer filter) and sanitises submitted input against the
 * registered fields. Those are the behaviours the rest of the plugin depends on;
 * the admin-page HTML is exercised end-to-end, not here.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Settings\Field;
use Kntnt\Ai_Visibility\Core\Settings\Section;
use Kntnt\Ai_Visibility\Core\Settings\Settings;

/**
 * Builds a registry holding one 'markdown' section with two typed fields.
 */
function kntnt_test_settings(): Settings
{
    $settings = new Settings('kntnt_ai_visibility');
    $settings->register_section(new Section('markdown', 'Markdown', [
        new Field('include_archives', 'Include archives', 'checkbox', false, static fn($v): bool => (bool) $v),
        new Field('post_types', 'Post types', 'post_types', [], static fn($v): array => array_values(array_filter((array) $v, 'is_string'))),
    ]));

    return $settings;
}

describe('Settings::value', function (): void {

    it('returns the code default when nothing is saved', function (): void {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);

        expect(kntnt_test_settings()->value('markdown', 'include_archives'))->toBeFalse();
    });

    it('returns the saved value when present', function (): void {
        Functions\when('get_option')->justReturn(['markdown' => ['include_archives' => true]]);
        Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);

        expect(kntnt_test_settings()->value('markdown', 'include_archives'))->toBeTrue();
    });

    it('lets the developer filter override the resolved value', function (): void {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->alias(
            fn(string $hook, mixed $value): mixed => $hook === 'kntnt_ai_visibility_markdown_post_types' ? ['page'] : $value,
        );

        expect(kntnt_test_settings()->value('markdown', 'post_types'))->toBe(['page']);
    });

    it('returns null for an unknown field', function (): void {
        Functions\when('get_option')->justReturn([]);

        expect(kntnt_test_settings()->value('markdown', 'nope'))->toBeNull();
    });

});

describe('Settings::sanitize', function (): void {

    it('runs each field through its sanitiser and drops unknown keys', function (): void {
        $clean = kntnt_test_settings()->sanitize([
            'markdown' => [
                'include_archives' => '1',
                'post_types'       => ['post', 42, 'page'],
                'injected'         => 'evil',
            ],
            'ghost' => ['x' => 'y'],
        ]);

        expect($clean)->toBe([
            'markdown' => [
                'include_archives' => true,
                'post_types'       => ['post', 'page'],
            ],
        ]);
    });

    it('falls back to defaults for missing input', function (): void {
        $clean = kntnt_test_settings()->sanitize([]);

        expect($clean)->toBe([
            'markdown' => [
                'include_archives' => false,
                'post_types'       => [],
            ],
        ]);
    });

    it('routes a custom section through its own slice sanitiser', function (): void {
        // A custom section (e.g. the content-type matrix) owns a 2-level slice the
        // flat field model cannot express, so the registry hands it the whole slice.
        $settings = new Settings('kntnt_ai_visibility');
        $settings->register_section(new Section(
            'content_types',
            'Content types',
            sanitize: static fn(mixed $slice): array => ['post' => ['md' => ! empty($slice['post']['md'])]],
        ));

        $clean = $settings->sanitize(['content_types' => ['post' => ['md' => '1']]]);

        expect($clean)->toBe(['content_types' => ['post' => ['md' => true]]]);
    });

});
