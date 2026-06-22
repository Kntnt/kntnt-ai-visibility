<?php
/**
 * Value object naming one concrete discoverable artifact.
 *
 * An Identity is the stable, path-safe handle Core uses to locate an artifact
 * in the cache. The `key` is validated and path-safe by the time it reaches an
 * Identity — Core derives the cache filename from it directly and never from
 * the raw request URL (see docs/adr/0007, docs/adr/0008).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * One concrete artifact instance: its kind, cache key and source entity.
 *
 * @since 0.1.0
 */
final readonly class Identity {

	/**
	 * Builds an identity for one artifact instance.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind      The artifact kind, e.g. 'markdown-alternate'.
	 * @param string $key       The stable, validated, path-safe cache key.
	 * @param int    $source_id The source entity id (e.g. a post ID); 0 for
	 *                          singleton artifacts that have no source entity.
	 */
	public function __construct(
		public string $kind,
		public string $key,
		public int $source_id = 0,
	) {}

}
