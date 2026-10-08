<?php
/**
 * Seeds the real option lifecycle regression in disposable Playground.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Create pages before clearing the option, leaving a genuinely untouched site.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/exposure-options-helper.php', WP_CONTENT_DIR . '/mu-plugins/exposure-options-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
foreach ( [ 'page', 'other' ] as $name ) {
	wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_name' => 'exposure-' . $name,
		'post_title' => 'Exposure ' . $name,
		'post_content' => '<p>EXPOSURE-' . strtoupper( $name ) . '-CONTENT</p>',
	] );
}
flush_rewrite_rules();
delete_option( 'kntnt_ai_visibility' );
