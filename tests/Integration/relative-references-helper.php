<?php
/**
 * Changes URL shapes only within disposable reference-resolution fixtures.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Plugin;

// Let Bogo finish its deferred rewrite lifecycle before returning state.
add_action( 'wp_loaded', static function (): void {
	if ( ( $_GET['relative_fixture_token'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['action'] ?? '' ) );
	$structures = [ 'pretty' => '/%postname%/', 'no-slash' => '/%postname%', 'dated' => '/%year%/%monthnum%/%day%/%postname%/', 'plain' => '' ];
	if ( array_key_exists( $action, $structures ) ) {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( $structures[ $action ] );
		flush_rewrite_rules();
	}
	if ( $action !== 'state' ) {
		( new File_Store( static fn(): string => Plugin::cache_dir() ) )->flush_all();
	}
	$locator = new Markdown_Alternate();
	$result = [ 'php' => PHP_VERSION, 'sources' => [] ];
	foreach ( get_option( 'kntnt_relative_reference_ids', [] ) as $locale => $ids ) {
		foreach ( [ 'front', 'nested', 'post' ] as $role ) {
			$post = get_post( $ids[ $role ] );
			$result['sources'][] = [ 'id' => $post->ID, 'role' => $role, 'locale' => $locale, 'canonical' => $locator->canonical_url_for( $post ), 'alternate' => $locator->url_for( $post ) ];
		}
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $result );
	exit;
}, 100 );
