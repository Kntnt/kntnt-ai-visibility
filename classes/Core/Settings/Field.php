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
	 * The field label, resolved lazily via label().
	 *
	 * Stored as a string or a `Closure(): string` so a translatable label can be
	 * translated when the settings page renders (on an admin hook, after `init`)
	 * rather than when the field is built at plugin bootstrap — which would trip
	 * WordPress 6.7's "translation loaded too early" notice and freeze the string
	 * against the locale determinable before the current user exists.
	 *
	 * @since 0.5.1
	 *
	 * @var string|(\Closure(): string)
	 */
	private string|\Closure $label;

	/**
	 * The field's help text, resolved lazily via description().
	 *
	 * Lazy for the same reason as $label.
	 *
	 * @since 0.5.1
	 *
	 * @var string|(\Closure(): string)
	 */
	private string|\Closure $description;

	/**
	 * Builds a settings field with its default, sanitiser and renderer.
	 *
	 * @since 0.1.0
	 *
	 * @param string                               $key         The field key within the section.
	 * @param string|(\Closure(): string)          $label       The human-readable label, or a closure
	 *                                                          that returns it — wrap a translatable
	 *                                                          label in a closure so it resolves at
	 *                                                          render time.
	 * @param string                               $type        The control type ('checkbox', 'text').
	 * @param mixed                                $default     The zero-config default value.
	 * @param callable(mixed): mixed               $sanitize    Sanitiser for a submitted value.
	 * @param string|(\Closure(): string)          $description Optional help text shown under the field,
	 *                                                          or a closure that returns it.
	 * @param (callable(mixed, string): void)|null $render Optional custom renderer.
	 */
	public function __construct(
		public string $key,
		string|\Closure $label,
		public string $type,
		public mixed $default,
		callable $sanitize,
		string|\Closure $description = '',
		?callable $render = null,
	) {
		$this->label = $label;
		$this->description = $description;
		$this->sanitize = $sanitize;
		$this->render = $render;
	}

	/**
	 * Resolves the field label.
	 *
	 * @since 0.5.1
	 *
	 * @return string The label text.
	 */
	public function label(): string {
		return $this->label instanceof \Closure ? ( $this->label )() : $this->label;
	}

	/**
	 * Resolves the field's help text.
	 *
	 * @since 0.5.1
	 *
	 * @return string The help text, or '' when the field has none.
	 */
	public function description(): string {
		return $this->description instanceof \Closure ? ( $this->description )() : $this->description;
	}

}
