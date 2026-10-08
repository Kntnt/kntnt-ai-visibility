<?php
/**
 * The Core eligibility predicate and enumeration.
 *
 * Promotes the Release-1 Markdown eligibility to Core, where both the `.md`
 * provider and the llms enumeration depend on it (docs/spec/llms-txt.md §3.1).
 * is_servable() is the universal hard guard — published, front-end-viewable, not
 * an attachment — the security rule that lets the early router serve before
 * WordPress auth. is_eligible() adds membership of the matrix `.md` set and that
 * the post is not curated out by a path-exclusion pattern. The aggregates read
 * enumerate(), which excludes drafts, password-protected posts and the same
 * path-excluded entries, so the early-served per-page cache never holds
 * protected content and no artifact carries a path the owner excluded.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Content\Content_Types;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;

/**
 * Decides what may be served, what is `.md`-eligible, and what to aggregate.
 *
 * @since 0.2.0
 */
final class Eligibility {

	/**
	 * Binds eligibility to the type matrix and the path-exclusion gate.
	 *
	 * @since 0.2.0
	 *
	 * @param Content_Types $types      The content-type capability matrix.
	 * @param Exclusions    $exclusions The path-exclusion gate.
	 */
	public function __construct(
		private readonly Content_Types $types,
		private readonly Exclusions $exclusions,
	) {}

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
	 * Reports whether a post is `.md`-eligible: servable, in the effective `.md`
	 * set, and not curated out by a path-exclusion pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post The candidate post.
	 * @return bool
	 */
	public function is_eligible( \WP_Post $post ): bool {
		return $this->is_servable( $post )
			&& in_array( $post->post_type, $this->md_types(), true )
			&& ! $this->exclusions->is_excluded( $post );
	}

	/**
	 * Enumerates the published, non-password-protected posts of the given types.
	 *
	 * Runs one query per type so each keeps its own ordering — hierarchical types
	 * by menu_order then title, others by date descending — and returns the posts
	 * grouped in the passed types' order, the read the aggregates share. Password-
	 * protected posts are excluded so the aggregation never caches or concatenates
	 * content the early router would serve before WordPress auth, and posts whose
	 * path matches an exclusion pattern are dropped so the aggregates honour the
	 * same per-URL curation as the per-page `.md`. Requested types are restricted
	 * to the effective Markdown type set before querying.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $types The post types to enumerate, in output order.
	 * @return list<\WP_Post>
	 */
	public function enumerate( array $types ): array {

		// Aggregate callers cannot widen the effective Markdown policy.
		$types = array_intersect( $types, $this->md_types() );

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
				if ( $post instanceof \WP_Post && $this->is_eligible( $post ) ) {
					$posts[] = $post;
				}
			}
		}

		return $posts;

	}

	/**
	 * Returns the effective `.md` post-type set shared by all artifact consumers.
	 *
	 * The developer filter may add viewable types as well as remove matrix rows.
	 * Its output must still obey the universal non-attachment/viewability guard.
	 * Code-driven policy changes require a deployment cache purge; see the llms
	 * specification's exposure-policy deployment procedure.
	 *
	 * @since 0.2.0
	 *
	 * @return list<string>
	 */
	public function md_types(): array {

		// The matrix `md` column is the source; the filter is the developer escape
		// hatch mirroring it.
		$types = apply_filters( 'kntnt_ai_visibility_eligible_post_types', $this->types->types_for( 'md' ) );

		// Filters are an integration boundary; reject malformed selections and
		// prevent injected types bypassing the universal public-artifact guard.
		if ( ! is_array( $types ) ) {
			return [];
		}
		$types = array_filter(
			$types,
			static fn( mixed $type ): bool => is_string( $type ) && $type !== 'attachment' && is_post_type_viewable( $type ),
		);

		return array_values( array_unique( $types ) );

	}

}
