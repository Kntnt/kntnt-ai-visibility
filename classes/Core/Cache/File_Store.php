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

/**
 * Stores generated artifacts as files under a Core-owned cache directory.
 *
 * @since 0.1.0
 */
final class File_Store implements Store {

	/**
	 * Lazily-resolved, cached absolute base directory (no trailing slash).
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private ?string $base = null;

	/**
	 * Binds the store to a lazy base-directory provider.
	 *
	 * @since 0.1.0
	 *
	 * @param callable(): string $base_dir_provider Returns the absolute cache
	 *                                              base directory. Invoked once,
	 *                                              on first use.
	 */
	public function __construct( private $base_dir_provider ) {}

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
		return is_file( $this->path_for( $identity ) );
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
	 * @return void
	 */
	public function write( Identity $identity, string $bytes ): void {

		// Make sure the cache directory exists and is protected from listing.
		$this->ensure_base();

		// Create the file's parent directory (slash-bearing keys nest), then
		// write atomically via a temporary file and rename so a concurrent
		// reader never sees a half-written file.
		$path = $this->path_for( $identity );
		wp_mkdir_p( dirname( $path ) );
		$tmp = $path . '.' . uniqid( '', true ) . '.tmp';
		if ( file_put_contents( $tmp, $bytes, LOCK_EX ) !== false ) {
			rename( $tmp, $path );
		}

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

		// Remove the file when present; an absent file is a no-op.
		$path = $this->path_for( $identity );
		if ( is_file( $path ) ) {
			unlink( $path );
		}

	}

	/**
	 * Removes the entire cache directory.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush_all(): void {

		// Nothing to do when the cache directory was never created.
		$base = $this->base();
		if ( ! is_dir( $base ) ) {
			return;
		}

		// Walk the tree depth-first, removing files before their directories.
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		// phpcs:ignore Generic.Commenting.DocComment.MissingShort -- inline @var to type the iterator value.
		/** @var \SplFileInfo $entry */
		foreach ( $entries as $entry ) {
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		rmdir( $base );

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
	 * @return void
	 */
	private function ensure_base(): void {

		// Create the directory and drop an empty index.html so a misconfigured
		// webserver cannot list the cache contents.
		$base = $this->base();
		wp_mkdir_p( $base );
		$guard = $base . '/index.html';
		if ( ! is_file( $guard ) ) {
			file_put_contents( $guard, '' );
		}

	}

}
