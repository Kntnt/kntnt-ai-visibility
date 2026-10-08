<?php
/**
 * Stable installation URLs, independent of the current content language.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Keeps artifact storage and site-wide links relative to the configured home.
 *
 * @since 0.5.2
 */
final class Site_Url {

	/**
	 * Builds an installation URL without home_url's request-language filters.
	 *
	 * @since 0.5.2
	 * @param string $path Installation-relative path, including any language prefix.
	 * @return string
	 */
	public static function home( string $path = '' ): string {
		$home = get_option( 'home' );
		$base = rtrim( is_string( $home ) ? $home : '', '/' );

		return $path === '' ? $base : $base . '/' . ltrim( $path, '/' );

	}

}
