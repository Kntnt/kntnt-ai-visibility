<?php
/**
 * The file-backed artifact cache store.
 *
 * One file per artifact under an isolated directory in uploads that only Core
 * writes (docs/adr/0007). The base directory is resolved lazily — the provider
 * is only invoked when a cache operation actually needs the path, so an
 * ordinary HTML request never pays for resolving the uploads directory.
 *
 * Paths are derived from an Identity's validated key; this store assumes the key
 * is already safe (the serve router validates untrusted input before any path is
 * built). A realpath containment check in the router is the backstop.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;

/**
 * Stores generated artifacts as files under a Core-owned cache directory.
 *
 * @since 0.1.0
 */
final class File_Store implements Store {

	/**
	 * Bounds generation probes while normal requests drain an obsolete backlog.
	 *
	 * @since 0.5.2
	 *
	 * @var int
	 */
	private const PRUNE_BATCH_SIZE = 32;

	/**
	 * Lazily-resolved, cached absolute base directory (no trailing slash).
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $base = null;

	/**
	 * The lazily resolved publication coordinator.
	 *
	 * @since 0.5.2
	 *
	 * @var Publication_Barrier|null
	 */
	private ?Publication_Barrier $publication = null;

	/**
	 * Avoids repeating the same controlled cache refusal in one store instance.
	 *
	 * @since 0.5.2
	 *
	 * @var bool
	 */
	private bool $refusal_logged = false;

	/**
	 * Binds the store to a lazy base-directory provider.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): string $base_dir_provider Returns the absolute cache
	 *                                              base directory. Invoked once,
	 *                                              on first use.
	 * @param Logger             $logger            Receives controlled write failures.
	 * @param string|null        $barrier_directory Isolates coordination resources; defaults to system temp.
	 */
	public function __construct(
		private $base_dir_provider,
		private readonly Logger $logger = new Plugin_Logger(),
		private readonly ?string $barrier_directory = null,
	) {}

	/**
	 * Resolves one coordinator shared by every generator and invalidation writer.
	 *
	 * @since 0.5.2
	 *
	 * @return Publication_Barrier The stable store coordinator.
	 */
	public function publication(): Publication_Barrier {
		return $this->publication ??= new Publication_Barrier( $this->base(), $this->barrier_directory );
	}

	/**
	 * Returns the resolved cache base directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function base_dir(): string {
		return $this->base();
	}

	/**
	 * Derives the cache path base/kind/key.md for an identity.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @return string
	 */
	public function path_for( Identity $identity ): string {
		return $this->base() . '/' . $identity->kind . '/' . $identity->key . '.md';
	}

	/**
	 * Reports whether a cache file exists for an identity.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @return bool
	 */
	public function has( Identity $identity ): bool {
		return $this->readable() && is_file( $this->path_for( $identity ) );
	}

	/**
	 * Reads the cached bytes for an identity, or null when absent.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @return string|null
	 */
	public function read( Identity $identity ): ?string {
		if ( ! $this->readable() ) {
			return null;
		}

		// Return the bytes only when the file exists and is readable.
		$path = $this->path_for( $identity );
		if ( ! is_file( $path ) ) {
			return null;
		}
		$bytes = file_get_contents( $path );

		return $bytes === false ? null : $bytes;

	}

	/**
	 * Writes bytes to the cache for an identity, creating directories as needed.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @param string   $bytes    The bytes to store.
	 * @return bool True after atomic publication; false on a logged failure.
	 */
	public function write( Identity $identity, string $bytes ): bool {
		if ( ! $this->readable() ) {
			return false;
		}

		// Make sure the cache directory exists and is protected from listing.
		if ( ! $this->ensure_base() ) {
			return false;
		}

		// Create the file's parent directory (slash-bearing keys nest), then
		// write atomically via a temporary file and rename so a concurrent
		// reader never sees a half-written file.
		$path = $this->path_for( $identity );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- expected filesystem failures are logged through the plugin logger.
		if ( ! @wp_mkdir_p( dirname( $path ) ) ) {
			return $this->write_failed( 'mkdir', dirname( $path ) );
		}
		$tmp = $path . '.' . uniqid( '', true ) . '.tmp';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- expected filesystem failures are logged through the plugin logger.
		$written = @file_put_contents( $tmp, $bytes, LOCK_EX );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- expected filesystem failures are logged through the plugin logger.
		$published = $written === strlen( $bytes ) && @rename( $tmp, $path );
		if ( ! $published ) {

			// A partial write or failed rename must not abandon its temporary file.
			if ( is_file( $tmp ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- cleanup failure is logged separately.
				if ( ! @unlink( $tmp ) ) {
					$this->write_failed( 'cleanup', $tmp );
				}
			}

			return $this->write_failed( $written === strlen( $bytes ) ? 'rename' : 'write', $path );

		}

		return true;

	}

	/**
	 * Deletes the cache file for an identity, if present.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @return void
	 */
	public function delete( Identity $identity ): void {
		$this->publication()->revoke(
			function () use ( $identity ): void {
				$barrier = $this->publication();
				$readable = $barrier->readable();
				$barrier->poison();

				// Remove the file when present; an absent file is a no-op.
				$path = $this->path_for( $identity );
				if ( is_file( $path ) ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failed erasure retains durable poison and is logged.
					if ( ! @unlink( $path ) ) {
						$this->erasure_failed( $path );
						return;
					}
				}
				if ( $readable ) {
					$barrier->recover();
				}
			}
		);

	}

	/**
	 * Removes the entire cache directory.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush_all(): void {
		$this->publication()->revoke(
			function (): void {
				$barrier = $this->publication();
				$barrier->poison();

				// Nothing to do when the cache directory was never created.
				$base = $this->base();
				if ( ! is_dir( $base ) ) {
					$barrier->recover();
					return;
				}

				// Walk the tree depth-first, removing files before their directories.
				try {
					$entries = new \RecursiveIteratorIterator(
						new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
						\RecursiveIteratorIterator::CHILD_FIRST,
					);
					// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var to type the iterator value.
					/** @var \SplFileInfo $entry */
					foreach ( $entries as $entry ) {
						// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failed erasure retains durable poison and is logged.
						$removed = $entry->isDir() ? @rmdir( $entry->getPathname() ) : @unlink( $entry->getPathname() );
						if ( ! $removed ) {
							$this->erasure_failed( $entry->getPathname() );
							return;
						}
					}
				} catch ( \UnexpectedValueException ) {
					$this->erasure_failed( $base );
					return;
				}
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failed erasure retains durable poison and is logged.
				if ( ! @rmdir( $base ) ) {
					$this->erasure_failed( $base );
					return;
				}
				$barrier->recover();
			}
		);

	}

	/**
	 * Lazily removes a bounded batch of known older aggregate generations.
	 *
	 * Calls $current under the store's short publication barrier. An obsolete
	 * caller, absent current file or poisoned store never authorises cleanup.
	 * Only owned llms-txt/llms-full version keys older than that authoritative
	 * identity can be removed; current/newer files and other kinds are retained.
	 *
	 * @since 0.2.0
	 *
	 * @param Identity              $identity The successfully persisted caller identity.
	 * @param callable(): ?Identity $current  Reads the fresh authoritative identity; never renders.
	 * @return void
	 */
	public function prune_siblings( Identity $identity, callable $current ): void {

		// Obsolete requests never authorise cleanup after a newer publication.
		$barrier = $this->publication();
		try {
			$generation = $barrier->current();
			$barrier->publish(
				$generation,
				function () use ( $identity, $current ): void {
					$authoritative = $current();
					if ( $authoritative === null || $authoritative->kind !== $identity->kind
						|| $authoritative->key !== $identity->key ) {
						return;
					}
					$this->prune_current( $identity );
				},
			);
		} catch ( Obsolete_Artifact | \UnexpectedValueException ) {
			$this->logger->warning( 'Skipped aggregate cleanup: publication state is unavailable or obsolete' );
		}

	}

	/**
	 * Removes siblings only after the current identity has been established.
	 *
	 * @since 0.5.2
	 *
	 * @param Identity $identity The authoritative aggregate identity.
	 * @return void
	 */
	private function prune_current( Identity $identity ): void {

		// Only the two owned aggregate key families have ordered generations.
		$prefix = match ( $identity->kind ) {
			'llms-txt' => 'llms-v',
			'llms-full' => 'llms-full-v',
			default => null,
		};
		if ( $prefix === null ) {
			return;
		}
		$pattern = '/\A' . preg_quote( $prefix, '/' ) . '([1-9][0-9]*)\.md\z/';
		if ( preg_match( $pattern, $identity->key . '.md', $current ) !== 1 ) {
			return;
		}
		$version = (int) $current[1];

		// Resolve the kind directory and refuse to act unless it lies strictly
		// inside the cache base — the realpath containment the serve router uses,
		// so a stray kind can never reach files outside the cache tree (and the
		// nested markdown-alternate/ files, in their own kind dir, stay untouched).
		$base = realpath( $this->base() );
		$dir = realpath( $this->base() . '/' . $identity->kind );
		if ( $base === false || $dir === false || ! str_starts_with( $dir, $base . '/' ) ) {
			return;
		}

		// Current persisted output is required; poison never authorises cleanup.
		$keep = $dir . '/' . $identity->key . '.md';
		clearstatcache( true, $keep );
		if ( ! $this->publication()->readable() || ! is_file( $keep ) ) {
			return;
		}

		// Persist a numeric cursor across requests without scanning retained names.
		// Each call probes at most 32 owned generation paths, even behind an
		// arbitrarily large prefix of unknown, current or future files.
		$cursor_path = $dir . '/.prune-next';
		$cursor_safe = ! is_link( $cursor_path ) && ( ! file_exists( $cursor_path ) || is_file( $cursor_path ) );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- optional cursor failure restarts bounded cleanup safely.
		$cursor = $cursor_safe && is_file( $cursor_path ) ? @file_get_contents( $cursor_path, false, null, 0, 32 ) : false;
		$next = is_string( $cursor ) && preg_match( '/\A[1-9][0-9]*\n\z/', $cursor ) === 1 ? (int) $cursor : 1;
		$next = $next > 0 && $next < $version ? $next : 1;
		for ( $visited = 0; $visited < self::PRUNE_BATCH_SIZE && $next < $version; ++$visited, ++$next ) {
			$path = $dir . '/' . $prefix . $next . '.md';
			clearstatcache( true, $path );
			if ( ! is_file( $path ) || is_link( $path ) ) {
				continue;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- optional cleanup failure never changes captured response bytes.
			if ( ! @unlink( $path ) ) {
				$this->logger->warning( 'Aggregate cleanup failed', [ 'path' => $path ] );
			}
		}

		// A completed pass wraps so late older files are considered next time.
		// The publication barrier serialises this one bounded metadata file.
		$next = $next >= $version ? 1 : $next;
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- optional cursor persistence failure is logged without failing valid output.
		if ( ! $cursor_safe || @file_put_contents( $cursor_path, $next . "\n" ) !== strlen( $next . "\n" ) ) {
			$this->logger->warning( 'Aggregate cleanup cursor could not be saved', [ 'path' => $cursor_path ] );
		}

	}

	/**
	 * Returns the resolved, cached base directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string The absolute base directory, without a trailing slash.
	 */
	private function base(): string {
		return $this->base ??= rtrim( ( $this->base_dir_provider )(), '/' );
	}

	/**
	 * Ensures the base directory exists and carries an index.html listing guard.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when the guarded base is ready; false on a logged failure.
	 */
	private function ensure_base(): bool {

		// Create the directory and drop an empty index.html so a misconfigured
		// webserver cannot list the cache contents.
		$base = $this->base();
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- expected filesystem failures are logged through the plugin logger.
		if ( ! @wp_mkdir_p( $base ) ) {
			return $this->write_failed( 'mkdir', $base );
		}
		$guard = $base . '/index.html';
		if ( ! is_file( $guard ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- expected filesystem failures are logged through the plugin logger.
			if ( @file_put_contents( $guard, '' ) === false ) {
				return $this->write_failed( 'guard', $guard );
			}
		}

		return true;

	}

	/**
	 * Reports a persistence failure without exposing filesystem diagnostics.
	 *
	 * @since 0.5.2
	 *
	 * @param string $operation The failed filesystem operation.
	 * @param string $path      The affected Core-owned path.
	 * @return false
	 */
	private function write_failed( string $operation, string $path ): false {
		$this->logger->warning(
			'Cache write failed',
			[
				'operation' => $operation,
				'path' => $path,
			],
		);
		return false;

	}

	/**
	 * Reports failed revocation while the stable poison keeps readers closed.
	 *
	 * @since 0.5.2
	 *
	 * @param string $path The affected Core-owned path.
	 * @return void
	 */
	private function erasure_failed( string $path ): void {
		$this->logger->warning( 'Cache erasure failed; repair storage and flush the whole cache', [ 'path' => $path ] );
	}

	/**
	 * Refuses leftovers and reports unavailable coordination or failed erasure.
	 *
	 * @since 0.5.2
	 *
	 * @return bool Whether cached bytes may be trusted.
	 */
	private function readable(): bool {
		if ( $this->publication()->readable() ) {
			return true;
		}
		if ( ! $this->refusal_logged ) {
			$this->refusal_logged = true;
			$this->logger->warning( 'Cache refused: unverified erasure or unavailable publication state' );
		}
		return false;
	}

}
