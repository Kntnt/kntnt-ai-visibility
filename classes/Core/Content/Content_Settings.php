<?php
/**
 * The Core content-type settings section and clear-cache action.
 *
 * With the per-module post-type fields gone, Core owns the single settings
 * section: the content-type matrix and the clear-cache action beside it
 * (docs/spec/llms-txt.md §6, docs/adr/0010). The section is a custom section —
 * its renderer draws the matrix's checkbox table plus the clear-cache button,
 * and its sanitiser delegates to the matrix, which walks the registered rows x
 * columns and enforces the `.md` dependency. The clear-cache action flushes the
 * whole cache (which now includes the llms aggregates) and bumps the cache
 * version so the version-stamped aggregates rebuild lazily.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Content;

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Settings\Section;

/**
 * Builds the content-type settings section and runs the clear-cache action.
 *
 * @since 0.2.0
 */
final class Content_Settings {

	/**
	 * The section id; namespaces the matrix within the single option.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const SECTION_ID = 'content_types';

	/**
	 * The admin_post action the clear-cache button triggers.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const CLEAR_CACHE_ACTION = 'kntnt_ai_visibility_clear_cache';

	/**
	 * Binds the section to the matrix, cache store, version stamp and option key.
	 *
	 * @since 0.2.0
	 *
	 * @param Content_Matrix $matrix     The content-type capability matrix.
	 * @param Store          $cache      The artifact cache store.
	 * @param Cache_Version  $version    The cache-version stamp.
	 * @param string         $option_key The single option name (the form-name prefix root).
	 */
	public function __construct(
		private readonly Content_Matrix $matrix,
		private readonly Store $cache,
		private readonly Cache_Version $version,
		private readonly string $option_key = 'kntnt_ai_visibility',
	) {}

	/**
	 * Registers the privileged clear-cache admin_post action.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_' . self::CLEAR_CACHE_ACTION, [ $this, 'handle_clear_cache' ] );
	}

	/**
	 * Builds the custom content-type settings section.
	 *
	 * @since 0.2.0
	 *
	 * @return Section
	 */
	public function section(): Section {

		// Capture the collaborators the closures need; the matrix renders the table
		// and sanitises the slice, and the form-name prefix roots the checkboxes.
		$matrix = $this->matrix;
		$prefix = sprintf( '%s[%s]', $this->option_key, self::SECTION_ID );

		return new Section(
			self::SECTION_ID,
			__( 'Content types', 'kntnt-ai-visibility' ),
			render: static function () use ( $matrix, $prefix ): void {
				printf(
					'<p class="description">%s</p>',
					esc_html__( 'Choose which content types are exposed to AI agents. The llms.txt and llms-full.txt columns require Markdown (.md) for that type and are forced off without it.', 'kntnt-ai-visibility' ),
				);
				$matrix->render_table( $prefix );
				self::render_clear_cache();
			},
			sanitize: static fn( mixed $slice ): array => $matrix->sanitize( $slice ),
		);

	}

	/**
	 * Flushes the whole cache for an authorised clear-cache request, then exits.
	 *
	 * Bumps the cache version (so the version-stamped aggregates rebuild lazily)
	 * and removes the cache directory, then returns to the originating settings
	 * screen with a flag.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function handle_clear_cache(): void {

		// Only an authorised, nonce-checked request may flush the cache.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache.', 'kntnt-ai-visibility' ) );
		}
		check_admin_referer( self::CLEAR_CACHE_ACTION );

		// Bump then flush, so the next request finds no version-stamped aggregate
		// file and rebuilds, and every per-page file is gone too.
		$this->version->bump();
		$this->cache->flush_all();

		// Return to the originating settings screen with a flag.
		$referer = wp_get_referer();
		$back = $referer !== false ? $referer : admin_url();
		wp_safe_redirect( add_query_arg( 'kntnt_ai_visibility_cache_cleared', '1', $back ) );

		exit;

	}

	/**
	 * Renders the clear-cache button as a nonce-protected admin-post link.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function render_clear_cache(): void {

		// A nonce-protected link to the admin_post action handled above.
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::CLEAR_CACHE_ACTION ),
			self::CLEAR_CACHE_ACTION,
		);
		printf(
			'<p><a href="%s" class="button">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Clear cache', 'kntnt-ai-visibility' ),
		);

	}

}
