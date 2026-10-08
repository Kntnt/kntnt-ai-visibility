<?php
/**
 * Seeds translated static homes, posts listings and ordinary singular pages.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Use real Bogo translation groups and the site's native permalink filters.
bogo_set_prop( 'enus_deactivated', true );
$ids = [];
foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
	foreach ( [ 'front', 'posts', 'ordinary' ] as $role ) {
		$ids[ $locale ][ $role ] = wp_insert_post( [
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_name' => 'role-' . $role,
			'post_title' => $role . ' ' . $locale,
			'post_content' => '<p>ROLE-' . $role . '-' . $locale . '</p>',
			'meta_input' => [ '_locale' => $locale ],
		] );
	}
}
foreach ( [ 'front', 'posts', 'ordinary' ] as $role ) {
	update_post_meta( $ids['sv_SE'][ $role ], '_original_post', get_post_meta( $ids['en_GB'][ $role ], '_original_post', true ) );
}

// Install test-only controls after the real plugin and Bogo have booted.
update_option( 'kntnt_posts_page_ids', $ids );
update_option( 'permalink_structure', '/%postname%/' );
update_option( 'page_on_front', $ids['en_GB']['front'] );
update_option( 'page_for_posts', $ids['en_GB']['posts'] );
update_option( 'show_on_front', 'page' );
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/posts-page-helper.php', WP_CONTENT_DIR . '/mu-plugins/posts-page-helper.php' );
flush_rewrite_rules();
