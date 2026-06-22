<?php
/**
 * Invalidates Markdown-alternate caches when content or context changes.
 *
 * Per-entity, delete-on-change: a page's own `.md` is deleted on save and on
 * every status transition, so the early router — which runs before WordPress
 * auth — can never serve a cached file for content that has become non-public
 * (docs/adr/0007, docs/spec §5). Indirect changes (theme switch, plugin
 * settings) flush the whole file cache and bump the cache-version stamp.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Markdown;

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\Store;

/**
 * Registers and runs the Markdown cache invalidation hooks.
 *
 * @since 0.1.0
 */
final class Invalidation {

	/**
	 * Binds invalidation to the provider, cache store and version stamp.
	 *
	 * @since 0.1.0
	 *
	 * @param Page_Markdown_Provider $provider The Markdown-alternate provider.
	 * @param Store                  $cache    The artifact cache store.
	 * @param Cache_Version          $version  The cache-version stamp.
	 */
	public function __construct(
		private readonly Page_Markdown_Provider $provider,
		private readonly Store $cache,
		private readonly Cache_Version $version,
	) {}

	/**
	 * Registers the invalidation hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {

		// Per-entity invalidation on content changes and status transitions.
		add_action( 'save_post', [ $this, 'on_save' ], 10, 2 );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );

		// Whole-cache invalidation on indirect changes.
		add_action( 'switch_theme', [ $this, 'flush' ] );
		add_action( 'update_option_kntnt_ai_visibility', [ $this, 'flush' ] );

	}

	/**
	 * Deletes a saved post's cached alternate.
	 *
	 * @since 0.1.0
	 *
	 * @param int      $post_id The saved post id.
	 * @param \WP_Post $post    The saved post.
	 * @return void
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {
		$this->delete( $post );
	}

	/**
	 * Deletes a post's cached alternate on any status transition.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $new_status The new status.
	 * @param string   $old_status The old status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		$this->delete( $post );
	}

	/**
	 * Flushes the whole cache and bumps the version on an indirect change.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->version->bump();
		$this->cache->flush_all();
	}

	/**
	 * Deletes one post's cached alternate, skipping revisions and autosaves.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post.
	 * @return void
	 */
	private function delete( \WP_Post $post ): void {

		// Revisions and autosaves are not servable entries; ignore them.
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		$this->cache->delete( $this->provider->identity_for_post( $post ) );

	}

}
