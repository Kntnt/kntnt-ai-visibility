<?php
/**
 * Seeds a successful prefix, a conversion fault source and an empty page.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Keep fault injection exclusively in this disposable fixture installation.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/conversion-failure-helper.php', WP_CONTENT_DIR . '/mu-plugins/conversion-failure-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
foreach ( [ 'prefix' => '<p>VALID-PREFIX-BODY</p>', 'ordinary' => '<p>PUBLIC-BODY</p>', 'empty' => '' ] as $slug => $content ) {
	$id = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_name' => $slug,
		'post_title' => 'Conversion fixture ' . $slug,
		'post_content' => $content,
		'menu_order' => $slug === 'prefix' ? -10 : 0,
	] );
	update_option( 'kntnt_conversion_fixture_' . $slug, $id );
}
flush_rewrite_rules();
