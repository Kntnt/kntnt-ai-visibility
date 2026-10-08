<?php
/**
 * Seeds the render/revocation tracer with native WordPress persistence.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/publication-helper.php', WP_CONTENT_DIR . '/mu-plugins/publication-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
wp_insert_post( [
	'post_type' => 'post',
	'post_status' => 'publish',
	'post_name' => 'publication-draft',
	'post_title' => 'Publication draft',
	'post_content' => '[publication_revoke]',
] );
update_option( 'publication_ready', true );
flush_rewrite_rules();
