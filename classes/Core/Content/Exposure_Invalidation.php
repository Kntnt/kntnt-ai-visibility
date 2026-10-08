<?php
/**
 * Invalidates artifacts across the unified exposure option's lifecycle.
 *
 * @package Kntnt\Ai_Visibility
 * @since 0.5.2
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Content;

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\Store;

/**
 * Owns one cache turnover for each saved exposure change or successful reset.
 *
 * Comparing the saved exposure slices is deliberately conservative: rebuilt
 * artifacts apply the shared effective eligibility and exclusion policies,
 * including filters, without maintaining a second copy of their logic here.
 * Other sections, such as content signals, do not alter artifact bytes.
 *
 * @since 0.5.2
 */
final readonly class Exposure_Invalidation {

	/**
	 * Binds lifecycle invalidation to the shared cache and generation stamp.
	 *
	 * @since 0.5.2
	 *
	 * @param Store         $cache      The plugin-owned artifact cache.
	 * @param Cache_Version $version    The shared aggregate generation stamp.
	 * @param string        $option_key The unified settings option.
	 */
	public function __construct(
		private Store $cache,
		private Cache_Version $version,
		private string $option_key = 'kntnt_ai_visibility',
	) {}

	/**
	 * Observes completed option creation, updates and successful deletion.
	 *
	 * WordPress's dynamic delete_option hook runs before deletion; the generic
	 * deleted_option hook runs afterwards, when zero-config defaults apply.
	 *
	 * @since 0.5.2
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'added_option', [ $this, 'on_option_add' ], 10, 2 );
		add_action( 'update_option_' . $this->option_key, [ $this, 'on_option_update' ], 10, 2 );
		add_action( 'deleted_option', [ $this, 'on_option_delete' ] );
	}

	/**
	 * Compares the first saved exposure settings with untouched defaults.
	 *
	 * @since 0.5.2
	 *
	 * @param string $option The newly created option's name.
	 * @param mixed  $value  The stored value.
	 * @return void
	 */
	public function on_option_add( string $option, mixed $value ): void {
		if ( $option === $this->option_key ) {
			$this->on_option_update( [], $value );
		}
	}

	/**
	 * Turns over all artifacts once when either saved exposure slice changes.
	 *
	 * @since 0.5.2
	 *
	 * @param mixed $old The option before the update.
	 * @param mixed $new The option after the update.
	 * @return void
	 */
	public function on_option_update( mixed $old, mixed $new ): void {
		if ( $this->exposure( $old ) !== $this->exposure( $new ) ) {
			$this->flush();
		}
	}

	/**
	 * Purges the previous policy after a successful removal restores defaults.
	 *
	 * @since 0.5.2
	 *
	 * @param string $option The successfully deleted option's name.
	 * @return void
	 */
	public function on_option_delete( string $option ): void {
		if ( $option === $this->option_key ) {
			$this->flush();
		}
	}

	/**
	 * Reads the slices that can change artifact exposure from stored settings.
	 *
	 * Missing and malformed slices have the same defaults as their runtime
	 * readers. A signals-only save therefore never invalidates artifact bytes.
	 *
	 * @since 0.5.2
	 *
	 * @param mixed $option The complete stored option.
	 * @return array{content_types: array<mixed>, exclusions: string}
	 */
	private function exposure( mixed $option ): array {

		// Stored options are an untyped boundary; match the runtime defaults.
		$option = is_array( $option ) ? $option : [];
		$types = $option[ Content_Settings::SECTION_ID ] ?? [];
		$exclusions = $option[ Exclusion_Settings::SECTION_ID ] ?? [];
		$paths = is_array( $exclusions ) ? ( $exclusions[ Exclusion_Settings::FIELD_KEY ] ?? '' ) : '';

		return [
			'content_types' => is_array( $types ) ? $types : [],
			'exclusions' => is_scalar( $paths ) ? (string) $paths : '',
		];

	}

	/**
	 * Replaces aggregate generations and removes all previous per-page bytes.
	 *
	 * @since 0.5.2
	 *
	 * @return void
	 */
	private function flush(): void {
		$this->version->bump();
		$this->cache->flush_all();
	}

}
