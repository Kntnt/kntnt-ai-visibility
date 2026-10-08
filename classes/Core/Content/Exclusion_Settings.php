<?php
/**
 * The path-exclusion settings section.
 *
 * Contributes the second Core settings section beside the content-type matrix: a
 * textarea of path patterns, one regular expression per line, that curate
 * individual entries out of every artifact (see Exclusions). The section is
 * field-based — a single textarea field with a custom renderer and a sanitiser
 * that keeps only the lines that compile, reporting the rejected ones as a
 * settings error so a typo is never silently stored as an inert pattern.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.5.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Content;

use Kntnt\Ai_Visibility\Core\Settings\Field;
use Kntnt\Ai_Visibility\Core\Settings\Section;

/**
 * Builds the path-exclusion section; Core owns option lifecycle invalidation.
 *
 * @since 0.5.0
 */
final class Exclusion_Settings {

	/**
	 * The section id; namespaces the pattern text within the single option.
	 *
	 * @since 0.5.0
	 *
	 * @var string
	 */
	public const SECTION_ID = 'exclusions';

	/**
	 * The field key the pattern text is stored under.
	 *
	 * @since 0.5.0
	 *
	 * @var string
	 */
	public const FIELD_KEY = 'paths';

	/**
	 * Builds the field-based path-exclusion section.
	 *
	 * @since 0.5.0
	 *
	 * @return Section
	 */
	public function section(): Section {

		return new Section(
			self::SECTION_ID,
			static fn(): string => __( 'Excluded paths', 'kntnt-ai-visibility' ),
			fields: [
				new Field(
					key: self::FIELD_KEY,
					label: static fn(): string => __( 'Path patterns', 'kntnt-ai-visibility' ),
					type: 'text',
					default: '',
					sanitize: [ self::class, 'sanitize_patterns' ],
					description: static fn(): string => __( 'One regular expression per line, matched against each page’s path (e.g. /cookiepolicy/). A matching page is left out of its .md alternate, llms.txt and llms-full.txt. Write the pattern without delimiters or flags — matching is Unicode-aware and case-insensitive. Anchor with ^ and $ as needed; an invalid line is reported and dropped when you save.', 'kntnt-ai-visibility' ),
					render: [ self::class, 'render_textarea' ],
				),
			],
		);

	}

	/**
	 * Sanitises the submitted pattern text, dropping and reporting invalid lines.
	 *
	 * Keeps only the lines that compile to a valid regular expression, so the
	 * runtime gate never has to guard against a malformed pattern; the dropped
	 * lines are surfaced as a settings error naming each one.
	 *
	 * @since 0.5.0
	 *
	 * @param mixed $value The raw submitted textarea value.
	 * @return string The cleaned, newline-joined list of valid patterns.
	 */
	public static function sanitize_patterns( mixed $value ): string {

		// Partition the submitted lines into the ones that compile and the ones
		// that do not.
		$text = is_scalar( $value ) ? (string) $value : '';
		$valid = [];
		$invalid = [];
		foreach ( Exclusions::split_patterns( $text ) as $line ) {
			if ( Exclusions::is_valid( $line ) ) {
				$valid[] = $line;
			} else {
				$invalid[] = $line;
			}
		}

		// Report the rejected lines so a typo is never silently kept as an inert
		// pattern the author believes is excluding something.
		if ( $invalid !== [] ) {
			add_settings_error(
				'kntnt_ai_visibility',
				'invalid-exclusion-pattern',
				sprintf(
					/* translators: %s: comma-separated list of the rejected patterns. */
					__( 'These exclusion patterns are not valid regular expressions and were removed: %s', 'kntnt-ai-visibility' ),
					implode( ', ', $invalid ),
				),
				'error',
			);
		}

		return implode( "\n", $valid );

	}

	/**
	 * Renders the pattern textarea.
	 *
	 * @since 0.5.0
	 *
	 * @param mixed  $value The field's effective value.
	 * @param string $name  The form `name` attribute.
	 * @return void
	 */
	public static function render_textarea( mixed $value, string $name ): void {
		printf(
			'<textarea name="%s" rows="6" class="large-text code" placeholder="%s">%s</textarea>',
			esc_attr( $name ),
			esc_attr( '/cookiepolicy/' ),
			esc_textarea( is_scalar( $value ) ? (string) $value : '' ),
		);
	}

}
