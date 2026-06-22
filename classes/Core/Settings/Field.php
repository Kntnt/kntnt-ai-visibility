<?php
/**
 * Value object describing one settings field.
 *
 * A field declares its storage key, its label, its zero-config default, and the
 * sanitiser that cleans a submitted value. Defaults live in code and work
 * untouched; settings only override them (docs/adr/0010). Every field's value
 * is mirrored by a developer filter named from the section and field keys.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Settings;

/**
 * One field within a settings section.
 *
 * @since 0.1.0
 */
final readonly class Field {

	/**
	 * The sanitiser applied to a submitted value.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(mixed): mixed
	 */
	public mixed $sanitize;

	/**
	 * Optional custom renderer, for controls the registry cannot render itself.
	 *
	 * Receives the field's effective value and the form `name` attribute and
	 * echoes the control. When null, the registry renders by $type.
	 *
	 * @since 0.1.0
	 *
	 * @var (callable(mixed, string): void)|null
	 */
	public mixed $render;

	/**
	 * Builds a settings field with its default, sanitiser and renderer.
	 *
	 * @since 0.1.0
	 *
	 * @param string                               $key         The field key within the section.
	 * @param string                               $label       The human-readable label.
	 * @param string                               $type        The control type ('checkbox', 'text').
	 * @param mixed                                $default     The zero-config default value.
	 * @param callable(mixed): mixed               $sanitize    Sanitiser for a submitted value.
	 * @param string                               $description Optional help text shown under the field.
	 * @param (callable(mixed, string): void)|null $render Optional custom renderer.
	 */
	public function __construct(
		public string $key,
		public string $label,
		public string $type,
		public mixed $default,
		callable $sanitize,
		public string $description = '',
		?callable $render = null,
	) {
		$this->sanitize = $sanitize;
		$this->render = $render;
	}

}
