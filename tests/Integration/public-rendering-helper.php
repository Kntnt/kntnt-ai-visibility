<?php
/**
 * Audience-sensitive content and controls for disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Plugin;

// Exercise actual WordPress identity and a directly inspected visitor cookie.
add_filter( 'query_vars', static fn( array $vars ): array => [ ...$vars, 'member_token' ] );
add_shortcode( 'public_render_audience', static function (): string {
	return is_user_logged_in() || isset( $_COOKIE['fixture_member'] ) || get_query_var( 'member_token' ) === 'private'
		? 'MEMBER-PRIVATE-DETAIL' : 'PUBLIC-DETAIL';
} );

// Real content integrations can veto shared publication while rendering.
add_shortcode( 'public_render_signal', static function (): string {
	$signal = get_option( 'kntnt_public_fixture_signal', '' );
	if ( str_starts_with( $signal, 'headers' ) ) {
		nocache_headers();
	} elseif ( $signal === 'constant' ) {
		define( 'DONOTCACHEPAGE', true );
	} elseif ( $signal === 'integration' ) {
		do_action( 'kntnt_ai_visibility_public_content_nocache' );
	} elseif ( $signal === 'cookie' ) {
		setcookie( 'private_content', 'fixture' );
	}
	return $signal === '' ? 'PUBLIC-CACHEABLE-CONTENT' : 'SIGNALLED-PRIVATE-CONTENT';
} );

// Pre-existing transport headers must not hide a content integration's veto.
add_action( 'template_redirect', static function (): void {
	$signal = get_option( 'kntnt_public_fixture_signal', '' );
	if ( $signal === 'headers-preexisting' ) {
		nocache_headers();
	} elseif ( $signal === 'constant-preexisting' ) {
		define( 'DONOTCACHEPAGE', true );
	}
}, -50 );

// Aggregate escape-hatch filters must use the same public audience as pages.
foreach ( [ 'kntnt_ai_visibility_llms_txt', 'kntnt_ai_visibility_llms_full_txt' ] as $hook ) {
	add_filter( $hook, static function ( string $document ): string {
		return $document . ( is_user_logged_in() ? 'MEMBER-PRIVATE-AGGREGATE' : 'PUBLIC-AGGREGATE' );
	} );
}

// Fixed tokens are available exclusively in the disposable fixture installation.
add_action( 'init', static function (): void {
	$action = sanitize_key( (string) ( $_GET['public_fixture'] ?? '' ) );
	if ( $action === '' ) {
		return;
	}
	if ( $action === 'login' ) {
		wp_set_auth_cookie( (int) get_option( 'kntnt_public_fixture_user' ) );
		header( 'Content-Type: text/plain' );
		echo 'fixture-login';
		exit;
	}
	$store = new File_Store( static fn(): string => Plugin::cache_dir() );
	if ( $action === 'reset' ) {
		$store->flush_all();
		update_option( 'kntnt_public_fixture_signal', '' );
	}
	if ( str_starts_with( $action, 'signal-' ) ) {
		update_option( 'kntnt_public_fixture_signal', substr( $action, 7 ) );
	}
	$post = get_post( (int) get_option( 'kntnt_public_fixture_ordinary' ) );
	$uncacheable = get_post( (int) get_option( 'kntnt_public_fixture_uncacheable' ) );
	$protected = get_post( (int) get_option( 'kntnt_public_fixture_protected' ) );
	if ( $action === 'preview-url' ) {
		echo esc_url_raw( add_query_arg( [
			'preview' => 'true',
			'preview_id' => $post->ID,
			'preview_nonce' => wp_create_nonce( 'post_preview_' . $post->ID ),
		], get_permalink( $post ) ) );
		exit;
	}
	if ( in_array( $action, [ 'direct-success', 'direct-failure' ], true ) ) {

		// Observe the actual Core seam without replacing its collaborators.
		$caller = wp_get_current_user();
		$cookies = $_COOKIE;
		$query = $_GET;
		$server = $_SERVER;
		$wp_query = $GLOBALS['wp_query'];
		$query_vars = $wp_query->query_vars;
		$service = new Page_Markdown_Service( new Front_Matter(), new Single_Flight( $store ), new Plugin_Logger() );
		$failure = static function (): never {
			throw new RuntimeException( 'fixture-render-failure' );
		};
		if ( $action === 'direct-failure' ) {
			add_filter( 'the_content', $failure, 99 );
		}
		$bytes = '';
		$error = '';
		try {
			$bytes = $service->for_post( $post );
		} catch ( RuntimeException $exception ) {
			$error = $exception->getMessage();
		} finally {
			remove_filter( 'the_content', $failure, 99 );
		}
		header( 'Content-Type: application/json' );
		echo wp_json_encode( [
			'bytes' => $bytes,
			'error' => $error,
			'restored' => wp_get_current_user() === $caller && current_user_can( 'manage_options' ) && $_COOKIE === $cookies && $_GET === $query && $_SERVER === $server && $GLOBALS['wp_query'] === $wp_query && $wp_query->query_vars === $query_vars,
		] );
		exit;
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [
		'php' => PHP_VERSION,
		'logged_in' => is_user_logged_in(),
		'artifact' => $store->read( ( new Markdown_Alternate() )->identity_for( $post ) ),
		'uncacheable' => $store->has( ( new Markdown_Alternate() )->identity_for( $uncacheable ) ),
		'protected' => $store->has( ( new Markdown_Alternate() )->identity_for( $protected ) ),
		'password_required' => post_password_required( $protected ),
	] );
	exit;
}, -100 );
