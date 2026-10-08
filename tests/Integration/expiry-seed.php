<?php
/**
 * Seed native sources and deliberate unhooked rendering changes in Playground.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// The MU filter is available before the plugin selects its effective lifetime.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/expiry-helper.php', WP_CONTENT_DIR . '/mu-plugins/expiry-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );

// Keep every full-aggregate constituent inside the explicitly aged fixture.
foreach ( get_posts( [ 'post_type' => [ 'post', 'page' ], 'post_status' => 'any', 'numberposts' => -1 ] ) as $sample ) {
	wp_delete_post( $sample->ID, true );
}
$id = wp_insert_post( [
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_name' => 'ttl',
	'post_title' => 'Expiry fixture',
	'post_content' => '<p>Public expiry source.</p>',
] );
update_option( 'kntnt_expiry_id', $id );
flush_rewrite_rules();
