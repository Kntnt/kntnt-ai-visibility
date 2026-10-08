<?php
/**
 * Distinguishes singular source pages from the configured blog listing.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Resolves source roles independently of the current routing query.
 *
 * Dedicated alternate requests need not have a singular WordPress main query.
 * Bogo filters the configured posts-page ID by the active locale, so resolve it
 * in the supplied source's language and restore the exact caller locale.
 *
 * @since 0.5.2
 */
final class Source_Role {

	/**
	 * Reports whether the source represents singular content rather than a list.
	 *
	 * A saved posts page becomes an ordinary singular page when show_on_front
	 * is posts. Explicit raw for_post rendering deliberately does not use this
	 * publication-role restriction.
	 *
	 * @since 0.5.2
	 *
	 * @param \WP_Post $post The explicitly supplied source.
	 * @return bool Whether its canonical role supports an alternate artifact.
	 */
	public static function is_singular( \WP_Post $post ): bool {

		// Only static-home configurations assign a page to the blog listing.
		if ( $post->post_type !== 'page' || get_option( 'show_on_front' ) !== 'page' ) {
			return true;
		}

		// Resolve translated option IDs in the source language, never the visitor's.
		$locale = function_exists( 'bogo_get_post_locale' ) ? bogo_get_post_locale( $post->ID ) : null;
		$switched = is_string( $locale ) && switch_to_locale( $locale );
		try {
			$posts_id = get_option( 'page_for_posts' );
			return ! is_numeric( $posts_id ) || $post->ID !== (int) $posts_id;
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}

	}

}
