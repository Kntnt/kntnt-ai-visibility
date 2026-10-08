<?php
/**
 * Reports actual PHP and WordPress Unicode compatibility functions.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Keep the control endpoint confined to this disposable WordPress fixture.
add_action( 'wp_loaded', static function (): void {
	if ( sanitize_key( (string) ( $_GET['unicode_fixture'] ?? '' ) ) !== 'state' ) {
		return;
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [
		'php' => PHP_VERSION,
		'mbstring_extension' => extension_loaded( 'mbstring' ),
		'mb_strlen_internal' => ( new ReflectionFunction( 'mb_strlen' ) )->isInternal(),
		'mb_substr_internal' => ( new ReflectionFunction( 'mb_substr' ) )->isInternal(),
		'wordpress_compat_helpers' => function_exists( '_mb_strlen' ) && function_exists( '_mb_substr' ),
	] );
	exit;
}, 100 );
