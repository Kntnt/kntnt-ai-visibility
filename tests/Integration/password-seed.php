<?php
/**
 * Seeds the stored-password regression in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install only test-owned pages and controls in the disposable filesystem.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/password-helper.php', WP_CONTENT_DIR . '/mu-plugins/password-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
foreach ( [ 'protected' => 'fixture', 'ordinary' => '' ] as $slug => $password ) {
	$id = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_name' => $slug,
		'post_title' => $slug === 'protected' ? 'Password fixture' : 'Public fixture',
		'post_password' => $password,
		'post_content' => $slug === 'protected' ? '<p>PASSWORD-CONTENT</p>' : '<p>PUBLIC-CONTENT</p>',
	] );
	if ( $slug === 'protected' ) {
		update_option( 'kntnt_password_fixture_id', $id );
	}
}
flush_rewrite_rules();
