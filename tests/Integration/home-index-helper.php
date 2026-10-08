<?php
/**
 * Test-only home, language-policy and cache controls in disposable Playground.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Exercise both default-language URL policies, like the canonical-path fixture.
add_filter( 'bogo_use_implicit_lang', static fn(): bool => ! get_option( 'kntnt_home_index_explicit', false ) );

// Let Bogo finish deferred rewrite setup before returning fixture state.
add_action( 'wp_loaded', static function (): void {

    // Keep fixture controls inaccessible without the test-only token.
    if ( ( $_GET['home_index_token'] ?? '' ) !== 'fixture-only' ) {
        return;
    }

    // Exercise normal option hooks when changing the configured home.
    $action = sanitize_key( (string) ( $_GET['home_index_action'] ?? '' ) );
    $ids = get_option( 'kntnt_home_index_ids', [] );
    if ( $action === 'static' ) {
        update_option( 'show_on_front', 'page' );
        update_option( 'page_on_front', $ids['en_GB']['front'] );
    } elseif ( $action === 'blog' ) {
        update_option( 'show_on_front', 'posts' );
    } elseif ( $action === 'explicit' ) {
        update_option( 'kntnt_home_index_explicit', true );
        flush_rewrite_rules();
    }

    // Start each warm-order scenario with no persisted artifacts.
    if ( $action === 'flush' || $action === 'explicit' ) {
        ( new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => \Kntnt\Ai_Visibility\Plugin::cache_dir() ) )->flush_all();
    }

    // Report readiness only after all rewrite and option hooks have run.
    header( 'Content-Type: application/json' );
    echo wp_json_encode( $ids );
    exit;

}, 100 );
