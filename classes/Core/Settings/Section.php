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
 * @since 0.1.0
 */
final readonly class Section {

	/**
	 * Builds a settings section from its id, title and fields.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $id     The section id; namespaces the module's keys.
	 * @param string  $title  The section heading shown on the settings page.
	 * @param Field[] $fields The fields in this section.
	 */
	public function __construct(
		public string $id,
		public string $title,
		public array $fields,
	) {}

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
