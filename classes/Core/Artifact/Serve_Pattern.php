<?php
/**
 * Value object declaring the URL shape a provider serves.
 *
 * A provider publishes its Serve_Pattern so the early serve router can build a
 * security allowlist: only a shape a registered provider claims is ever served
 * from the cache directory (docs/adr/0007, docs/adr/0008). Release 1 has a
 * single suffix-based shape — paths ending in `.md`.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * The recognisable URL shape of one artifact kind, for the serve allowlist.
 *
 * @since 0.1.0
 */
final readonly class Serve_Pattern {

	/**
	 * Declares one artifact kind's serve shape.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind   The artifact kind this shape produces.
	 * @param string $suffix The case-sensitive path suffix that identifies it,
	 *                        e.g. '.md'. The cache key is the path with this
	 *                        suffix stripped.
	 */
	public function __construct(
		public string $kind,
		public string $suffix,
	) {}

}
