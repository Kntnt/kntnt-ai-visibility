<?php
/**
 * Seeds language and cache-lifecycle fixtures in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install test control hooks in the disposable WordPress filesystem.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/audit-helper.php', WP_CONTENT_DIR . '/mu-plugins/audit-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
bogo_set_prop( 'enus_deactivated', true );

// The same slug must resolve to two different posts, including on cache hits.
$ids = [];
foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
	$id = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_name' => 'same-slug',
		'post_title' => $locale . ' fixture',
		'post_content' => '<p>BODY-' . $locale . '</p>[audit_context]',
		'post_excerpt' => 'Excerpt ' . $locale,
		'meta_input' => [ '_locale' => $locale ],
	] );
	wp_update_post( [ 'ID' => $id, 'post_name' => 'same-slug' ] );
	$ids[ $locale ] = $id;
}
update_post_meta( $ids['sv_SE'], '_original_post', get_post_meta( $ids['en_GB'], '_original_post', true ) );
update_option( 'kntnt_audit_ids', $ids );

// Ordinary posts expose stale permalink caches on status changes and renames.
foreach ( [ 'draft-later', 'rename-later', 'delete-later', 'protect-later' ] as $slug ) {
	wp_insert_post( [
		'post_type' => 'post',
		'post_status' => 'publish',
		'post_name' => $slug,
		'post_title' => $slug,
		'post_content' => '<p>PRIVATE-CANDIDATE-' . $slug . '</p>',
	] );
}
wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'secret', 'post_title' => 'Secret', 'post_password' => 'fixture', 'post_content' => 'PASSWORD-CONTENT' ] );
flush_rewrite_rules();
