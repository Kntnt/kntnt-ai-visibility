<?php
/**
 * WordPress stubs for unit testing.
 *
 * Defines the constants the plugin references at include time so autoloaded
 * classes parse and run without a live WordPress installation. Loaded via the
 * Composer autoload-dev "files" entry, so it runs before any test or plugin
 * code. WordPress functions are stubbed per-test with Brain Monkey instead of
 * here, to keep behaviour explicit in each test.
 *
 * @package Tests
 * @since   0.1.0
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
