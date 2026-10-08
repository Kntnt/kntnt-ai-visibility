<?php
/**
 * Seeds static homes and actual index pages in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Keep source-specific bodies distinct, including translated index hierarchies.
update_option( 'permalink_structure', '/%postname%/' );
bogo_set_prop( 'enus_deactivated', true );
$ids = [];
foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
    foreach ( [ 'front' => 'ordinary', 'index' => 'index', 'nested' => 'index' ] as $role => $slug ) {

        // Give each source a unique literal marker and a stable canonical path.
        $post = [
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_name' => $slug,
            'post_title' => $role . ' ' . $locale,
            'post_content' => '<p>BODY-' . $role . '-' . $locale . '</p>',
            'meta_input' => [ '_locale' => $locale ],
        ];
        if ( $role === 'nested' ) {
            $post['post_parent'] = $ids[ $locale ]['index'];
        }
        $id = wp_insert_post( $post );
        wp_update_post( [ 'ID' => $id, 'post_name' => $slug ] );
        $ids[ $locale ][ $role ] = $id;

    }
}

// Relate translations so Bogo resolves the configured front in each language.
foreach ( [ 'front', 'index', 'nested' ] as $role ) {
    update_post_meta( $ids['sv_SE'][ $role ], '_original_post', get_post_meta( $ids['en_GB'][ $role ], '_original_post', true ) );
}

// Install lifecycle controls and expose readiness after the seed is complete.
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $ids['en_GB']['front'] );
update_option( 'kntnt_home_index_ids', $ids );
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/home-index-helper.php', WP_CONTENT_DIR . '/mu-plugins/home-index-helper.php' );
flush_rewrite_rules();
