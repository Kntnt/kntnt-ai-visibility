<?php
/**
 * Test-only full-path lifecycle controls in disposable Playground.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Let Bogo finish its initial rewrite refresh before fixture controls exit.
add_action( 'wp_loaded', static function (): void {
    if ( ( $_GET['canonical_token'] ?? '' ) !== 'fixture-only' ) {
        return;
    }
    $action = sanitize_key( (string) ( $_GET['canonical_action'] ?? '' ) );
    $ids = get_option( 'kntnt_canonical_ids', [] );
    if ( $action === 'language' ) {
        update_post_meta( $ids['language'], '_locale', 'sv_SE' );
    } elseif ( $action === 'dated' ) {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure( '/%year%/%monthnum%/%day%/%postname%/' );
        flush_rewrite_rules();
    } elseif ( $action === 'flat' ) {
        global $wp_rewrite;
        $wp_rewrite->set_permalink_structure( '/%postname%/' );
        flush_rewrite_rules();
    } elseif ( $action === 'explicit' ) {
        update_option( 'kntnt_audit_explicit_languages', true );
        flush_rewrite_rules();
    } elseif ( $action === 'default-swedish' || $action === 'default-english' ) {
        update_option( 'WPLANG', $action === 'default-swedish' ? 'sv_SE' : 'en_GB' );
        flush_rewrite_rules();
    } elseif ( $action === 'implicit' ) {
        update_option( 'kntnt_audit_explicit_languages', false );
        flush_rewrite_rules();
    } elseif ( $action === 'front' ) {
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $ids['en_GB'] );
    }
    header( 'Content-Type: application/json' );
    echo wp_json_encode( $ids );
    exit;
}, 100 );
