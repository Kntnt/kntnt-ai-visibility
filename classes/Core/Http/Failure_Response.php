<?php
/**
 * Emits the shared plain-text public-artifact failure contract.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Http;

use Kntnt\Ai_Visibility\Core\Artifact\Request;

/**
 * Refuses failed artifacts without representation validators or visitor details.
 *
 * Transport uses PHP primitives only, so the early router remains independent
 * of WordPress query/auth state. The fixed refusal text respects the translation
 * lifecycle; diagnostics and exception messages belong to the plugin logger.
 *
 * @since 0.5.2
 */
final class Failure_Response {

	/**
	 * Translates the fixed refusal without triggering early catalogue loading.
	 *
	 * WordPress's normal translation path may load a catalogue just in time.
	 * Before the theme lifecycle, use only an already loaded catalogue or the
	 * standard global/domain gettext filters. This preserves translations without
	 * producing a too-early loading notice before the refusal headers are sent.
	 *
	 * @since 0.5.2
	 *
	 * @param bool $early Whether the early cache router owns this refusal.
	 * @return string Fixed public text, translated when available.
	 */
	public static function refusal_message( bool $early = false ): string {

		if ( ! $early || is_textdomain_loaded( 'kntnt-ai-visibility' ) ) {
			return __( 'This content cannot produce a public artifact.', 'kntnt-ai-visibility' );
		}

		// Match translate()'s filter order without asking it to load a catalogue.
		$source = 'This content cannot produce a public artifact.';
		$translated = apply_filters( 'gettext', $source, $source, 'kntnt-ai-visibility' );
		// phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- WordPress's native gettext hook includes the literal text domain.
		$translated = apply_filters( 'gettext_kntnt-ai-visibility', $translated, $source, 'kntnt-ai-visibility' );
		return is_string( $translated ) ? $translated : $source;

	}

	/**
	 * Emits a controlled failure; conditional requests never become 304.
	 *
	 * @since 0.5.2
	 *
	 * @param Request $request The request whose method controls body emission.
	 * @param int     $status The deliberate refusal or generation-failure status.
	 * @param string  $message A fixed translated plain-text visitor message.
	 * @return never
	 */
	public static function send( Request $request, int $status, string $message ): never {

		// Remove any inherited representation metadata before refusing publication.
		foreach ( [ 'ETag', 'Last-Modified', 'Content-Length', 'Link' ] as $name ) {
			header_remove( $name );
		}
		http_response_code( $status );
		header( 'Cache-Control: no-store' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );

		// HEAD carries the same failure policy without transmitting message bytes.
		if ( $request->method !== 'HEAD' ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed translated plain text; HTML escaping changes these response bytes.
			echo $message;
		}

		exit;

	}

}
