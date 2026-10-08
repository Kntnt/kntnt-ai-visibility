<?php
/**
 * Exposes WordPress option lifecycle controls in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;

// Mutate through real WordPress functions after all lifecycle hooks are ready.
add_action( 'wp_loaded', static function (): void {
	if ( ( $_GET['exposure_options_fixture'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['action'] ?? '' ) );
	$version = new Cache_Version();
	$before = $version->current();
	$value = match ( $action ) {
		'matrix-off' => [
			'content_types' => [ 'page' => [ 'md' => false, 'llms' => false, 'llms_full' => false ] ],
			'exclusions' => [ 'paths' => '' ],
		],
		'exclude-page' => [ 'exclusions' => [ 'paths' => '^/exposure-page/$' ] ],
		'combined' => [
			'content_types' => [ 'page' => [ 'md' => true, 'llms' => false, 'llms_full' => false ] ],
			'exclusions' => [ 'paths' => '^/exposure-other/$' ],
		],
		'reset' => [],
		'signals-one' => [ 'signals' => [ 'search' => 'yes' ] ],
		'signals-two' => [ 'signals' => [ 'search' => 'no' ] ],
		default => null,
	};
	if ( $value !== null ) {
		update_option( 'kntnt_ai_visibility', $value );
	}
	if ( $action === 'remove' ) {
		delete_option( 'kntnt_ai_visibility' );
	}
	if ( $action === 'unrelated' ) {
		update_option( 'kntnt_exposure_options_unrelated', true );
		delete_option( 'kntnt_exposure_options_unrelated' );
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [
		'php' => PHP_VERSION,
		'exists' => get_option( 'kntnt_ai_visibility', 'fixture-option-is-absent' ) !== 'fixture-option-is-absent',
		'before' => $before,
		'after' => $version->current(),
	] );
	exit;
}, -100 );
