<?php
/**
 * Isolates the WordPress rendering context of an individual artifact source.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Runs a render with the source post and restores the caller even on failure.
 *
 * @since 0.5.2
 */
final class Post_Context {

	/**
	 * Supplies the globals used by shortcodes and dynamic blocks, and Bogo locale.
	 *
	 * @since 0.5.2
	 *
	 * @param \WP_Post           $post The source post.
	 * @param callable(): string $render The rendering operation.
	 * @return string The rendered artifact.
	 */
	public static function render( \WP_Post $post, callable $render ): string {
		$names = [ 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' ];
		$saved = [];
		foreach ( $names as $name ) {
			if ( array_key_exists( $name, $GLOBALS ) ) {
				$saved[ $name ] = $GLOBALS[ $name ];
			}
		}
		$switched = false;
		try {
			$locale = function_exists( 'bogo_get_post_locale' ) ? bogo_get_post_locale( $post->ID ) : null;
			if ( is_string( $locale ) ) {
				$switched = switch_to_locale( $locale );
			}
			// setup_postdata() needs this assignment; finally restores the caller.
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required source-post rendering context.
			$GLOBALS['post'] = $post;
			setup_postdata( $post );
			return $render();
		} finally {
			foreach ( $names as $name ) {
				if ( array_key_exists( $name, $saved ) ) {
					$GLOBALS[ $name ] = $saved[ $name ];
				} else {
					unset( $GLOBALS[ $name ] );
				}
			}
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

}
