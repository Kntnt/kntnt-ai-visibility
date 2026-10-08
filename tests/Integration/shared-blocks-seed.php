<?php
/**
 * Seeds native synced-pattern and nested reusable-block references.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/shared-blocks-helper.php', WP_CONTENT_DIR . '/mu-plugins/shared-blocks-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
$block = wp_insert_post( [
    'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Shared pattern',
    'post_content' => '<!-- wp:paragraph --><p>SHARED-ORIGINAL</p><!-- /wp:paragraph -->',
] );
$reference = '<!-- wp:block {"ref":' . $block . '} /-->';
$nested = wp_insert_post( [
    'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Nested pattern',
    'post_content' => $reference,
] );
$ids = [ 'block' => $block, 'nested' => $nested ];
foreach ( [ 'pattern-a' => $reference, 'pattern-b' => $reference,
    'pattern-nested' => '<!-- wp:block {"ref":' . $nested . '} /-->',
    'pattern-unrelated' => '<!-- wp:paragraph --><p>UNRELATED-SOURCE</p><!-- /wp:paragraph -->' ] as $slug => $content ) {
    $ids[ $slug ] = wp_insert_post( [
        'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $slug,
        'post_name' => $slug, 'post_content' => $content,
    ] );
}
update_option( 'shared_blocks_ids', $ids );
update_option( 'shared_blocks_ready', true );
flush_rewrite_rules();
