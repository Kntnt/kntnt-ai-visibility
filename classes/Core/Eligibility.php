<?php
/**
 * The Core eligibility predicate and enumeration.
 *
 * Promotes the Release-1 Markdown eligibility to Core, where both the `.md`
 * provider and the llms enumeration depend on it (docs/spec/llms-txt.md §3.1).
 * is_servable() is the universal hard guard — published, front-end-viewable, not
 * an attachment — the security rule that lets the early router serve before
 * WordPress auth. is_eligible() adds membership of the matrix `.md` set. The
 * aggregates read enumerate(), which excludes drafts and password-protected
 * posts so the early-served per-page cache never holds protected content.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Content\Content_Types;

/**
 * Decides what may be served, what is `.md`-eligible, and what to aggregate.
 *
 * @since 0.2.0
 */
final class Eligibility {

	/**
	 * Binds eligibility to the content-type matrix it reads its scope from.
	 *
	 * @since 0.2.0
	 *
	 * @param Content_Types $types The content-type capability matrix.
	 */
	public function __construct( private readonly Content_Types $types ) {}

	/**
	 * The universal hard guard: published, front-end-viewable, not an attachment.
	 *
	 * This is the rule that lets the early router serve a cache file before
	 * WordPress auth runs, so non-public content can never be cached and served.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post The candidate post.
	 * @return bool
	 */
	public function is_servable( \WP_Post $post ): bool {

		// Only published, non-attachment, viewable entries qualify; attachments
		// are listings of media, not singular content.
		if ( $post->post_status !== 'publish' ) {
			return false;
		}

		return $post->post_type !== 'attachment' && is_post_type_viewable( $post->post_type );

	}

	/**
	 * Reports whether a post is `.md`-eligible: servable and in the matrix `.md` set.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post The candidate post.
	 * @return bool
	 */
	public function is_eligible( \WP_Post $post ): bool {
		return $this->is_servable( $post ) && in_array( $post->post_type, $this->md_types(), true );
	}

	/**
	 * Enumerates the published, non-password-protected posts of the given types.
	 *
	 * Runs one query per type so each keeps its own ordering — hierarchical types
	 * by menu_order then title, others by date descending — and returns the posts
	 * grouped in the passed types' order, the read the aggregates share. Password-
	 * protected posts are excluded so the aggregation never caches or concatenates
	 * content the early router would serve before WordPress auth.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $types The post types to enumerate, in output order.
	 * @return list<\WP_Post>
	 */
	public function enumerate( array $types ): array {

		// One query per type, concatenated in the passed order.
		$posts = [];
		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}
			$hierarchical = is_post_type_hierarchical( $type );
			$found = get_posts(
				[
					'post_type'      => $type,
					'post_status'    => 'publish',
					'has_password'   => false,
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'orderby'        => $hierarchical ? 'menu_order title' : 'date',
					'order'          => $hierarchical ? 'ASC' : 'DESC',
				],
			);
			foreach ( is_array( $found ) ? $found : [] as $post ) {
				if ( $post instanceof \WP_Post ) {
					$posts[] = $post;
				}
			}
		}

		return $posts;

	}

	/**
	 * Returns the `.md` post-type set: the matrix column through the filter.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string>
	 */
	private function md_types(): array {

		// The matrix `md` column is the source; the filter is the developer escape
		// hatch mirroring it.
		$types = apply_filters( 'kntnt_ai_visibility_eligible_post_types', $this->types->types_for( 'md' ) );

		return is_array( $types ) ? array_values( array_filter( $types, 'is_string' ) ) : [];

	}

}
