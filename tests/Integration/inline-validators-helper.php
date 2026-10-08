<?php
/**
 * Mutates actual rendering dependencies without editing the source page.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Plugin;

// Render public metadata using the actual source post context.
add_shortcode( 'inline_fixture_meta', static fn(): string => (string) get_post_meta( get_the_ID(), 'fixture_public_meta', true ) );

// Competing selectors must survive both full and conditional inline responses.
header( 'Vary: Cookie' );
add_action( 'template_redirect', static function (): void {
	header( 'Vary: Accept-Encoding', false );

	// Reproduce an HTML integration that already supplied the source date.
	$post = get_queried_object();
	if ( $post instanceof \WP_Post ) {
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) get_post_modified_time( 'U', true, $post ) ) . ' GMT' );
	}
}, -100 );

// Fixed controls are available only in this disposable WordPress filesystem.
add_action( 'init', static function (): void {
	$action = sanitize_key( (string) ( $_GET['inline_fixture'] ?? '' ) );
	if ( $action === '' ) {
		return;
	}
	$page = (int) get_option( 'kntnt_inline_fixture_page' );
	if ( $action === 'metadata' ) {
		update_post_meta( $page, 'fixture_public_meta', 'META-CHANGED' );
	} elseif ( $action === 'shared' ) {
		wp_update_post( [
			'ID' => (int) get_option( 'kntnt_inline_fixture_shared' ),
			'post_content' => '<!-- wp:paragraph --><p>SHARED-CHANGED</p><!-- /wp:paragraph -->',
		] );
	}
	$post = get_post( $page );
	$store = new File_Store( static fn(): string => Plugin::cache_dir() );
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [
		'php' => PHP_VERSION,
		'modified' => $post->post_modified_gmt,
		'source_date' => gmdate( 'D, d M Y H:i:s', (int) get_post_modified_time( 'U', true, $post ) ) . ' GMT',
		'cached' => $store->has( ( new Markdown_Alternate() )->identity_for( $post ) ),
	] );
	exit;
}, -100 );
