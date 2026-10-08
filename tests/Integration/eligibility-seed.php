<?php
/**
 * Seeds the disposable Playground site for effective exposure-policy tests.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Begin with a cold page exclusion, without changing plugin exposure settings.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/eligibility-helper.php', WP_CONTENT_DIR . '/mu-plugins/eligibility-helper.php' );
update_option( 'kntnt_eligibility_fixture_policy', 'no-page' );
update_option( 'permalink_structure', '/%postname%/' );
wp_insert_user( [
	'user_login' => 'eligibility-admin',
	'user_pass' => 'fixture-only',
	'role' => 'administrator',
] );
foreach ( ['page', 'post'] as $type ) {
	wp_insert_post( [
		'post_type' => $type,
		'post_status' => 'publish',
		'post_name' => 'policy-' . $type,
		'post_title' => 'Policy ' . $type,
		'post_content' => '<p>ELIGIBILITY-' . strtoupper( $type ) . '-BODY</p>',
	] );
}
flush_rewrite_rules();

// The final ready marker prevents probing before blueprint completion.
update_option( 'kntnt_eligibility_fixture_ready', true );
