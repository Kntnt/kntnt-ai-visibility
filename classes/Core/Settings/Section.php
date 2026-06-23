<?php
/**
 * Value object describing one settings section.
 *
 * Each module contributes exactly one section — its id namespaces the module's
 * keys within the single option, and its fields carry their own defaults and
 * sanitisers. Core composes the sections into one server-side settings page,
 * one section per module (docs/adr/0010).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Settings;

/**
 * A module's settings section: an id, a title and its fields.
 *
 * Most sections are field-based: Core renders each field as a row and sanitises
 * field by field. A section may instead be **custom** — it owns its whole-body
 * renderer and a slice sanitiser — for a control the flat field model cannot
 * express, such as the content-type matrix's 2-level `[type][column]` slice
 * (docs/spec/llms-txt.md §6). A custom section carries no fields; Core hands its
 * sanitiser the whole `$input[$id]` slice and stores the result at `$clean[$id]`.
 *
 * @since 0.1.0
 */
final readonly class Section {

	/**
	 * The section heading, resolved lazily via title().
	 *
	 * Stored as a string or a `Closure(): string` so a translatable heading can
	 * be translated when the settings page renders (on an admin hook, after
	 * `init`) rather than when the section is built at plugin bootstrap — which
	 * would trip WordPress 6.7's "translation loaded too early" notice.
	 *
	 * @since 0.2.1
	 *
	 * @var string|(\Closure(): string)
	 */
	private string|\Closure $title;

	/**
	 * Builds a settings section from its id, title and either fields or closures.
	 *
	 * @since 0.1.0
	 *
	 * @param string                                       $id       The section id; namespaces the module's keys.
	 * @param string|(\Closure(): string)                  $title    The section heading, or a closure that
	 *                                                                returns it — wrap a translatable heading
	 *                                                                in a closure so it resolves at render time.
	 * @param Field[]                                      $fields   The fields in this section (empty for a custom section).
	 * @param (\Closure(): void)|null                      $render   Optional whole-section renderer; echoes the body.
	 * @param (\Closure(mixed): array<string, mixed>)|null $sanitize Optional slice sanitiser; maps `$input[$id]` to the clean slice.
	 */
	public function __construct(
		public string $id,
		string|\Closure $title,
		public array $fields = [],
		public ?\Closure $render = null,
		public ?\Closure $sanitize = null,
	) {
		$this->title = $title;
	}

	/**
	 * Resolves the section heading.
	 *
	 * @since 0.2.1
	 *
	 * @return string The heading text.
	 */
	public function title(): string {
		return $this->title instanceof \Closure ? ( $this->title )() : $this->title;
	}

	/**
	 * Returns a field by key, or null when the section has no such field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The field key.
	 * @return Field|null
	 */
	public function field( string $key ): ?Field {

		// Linear search: a section holds only a handful of fields.
		foreach ( $this->fields as $field ) {
			if ( $field->key === $key ) {
				return $field;
			}
		}

		return null;

	}

}
