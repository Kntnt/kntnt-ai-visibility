<?php
/**
 * Controls native URL modes and downstream workflows in disposable Playground.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Exercise both native Bogo policies without composing prefixes in the plugin.
add_filter( 'bogo_use_implicit_lang', static fn(): bool => ! get_option( 'kntnt_slash_explicit', false ) );

// Let Bogo's deferred rewrite registration finish before reporting state.
add_action( 'wp_loaded', static function (): void {
	if ( ( $_GET['slash_fixture_token'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['action'] ?? 'state' ) );
	if ( in_array( $action, [ 'pretty', 'noslash', 'plain' ], true ) ) {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( match ( $action ) {
			'pretty' => '/%postname%/',
			'noslash' => '/%postname%',
			default => '',
		} );
		flush_rewrite_rules();
	} elseif ( $action === 'explicit' || $action === 'implicit' ) {
		update_option( 'kntnt_slash_explicit', $action === 'explicit' );
		flush_rewrite_rules();
	}
	if ( $action !== 'state' ) {
		( new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => \Kntnt\Ai_Visibility\Plugin::cache_dir() ) )->flush_all();
	}
	$sources = [];
	foreach ( get_option( 'kntnt_slash_ids', [] ) as $locale => $roles ) {
		foreach ( $roles as $role => $id ) {
			$post = get_post( $id );
			$sources[] = [ 'id' => $id, 'role' => $role, 'locale' => $locale, 'alternate' => ( new \Kntnt\Ai_Visibility\Core\Markdown_Alternate() )->url_for( $post ) ];
		}
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [ 'php' => PHP_VERSION, 'sources' => $sources ] );
	exit;
}, 100 );

// A downstream site workflow witnesses fall-through after the plugin handler.
add_action( 'template_redirect', static function (): void {
	if ( ( $_GET['slash_fixture_probe'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'X-Kntnt-Slash-Downstream: reached' );
	echo 'DOWNSTREAM WORKFLOW';
	exit;
}, 1 );
