<?php
/**
 * The artifact cache-store contract.
 *
 * One file per artifact under an isolated, Core-owned directory in uploads. The
 * same file is both the inner cache (a hit skips render + convert) and the
 * outer cache (the early router serves it without booting WordPress fully). The
 * store derives every path from an Identity's validated key — never from a raw
 * URL (docs/adr/0007).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;

/**
 * File-backed storage for generated artifacts.
 *
 * @since 0.1.0
 */
interface Store {

	/**
	 * Returns the absolute cache base directory, without a trailing slash.
	 *
	 * The serve router uses this as the realpath containment root: a resolved
	 * cache path must lie strictly inside it.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function base_dir(): string;

	/**
	 * Returns the absolute, contained cache path for an identity.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @return string The absolute filesystem path of its cache file.
	 */
	public function path_for( Identity $identity ): string;

	/**
	 * Reports whether a cache file exists for an identity.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @return bool
	 */
	public function has( Identity $identity ): bool;

	/**
	 * Reads the cached bytes for an identity, or null when absent.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @return string|null
	 */
	public function read( Identity $identity ): ?string;

	/**
	 * Writes bytes to the cache for an identity, creating directories as needed.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @param string   $bytes    The bytes to store.
	 * @return void
	 */
	public function write( Identity $identity, string $bytes ): void;

	/**
	 * Deletes the cache file for an identity, if present.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @return void
	 */
	public function delete( Identity $identity ): void;

	/**
	 * Removes the entire cache directory.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush_all(): void;

	/**
	 * Deletes the other cache files in the identity's kind directory.
	 *
	 * Prunes the orphaned, version-stamped aggregates a cache-version bump leaves
	 * behind (e.g. `llms-txt/llms-v7.md` after a bump to v8): every file directly
	 * in the identity's kind directory except the identity's own file is removed
	 * (docs/spec/llms-txt.md §5.5, an optional optimisation). It is scoped to the
	 * one kind directory and contained within the cache base, so it never touches
	 * the nested, non-version-stamped `markdown-alternate/` files.
	 *
	 * @since 0.2.0
	 *
	 * @param Identity $identity The identity whose file is kept; its kind directory is pruned.
	 * @return void
	 */
	public function prune_siblings( Identity $identity ): void;

}
