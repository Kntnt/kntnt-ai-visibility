<?php
/**
 * Observes cache bypass timing and competing Vary headers in Playground.
 *
 * This fixture exercises the public cache-integration action without a
 * particular cache plugin or the customer's production configuration.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Capture a Vary value that already exists before ordinary plugins load.
header( 'Vary: Cookie' );
add_action( 'plugins_loaded', static function (): void {
	$GLOBALS['kntnt_cache_fixture_early_bypass'] = defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE;
}, -100 );

// A site adapter can receive the bypass before ordinary plugins initialise.
add_action( 'kntnt_ai_visibility_cache_bypass', static function (): void {
	$GLOBALS['kntnt_cache_fixture_integration_bypass'] = true;
} );

// A later integration can append another Vary field before inline serving.
add_action( 'template_redirect', static function (): void {
	header( 'Vary: Accept-Encoding', false );
	if ( isset( $_GET['cache_vary_star'] ) ) {
		header( 'Vary: *', false );
	}
	header( 'X-Kntnt-Early-Bypass: ' . ( empty( $GLOBALS['kntnt_cache_fixture_early_bypass'] ) ? '0' : '1' ) );
	header( 'X-Kntnt-Integration-Bypass: ' . ( empty( $GLOBALS['kntnt_cache_fixture_integration_bypass'] ) ? '0' : '1' ) );
}, -100 );

// Reset only this disposable site's artifact store for a genuinely cold probe.
add_action( 'init', static function (): void {
	if ( ( $_GET['cache_test_ready'] ?? '' ) === 'fixture-only' ) {
		echo 'cache-ready';
		exit;
	}
	if ( ( $_GET['cache_test_reset'] ?? '' ) === 'fixture-only' ) {
		( new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => \Kntnt\Ai_Visibility\Plugin::cache_dir() ) )->flush_all();
	}
}, -100 );
