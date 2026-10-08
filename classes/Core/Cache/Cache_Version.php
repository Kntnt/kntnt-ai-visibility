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
	 * Binds version-only invalidations to the shared publication barrier.
	 *
	 * @param Store|null $store The production cache store.
	 */
	public function __construct( private readonly ?Store $store = null ) {}

	/**
	 * Returns the current cache version, never below 1.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 * @throws Obsolete_Artifact When authoritative generation state is unavailable.
	 */
	public function current(): int {
		$wpdb = $this->database();
		$sql = $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION );
		if ( ! is_string( $sql ) ) {
			throw new Obsolete_Artifact( 'The public artifact database query is unavailable.' );
		}
		$suppressed = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed template and all arguments prepared above; null refused.
			$value = $wpdb->get_var( $sql );
			if ( $wpdb->last_error !== '' ) {
				throw new Obsolete_Artifact( 'The public artifact database query failed.' );
			}
		} finally {
			$wpdb->suppress_errors( $suppressed );
		}

		return max( 1, is_numeric( $value ) ? (int) $value : 1 );

	}

	/**
	 * Increments the stored cache version.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 * @throws Obsolete_Artifact When generation advancement cannot complete safely.
	 */
	public function bump(): void {

		// Atomic SQL advancement cannot lose another writer's invalidation or
		// reuse a request-local or persistent WordPress option-cache value.
		$advance = function (): void {
			$wpdb = $this->database();
			$insert_sql = $wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, '1', 'off')",
				$wpdb->options,
				self::OPTION,
			);
			$update_sql = $wpdb->prepare(
				'UPDATE %i SET option_value = CASE WHEN CAST(option_value AS UNSIGNED) < 1 '
				. 'THEN 2 ELSE CAST(option_value AS UNSIGNED) + 1 END WHERE option_name = %s',
				$wpdb->options,
				self::OPTION,
			);
			if ( ! is_string( $insert_sql ) || ! is_string( $update_sql ) ) {
				throw new Obsolete_Artifact( 'The public artifact database query is unavailable.' );
			}
			$suppressed = $wpdb->suppress_errors( true );
			try {
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed template and all arguments prepared above; null refused.
				$insert = $wpdb->query( $insert_sql );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fixed template and all arguments prepared above; null refused.
				$update = $wpdb->query( $update_sql );
				if ( $insert === false || $update === false ) {
					throw new Obsolete_Artifact( 'The public artifact generation is unavailable.' );
				}
			} finally {
				$wpdb->suppress_errors( $suppressed );
			}
			wp_cache_delete( self::OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		};
		if ( $this->store === null ) {
			$advance();
		} else {
			$this->store->publication()->revoke(
				function () use ( $advance ): void {
					try {
						$advance();
					} catch ( \Throwable $failure ) {

						// A failed stamp must never leave the old public generation
						// readable after database recovery. Re-entrant flush shares
						// this already-held barrier across same-base store objects.
						$this->store->flush_all();
						throw $failure;
					}
				},
			);
		}

	}

	/**
	 * Requires the actual WordPress database boundary before reading state.
	 *
	 * @return \wpdb The initialised WordPress database.
	 * @throws Obsolete_Artifact When authoritative state is unavailable.
	 */
	private function database(): \wpdb {
		$database = $GLOBALS['wpdb'] ?? null;
		if ( ! $database instanceof \wpdb ) {
			throw new Obsolete_Artifact( 'The public artifact database is unavailable.' );
		}
		return $database;
	}

}
