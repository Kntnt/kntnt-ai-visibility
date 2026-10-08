<?php
/**
 * Serialises short publication and revocation operations for one cache store.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

/**
 * Keeps a generation outside the tree which invalidation removes.
 *
 * @since 0.5.2
 */
final class Publication_Barrier {

	/**
	 * Handles already locked by this request, including re-entrant hooks.
	 *
	 * @since 0.5.2
	 *
	 * @var array<string, resource>
	 */
	private static array $held = [];

	/**
	 * Binds the barrier to the store's stable absolute base.
	 *
	 * @since 0.5.2
	 *
	 * @param string      $base The cache store base directory.
	 * @param string|null $directory Explicit coordination directory; must already exist.
	 */
	public function __construct( private readonly string $base, private readonly ?string $directory = null ) {}

	/**
	 * Captures the current generation before any source work starts.
	 *
	 * @since 0.5.2
	 *
	 * @return int The current generation.
	 */
	public function current(): int {
		return $this->locked( fn( $handle ): int => $this->read( $handle ) );
	}

	/**
	 * Publishes only while the captured generation is still current.
	 *
	 * @since 0.5.2
	 *
	 * @template T
	 * @param int           $generation The producer's captured generation.
	 * @param callable(): T $publish    Short operation; never the renderer.
	 * @return T The operation's result.
	 * @throws Obsolete_Artifact When revocation won the race.
	 */
	public function publish( int $generation, callable $publish ): mixed {
		return $this->locked(
			function ( $handle ) use ( $generation, $publish ): mixed {
				if ( $this->read( $handle ) !== $generation ) {
					throw new Obsolete_Artifact( 'The public artifact generation was revoked.' );
				}
				$result = $publish();
				if ( $this->read( $handle ) !== $generation ) {
					throw new Obsolete_Artifact( 'The public artifact generation was revoked.' );
				}
				return $result;
			}
		);
	}

	/**
	 * Advances the generation atomically before removing public artifacts.
	 *
	 * @since 0.5.2
	 *
	 * @param callable(): void $invalidate The invalidation operation.
	 * @return void
	 */
	public function revoke( callable $invalidate ): void {
		$this->locked(
			function ( $handle ) use ( $invalidate ): void {
				$next = (string) ( $this->read( $handle ) + 1 );
				rewind( $handle );
				if ( ! ftruncate( $handle, 0 ) || fwrite( $handle, $next ) !== strlen( $next ) || ! fflush( $handle ) ) {
					throw new Obsolete_Artifact( 'The public artifact generation is unavailable.' );
				}
				$invalidate();
			}
		);
	}

	/**
	 * Runs a short operation with the shared, store-scoped advisory lock.
	 *
	 * @since 0.5.2
	 *
	 * @template T
	 * @param callable(resource): T $operation The protected operation.
	 * @return T The operation's result.
	 * @throws Obsolete_Artifact When safe coordination is unavailable.
	 */
	private function locked( callable $operation ): mixed {

		// The stable inode must survive flushes and be shared by every writer.
		$path = ( $this->directory ?? sys_get_temp_dir() ) . '/kntnt-aiv-publication-' . hash( 'sha256', $this->base ) . '.lock';
		if ( isset( self::$held[ $path ] ) ) {
			return $operation( self::$held[ $path ] );
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- coordination failure is a controlled refusal.
		$handle = @fopen( $path, 'c+' );
		if ( $handle === false ) {
			throw new Obsolete_Artifact( 'The public artifact generation is unavailable.' );
		}
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				throw new Obsolete_Artifact( 'The public artifact generation is unavailable.' );
			}
			self::$held[ $path ] = $handle;
			return $operation( $handle );
		} finally {
			unset( self::$held[ $path ] );
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}

	}

	/**
	 * Reads the epoch from the already locked inode, without process caches.
	 *
	 * @since 0.5.2
	 *
	 * @param resource $handle The locked generation file.
	 * @return int The generation.
	 */
	private function read( $handle ): int {
		rewind( $handle );
		$value = stream_get_contents( $handle );
		return is_string( $value ) && ctype_digit( $value ) ? (int) $value : 0;
	}

}
