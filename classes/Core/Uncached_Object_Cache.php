<?php
/**
 * Cache bypass used only while checking authoritative publication state.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Misses reads and discards writes without touching a persistent cache backend.
 *
 * @since 0.5.2
 */
final class Uncached_Object_Cache {

	/**
	 * Forces WordPress to read the authoritative data source.
	 *
	 * @since 0.5.2
	 *
	 * @param int|string $key   Cache key.
	 * @param string     $group Cache group.
	 * @param bool       $force Whether to force a backend read.
	 * @param bool|null  $found Receives the cache-miss flag.
	 * @param-out false $found
	 * @return false Always a miss.
	 */
	public function get( int|string $key, string $group = '', bool $force = false, ?bool &$found = null ): false {
		$found = false;
		return false;
	}

	/**
	 * Misses each key in WordPress's bulk read API.
	 *
	 * @since 0.5.2
	 *
	 * @param array<int|string> $keys  Cache keys.
	 * @param string            $group Cache group.
	 * @param bool              $force Whether to force a backend read.
	 * @return array<int|string, false> A miss for every requested key.
	 */
	public function get_multiple( array $keys, string $group = '', bool $force = false ): array {
		return array_fill_keys( $keys, false );
	}

	/**
	 * Discards cache mutations and optional backend operations in this scope.
	 *
	 * A fresh-read scope must neither seed stale persistent entries nor evict
	 * another request's entries. WordPress treats cache writes as optional.
	 *
	 * @since 0.5.2
	 *
	 * @param string       $method    The cache operation.
	 * @param array<mixed> $arguments Its arguments.
	 * @return false No backend operation is supported in this scope.
	 */
	public function __call( string $method, array $arguments ): false {
		return false;
	}

}
