<?php
/**
 * Seeds query-addressed sources alongside the settled home/index fixtures.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require __DIR__ . '/home-index-seed.php';

// Include both native plain selectors: page_id for pages and p for posts.
$ids = get_option( 'kntnt_home_index_ids' );
foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
    $ids[ $locale ]['post'] = wp_insert_post( [
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_name' => 'plain-story',
        'post_title' => 'post ' . $locale,
        'post_content' => '<p>BODY-post-' . $locale . '</p>',
        'meta_input' => [ '_locale' => $locale ],
    ] );
    wp_update_post( [ 'ID' => $ids[ $locale ]['post'], 'post_name' => 'plain-story' ] );
}
update_post_meta( $ids['sv_SE']['post'], '_original_post', get_post_meta( $ids['en_GB']['post'], '_original_post', true ) );
update_option( 'kntnt_ai_visibility', [ 'content_types' => [ 'post' => [ 'llms_full' => true ] ] ] );
update_option( 'kntnt_plain_permalink_ids', $ids );

// Start with the reported failing configuration, using the native lifecycle.
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '' );
copy( __DIR__ . '/plain-permalink-helper.php', WP_CONTENT_DIR . '/mu-plugins/plain-permalink-helper.php' );
flush_rewrite_rules();
