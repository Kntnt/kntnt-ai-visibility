<?php
/**
 * Installs disposable native lifecycle controls and three public source pages.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require_once '/wordpress/wp-load.php';

// Keep the controls available after the ordinary plugin is deactivated.
wp_mkdir_p( WPMU_PLUGIN_DIR );
copy( __DIR__ . '/deactivation-helper.php', WPMU_PLUGIN_DIR . '/deactivation-fixture.php' );

// Give the home and a literal index page distinct final Markdown addresses.
$sources = [];
foreach ( ['home', 'ordinary', 'index'] as $slug ) {

	// Public source bytes let HTTP detect stale files after reactivation.
	$sources[ $slug ] = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_name' => $slug,
		'post_title' => ucfirst( $slug ),
		'post_content' => '<p>DEACTIVATION-' . $slug . '-ORIGINAL</p>',
	] );

}
update_option( 'kntnt_deactivation_sources', $sources );
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $sources['home'] );
update_option( 'kntnt_ai_visibility', [
	'content_types' => ['page' => ['md' => true, 'llms' => true, 'llms_full' => true]],
	'exclusions' => ['paths' => ''],
] );

// Native rewrite registration is deliberate fixture setup, never a cache purge.
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
add_rewrite_rule( '^fixture-keep$', 'index.php?deactivation_fixture=keep', 'top' );
flush_rewrite_rules();
