<?php
/**
 * An immutable cached representation detached from its removable pathname.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

/**
 * Supplies the same bytes and metadata to response headers and body.
 *
 * @since 0.5.2
 */
final readonly class Snapshot {

	/**
	 * Captures one opened cache file before optional generation cleanup.
	 *
	 * @since 0.5.2
	 *
	 * @param string $bytes         The complete cached representation.
	 * @param int    $last_modified The opened file's modification time.
	 * @param string $canonical_url Its validated stored canonical URL, if requested.
	 */
	public function __construct(
		public string $bytes,
		public int $last_modified,
		public string $canonical_url = '',
	) {}

}
