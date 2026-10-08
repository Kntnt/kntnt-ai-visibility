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
		return $this->locked( fn( $handle ): int => $this->read( $handle )[0] );
	}

	/**
	 * Reports whether previous revocation completed its filesystem erasure.
	 *
	 * @since 0.5.2
	 *
	 * @return bool False for durable poison or unavailable coordination.
	 * @phpstan-impure Reads shared state that another process can change.
	 */
	public function readable(): bool {
		try {
			return $this->locked( fn( $handle ): bool => ! $this->read( $handle )[1] );
		} catch ( Obsolete_Artifact ) {
			return false;
		}
	}

	/**
	 * Prevents all readers from trusting files before erasure begins.
	 *
	 * @since 0.5.2
	 *
	 * @return void
	 */
	public function poison(): void {
		$this->locked( fn( $handle ) => $this->write( $handle, $this->read( $handle )[0], true ) );
	}

	/**
	 * Restores reads only after the caller has verified successful erasure.
	 *
	 * @since 0.5.2
	 *
	 * @return void
	 */
	public function recover(): void {
		$this->locked( fn( $handle ) => $this->write( $handle, $this->read( $handle )[0], false ) );
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
				if ( $this->read( $handle )[0] !== $generation ) {
					throw new Obsolete_Artifact( 'The public artifact generation was revoked.' );
				}
				$result = $publish();
				if ( $this->read( $handle )[0] !== $generation ) {
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
				[ $generation, $poisoned ] = $this->read( $handle );
				$this->write( $handle, $generation + 1, $poisoned );
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
		$directory = $this->directory ?? sys_get_temp_dir();
		$path = $directory . '/kntnt-aiv-publication-' . hash( 'sha256', $this->base ) . '.lock';
		if ( isset( self::$held[ $path ] ) ) {
			return $operation( self::$held[ $path ] );
		}
		$created = false;
		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an existing inode must never be replaced.
			$handle = @fopen( $path, 'r+' );
		} else {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- exclusive creation distinguishes initialisation from corruption.
			$handle = @fopen( $path, 'x+' );
			$created = $handle !== false;
			if ( ! $created ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- another worker may have created the stable inode.
				$handle = @fopen( $path, 'r+' );
			}
		}
		if ( $handle === false ) {
			throw new Obsolete_Artifact( 'The public artifact generation is unavailable.' );
		}
		try {
			if ( ! flock( $handle, LOCK_EX ) ) {
				throw new Obsolete_Artifact( 'The public artifact generation is unavailable.' );
			}
			self::$held[ $path ] = $handle;
			if ( $created ) {
				$this->write( $handle, 0, false );
			}
			return $operation( $handle );
		} finally {
			unset( self::$held[ $path ] );
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}

	}

	/**
	 * Reads the epoch and durable poison, without process caches.
	 *
	 * @since 0.5.2
	 *
	 * @param resource $handle The locked generation file.
	 * @return array{int, bool} The generation and erasure poison.
	 * @throws Obsolete_Artifact When the existing state cannot be trusted.
	 */
	private function read( $handle ): array {
		rewind( $handle );
		$value = stream_get_contents( $handle );
		if ( ! is_string( $value ) || preg_match( '/\A([0-9]+)\n(readable|poisoned)\n\z/', $value, $matches ) !== 1 ) {
			throw new Obsolete_Artifact( 'The public artifact generation is unavailable.' );
		}
		return [ (int) $matches[1], $matches[2] === 'poisoned' ];
	}

	/**
	 * Updates both parts under the stable inode's already-held lock.
	 *
	 * An interrupted update leaves invalid state, which all readers refuse.
	 *
	 * @since 0.5.2
	 *
	 * @param resource $handle     The locked coordination file.
	 * @param int      $generation The current generation.
	 * @param bool     $poisoned   Whether erasure remains unverified.
	 * @return void
	 * @throws Obsolete_Artifact When the update fails.
	 */
	private function write( $handle, int $generation, bool $poisoned ): void {
		$value = (string) $generation . "\n" . ( $poisoned ? 'poisoned' : 'readable' ) . "\n";
		rewind( $handle );
		if ( ! ftruncate( $handle, 0 ) || fwrite( $handle, $value ) !== strlen( $value ) || ! fflush( $handle ) ) {
			throw new Obsolete_Artifact( 'The public artifact generation is unavailable.' );
		}
	}

}
