<?php
/**
 * Mutate native site data without resaving the consuming source.
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

// This adapter declares only the menu consumed by its curated-resource body.
add_action( 'wp_update_nav_menu_item', static function ( int $menu_id ): void {
	if ( $menu_id === (int) get_option( 'kntnt_indirect_menu' ) ) {
		do_action( 'kntnt_ai_visibility_indirect_content_changed' );
	}
} );
add_shortcode( 'indirect_menu', static function (): string {
	$html = '<ul>';
	foreach ( wp_get_nav_menu_items( (int) get_option( 'kntnt_indirect_menu' ) ) ?: [] as $item ) {
		$html .= '<li><a href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . '</a></li>';
	}
	return $html . '</ul>';
} );

// The token and writer exist only in this disposable MU-plugin fixture.
add_action( 'wp_loaded', static function (): void {
	if ( ( $_GET['indirect_fixture'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['action'] ?? 'state' ) );
	if ( $action === 'site' ) {
		update_option( 'blogname', 'SITE-B' );
		update_option( 'blogdescription', 'TAGLINE-B' );
	} elseif ( $action === 'unrelated' ) {
		update_option( 'kntnt_indirect_unrelated', 'UNRELATED' );
	} elseif ( $action === 'profile-unrelated' ) {
		wp_update_user( [ 'ID' => 1, 'user_url' => 'https://example.test/unrelated-profile' ] );
	} elseif ( $action === 'author' ) {
		wp_update_user( [ 'ID' => 1, 'display_name' => 'AUTHOR-B' ] );
	} elseif ( $action === 'menu' ) {
		wp_update_nav_menu_item( (int) get_option( 'kntnt_indirect_menu' ), (int) get_option( 'kntnt_indirect_menu_item' ), [
			'menu-item-title' => 'MENU-B',
			'menu-item-url' => 'https://example.test/resource-b',
			'menu-item-type' => 'custom',
			'menu-item-status' => 'publish',
		] );
	}
	$store = new File_Store( static fn(): string => Plugin::cache_dir() );
	$version = ( new Cache_Version() )->current();
	$post = get_post( (int) get_option( 'kntnt_indirect_source' ) );
	$identities = [
		'page' => ( new Markdown_Alternate() )->identity_for( $post ),
		'index' => new Identity( 'llms-txt', 'llms-v' . $version ),
		'full' => new Identity( 'llms-full', 'llms-full-v' . $version ),
	];
	$cached = [];
	foreach ( $identities as $name => $identity ) {
		$cached[ $name ] = $store->has( $identity );
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [ 'php' => PHP_VERSION, 'version' => $version, 'cached' => $cached, 'modified' => $post->post_modified_gmt ] );
	exit;
}, 100 );
