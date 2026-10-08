<?php
/**
 * Exercises native shared-block persistence without fixture cache purges.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Plugin;

add_filter( 'render_block', static function ( string $html ): string {
    if ( $GLOBALS['shared_blocks_mutating'] ?? false ) {
        ++$GLOBALS['shared_blocks_eager_renders'];
    }
    return $html;
} );

add_shortcode( 'shared_blocks_mutate', static function (): string {
    $ids = get_option( 'shared_blocks_ids', [] );
    $html = do_blocks( '<!-- wp:block {"ref":' . $ids['block'] . '} /-->' );
    if ( get_option( 'shared_blocks_armed' ) === 'page' ) {
        delete_option( 'shared_blocks_armed' );
        wp_update_post( [ 'ID' => $ids['block'], 'post_content' => '<!-- wp:paragraph --><p>SHARED-INFLIGHT</p><!-- /wp:paragraph -->' ] );
    }
    return $html;
} );

add_filter( 'kntnt_ai_visibility_llms_full_txt', static function ( string $bytes ): string {
    if ( get_option( 'shared_blocks_armed' ) === 'full' ) {
        delete_option( 'shared_blocks_armed' );
        $ids = get_option( 'shared_blocks_ids', [] );
        wp_update_post( [ 'ID' => $ids['block'], 'post_content' => '<!-- wp:paragraph --><p>SHARED-FINAL</p><!-- /wp:paragraph -->' ] );
    }
    return $bytes;
} );

add_action( 'init', static function (): void {
    if ( ( $_GET['shared_blocks_token'] ?? '' ) !== 'fixture-only' ) {
        return;
    }
    $ids = get_option( 'shared_blocks_ids', [] );
    $action = $_GET['shared_blocks_action'] ?? 'state';
    $cache = new File_Store( Plugin::cache_dir( ... ) );
    $version = new Cache_Version( $cache );
    $before = $version->current();
    $GLOBALS['shared_blocks_eager_renders'] = 0;
    $GLOBALS['shared_blocks_mutating'] = true;
    switch ( $action ) {
        case 'edit':
            wp_update_post( [ 'ID' => $ids['block'], 'post_content' => '<!-- wp:paragraph --><p>SHARED-CHANGED</p><!-- /wp:paragraph -->' ] );
            break;
        case 'edit-nested':
            wp_update_post( [ 'ID' => $ids['nested'], 'post_content' => '<!-- wp:paragraph --><p>NESTED-CHANGED</p><!-- /wp:paragraph --><!-- wp:block {"ref":' . $ids['block'] . '} /-->' ] );
            break;
        case 'draft':
        case 'publish':
            wp_update_post( [ 'ID' => $ids['block'], 'post_status' => $action ] );
            break;
        case 'trash':
            wp_trash_post( $ids['block'] );
            break;
        case 'delete':
            wp_delete_post( $ids['block'], true );
            break;
        case 'plain':
            $ids['block'] = wp_insert_post( [ 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'Replacement pattern', 'post_content' => '<!-- wp:paragraph --><p>SHARED-ORIGINAL</p><!-- /wp:paragraph -->' ] );
            $reference = '<!-- wp:block {"ref":' . $ids['block'] . '} /-->';
            wp_update_post( [ 'ID' => $ids['nested'], 'post_content' => $reference ] );
            foreach ( [ 'pattern-a', 'pattern-b' ] as $role ) {
                wp_update_post( [ 'ID' => $ids[ $role ], 'post_content' => $reference ] );
            }
            update_option( 'shared_blocks_ids', $ids );
            global $wp_rewrite;
            $wp_rewrite->set_permalink_structure( '' );
            flush_rewrite_rules();
            break;
        case 'prepare-page':
            $ids['pattern-inflight'] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'pattern-inflight', 'post_name' => 'pattern-inflight', 'post_content' => '[shared_blocks_mutate]' ] );
            update_option( 'shared_blocks_ids', $ids );
            update_option( 'shared_blocks_armed', 'page' );
            break;
        case 'arm-full':
            // Only the aggregate becomes cold; its page dependencies stay warm.
            $version->bump();
            update_option( 'shared_blocks_armed', 'full' );
            break;
    }
    $GLOBALS['shared_blocks_mutating'] = false;
    $locator = new Markdown_Alternate();
    $pages = [];
    foreach ( $ids as $role => $id ) {
        if ( str_starts_with( $role, 'pattern-' ) ) {
            $pages[ $role ] = $cache->has( $locator->identity_for( get_post( $id ) ) );
        }
    }
    $current = $version->current();
    wp_send_json( [
        'ready' => (bool) get_option( 'shared_blocks_ready' ), 'php' => PHP_VERSION,
        'ids' => $ids, 'before' => $before, 'current' => $current, 'cached_pages' => $pages,
        'eager_renders' => $GLOBALS['shared_blocks_eager_renders'],
        'old_full' => $cache->has( new Identity( 'llms-full', 'llms-full-v' . $before ) ),
        'current_full' => $cache->has( new Identity( 'llms-full', 'llms-full-v' . $current ) ),
    ] );
}, -100 );
