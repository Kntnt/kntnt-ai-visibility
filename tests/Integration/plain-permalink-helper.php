<?php
/**
 * Changes plain/pretty permalink settings in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Run after Bogo's deferred rewrite lifecycle, before returning fixture state.
add_action( 'wp_loaded', static function (): void {

    // The fixed token is only present in this disposable test installation.
    if ( ( $_GET['plain_permalink_token'] ?? '' ) !== 'fixture-only' ) {
        return;
    }

    // Setting changes exercise production invalidation without a manual flush.
    $action = sanitize_key( (string) ( $_GET['plain_permalink_action'] ?? '' ) );
    if ( $action === 'plain' || $action === 'pretty' ) {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure( $action === 'plain' ? '' : '/%postname%/' );
        flush_rewrite_rules();
    } elseif ( $action === 'blog' || $action === 'static' ) {
        update_option( 'show_on_front', $action === 'blog' ? 'posts' : 'page' );
    } elseif ( $action === 'explicit' || $action === 'implicit' ) {
        update_option( 'kntnt_home_index_explicit', $action === 'explicit' );
        flush_rewrite_rules();
    }

    // Cache-order tests start empty; Bogo's filter-policy fixture needs a purge.
    if ( in_array( $action, [ 'flush', 'explicit', 'implicit' ], true ) ) {
        ( new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => \Kntnt\Ai_Visibility\Plugin::cache_dir() ) )->flush_all();
    }

    // IDs are fixture observations; expected paths and bodies live in the probe.
    header( 'Content-Type: application/json' );
    echo wp_json_encode( get_option( 'kntnt_plain_permalink_ids', [] ) );
    exit;

}, 100 );
