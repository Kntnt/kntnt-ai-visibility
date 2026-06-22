<?php
/**
 * The settings-registry contract.
 *
 * Modules register one section each; Core composes them into a single
 * server-side settings page backed by the single option `kntnt_ai_visibility`
 * (docs/adr/0010). Consumers read effective values through value(), which
 * resolves the saved value or the field's code default and then applies the
 * field's developer filter — the programmatic escape hatch.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Settings;

/**
 * Collects settings sections and resolves effective field values.
 *
 * @since 0.1.0
 */
interface Registry {

	/**
	 * Registers a module's settings section.
	 *
	 * @since 0.1.0
	 *
	 * @param Section $section The section to register.
	 * @return void
	 */
	public function register_section( Section $section ): void;

	/**
	 * Resolves the effective value of a field.
	 *
	 * Returns the saved value when present, otherwise the field's code default,
	 * and then applies the field's developer filter so code can override either.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section The section id.
	 * @param string $key     The field key.
	 * @return mixed The effective value.
	 */
	public function value( string $section, string $key ): mixed;

}
