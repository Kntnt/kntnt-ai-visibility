<?php
/**
 * Conditional-request freshness.
 *
 * The single rule shared by the early serve router and the Markdown request
 * handler for deciding whether a client's cached copy is still fresh and may be
 * answered with 304: a matching (or wildcard) If-None-Match is authoritative
 * over the date; otherwise an If-Modified-Since no older than the resource is
 * fresh.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Http;

/**
 * Evaluates HTTP conditional-request freshness.
 *
 * @since 0.1.0
 */
final class Conditional_Request {

	/**
	 * Reports whether the client's cached copy is still fresh.
	 *
	 * @since 0.1.0
	 *
	 * @param string $if_none_match     The If-None-Match request header, or ''.
	 * @param string $if_modified_since The If-Modified-Since request header, or ''.
	 * @param string $etag              The current content ETag (quoted).
	 * @param int    $last_modified     The resource's last-modified Unix time.
	 * @return bool True when the client may be answered with 304.
	 */
	public static function is_fresh( string $if_none_match, string $if_modified_since, string $etag, int $last_modified ): bool {

		// A matching or wildcard If-None-Match is authoritative over the date.
		if ( $if_none_match !== '' ) {
			if ( trim( $if_none_match ) === '*' ) {
				return true;
			}
			$current = self::strip_weak( $etag );
			foreach ( explode( ',', $if_none_match ) as $candidate ) {
				if ( self::strip_weak( trim( $candidate ) ) === $current ) {
					return true;
				}
			}
			return false;
		}

		// Otherwise honour If-Modified-Since when the resource is no newer than it.
		if ( $if_modified_since !== '' ) {
			$since = strtotime( $if_modified_since );
			return $since !== false && $last_modified <= $since;
		}

		return false;

	}

	/**
	 * Strips the weak indicator prefix from an ETag for comparison.
	 *
	 * Per RFC 7232 §2.3, a weak ETag has the form `W/"…"`. Stripping the
	 * leading `W/` before comparing implements the weak-comparison rule from
	 * §2.3: `W/"abc"` and `"abc"` are considered equivalent.
	 *
	 * @since 0.2.3
	 *
	 * @param string $etag A quoted ETag string, optionally prefixed with `W/`.
	 * @return string The ETag with any `W/` prefix removed.
	 */
	private static function strip_weak( string $etag ): string {
		return str_starts_with( $etag, 'W/' ) ? substr( $etag, 2 ) : $etag;
	}

}
