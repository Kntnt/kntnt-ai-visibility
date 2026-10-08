<?php
/**
 * Seeds anonymous-rendering regressions in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install fixture integrations before the next real HTTP request.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/public-rendering-helper.php', WP_CONTENT_DIR . '/mu-plugins/public-rendering-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
$user = wp_create_user( 'public-render-fixture', 'fixture-only-password', 'public-render@example.test' );
( new WP_User( $user ) )->set_role( 'administrator' );
update_option( 'kntnt_public_fixture_user', $user );
foreach ( [ 'ordinary' => '', 'protected' => 'fixture', 'uncacheable' => '' ] as $slug => $password ) {
	$id = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_name' => $slug,
		'post_title' => 'Public rendering fixture',
		'post_password' => $password,
		'post_content' => $slug === 'uncacheable' ? '[public_render_signal]' : '[public_render_audience]',
	] );
	update_option( 'kntnt_public_fixture_' . $slug, $id );
}

// A real unpublished autosave gives authenticated preview requests private bytes.
wp_set_current_user( $user );
require ABSPATH . 'wp-admin/includes/post.php';
wp_create_post_autosave( [
	'post_ID' => (int) get_option( 'kntnt_public_fixture_ordinary' ),
	'post_content' => 'PREVIEW-PRIVATE-DETAIL',
	'post_title' => 'Private preview',
	'post_excerpt' => '',
] );
flush_rewrite_rules();
