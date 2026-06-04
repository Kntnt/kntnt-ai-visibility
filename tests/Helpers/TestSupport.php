<?php
/**
 * Shared test-support helpers.
 *
 * Small utilities used across the unit tests. Loaded via the Composer
 * autoload-dev "files" entry so the functions are always available.
 *
 * @package Tests
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Plugin;

if (!function_exists('kntnt_test_reset_plugin')) {
    /**
     * Resets the Plugin singleton's static state between tests.
     *
     * The Plugin caches its instance, the main-file path, and the parsed
     * header in private static properties that otherwise leak across tests in
     * the same process. Each affected test resets them to start clean.
     *
     * @return void
     */
    function kntnt_test_reset_plugin(): void {
        $ref = new ReflectionClass(Plugin::class);
        foreach (['instance', 'plugin_file', 'plugin_data'] as $property) {
            $prop = $ref->getProperty($property);
            $prop->setValue(null, $property === 'plugin_file' ? '' : null);
        }
    }
}

if (!function_exists('kntnt_test_seed_plugin_header')) {
    /**
     * Seeds the Plugin's cached header and file path via reflection.
     *
     * Lets a test inject a known plugin header (and main-file path) without a
     * live WordPress installation, so code that reads Plugin::get_plugin_data()
     * sees deterministic values.
     *
     * @param array<string,string> $data The header fields to expose.
     * @param string               $file The main-file path to expose.
     * @return void
     */
    function kntnt_test_seed_plugin_header(
        array $data,
        string $file = '/plugins/kntnt-ai-visibility/kntnt-ai-visibility.php'
    ): void {
        $ref = new ReflectionClass(Plugin::class);
        $ref->getProperty('plugin_data')->setValue(null, $data);
        $ref->getProperty('plugin_file')->setValue(null, $file);
    }
}
