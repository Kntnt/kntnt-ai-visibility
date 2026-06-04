<?php
/**
 * Unit tests for the Plugin singleton.
 *
 * Covers the bootstrap (singleton identity, hook wiring), the metadata helpers
 * (file path, header parsing, version, slug, directory), the deactivation
 * cleanup, and the singleton guards. The Plugin lives in a namespace, so its
 * unqualified add_filter() call falls back to the global function only when one
 * exists; the beforeEach hook provides a recording stub for it.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Plugin;

const KNTNT_TEST_PLUGIN_FILE = '/plugins/kntnt-ai-visibility/kntnt-ai-visibility.php';

describe('Plugin', function (): void {

    beforeEach(function (): void {
        kntnt_test_reset_plugin();

        // Record every hook the constructor registers, and provide the global
        // add_filter the namespaced constructor falls back to.
        $GLOBALS['kntnt_test_added_filters'] = [];
        Functions\when('add_filter')->alias(function (string $hook, $callback): bool {
            $GLOBALS['kntnt_test_added_filters'][$hook] = $callback;
            return true;
        });
    });

    afterEach(function (): void {
        kntnt_test_reset_plugin();
    });

    it('creates a singleton and wires the update filter on the first call', function (): void {
        $first  = Plugin::get_instance(KNTNT_TEST_PLUGIN_FILE);
        $second = Plugin::get_instance('/ignored/on/second/call.php');

        expect($first)->toBeInstanceOf(Plugin::class);
        expect($second)->toBe($first);
        expect(Plugin::get_plugin_file())->toBe(KNTNT_TEST_PLUGIN_FILE);
        expect($GLOBALS['kntnt_test_added_filters'])->toHaveKey('pre_set_site_transient_update_plugins');
    });

    it('derives the slug from the main-file name', function (): void {
        Plugin::get_instance(KNTNT_TEST_PLUGIN_FILE);

        expect(Plugin::get_slug())->toBe('kntnt-ai-visibility');
    });

    it('returns the plugin directory with a trailing slash', function (): void {
        Functions\when('plugin_dir_path')->alias(static fn(string $file): string => rtrim(dirname($file), '/') . '/');

        Plugin::get_instance(KNTNT_TEST_PLUGIN_FILE);

        expect(Plugin::get_plugin_dir())->toBe('/plugins/kntnt-ai-visibility/');
    });

    it('reads the version from the plugin header via the get_file_data fallback', function (): void {
        Functions\when('get_file_data')->justReturn(['Version' => '1.2.3', 'PluginURI' => 'https://github.com/Kntnt/kntnt-ai-visibility']);

        Plugin::get_instance(KNTNT_TEST_PLUGIN_FILE);

        expect(Plugin::get_version())->toBe('1.2.3');

        // A second read comes from the static cache, not another file read.
        expect(Plugin::get_version())->toBe('1.2.3');
    });

    it('returns an empty version string when the header has no version', function (): void {
        kntnt_test_seed_plugin_header([]);

        expect(Plugin::get_version())->toBe('');
    });

    it('clears the plugin transients on deactivation', function (): void {
        $wpdb          = Mockery::mock();
        $wpdb->options = 'wp_options';
        $wpdb->shouldReceive('esc_like')->andReturnUsing(static fn(string $s): string => $s);
        $wpdb->shouldReceive('prepare')->once()->andReturn('PREPARED DELETE');
        $wpdb->shouldReceive('query')->once()->with('PREPARED DELETE')->andReturn(2);
        $GLOBALS['wpdb'] = $wpdb;

        Plugin::deactivate();

        // Mockery's once() expectations assert the prepared query ran exactly once.
        expect(true)->toBeTrue();
    });

    it('refuses to be cloned', function (): void {
        $plugin = Plugin::get_instance(KNTNT_TEST_PLUGIN_FILE);

        expect(static fn() => clone $plugin)->toThrow(LogicException::class);
    });

    it('refuses to be unserialized', function (): void {
        $plugin = Plugin::get_instance(KNTNT_TEST_PLUGIN_FILE);

        expect(static fn() => $plugin->__wakeup())->toThrow(LogicException::class);
    });

});
