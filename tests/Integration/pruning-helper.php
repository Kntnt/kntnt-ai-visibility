<?php
/**
 * Completes a newer generation after the old one has published its bytes.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Plugin;

/** Uses only real, disposable fixture-owned WordPress cache storage. */
function pruning_store(): File_Store {
    return new File_Store( Plugin::cache_dir( ... ) );
}

add_action( 'init', static function (): void {
    if ( ( $_GET['pruning_token'] ?? '' ) !== 'fixture-only' ) {
        return;
    }
    $store = pruning_store();
    $version = new Cache_Version( $store );
    if ( ( $_GET['pruning_action'] ?? '' ) === 'reset' ) {
        $store->flush_all();
        update_option( 'kntnt_ai_visibility_cache_version', 2 );
    }
    wp_send_json( [
        'ready' => (bool) get_option( 'pruning_ready' ),
        'php' => PHP_VERSION,
        'current' => $version->current(),
        'old_index' => $store->has( new Identity( 'llms-txt', 'llms-v2' ) ),
        'new_index' => $store->read( new Identity( 'llms-txt', 'llms-v3' ) ),
        'old_full' => $store->has( new Identity( 'llms-full', 'llms-full-v2' ) ),
        'new_full' => $store->read( new Identity( 'llms-full', 'llms-full-v3' ) ),
        'old_mtime' => get_option( 'pruning_old_mtime' ),
    ] );
}, -100 );

foreach ( [ 'llms_txt' => 'llms-txt', 'llms_full_txt' => 'llms-full' ] as $filter => $kind ) {
    add_filter( 'kntnt_ai_visibility_' . $filter, static function ( string $bytes ) use ( $kind ): string {
        if ( ( $_SERVER['HTTP_X_PRUNING_OVERLAP'] ?? '' ) !== 'new-after-old' ) {
            return $bytes;
        }
        $GLOBALS['pruning_published_kind'] = $kind;
        return $kind === 'llms-txt' ? "OLD TWO\n" : "OLD FULL TWO\n";
    }, 100 );
}

// The authoritative query made by optional cleanup is a real public I/O seam.
// Before publication the old aggregate does not exist, so generation validation
// remains unchanged; after publication the newer request can complete first.
add_filter( 'query', static function ( string $query ): string {
    $kind = $GLOBALS['pruning_published_kind'] ?? null;
    if ( $kind === null || ! str_starts_with( $query, 'SELECT option_value FROM ' )
        || ! str_contains( $query, "'kntnt_ai_visibility_cache_version'" ) ) {
        return $query;
    }
    $prefix = $kind === 'llms-txt' ? 'llms-v' : 'llms-full-v';
    $store = pruning_store();
    if ( ! $store->has( new Identity( $kind, $prefix . '2' ) ) ) {
        return $query;
    }
    unset( $GLOBALS['pruning_published_kind'] );
    update_option( 'pruning_old_mtime', filemtime( $store->path_for( new Identity( $kind, $prefix . '2' ) ) ) );
    $version = new Cache_Version( $store );
    $version->bump();
    $current = new Identity( $kind, $prefix . '3' );
    $store->write( $current, $kind === 'llms-txt' ? "CURRENT THREE\n" : "CURRENT FULL THREE\n" );
    $store->prune_siblings( $current, static fn(): Identity => new Identity( $kind, $prefix . $version->current() ) );
    return $query;
} );
