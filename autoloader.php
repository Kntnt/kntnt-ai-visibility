<?php
/**
 * PSR-4 autoloader bootstrap.
 *
 * Delegates all class loading to the Composer-generated autoloader so the
 * main plugin file stays thin, the plugin's own `Kntnt\Ai_Visibility\*`
 * classes resolve to `classes/*.php`, and the bundled runtime dependency
 * (`kntnt/html-to-markdown`) is available under its own namespace. The class
 * map benefits from `--optimize-autoloader` in release builds.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Prevent direct file access outside WordPress.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Hand off to Composer's optimised class map.
require_once __DIR__ . '/vendor/autoload.php';
