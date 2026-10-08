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
	 * Supplies one source's singular main query, active Loop and Bogo locale.
	 *
	 * The query uses the supplied post rather than running a second database
	 * query. It is isolated from the caller, including when the caller has no
	 * singular source or uses distinct main/secondary queries. Invoke within
	 * Public_Rendering so source integrations retain the anonymous audience.
	 *
	 * @since 0.5.2
	 *
	 * @param \WP_Post           $post The source post.
	 * @param callable(): string $render The rendering operation.
	 * @return string The rendered artifact.
	 */
	public static function render( \WP_Post $post, callable $render ): string {

		// Preserve presence, values and query identities for exact restoration.
		$names = [ 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages', 'wp_query', 'wp_the_query' ];
		$saved = [];
		foreach ( $names as $name ) {
			if ( array_key_exists( $name, $GLOBALS ) ) {
				$saved[ $name ] = $GLOBALS[ $name ];
			}
		}
		$switched = false;

		// Source language and query state belong to this rendering scope only.
		try {
			$locale = function_exists( 'bogo_get_post_locale' ) ? bogo_get_post_locale( $post->ID ) : null;
			if ( is_string( $locale ) ) {
				$switched = switch_to_locale( $locale );
			}

			// WordPress conditionals and setup_postdata must use the same source.
			$query = new \WP_Query();
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated source main query, restored in finally.
			$GLOBALS['wp_query'] = $query;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Main-query identity is part of the content contract.
			$GLOBALS['wp_the_query'] = $query;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required source-post rendering context.
			$GLOBALS['post'] = $post;
			$query->parse_query(
				[
					$post->post_type === 'page' ? 'page_id' : 'p' => $post->ID,
					'post_type' => $post->post_type,
					'fields' => 'all',
				],
			);

			// A supplied posts-page source is content, not its configured listing.
			$query->is_page = $post->post_type === 'page';
			$query->is_single = ! $query->is_page;
			$query->is_singular = true;
			$query->is_home = false;
			$query->is_posts_page = false;
			$query->posts = [ $post ];
			$query->post_count = 1;
			$query->found_posts = 1;
			$query->max_num_pages = 1;
			$query->queried_object = $post;
			$query->queried_object_id = $post->ID;
			$query->the_post();

			return $render();
		} finally {

			// Restore exact globals rather than rewinding or re-querying the caller.
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
