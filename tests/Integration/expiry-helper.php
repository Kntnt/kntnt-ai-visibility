<?php
/**
 * Drive actual public cache expiry without a source or invalidation mutation.
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

// Deliberately change bytes through an unrelated option missed by invalidation.
add_filter( 'kntnt_ai_visibility_cache_ttl', static fn() => get_option( 'kntnt_expiry_ttl', WEEK_IN_SECONDS ) );
add_filter( 'kntnt_ai_visibility_public_content_html', static fn( string $html ): string => $html . '<p>TTL-BODY-' . esc_html( (string) get_option( 'kntnt_expiry_marker', 'A' ) ) . '</p>' );
add_filter( 'get_the_excerpt', static fn(): string => 'TTL-EXCERPT-' . get_option( 'kntnt_expiry_marker', 'A' ) );

// Only the disposable fixture can age files, through public store identities.
add_action( 'wp_loaded', static function (): void {
	if ( ( $_GET['expiry_fixture'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$store = new File_Store( static fn(): string => Plugin::cache_dir() );
	$action = sanitize_key( (string) ( $_GET['action'] ?? 'state' ) );
	if ( $action === 'configure' ) {
		if ( ( $_GET['ttl'] ?? '' ) === 'default' ) {
			delete_option( 'kntnt_expiry_ttl' );
		} else {
			update_option( 'kntnt_expiry_ttl', (string) ( $_GET['ttl'] ?? WEEK_IN_SECONDS ) );
		}
		update_option( 'kntnt_expiry_marker', 'A' );
		$store->flush_all();
	} elseif ( $action === 'mutate' ) {
		update_option( 'kntnt_expiry_marker', 'B' );
	}
	$version = ( new Cache_Version() )->current();
	$post = get_post( (int) get_option( 'kntnt_expiry_id' ) );
	$identities = [
		'page' => ( new Markdown_Alternate() )->identity_for( $post ),
		'index' => new Identity( 'llms-txt', 'llms-v' . $version, 0 ),
		'full' => new Identity( 'llms-full', 'llms-full-v' . $version, 0 ),
	];
	$files = [];
	foreach ( $identities as $name => $identity ) {
		$path = $store->path_for( $identity );
		if ( $action === 'age' && is_file( $path ) ) {
			touch( $path, time() - (int) ( $_GET['age'] ?? 700000 ) );
		}
		clearstatcache( true, $path );
		$files[ $name ] = is_file( $path ) ? (int) filemtime( $path ) : null;
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [ 'php' => PHP_VERSION, 'version' => $version, 'files' => $files ] );
	exit;
}, 100 );
