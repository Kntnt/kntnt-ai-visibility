<?php
/**
 * Plugin uninstall script.
 *
 * Removes everything the plugin persists when it is deleted through the
 * WordPress admin (docs/spec §7): the single settings option, the cache-version
 * stamp (which lives in its own option key per docs/adr/0010), and the file
 * cache directory under uploads (docs/adr/0007). The plugin creates no custom
 * tables or post types. WordPress runs this file in isolation without loading
 * the plugin, so it requires the autoloader itself to reuse the same cache
 * store and option keys the rest of the plugin uses.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Plugin;

// Bail unless WordPress is performing a genuine uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Load the plugin's autoloader so the cleanup can reuse the cache store and the
// option keys it shares with the rest of the plugin.
require_once __DIR__ . '/autoloader.php';

// Remove the single settings option and the cache-version stamp.
delete_option( 'kntnt_ai_visibility' );
delete_option( Cache_Version::OPTION );

// Remove the entire Core-owned file cache directory.
( new File_Store( static fn(): string => Plugin::cache_dir() ) )->flush_all();
