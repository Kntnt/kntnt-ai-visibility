<?php
/**
 * Installs the negotiated-cache HTTP fixture in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install an isolated ordinary page and the cache-integration observer.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/negotiated-cache-helper.php', WP_CONTENT_DIR . '/mu-plugins/negotiated-cache-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
wp_insert_post( [
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_name' => 'ordinary',
	'post_title' => 'Ordinary page',
	'post_content' => '<p>PUBLIC-CACHE-FIXTURE</p>',
] );
flush_rewrite_rules();
