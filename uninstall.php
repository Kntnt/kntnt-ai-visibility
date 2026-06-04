<?php
/**
 * Plugin uninstall script.
 *
 * Removes everything the plugin may have stored when it is deleted through the
 * WordPress admin. The plugin creates no custom tables or post types; the
 * Markdown module caches rendered output in transients (see docs/adr/0001), so
 * a clean uninstall removes the plugin's transients plus its settings option
 * (added by later modules; harmless to delete when absent).
 *
 * Runs without the plugin's autoloader, so every call is fully qualified and
 * uses raw $wpdb directly.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Bail unless WordPress is performing a genuine uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Remove the plugin's settings option.
delete_option( 'kntnt_ai_visibility_settings' );

// Remove every transient the plugin may have cached — the Markdown render
// cache and any future module caches — including their timeout twins. The %i
// placeholder safely interpolates the table identifier.
$sql = $wpdb->prepare(
	'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
	$wpdb->options,
	$wpdb->esc_like( '_transient_kntnt_ai_visibility_' ) . '%',
	$wpdb->esc_like( '_transient_timeout_kntnt_ai_visibility_' ) . '%',
);
if ( is_string( $sql ) ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is the $wpdb->prepare() result above.
	$wpdb->query( $sql );
}
