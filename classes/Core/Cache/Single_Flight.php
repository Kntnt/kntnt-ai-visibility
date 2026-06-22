<?php
/**
 * The single-flight cache materialiser.
 *
 * Wraps a cache read/produce/write in a per-identity advisory lock so concurrent
 * misses do not all generate the same artifact (docs/spec/llms-txt.md §3.3). It
 * is the stampede guard the per-page Markdown service and the O(site) llms
 * aggregates share — the aggregates are exactly where single-flight matters most.
 * The lock lives outside the cache tree (the system temp dir by default) so
 * locking never depends on the cache directory already existing.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;

/**
 * Holds a per-identity lock around cache produce-and-write.
 *
 * @since 0.2.0
 */
final class Single_Flight {

	/**
	 * The directory the advisory lock files live in.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private string $lock_dir;

	/**
	 * Binds the materialiser to its cache store and lock directory.
	 *
	 * @since 0.2.0
	 *
	 * @param Store       $store    The cache store read and written through.
	 * @param string|null $lock_dir The lock directory; defaults to the system temp dir.
	 */
	public function __construct(
		private readonly Store $store,
		?string $lock_dir = null,
	) {
		$this->lock_dir = $lock_dir ?? sys_get_temp_dir();
	}

	/**
	 * Returns the cached bytes, producing and caching them once on a miss.
	 *
	 * Returns the cached bytes when present; otherwise holds a per-identity
	 * advisory lock, re-checks the cache (a concurrent flight may have filled it),
	 * runs $produce, writes and returns — so concurrent misses do not all generate.
	 *
	 * @since 0.2.0
	 *
	 * @param Identity           $identity The cache identity to materialise under.
	 * @param callable(): string $produce The producer run on a confirmed miss.
	 * @return string
	 */
	public function once( Identity $identity, callable $produce ): string {

		// Serve an existing cache file without producing.
		$cached = $this->store->read( $identity );
		if ( $cached !== null ) {
			return $cached;
		}

		// Single-flight: hold the lock, re-check, then produce and store.
		$lock = $this->acquire_lock( $identity );
		try {
			$cached = $this->store->read( $identity );
			if ( $cached !== null ) {
				return $cached;
			}
			$bytes = $produce();
			$this->store->write( $identity, $bytes );
			return $bytes;
		} finally {
			$this->release_lock( $lock );
		}

	}

	/**
	 * Acquires a per-identity advisory lock for single-flight generation.
	 *
	 * @since 0.2.0
	 *
	 * @param Identity $identity The identity being generated.
	 * @return resource|null The locked handle, or null when locking is unavailable.
	 */
	private function acquire_lock( Identity $identity ) {

		// Lock outside the cache tree so locking never depends on the cache
		// directory already existing.
		$path = $this->lock_dir . '/kntnt-aiv-' . md5( $identity->kind . '/' . $identity->key ) . '.lock';
		$handle = fopen( $path, 'c' );
		if ( $handle === false ) {
			return null;
		}
		flock( $handle, LOCK_EX );

		return $handle;

	}

	/**
	 * Releases a lock acquired by acquire_lock().
	 *
	 * @since 0.2.0
	 *
	 * @param resource|null $handle The locked handle, or null.
	 * @return void
	 */
	private function release_lock( $handle ): void {

		// Nothing to release when locking was unavailable.
		if ( $handle === null ) {
			return;
		}
		flock( $handle, LOCK_UN );
		fclose( $handle );

	}

}
