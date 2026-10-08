<?php
/**
 * Seeds real filesystem-failure HTTP regressions in disposable Playground.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install fixture controls only in this disposable WordPress filesystem.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/cache-failure-helper.php', WP_CONTENT_DIR . '/mu-plugins/cache-failure-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
wp_insert_post( [
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_name' => 'ordinary',
	'post_title' => 'Cache failure fixture',
	'post_content' => '<p>CACHE-FAILURE-CONTENT – public content remains available.</p>',
] );
flush_rewrite_rules();
