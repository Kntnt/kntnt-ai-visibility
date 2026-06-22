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

        // Record every hook the constructor and its module boot register, and
        // provide the global add_filter/add_action the namespaced code falls
        // back to. The constructor now builds the Core service graph and boots
        // the Markdown module, so stub the few WordPress helpers that path calls.
        $GLOBALS['kntnt_test_added_filters'] = [];
        $GLOBALS['kntnt_test_added_actions'] = [];
        Functions\when('add_filter')->alias(function (string $hook, $callback = null): bool {
            $GLOBALS['kntnt_test_added_filters'][$hook] = $callback;
            return true;
        });
        Functions\when('add_action')->alias(function (string $hook, $callback = null): bool {
            $GLOBALS['kntnt_test_added_actions'][$hook] = $callback;
            return true;
        });
        Functions\when('apply_filters')->alias(static fn(string $hook, mixed $value = null): mixed => $value);
        Functions\when('__')->returnArg();
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

    it('boots the Markdown module on construction', function (): void {
        Plugin::get_instance(KNTNT_TEST_PLUGIN_FILE);

        // The module's request handler and discovery register these hooks, so
        // their presence proves the Core graph was built and the module booted.
        expect($GLOBALS['kntnt_test_added_actions'])->toHaveKey('template_redirect');
        expect($GLOBALS['kntnt_test_added_actions'])->toHaveKey('wp_head');
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

    it('builds the cache directory under the uploads base', function (): void {
        Functions\when('wp_upload_dir')->justReturn(['basedir' => '/var/www/uploads']);

        expect(Plugin::cache_dir())->toBe('/var/www/uploads/kntnt-ai-visibility-cache');
    });

    it('runs the early serve router and falls through on a non-markdown request', function (): void {
        // A plain GET / is not a cache-grade artifact request, so the router
        // resolves nothing and serve_early() returns without serving or exiting.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = '/';
        Functions\when('wp_unslash')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('home_url')->justReturn('https://example.com/');
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component = -1): mixed => parse_url($url, $component));

        Plugin::get_instance(KNTNT_TEST_PLUGIN_FILE)->serve_early();

        // Reaching here means the router fell through cleanly (no exit, no throw).
        expect(true)->toBeTrue();
    });

    it('flushes rewrite rules and clears the cache on deactivation', function (): void {
        // Point the cache at a directory that does not exist, so flush_all() is a
        // no-op and the test never touches the real filesystem.
        Functions\when('wp_upload_dir')->justReturn(['basedir' => sys_get_temp_dir() . '/kntnt-aiv-absent-' . uniqid()]);
        Functions\expect('flush_rewrite_rules')->once();

        Plugin::deactivate();

        // Mockery's once() expectation asserts the rewrite flush ran exactly once;
        // the settings option is deliberately left untouched for reactivation.
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
