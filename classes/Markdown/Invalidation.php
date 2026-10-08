<?php
/**
 * Invalidates Markdown-alternate caches when content or context changes.
 *
 * Per-entity, delete-on-change: a page's own `.md` is deleted on save and on
 * every status transition, so the early router — which runs before WordPress
 * auth — can never serve a cached file for content that has become non-public
 * (docs/adr/0007, docs/spec §5). Indirect content and language changes flush the
 * whole cache and bump its generation. Core owns exposure settings invalidation.
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
		add_action( 'pre_post_update', [ $this, 'before_update' ] );
		add_action( 'before_delete_post', [ $this, 'before_update' ] );

		// Whole-cache invalidation on indirect changes.
		add_action( 'switch_theme', [ $this, 'flush' ] );
		foreach ( [ 'set_object_terms', 'edited_term', 'delete_term' ] as $hook ) {
			add_action( $hook, [ $this, 'flush' ] );
		}
		foreach ( [
			'page_on_front',
			'show_on_front',
			'permalink_structure',
			'home',
			'WPLANG',
			'blogname',
			'blogdescription',
		] as $option ) {
			add_action( 'update_option_' . $option, [ $this, 'flush' ] );
		}
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ $this, 'on_meta' ], 10, 3 );
		}
		add_action( 'profile_update', [ $this, 'on_profile_update' ], 10, 2 );

		// Integrations signal committed indirect public-body dependency changes.
		add_action( 'kntnt_ai_visibility_indirect_content_changed', [ $this, 'flush' ] );

	}

	/**
	 * Removes the old permalink before WordPress changes the stored post.
	 *
	 * @since 0.5.2
	 *
	 * @param int $post_id The post being changed.
	 * @return void
	 */
	public function before_update( int $post_id ): void {
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post ) {
			// A parent page's address also changes all descendant addresses.
			if ( is_post_type_hierarchical( $post->post_type ) ) {
				$this->flush();
			} else {
				$this->delete( $post );
			}
		}
	}

	/**
	 * Flushes for language, featured-image or declared public-field changes.
	 *
	 * @since 0.5.2
	 *
	 * @param int|array<int> $meta_id The changed metadata ID or deleted IDs.
	 * @param int            $post_id The owning post ID.
	 * @param string         $meta_key The changed metadata key.
	 * @return void
	 */
	public function on_meta( int|array $meta_id, int $post_id, string $meta_key ): void {

		// Preserve Bogo's language/group turnover independently of field declarations.
		if ( in_array( $meta_key, [ '_locale', '_original_post' ], true ) ) {
			$this->flush();
			return;
		}

		// Editor bookkeeping never contributes to the public representation.
		if ( in_array( $meta_key, [ '_edit_lock', '_edit_last' ], true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}

		/**
		 * Declares stored fields consumed by this source's public body integration.
		 *
		 * Declare keys independently of their current values, including absent
		 * fields. A trailing * declares a non-empty key prefix for repeater or
		 * flexible-content children, including deleted keys. This controls
		 * invalidation only; it never exposes metadata.
		 * Featured images and Bogo language keys are covered without declaration.
		 *
		 * @since 0.5.2
		 *
		 * @param list<string> $keys The additional rendered metadata dependencies.
		 * @param \WP_Post     $post The source whose public representation uses them.
		 */
		$keys = apply_filters( 'kntnt_ai_visibility_public_content_meta_keys', [], $post );
		if ( $meta_key === '_thumbnail_id' ) {
			$this->flush();
			return;
		}
		if ( ! is_array( $keys ) ) {
			return;
		}
		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}
			$prefix = str_ends_with( $key, '*' ) ? substr( $key, 0, -1 ) : '';
			if ( $meta_key === $key || ( $prefix !== '' && str_starts_with( $meta_key, $prefix ) ) ) {
				$this->flush();
				return;
			}
		}

	}

	/**
	 * Invalidates author front-matter after the stored display name changes.
	 *
	 * Other profile fields are not built-in rendering dependencies. Whole-cache
	 * turnover avoids eagerly enumerating every source by the changed author.
	 *
	 * @since 0.5.2
	 *
	 * @param int      $user_id The updated WordPress user.
	 * @param \WP_User $old_user_data The stored user before the successful update.
	 * @return void
	 */
	public function on_profile_update( int $user_id, \WP_User $old_user_data ): void {
		$current = get_userdata( $user_id );
		if ( $current !== false && $current->display_name !== $old_user_data->display_name ) {
			$this->flush();
		}
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
