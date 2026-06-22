<?php
/**
 * Value object pairing a resolved cache path with the pattern that matched it.
 *
 * The serve router's resolve() returns this so serve() can pick the Content-Type
 * and decide whether a `rel="canonical"` back-link applies without re-matching
 * the request against the allowlist (docs/spec/llms-txt.md §3.4). The path is the
 * contained realpath — guaranteed by resolve() to be an existing file strictly
 * inside the cache base.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;

/**
 * A contained cache path and the serve pattern that matched the request.
 *
 * @since 0.2.0
 */
final readonly class Resolved {

	/**
	 * Pairs the contained cache path with its matched serve pattern.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $path    The contained realpath of the cache file.
	 * @param Serve_Pattern $pattern The serve pattern that matched the request.
	 */
	public function __construct(
		public string $path,
		public Serve_Pattern $pattern,
	) {}

}
