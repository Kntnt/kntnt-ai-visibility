<?php
/**
 * The Markdown module's settings section.
 *
 * Contributes one section to the Core settings page (docs/adr/0010): a
 * post-type override and a clear-cache action button. The post-type control is
 * a comma-separated text field rather than a multi-select — zero-config means
 * the default (every publicly-queryable type) already works, so the field only
 * has to let an owner narrow or extend it. The clear-cache button posts to an
 * admin_post action the Module handles; this class owns the action name and the
 * button markup, the Module owns the privileged handler.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Markdown;

use Kntnt\Ai_Visibility\Core\Settings\Field;
use Kntnt\Ai_Visibility\Core\Settings\Section;

/**
 * Builds the Markdown settings section and its field controls.
 *
 * @since 0.1.0
 */
final class Settings {

	/**
	 * The section id; namespaces the module's keys within the single option.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const SECTION_ID = 'markdown';

	/**
	 * The admin_post action the clear-cache button triggers.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	public const CLEAR_CACHE_ACTION = 'kntnt_ai_visibility_clear_cache';

	/**
	 * Builds the Markdown settings section.
	 *
	 * @since 0.1.0
	 *
	 * @return Section
	 */
	public function section(): Section {
		return new Section(
			self::SECTION_ID,
			__( 'Markdown alternates', 'kntnt-ai-visibility' ),
			[
				new Field(
					'post_types',
					__( 'Post types', 'kntnt-ai-visibility' ),
					'post_types',
					[],
					[ self::class, 'sanitize_post_types' ],
					__( 'Comma-separated post-type slugs to expose as Markdown. Leave empty to expose every public post type.', 'kntnt-ai-visibility' ),
					[ self::class, 'render_post_types' ],
				),
				new Field(
					'clear_cache',
					__( 'Cache', 'kntnt-ai-visibility' ),
					'action',
					'',
					static fn(): string => '',
					__( 'Delete every cached Markdown file. They regenerate on the next request.', 'kntnt-ai-visibility' ),
					[ self::class, 'render_clear_cache' ],
				),
			],
		);
	}

	/**
	 * Sanitises the submitted post-type override into a list of slugs.
	 *
	 * Accepts the comma-separated text the field stores, or an already-split
	 * array, and reduces it to unique, sanitize_key'd, non-empty slugs.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $value The raw submitted value.
	 * @return list<string> The cleaned post-type slugs.
	 */
	public static function sanitize_post_types( mixed $value ): array {

		// Accept either the comma-separated text or an already-split array.
		$items = is_array( $value ) ? $value : explode( ',', is_string( $value ) ? $value : '' );

		// Reduce each entry to a clean slug, dropping the blanks.
		$slugs = [];
		foreach ( $items as $item ) {
			$slug = is_string( $item ) ? sanitize_key( trim( $item ) ) : '';
			if ( $slug !== '' ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );

	}

	/**
	 * Renders the post-type override as a comma-separated text input.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $value The effective value (a list of slugs).
	 * @param string $name  The form `name` attribute.
	 * @return void
	 */
	public static function render_post_types( mixed $value, string $name ): void {

		// Show the stored slugs joined back into the comma-separated text the
		// sanitiser splits again on submit.
		$slugs = is_array( $value ) ? array_values( array_filter( $value, 'is_string' ) ) : [];
		printf(
			'<input type="text" class="regular-text" name="%s" value="%s" />',
			esc_attr( $name ),
			esc_attr( implode( ', ', $slugs ) ),
		);

	}

	/**
	 * Renders the clear-cache button as a nonce-protected admin-post link.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $value The effective value (unused — the field stores nothing).
	 * @param string $name  The form `name` attribute (unused — this is an action, not an input).
	 * @return void
	 */
	public static function render_clear_cache( mixed $value, string $name ): void {

		// A nonce-protected link to the admin_post action the Module handles.
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::CLEAR_CACHE_ACTION ),
			self::CLEAR_CACHE_ACTION,
		);
		printf(
			'<a href="%s" class="button">%s</a>',
			esc_url( $url ),
			esc_html__( 'Clear Markdown cache', 'kntnt-ai-visibility' ),
		);

	}

}
