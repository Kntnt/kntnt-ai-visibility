<?php
/**
 * Seed native indirect dependencies for the disposable WordPress fixture.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Subsequent requests load only this explicitly installed fixture adapter.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/indirect-helper.php', WP_CONTENT_DIR . '/mu-plugins/indirect-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'blogname', 'SITE-A' );
update_option( 'blogdescription', 'TAGLINE-A' );
wp_update_user( [ 'ID' => 1, 'display_name' => 'AUTHOR-A' ] );

// A real page supplies both the direct alternate and the full aggregate.
$id = wp_insert_post( [
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_name' => 'indirect-source',
	'post_title' => 'Indirect source',
	'post_author' => 1,
	'post_content' => '<p>Public indirect body.</p>',
] );
update_option( 'kntnt_indirect_source', $id );
flush_rewrite_rules();
