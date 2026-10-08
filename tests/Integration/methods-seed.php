<?php
/**
 * Seeds a downstream form in a disposable Playground instance.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install the normal page and a handler that runs after artifact negotiation.
update_option( 'permalink_structure', '/%postname%/' );
wp_insert_post( [
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_name' => 'ordinary',
	'post_title' => 'Ordinary form',
	'post_content' => '<p>PUBLIC-ARTIFACT-CONTENT</p>',
] );
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/methods-helper.php', WP_CONTENT_DIR . '/mu-plugins/methods-helper.php' );
flush_rewrite_rules();
