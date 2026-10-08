<?php
/**
 * Checks queued sources, eligibility and complete identities before publication.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Obsolete_Artifact;

/**
 * Reads publication state independently of request and persistent WP caches.
 *
 * @since 0.5.2
 */
final class Publication_Source {

	/**
	 * Binds current policy and the authoritative shared identity scheme.
	 *
	 * @since 0.5.2
	 *
	 * @param callable(): Eligibility $eligibility Creates a fresh policy gate.
	 * @param Markdown_Alternate      $locator     Owns complete source identities.
	 */
	public function __construct( callable $eligibility, private readonly Markdown_Alternate $locator ) {
		$this->eligibility = \Closure::fromCallable( $eligibility );
	}

	/**
	 * Creates current policy independently of request-local saved options.
	 *
	 * @since 0.5.2
	 *
	 * @var \Closure(): Eligibility
	 */
	private readonly \Closure $eligibility;

	/**
	 * Refuses a stale source, revoked eligibility or changed address.
	 *
	 * @since 0.5.2
	 *
	 * @param Identity $identity The requested publication identity.
	 * @param \WP_Post $source   The queued source object used by the renderer.
	 * @param bool     $supported_role Whether canonical alternate publication is required.
	 * @return void
	 * @throws Obsolete_Artifact When the queued work no longer represents public state.
	 */
	public function verify( Identity $identity, \WP_Post $source, bool $supported_role = true ): void {

		$queued_canonical = $this->locator->canonical_url_for( $source );

		// Bypass rather than flush or restore stale entries into shared caches.
		global $wp_object_cache, $wp_rewrite;
		$original = $wp_object_cache;
		$original_rewrite = $wp_rewrite;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Scoped bypass; exact object restored in finally.
		$wp_object_cache = new Uncached_Object_Cache();
		try {
			if ( $wp_rewrite instanceof \WP_Rewrite ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Retain registered structures in the fresh scope.
				$wp_rewrite = clone $wp_rewrite;
				$wp_rewrite->init();
			}
			$fresh = get_post( $source->ID );
			if ( ! $fresh instanceof \WP_Post || $fresh->post_password !== '' ) {
				throw new Obsolete_Artifact( 'The public source was withdrawn.' );
			}

			// Compare stored fields, including content, type, date, status and slug.
			foreach ( $fresh->to_array() as $field => $value ) {
				if ( $field !== 'filter' && $value !== $source->$field ) {
					throw new Obsolete_Artifact( 'The queued public source has changed.' );
				}
			}
			// Raw explicit-source rendering keeps its fresh public-source checks.
			$eligibility = ( $this->eligibility )();
			$eligible = $supported_role ? $eligibility->is_eligible( $fresh ) : $eligibility->is_source_eligible( $fresh );
			if ( ! $eligible ) {
				throw new Obsolete_Artifact( 'The public source is no longer eligible.' );
			}
			$current = $this->locator->identity_for( $fresh );
			if ( $current->kind !== $identity->kind
				|| $current->key !== $identity->key
				|| $current->source_id !== $identity->source_id ) {
				throw new Obsolete_Artifact( 'The public source address has changed.' );
			}
			if ( $queued_canonical !== $this->locator->canonical_url_for( $fresh ) ) {
				throw new Obsolete_Artifact( 'The public canonical address has changed.' );
			}
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore exact caller-owned cache object.
			$wp_object_cache = $original;
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore exact caller-owned rewrite context.
			$wp_rewrite = $original_rewrite;
		}

	}

}
