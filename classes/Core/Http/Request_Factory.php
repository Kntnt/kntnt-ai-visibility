<?php
/**
 * Builds a Request value object from the current superglobals.
 *
 * The single place the plugin reads PHP superglobals, shared by the early serve
 * router and the Markdown request handler. The request path is read with
 * esc_url_raw( wp_unslash() ), never sanitize_text_field(), so percent-encoded
 * non-ASCII slugs survive intact for url_to_postid() (docs/spec §4.2).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Http;

use Kntnt\Ai_Visibility\Core\Artifact\Request;

/**
 * Constructs a Request from $_SERVER and $_GET.
 *
 * @since 0.1.0
 */
final class Request_Factory {

	/**
	 * Builds the request snapshot from the current superglobals.
	 *
	 * @since 0.1.0
	 *
	 * @return Request
	 */
	public static function from_globals(): Request {

		// Method and the percent-encoding-preserving request path.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$raw_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $raw_uri, PHP_URL_PATH );

		// Negotiation inputs and conditional-request headers. This is a read-only
		// public endpoint, so no nonce applies.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only content negotiation on a public URL.
		$format = isset( $_GET['format'] ) && is_string( $_GET['format'] ) ? sanitize_key( wp_unslash( $_GET['format'] ) ) : '';
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) && is_string( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) && is_string( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) && is_string( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) : '';

		return new Request(
			$method,
			$path,
			$format !== '' ? [ 'format' => $format ] : [],
			$accept,
			$if_none_match,
			$if_modified_since,
		);

	}

}
