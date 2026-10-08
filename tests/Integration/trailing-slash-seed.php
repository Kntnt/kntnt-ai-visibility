<?php
/**
 * Seeds actual translated sources in a disposable redirect fixture.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Keep canonical source paths distinct, including escaped index identities.
update_option( 'permalink_structure', '/%postname%/' );
bogo_set_prop( 'enus_deactivated', true );
$ids = [];
foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
	foreach ( [ 'ordinary' => 'about', 'front' => 'welcome', 'index' => 'index', 'nested' => 'index', 'unicode' => '%c3%b6ppettider' ] as $role => $slug ) {
		$source = [
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_name' => $slug,
			'post_title' => $role . ' ' . $locale,
			'post_content' => '<p>SLASH-SOURCE-' . $role . '-' . $locale . '</p>',
			'meta_input' => [ '_locale' => $locale ],
		];
		if ( $role === 'nested' ) {
			$source['post_parent'] = $ids[ $locale ]['index'];
		}
		$id = wp_insert_post( $source );
		wp_update_post( [ 'ID' => $id, 'post_name' => $slug ] );
		$ids[ $locale ][ $role ] = $id;
	}
}

// Relate native Bogo translations and configure the actual static front.
foreach ( [ 'ordinary', 'front', 'index', 'nested', 'unicode' ] as $role ) {
	update_post_meta( $ids['sv_SE'][ $role ], '_original_post', get_post_meta( $ids['en_GB'][ $role ], '_original_post', true ) );
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $ids['en_GB']['front'] );
update_option( 'kntnt_slash_ids', $ids );
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/trailing-slash-helper.php', WP_CONTENT_DIR . '/mu-plugins/trailing-slash-helper.php' );
flush_rewrite_rules();
