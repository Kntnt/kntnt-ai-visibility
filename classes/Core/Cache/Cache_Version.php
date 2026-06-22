<?php
/**
 * The cache-version stamp.
 *
 * A monotonic counter in its own option key — separate from the settings array,
 * because it is structural state, not configuration (docs/adr/0010). Indirect
 * changes (theme, menus, plugin settings) bump it to record a new cache
 * generation; the Release-1 invalidation flushes the file cache alongside the
 * bump, and Release 2's aggregate artifacts will use the stamp to invalidate
 * lazily.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

/**
 * Reads and bumps the cache-version stamp.
 *
 * @since 0.1.0
 */
final class Cache_Version {

	/**
	 * The option key the stamp lives in.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const OPTION = 'kntnt_ai_visibility_cache_version';

	/**
	 * Returns the current cache version, never below 1.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function current(): int {
		$value = get_option( self::OPTION, 1 );

		return max( 1, is_numeric( $value ) ? (int) $value : 1 );

	}

	/**
	 * Increments the stored cache version.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function bump(): void {
		update_option( self::OPTION, $this->current() + 1, false );
	}

}
