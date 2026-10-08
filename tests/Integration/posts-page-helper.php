<?php
/**
 * Controls only disposable posts-page fixtures through native WordPress APIs.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Plugin;

add_filter( 'bogo_use_implicit_lang', static fn(): bool => ! get_option( 'kntnt_posts_page_explicit', false ) );
add_action( 'template_redirect', static function (): void {
	header( 'X-Role-Home: ' . ( is_home() ? '1' : '0' ) );
	header( 'X-Role-Singular: ' . ( is_singular() ? '1' : '0' ) );
}, -100 );

// Change the configured role while a genuine public renderer is running.
add_filter( 'the_content', static function ( string $html ): string {
	$ids = get_option( 'kntnt_posts_page_ids' );
	if ( ( $_SERVER['HTTP_X_ROLE_REVOKE'] ?? '' ) === 'fixture-only' && get_the_ID() === $ids['en_GB']['ordinary'] ) {
		update_option( 'page_for_posts', $ids['en_GB']['ordinary'] );
	}
	return $html;
}, 1000 );

add_action( 'wp_loaded', static function (): void {
	if ( ( $_GET['posts_page_token'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$ids = get_option( 'kntnt_posts_page_ids' );
	$action = sanitize_key( (string) ( $_GET['action'] ?? 'state' ) );
	if ( $action === 'pretty' || $action === 'plain' ) {
		global $wp_rewrite;
		$wp_rewrite->set_permalink_structure( $action === 'pretty' ? '/%postname%/' : '' );
		flush_rewrite_rules();
	} elseif ( $action === 'explicit' || $action === 'implicit' ) {
		update_option( 'kntnt_posts_page_explicit', $action === 'explicit' );
		flush_rewrite_rules();
	} elseif ( $action === 'blog' || $action === 'static' ) {
		update_option( 'show_on_front', $action === 'blog' ? 'posts' : 'page' );
	} elseif ( $action === 'swap' || $action === 'restore' ) {
		update_option( 'page_for_posts', $ids['en_GB'][ $action === 'swap' ? 'ordinary' : 'posts' ] );
	} elseif ( $action === 'delete' ) {
		delete_option( 'page_for_posts' );
	} elseif ( $action === 'add' ) {
		add_option( 'page_for_posts', $ids['en_GB']['posts'] );
	}

	// Read the actual composition; no fixture store or publication replacement.
	$core = ( new ReflectionProperty( Plugin::class, 'core' ) )->getValue( Plugin::get_instance() );
	$provider = $core->artifacts()->providers()[0];
	$sources = [];
	foreach ( $ids as $locale => $roles ) {
		foreach ( $roles as $role => $id ) {
			$post = get_post( $id );
			$alternate = $core->markdown_alternate()->url_for( $post );
			$identity = $core->markdown_alternate()->identity_for( $post );
			$sources[ $locale ][ $role ] = [
				'id' => $id,
				'canonical' => $core->markdown_alternate()->canonical_url_for( $post ),
				'alternate' => $alternate,
				'eligible' => $core->eligibility()->is_eligible( $post ),
				'advertised' => array_map( static fn( $relation ): string => $relation->href, $provider->advertise( new Discovery_Context( $post ) ) ),
				'cached' => $core->cache()->has( $identity ),
			];
		}
	}

	// Raw explicitly supplied source rendering retains the #13 singular scope.
	$raw = [];
	$names = [ 'wp_query', 'wp_the_query', 'post', 'current_user' ];
	$caller = [];
	foreach ( $names as $name ) {
		$caller[ $name ] = $GLOBALS[ $name ] ?? null;
	}
	foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
		$seen = [];
		$observer = static function ( string $html ) use ( &$seen ): string {
			$seen = [ 'home' => is_home(), 'singular' => is_singular(), 'main' => is_main_query(), 'loop' => in_the_loop(), 'queried' => get_queried_object_id(), 'user' => get_current_user_id(), 'locale' => get_locale() ];
			return $html;
		};
		add_filter( 'the_content', $observer, 999 );
		try {
			$bytes = $core->page_markdown()->for_post( get_post( $ids[ $locale ]['posts'] ) );
		} catch ( Throwable $failure ) {
			header( 'Content-Type: application/json' );
			echo wp_json_encode( [ 'raw_error' => $failure::class, 'message' => $failure->getMessage(), 'action' => $action, 'locale' => $locale ] );
			exit;
		}
		remove_filter( 'the_content', $observer, 999 );
		$raw[ $locale ] = [ 'body' => str_contains( $bytes, 'ROLE-posts-' . str_replace( '_', '\\_', $locale ) ), 'scope' => $seen ];
	}
	$restored = true;
	foreach ( $caller as $name => $value ) {
		$restored = $restored && ( $GLOBALS[ $name ] ?? null ) === $value;
	}

	// A supported-role gate must be enforced by direct materialisation too.
	$materialise_refused = [];
	foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
		$post = get_post( $ids[ $locale ]['posts'] );
		if ( ! $core->eligibility()->is_eligible( $post ) ) {
			try {
				$core->page_markdown()->materialise( $core->markdown_alternate()->identity_for( $post ), $post );
				$materialise_refused[ $locale ] = false;
			} catch ( DomainException ) {
				$materialise_refused[ $locale ] = true;
			}
		}
	}
	// Queued explicit-source work remains subject to fresh stored-field checks.
	$stale_refused = null;
	if ( $action === 'stale' ) {
		$queued = get_post( $ids['en_GB']['posts'] );
		wp_update_post( [ 'ID' => $queued->ID, 'post_content' => '<p>ROLE-POSTS-CHANGED</p>' ] );
		try {
			$core->page_markdown()->for_post( $queued );
			$stale_refused = false;
		} catch ( DomainException ) {
			$stale_refused = true;
		}
		wp_update_post( [ 'ID' => $queued->ID, 'post_content' => $queued->post_content ] );
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [
		'php' => PHP_VERSION, 'sources' => $sources, 'raw' => $raw, 'restored' => $restored,
		'current' => ( new \Kntnt\Ai_Visibility\Core\Cache\Cache_Version( $core->cache() ) )->current(),
		'enumerated' => array_map( static fn( $post ): int => $post->ID, $core->eligibility()->enumerate( [ 'page' ] ) ),
		'materialise_refused' => $materialise_refused, 'stale_refused' => $stale_refused,
	] );
	exit;
}, 100 );
