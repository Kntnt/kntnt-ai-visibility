<?php
/**
 * Seeds sequential lifecycle sources in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require __DIR__ . '/home-index-seed.php';

// Keep the accepted home/index and language fixtures available to mutations.
$ids = get_option( 'kntnt_home_index_ids' );
foreach ( [ 'rename', 'draft', 'private', 'trash', 'protect', 'delete' ] as $role ) {
    $ids['posts'][ $role ] = wp_insert_post( [
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_name' => 'life-' . $role,
        'post_title' => 'Lifecycle ' . $role,
        'post_content' => '<p>LIFECYCLE-' . $role . '</p>',
        'meta_input' => [ '_locale' => 'en_GB' ],
    ] );
}
update_option( 'kntnt_ai_visibility', [ 'content_types' => [ 'post' => [ 'llms_full' => true ] ] ] );
update_option( 'kntnt_lifecycle_ids', $ids );
copy( __DIR__ . '/lifecycle-helper.php', WP_CONTENT_DIR . '/mu-plugins/lifecycle-helper.php' );
flush_rewrite_rules();
