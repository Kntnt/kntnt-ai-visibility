<?php
/**
 * Invalidates the llms aggregates when content changes.
 *
 * The aggregates are O(site), so they are invalidated by bumping the
 * cache-version stamp rather than deleted on change (docs/spec/llms-txt.md §5,
 * docs/adr/0007): a bump changes the version suffix, so the next request for
 * either aggregate resolves to a key with no file and rebuilds lazily, with the
 * TTL safety net bounding any staleness a hook misses. The version bumps when a
 * servable post is saved, and when a post is published, changed or — crucially —
 * unpublished or trashed, so a page that leaves public view is dropped from the
 * aggregates promptly rather than lingering until the TTL. Revisions and
 * autosaves are ignored (as in Release 1). The per-page `.md` keys are not
 * version-stamped, so
 * a bump never invalidates them — only the aggregates rebuild. Indirect changes
 * (theme switch, settings change) are covered by the Markdown invalidation, which
 * bumps the version and flushes the whole cache directory.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Llms;

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Eligibility;

/**
 * Bumps the cache-version stamp when a servable post changes.
 *
 * @since 0.2.0
 */
final class Invalidation {

	/**
	 * Binds invalidation to the eligibility predicate and the version stamp.
	 *
	 * @since 0.2.0
	 *
	 * @param Eligibility   $eligibility The eligibility predicate.
	 * @param Cache_Version $version     The cache-version stamp.
	 */
	public function __construct(
		private readonly Eligibility $eligibility,
		private readonly Cache_Version $version,
	) {}

	/**
	 * Registers the invalidation hooks.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'save_post', [ $this, 'on_save' ], 10, 2 );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
	}

	/**
	 * Bumps the version when a saved post is servable.
	 *
	 * @since 0.2.0
	 *
	 * @param int      $post_id The saved post id.
	 * @param \WP_Post $post    The saved post.
	 * @return void
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {

		// A save matters only when the post is servable now; an unpublish that
		// also fires save_post is caught by the transition below.
		$this->bump_for( $post, false );

	}

	/**
	 * Bumps the version when a transitioned post is, or just was, servable.
	 *
	 * @since 0.2.0
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {

		// A transition matters when the post is servable now (e.g. a fresh publish)
		// or when it is LEAVING the published state (an unpublish or trash): it is
		// no longer servable, but the aggregates must drop it, so the version bumps.
		$this->bump_for( $post, $old_status === 'publish' );

	}

	/**
	 * Bumps the cache version when relevant, skipping revisions and autosaves.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post          The post.
	 * @param bool     $was_published Whether the post just left the published state.
	 * @return void
	 */
	private function bump_for( \WP_Post $post, bool $was_published ): void {

		// Revisions and autosaves are not servable entries; ignore them.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		// Bump when the post is servable now or just left the published state —
		// either way an aggregate may have changed, so trigger a lazy rebuild.
		if ( $was_published || $this->eligibility->is_servable( $post ) ) {
			$this->version->bump();
		}

	}

}
