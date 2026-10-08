<?php
/**
 * Real source-aware integrations and controls in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Plugin;

/**
 * Reports actual WordPress conditionals rather than a supplied post argument.
 *
 * @return string The integration-visible source and audience.
 */
function kntnt_source_context_marker(): string {
	return 'Q' . get_queried_object_id() . '-P' . get_the_ID()
		. '-S' . (int) is_singular() . '-M' . (int) is_main_query() . '-L' . (int) in_the_loop()
		. '-T' . get_post_type() . '-U' . get_current_user_id() . '-' . get_locale();
}

add_shortcode( 'source_context', static fn(): string => '<p>SHORTCODE-' . kntnt_source_context_marker() . '</p>' );
add_action( 'init', static function (): void {
	register_block_type( 'kntnt-source/context', [ 'render_callback' => static fn(): string => '<p>BLOCK-' . kntnt_source_context_marker() . '</p>' ] );
} );
add_filter( 'the_content', static function ( string $content ): string {
	if ( is_singular( 'page' ) && is_main_query() && in_the_loop() ) {
		$content .= '<p>' . get_post_meta( get_the_ID(), 'source_field', true ) . '</p>';
	}
	return $content;
}, 30 );

// The fixed token is available only in this disposable installation.
add_action( 'init', static function (): void {
	if ( ( $_GET['source_context_fixture'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['action'] ?? '' ) );
	$sources = get_option( 'kntnt_source_context_sources' );
	$store = new File_Store( static fn(): string => Plugin::cache_dir() );
	if ( $action === 'flush' ) {
		$store->flush_all();
	}
	$result = [ 'php' => PHP_VERSION, 'sources' => [] ];
	foreach ( $sources as $key => $source ) {
		$result['sources'][ $key ] = [ ...$source, 'url' => get_permalink( $source['id'] ) ];
	}
	if ( str_starts_with( $action, 'direct-' ) ) {

		// Supply a genuine caller Loop, both aliased and distinct query objects.
		wp_set_current_user( (int) get_option( 'kntnt_source_context_user' ) );
		if ( $action === 'direct-posts-page' ) {
			update_option( 'show_on_front', 'page' );
			update_option( 'page_on_front', $sources['a-en_GB']['id'] );
			update_option( 'page_for_posts', $sources['b-sv_SE']['id'] );
		}
		$caller_query = new WP_Query( [ 'page_id' => $sources[ $action === 'direct-posts-page' ? 'a-en_GB' : 'b-sv_SE' ]['id'] ] );
		$GLOBALS['wp_query'] = $caller_query;
		$GLOBALS['wp_the_query'] = str_contains( $action, 'distinct' ) ? new WP_Query( [ 'page_id' => $sources['a-en_GB']['id'] ] ) : $caller_query;
		$caller_query->the_post();
		$switched = switch_to_locale( 'sv_SE' );
		$names = [ 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages', 'wp_query', 'wp_the_query' ];
		$saved = [];
		foreach ( $names as $name ) {
			if ( array_key_exists( $name, $GLOBALS ) ) {
				$saved[ $name ] = $GLOBALS[ $name ];
			}
		}
		$query_state = serialize( [ $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] ] );
		$caller = wp_get_current_user();
		$locale = get_locale();
		$service = new Page_Markdown_Service( new Front_Matter(), new Single_Flight( $store ), new Plugin_Logger() );
		$failure = static function ( string $content ): never {
			$GLOBALS['kntnt_source_failure_context'] = kntnt_source_context_marker();
			$GLOBALS['wp_query']->in_the_loop = false;
			throw new RuntimeException( 'source-context-failure' );
		};
		if ( str_contains( $action, 'failure' ) ) {
			add_filter( 'the_content', $failure, 40 );
		}
		$result['renders'] = [];
		try {
			foreach ( $sources as $key => $source ) {
				$result['renders'][ $key ] = $service->for_post( get_post( $source['id'] ) );
			}
		} catch ( RuntimeException $exception ) {
			$result['error'] = $exception->getMessage();
			$result['failure_context'] = $GLOBALS['kntnt_source_failure_context'];
		} finally {
			remove_filter( 'the_content', $failure, 40 );
		}
		$result['restored'] = wp_get_current_user() === $caller && get_locale() === $locale
			&& serialize( [ $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] ] ) === $query_state;
		foreach ( $names as $name ) {
			$result['restored'] = $result['restored'] && ( array_key_exists( $name, $saved )
				? array_key_exists( $name, $GLOBALS ) && $GLOBALS[ $name ] === $saved[ $name ]
				: ! array_key_exists( $name, $GLOBALS ) );
		}
		if ( $switched ) {
			restore_previous_locale();
		}
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $result );
	exit;
}, 100 );
