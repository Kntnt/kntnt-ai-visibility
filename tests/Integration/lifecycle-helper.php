<?php
/**
 * Exercises native sequential post mutations in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require WP_PLUGIN_DIR . '/kntnt-ai-visibility/tests/Integration/lifecycle-fixtures.php';

// Finish native mutation hooks before observing any subsequent HTTP artifact.
add_action( 'wp_loaded', static function (): void {

    // Keep mutation controls limited to the disposable fixture token.
    if ( ( $_GET['lifecycle_token'] ?? '' ) !== 'fixture-only' ) {
        return;
    }

    // Let all native post hooks finish before returning fixture readiness.
    $action = sanitize_key( (string) ( $_GET['lifecycle_action'] ?? '' ) );
    $ids = get_option( 'kntnt_lifecycle_ids', [] );
    if ( $action === 'rename' ) {
        wp_update_post( [ 'ID' => $ids['posts']['rename'], 'post_name' => 'life-renamed' ] );
    } elseif ( in_array( $action, [ 'draft', 'private' ], true ) ) {
        wp_update_post( [ 'ID' => $ids['posts'][ $action ], 'post_status' => $action ] );
    } elseif ( $action === 'trash' ) {
        wp_trash_post( $ids['posts']['trash'] );
    } elseif ( $action === 'protect' ) {
        wp_update_post( [ 'ID' => $ids['posts']['protect'], 'post_password' => 'fixture-secret' ] );
    } elseif ( $action === 'delete' ) {
        wp_delete_post( $ids['posts']['delete'], true );
    } else {
        $ids = kntnt_lifecycle_context_action( $action, $ids );
    }

    // IDs identify fixtures; public expectations live in the HTTP probe.
    header( 'Content-Type: application/json' );
    echo wp_json_encode( $ids );
    exit;

}, 100 );
