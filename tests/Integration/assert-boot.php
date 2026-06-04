<?php
/**
 * Playground end-to-end boot assertion.
 *
 * Runs inside WordPress Playground (WASM PHP 8.5) after the plugin has been
 * activated. It loads WordPress, verifies the plugin booted cleanly, and prints
 * a sentinel that the smoke-test harness greps for. Any failure throws, so the
 * Playground run exits non-zero. This file lives under tests/ and is never
 * shipped in the release zip.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare(strict_types=1);

require_once '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$plugin = 'kntnt-ai-visibility/kntnt-ai-visibility.php';

// The plugin requires PHP 8.5; confirm Playground actually booted on it.
if (PHP_VERSION_ID < 80500) {
    throw new RuntimeException('Expected PHP 8.5+, got ' . PHP_VERSION);
}

// Activation must have succeeded — a fatal during load would leave it inactive.
if (!is_plugin_active($plugin)) {
    throw new RuntimeException('Plugin is not active after activation.');
}

// The autoloader must resolve the plugin's own namespace.
if (!class_exists(Kntnt\Ai_Visibility\Plugin::class)) {
    throw new RuntimeException('Kntnt\\Ai_Visibility\\Plugin did not autoload.');
}

// The singleton must expose the header version, proving the bootstrap ran.
$version = Kntnt\Ai_Visibility\Plugin::get_version();
if ($version !== '0.1.0') {
    throw new RuntimeException('Unexpected plugin version: ' . $version);
}

// Reaching this line means every check above passed. The harness treats a
// zero exit code as the authoritative success signal (any failed check throws
// and makes the Playground run exit non-zero); this echo is for human readers
// of a verbose run. STDERR is intentionally not used — it is a CLI-only
// constant and is undefined in Playground's web SAPI.
echo "KNTNT_AI_VISIBILITY_BOOT_OK\n";
