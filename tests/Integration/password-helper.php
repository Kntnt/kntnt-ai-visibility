<?php
/**
 * Exposes password-gate and store observations in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Plugin;

// Observe the real WordPress cookie decision before the Markdown handler exits.
add_action( 'template_redirect', static function (): void {
	$post = get_post( (int) get_option( 'kntnt_password_fixture_id' ) );
	if ( $post instanceof WP_Post ) {
		header( 'X-Kntnt-Password-Required: ' . ( post_password_required( $post ) ? '1' : '0' ) );
	}
}, -100 );

// These fixed tokens are fixture controls, available only in this test site.
add_action( 'init', static function (): void {
	if ( ( $_GET['password_fixture'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['action'] ?? '' ) );
	if ( $action === 'override' ) {
		add_filter( 'post_password_required', '__return_false' );
		return;
	}
	$post = get_post( (int) get_option( 'kntnt_password_fixture_id' ) );
	if ( ! $post instanceof WP_Post ) {
		status_header( 503 );
		exit;
	}
	$store = new File_Store( static fn(): string => Plugin::cache_dir() );
	if ( $action === 'reset' ) {
		$store->flush_all();
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [
		'php' => PHP_VERSION,
		'artifact' => $store->has( ( new Markdown_Alternate() )->identity_for( $post ) ),
	] );
	exit;
}, -100 );
