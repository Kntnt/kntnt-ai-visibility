<?php
/**
 * An explicit field-backed renderer and controls for disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Core\Public_Content_Rendering_Failed;
use Kntnt\Ai_Visibility\Llms\Full_Provider;
use Kntnt\Ai_Visibility\Plugin;

/**
 * The fixture's public template selects one published field, never all metadata.
 *
 * @param WP_Post $source The public source.
 * @return string Its visitor-visible HTML body.
 */
function kntnt_public_content_body( WP_Post $source ): string {

	// Real conditional checks ensure aggregate requests use a source context.
	if ( get_queried_object_id() !== $source->ID || get_the_ID() !== $source->ID || ! is_singular() || ! is_main_query() || ! in_the_loop() || get_current_user_id() !== 0 ) {
		return '<p>WRONG-SOURCE-OR-AUDIENCE</p>';
	}
	return (string) get_post_meta( $source->ID, 'public_section', true );

}

add_shortcode( 'public_content_fixture', static fn(): string => '<p>ORDINARY-SHORTCODE-TEXT</p>' );
add_filter( 'kntnt_ai_visibility_public_content_html', static function ( string $html, WP_Post $source ): ?string {
	if ( get_post_meta( $source->ID, 'public_content_fixture', true ) === 'field' ) {
		$mode = get_option( 'kntnt_public_content_mode', 'valid' );
		if ( $mode === 'invalid' ) {
			return null;
		}
		if ( $mode === 'throw' ) {
			$GLOBALS['kntnt_public_content_failure_context'] = get_queried_object_id() === $source->ID && get_the_ID() === $source->ID
				&& is_main_query() && in_the_loop() && get_current_user_id() === 0 && $_GET === [] && $_COOKIE === [];
			throw new RuntimeException( 'PRIVATE-RENDERER-DIAGNOSTIC' );
		}
	}
	return get_post_meta( $source->ID, 'public_content_fixture', true ) === 'field' ? kntnt_public_content_body( $source ) : $html;
}, 10, 2 );

// Render canonical HTML with the same public field selection as the adapter.
add_filter( 'template_include', static function ( string $template ): string {
	return get_post_meta( get_queried_object_id(), 'public_content_fixture', true ) === 'field'
		? WP_PLUGIN_DIR . '/kntnt-ai-visibility/tests/Integration/public-content-template.php' : $template;
} );

// The fixed control token exists only in this disposable installation.
add_action( 'init', static function (): void {
	if ( ( $_GET['public_content_fixture'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['action'] ?? '' ) );
	if ( in_array( $action, [ 'valid', 'invalid', 'throw' ], true ) ) {
		update_option( 'kntnt_public_content_mode', $action );
	}
	$store = new File_Store( static fn(): string => Plugin::cache_dir() );
	if ( ! in_array( $action, [ 'ready', 'state', 'direct-failure' ], true ) ) {
		$store->flush_all();
	}
	$result = [ 'php' => PHP_VERSION, 'sources' => [] ];
	foreach ( get_option( 'kntnt_public_content_sources' ) as $name => $id ) {
		$result['sources'][ $name ] = [ 'id' => $id, 'url' => get_permalink( $id ) ];
	}
	$post = get_post( $result['sources']['field']['id'] );
	$identity = ( new Markdown_Alternate() )->identity_for( $post );
	$result['page'] = $store->read( $identity );
	$result['full'] = $store->read( new Identity( Full_Provider::KIND, 'llms-full-v' . ( new Cache_Version() )->current() ) );
	if ( $action === 'direct-failure' ) {

		// A genuine authenticated caller has an active main Loop to restore.
		wp_set_current_user( 1 );
		$GLOBALS['wp_query'] = new WP_Query( [ 'page_id' => $result['sources']['ordinary']['id'] ] );
		$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
		$GLOBALS['wp_query']->the_post();
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
		$request = [ $_GET, $_POST, $_REQUEST, $_COOKIE, $_SERVER ];
		$logs = [];
		$logger = new Plugin_Logger( static function ( string $line ) use ( &$logs ): void {
			$logs[] = $line;
		} );
		$service = new Page_Markdown_Service( new Front_Matter(), new Single_Flight( $store ), $logger );
		try {
			$service->materialise( $identity, $post );
		} catch ( Public_Content_Rendering_Failed $exception ) {
			$result['error_class'] = $exception::class;
			$result['previous'] = $exception->getPrevious()?->getMessage();
		}
		$result['failure_context'] = $GLOBALS['kntnt_public_content_failure_context'] ?? false;
		$result['restored'] = wp_get_current_user() === $caller && get_locale() === $locale
			&& [ $_GET, $_POST, $_REQUEST, $_COOKIE, $_SERVER ] === $request
			&& serialize( [ $GLOBALS['wp_query'], $GLOBALS['wp_the_query'] ] ) === $query_state;
		foreach ( $names as $name ) {
			$result['restored'] = $result['restored'] && ( array_key_exists( $name, $saved )
				? array_key_exists( $name, $GLOBALS ) && $GLOBALS[ $name ] === $saved[ $name ]
				: ! array_key_exists( $name, $GLOBALS ) );
		}
		$result['logs'] = $logs;
		$result['page'] = $store->read( $identity );

	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $result );
	exit;
}, 100 );
