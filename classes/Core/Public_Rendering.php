<?php
/**
 * Runs public artifact producers without the requesting visitor's identity.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Isolates anonymous rendering and restores the caller on every exit.
 *
 * @since 0.5.2
 */
final class Public_Rendering {

	/**
	 * Runs a producer as WordPress's anonymous user with no visitor cookies.
	 *
	 * @since 0.5.2
	 *
	 * @param callable(): string $produce The public artifact producer.
	 * @param bool               $persistent Whether the result may be published in a shared file.
	 * @param-immediately-invoked-callable $produce
	 * @return string The anonymous representation.
	 * @throws \DomainException When preview or integration signals forbid publication.
	 */
	public static function run( callable $produce, bool $persistent = false ): string {

		// WordPress preview filters can substitute unpublished autosave fields.
		self::assert_public_request( $persistent );

		// Initialise lazy authentication before saving WordPress's user globals.
		$caller = wp_get_current_user();
		$names = [
			'current_user',
			'user_ID',
			'user_login',
			'userdata',
			'user_level',
			'user_email',
			'user_url',
			'user_identity',
			'_COOKIE',
			'_GET',
			'_POST',
			'_REQUEST',
			'_SERVER',
			'_FILES',
			'wp_query',
			'wp_the_query',
		];
		$saved = [];
		foreach ( $names as $name ) {
			if ( array_key_exists( $name, $GLOBALS ) ) {
				$saved[ $name ] = $GLOBALS[ $name ];
			}
		}
		$headers = headers_list();
		$was_uncacheable = defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE;
		$cache_veto = false;
		$veto = static function () use ( &$cache_veto ): void {
			$cache_veto = true;
		};
		// Integrations emit this action while producing non-public content.
		add_action( 'kntnt_ai_visibility_public_content_nocache', $veto );
		$nocache = static function ( array $policy ) use ( &$cache_veto ): array {
			$cache_veto = true;
			return $policy;
		};
		add_filter( 'nocache_headers', $nocache );

		// Content and metadata filters must observe an anonymous visitor.
		try {

			// Request-specific credentials and form/query data are not public input.
			$_COOKIE = [];
			$_GET = [];
			$_POST = [];
			$_REQUEST = [];
			$_FILES = [];
			unset( $_SERVER['HTTP_COOKIE'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $_SERVER['REMOTE_USER'] );
			$_SERVER['QUERY_STRING'] = '';
			$_SERVER['REQUEST_URI'] = '/';
			$_SERVER['REQUEST_METHOD'] = 'GET';

			// Registered query vars can retain credentials after $_GET is cleared.
			$queries = [];
			foreach ( [ 'wp_query', 'wp_the_query' ] as $name ) {
				$query = $GLOBALS[ $name ] ?? null;
				if ( $query instanceof \WP_Query ) {
					$id = spl_object_id( $query );
					if ( ! isset( $queries[ $id ] ) ) {
						$queries[ $id ] = clone $query;
						$queries[ $id ]->query = [];
						$queries[ $id ]->query_vars = [];
					}
					$GLOBALS[ $name ] = $queries[ $id ];
				}
			}

			// Notify identity-sensitive integrations before rendering any bytes.
			wp_set_current_user( 0 );
			$bytes = $produce();

			// Refuse content whose integration vetoed public shared publication.
			$cache_veto = $cache_veto || ( ! $was_uncacheable && defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE );
			foreach ( array_diff( headers_list(), $headers ) as $header ) {
				if ( preg_match( '/^(?:Set-Cookie:|Cache-Control:.*\b(?:private|no-store|no-cache)\b)/i', $header ) === 1 ) {
					$cache_veto = true;
				}
			}
			if ( $cache_veto ) {
				throw new \DomainException( 'A content integration refused public artifact caching.' );
			}

			return $bytes;
		} finally {

			// Remove only this rendering scope's observer and header side effects.
			remove_action( 'kntnt_ai_visibility_public_content_nocache', $veto );
			remove_filter( 'nocache_headers', $nocache );
			if ( ! headers_sent() ) {
				header_remove();
				foreach ( $headers as $header ) {
					header( $header, false );
				}
			}

			// Notify user-dependent integrations, then restore exact caller values.
			foreach ( [ '_COOKIE', '_GET', '_POST', '_REQUEST', '_SERVER', '_FILES' ] as $name ) {
				$GLOBALS[ $name ] = $saved[ $name ] ?? [];
			}
			wp_set_current_user( $caller->ID );
			foreach ( $names as $name ) {
				if ( array_key_exists( $name, $saved ) ) {
					$GLOBALS[ $name ] = $saved[ $name ];
				} else {
					unset( $GLOBALS[ $name ] );
				}
			}
		}

	}

	/**
	 * Refuses private preview sources before either rendering or cache reads.
	 *
	 * @since 0.5.2
	 *
	 * @param bool $persistent Whether a shared publication is being requested.
	 * @return void
	 * @throws \DomainException When the caller is previewing unpublished content.
	 */
	public static function assert_public_request( bool $persistent = false ): void {
		$query = $GLOBALS['wp_query'] ?? null;
		if ( isset( $_GET['preview'] ) || isset( $_GET['preview_id'] ) || ( $query instanceof \WP_Query && $query->is_preview ) ) {
			throw new \DomainException( 'Preview requests cannot produce public artifacts.' );
		}
		if ( $persistent && defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
			throw new \DomainException( 'A content integration refused public artifact caching.' );
		}
	}

}
