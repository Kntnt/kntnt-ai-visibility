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

describe('Settings::register and add_page', function (): void {

    it('hooks admin_menu and admin_init on register', function (): void {
        $hooks = [];
        Functions\when('add_action')->alias(function (string $hook) use (&$hooks): void {
            $hooks[] = $hook;
        });

        (new Settings())->register();

        expect($hooks)->toContain('admin_menu');
        expect($hooks)->toContain('admin_init');
    });

    it('registers an options page under Settings with the manage_options capability', function (): void {
        $args = [];
        Functions\when('add_options_page')->alias(function (...$a) use (&$args): void {
            $args = $a;
        });

        (new Settings('kntnt_ai_visibility', 'kntnt-ai-visibility', 'AI Visibility'))->add_page();

        expect($args[2])->toBe('manage_options');
        expect($args[3])->toBe('kntnt-ai-visibility');
    });

});

describe('Settings::register_wp_settings', function (): void {

    beforeEach(function (): void {
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('checked')->alias(static fn($a, $b = true, $e = true): string => (bool) $a === (bool) $b ? 'checked' : '');
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->alias(fn(string $h, mixed $v): mixed => $v);
    });

    it('registers the option and composes a field-based and a custom section', function (): void {
        $option = null;
        $sections = [];
        $field_cbs = [];
        $section_cbs = [];
        Functions\when('register_setting')->alias(function (string $g, string $o) use (&$option): void {
            $option = $o;
        });
        Functions\when('add_settings_section')->alias(function (string $id, string $title, $cb) use (&$sections, &$section_cbs): void {
            $sections[] = $id;
            $section_cbs[$id] = $cb;
        });
        Functions\when('add_settings_field')->alias(function (string $id, string $label, $cb) use (&$field_cbs): void {
            $field_cbs[$id] = $cb;
        });

        $settings = new Settings('kntnt_ai_visibility');
        $settings->register_section(new Section('markdown', 'Markdown', [
            new Field('flag', 'Flag', 'checkbox', true, static fn($v): bool => (bool) $v, 'Flag help'),
            new Field('name', 'Name', 'text', 'x', static fn($v): string => (string) $v),
        ]));
        $settings->register_section(new Section(
            'content_types',
            'Content types',
            render: static function (): void {
                echo 'MATRIX';
            },
        ));

        $settings->register_wp_settings();

        expect($option)->toBe('kntnt_ai_visibility');
        expect($sections)->toContain('kntnt_ai_visibility_markdown');
        expect($sections)->toContain('kntnt_ai_visibility_content_types');

        // Invoking the captured callbacks exercises the field renderer (checkbox
        // with help text, then text) and the custom section's own renderer.
        ob_start();
        ($field_cbs['markdown_flag'])();
        $checkbox = (string) ob_get_clean();
        expect($checkbox)->toContain('type="checkbox"');
        expect($checkbox)->toContain('Flag help');

        ob_start();
        ($field_cbs['markdown_name'])();
        expect((string) ob_get_clean())->toContain('type="text"');

        ob_start();
        ($section_cbs['kntnt_ai_visibility_content_types'])();
        expect((string) ob_get_clean())->toContain('MATRIX');
    });

    it('renders a field with a custom renderer through that renderer', function (): void {
        $field_cbs = [];
        Functions\when('register_setting')->justReturn(null);
        Functions\when('add_settings_section')->justReturn(null);
        Functions\when('add_settings_field')->alias(function (string $id, string $label, $cb) use (&$field_cbs): void {
            $field_cbs[$id] = $cb;
        });

        $settings = new Settings('kntnt_ai_visibility');
        $settings->register_section(new Section('markdown', 'Markdown', [
            new Field('cpt', 'CPT', 'custom', '', static fn($v) => $v, '', static function ($value, string $name): void {
                printf('CUSTOM:%s', $name);
            }),
        ]));
        $settings->register_wp_settings();

        ob_start();
        ($field_cbs['markdown_cpt'])();
        expect((string) ob_get_clean())->toContain('CUSTOM:kntnt_ai_visibility[markdown][cpt]');
    });

});

describe('Settings::render_page', function (): void {

    it('renders nothing without the manage_options capability', function (): void {
        Functions\when('current_user_can')->justReturn(false);

        ob_start();
        (new Settings())->render_page();

        expect((string) ob_get_clean())->toBe('');
    });

    it('renders the settings form for a capable user', function (): void {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('esc_html')->returnArg();
        Functions\when('settings_fields')->justReturn(null);
        Functions\when('do_settings_sections')->justReturn(null);
        Functions\when('submit_button')->justReturn(null);

        ob_start();
        (new Settings('kntnt_ai_visibility', 'kntnt-ai-visibility', 'AI Visibility'))->render_page();
        $html = (string) ob_get_clean();

        expect($html)->toContain('<form action="options.php" method="post">');
        expect($html)->toContain('AI Visibility');
    });

});
