<?php
/**
 * Provides a real downstream form handler in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Plugin;

// Observe the public cache-store seam without opening its implementation files.
add_action( 'init', static function (): void {

	// This token identifies fixture control traffic in the disposable instance.
	$action = sanitize_key( wp_unslash( $_GET['methods_fixture'] ?? '' ) );
	if ( ! in_array( $action, [ 'reset', 'state' ], true ) ) {
		return;
	}
	$store = new File_Store( Plugin::cache_dir(...) );
	if ( $action === 'reset' ) {
		$store->flush_all();
	}

	// Read each artifact identity through Store, including aggregate versions.
	$version = ( new Cache_Version() )->current();
	$state = [
		'markdown' => $store->read( new Identity( 'markdown-alternate', 'ordinary' ) ),
		'index' => $store->read( new Identity( 'llms-txt', 'llms-v' . $version ) ),
		'full' => $store->read( new Identity( 'llms-full', 'llms-full-v' . $version ) ),
	];
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $state );
	exit;

}, 100 );

// A template_redirect callback after priority zero must receive the POST body.
add_action( 'template_redirect', static function (): void {

	// The ordinary WordPress workflow handles unsupported artifact methods too.
	$method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) );
	$path = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH );
	if ( ! in_array( $method, [ 'POST', 'OPTIONS', 'PUT' ], true )
		|| ! in_array( $path, [ '/ordinary/', '/ordinary.md', '/ordinary.md/', '/llms.txt', '/llms-full.txt' ], true ) ) {
		return;
	}

	// Return ordinary HTML from the submitted field, proving the form ran.
	$submission = sanitize_text_field( wp_unslash( $_POST['submission'] ?? '' ) );
	status_header( 200 );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo '<p>FORM-RECEIVED: ' . esc_html( $submission ) . '</p>';
	echo '<p>WORKFLOW-RECEIVED: ' . esc_html( $method . ' ' . $path ) . '</p>';
	exit;

}, 20 );
