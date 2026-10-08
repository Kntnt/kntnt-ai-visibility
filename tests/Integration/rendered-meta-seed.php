<?php
/**
 * Seeds native content and real attachments for rendered metadata regression.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';
update_option( 'permalink_structure', '/%postname%/' );
wp_mkdir_p( WPMU_PLUGIN_DIR );
copy( __DIR__ . '/rendered-meta-helper.php', WPMU_PLUGIN_DIR . '/rendered-meta-fixture.php' );
wp_insert_post( [
	'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'rendered-meta',
	'post_title' => 'Rendered metadata', 'post_content' => '[rendered_meta]',
] );
// Real PNG uploads and attachment rows exercise get_the_post_thumbnail_url.
foreach ( [ 'one', 'two' ] as $name ) {
	$upload = wp_upload_bits( 'rendered-meta-' . $name . '.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5l8AAAAASUVORK5CYII=' ) );
	$attachment = wp_insert_attachment( [ 'post_mime_type' => 'image/png', 'post_title' => 'Image ' . $name, 'post_status' => 'inherit' ], $upload['file'] );
	wp_update_attachment_metadata( $attachment, [ 'width' => 1, 'height' => 1, 'file' => _wp_relative_upload_path( $upload['file'] ) ] );
	update_option( 'rendered_meta_thumbnail-' . $name, $attachment );
}
flush_rewrite_rules();
