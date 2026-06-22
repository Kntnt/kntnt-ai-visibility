<?php
/**
 * The settings registry: one option, one composed page.
 *
 * Modules register sections; this class composes them into a single
 * server-side options page backed by the single option `kntnt_ai_visibility`
 * (docs/adr/0010). Effective values resolve as saved-value → code-default →
 * developer-filter, so the plugin works zero-config and code can override any
 * field. Submitted input is sanitised field by field; unknown keys are dropped.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Settings;

/**
 * Composes module settings sections into one option and one admin page.
 *
 * @since 0.1.0
 */
final class Settings implements Registry {

	/**
	 * The settings group name register_setting() uses.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private string $group;

	/**
	 * The registered sections, keyed by section id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, Section>
	 */
	private array $sections = [];

	/**
	 * Configures the option name, page slug and page title.
	 *
	 * @since 0.1.0
	 *
	 * @param string $option_key The single option name and developer-filter prefix.
	 * @param string $page_slug  The admin page slug.
	 * @param string $page_title The admin page title.
	 */
	public function __construct(
		private readonly string $option_key = 'kntnt_ai_visibility',
		private readonly string $page_slug = 'kntnt-ai-visibility',
		private readonly string $page_title = 'AI Visibility',
	) {
		$this->group = $this->option_key . '_group';
	}

	/**
	 * Registers the admin hooks that build and persist the settings page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register_wp_settings' ] );
	}

	/**
	 * Stores a section under its id.
	 *
	 * @since 0.1.0
	 *
	 * @param Section $section The section to register.
	 * @return void
	 */
	public function register_section( Section $section ): void {
		$this->sections[ $section->id ] = $section;
	}

	/**
	 * Resolves a field's effective value: saved, else default, then filtered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section The section id.
	 * @param string $key     The field key.
	 * @return mixed The effective value, or null for an unknown field.
	 */
	public function value( string $section, string $key ): mixed {

		// Resolve the field; an unknown section or key has no value.
		$field = ( $this->sections[ $section ] ?? null )?->field( $key );
		if ( $field === null ) {
			return null;
		}

		// Take the saved value when present, otherwise the code default, then
		// let the developer filter override either.
		$stored = get_option( $this->option_key, [] );
		$raw = $field->default;
		if ( is_array( $stored ) && isset( $stored[ $section ] ) && is_array( $stored[ $section ] ) && array_key_exists( $key, $stored[ $section ] ) ) {
			$raw = $stored[ $section ][ $key ];
		}

		return apply_filters( "{$this->option_key}_{$section}_{$key}", $raw );

	}

	/**
	 * Sanitises submitted settings against the registered fields.
	 *
	 * Only registered sections and fields survive; every value passes through
	 * its field's sanitiser, and a missing field falls back to its default.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input The raw submitted option array.
	 * @return array<string, array<string, mixed>> The cleaned option array.
	 */
	public function sanitize( mixed $input ): array {

		// Walk the registered shape, not the submitted shape, so injected keys
		// and sections cannot leak into the stored option.
		$input = is_array( $input ) ? $input : [];
		$clean = [];
		foreach ( $this->sections as $section_id => $section ) {
			$section_input = isset( $input[ $section_id ] ) && is_array( $input[ $section_id ] ) ? $input[ $section_id ] : [];

			// A custom section owns its whole slice; hand it the section input and
			// store what it returns verbatim.
			if ( $section->sanitize !== null ) {
				$clean[ $section_id ] = ( $section->sanitize )( $section_input );
				continue;
			}

			// A field-based section is sanitised field by field, each missing field
			// falling back to its default.
			$section_clean = [];
			foreach ( $section->fields as $field ) {
				$submitted = $section_input[ $field->key ] ?? $field->default;
				$section_clean[ $field->key ] = ( $field->sanitize )( $submitted );
			}
			$clean[ $section_id ] = $section_clean;
		}

		return $clean;

	}

	/**
	 * Adds the options page under Settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_options_page(
			$this->page_title,
			$this->page_title,
			'manage_options',
			$this->page_slug,
			[ $this, 'render_page' ],
		);
	}

	/**
	 * Registers the option, sections and fields with the Settings API.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_wp_settings(): void {

		// Register the single option with this class as its sanitiser.
		register_setting(
			$this->group,
			$this->option_key,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [],
			],
		);

		// Compose one Settings-API section per registered module section. A custom
		// section renders its whole body through its own renderer and registers no
		// fields; a field-based section adds each field bound to its section and key.
		foreach ( $this->sections as $section_id => $section ) {
			$wp_section = "{$this->option_key}_{$section_id}";
			$render = $section->render;
			$callback = $render !== null ? static fn() => $render() : '__return_null';
			add_settings_section( $wp_section, $section->title, $callback, $this->page_slug );
			foreach ( $section->fields as $field ) {
				add_settings_field(
					"{$section_id}_{$field->key}",
					$field->label,
					fn() => $this->render_field( $section_id, $field ),
					$this->page_slug,
					$wp_section,
				);
			}
		}

	}

	/**
	 * Renders the settings page form.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render_page(): void {

		// Gate the page on capability, defence-in-depth behind the menu cap.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html( $this->page_title ) );
		echo '<form action="options.php" method="post">';
		settings_fields( $this->group );
		do_settings_sections( $this->page_slug );
		submit_button();
		echo '</form></div>';

	}

	/**
	 * Renders one field's control.
	 *
	 * Delegates to the field's custom renderer when it has one; otherwise
	 * renders a checkbox or a text input from the field type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $section_id The owning section id.
	 * @param Field  $field      The field to render.
	 * @return void
	 */
	private function render_field( string $section_id, Field $field ): void {

		$value = $this->value( $section_id, $field->key );
		$name = sprintf( '%s[%s][%s]', $this->option_key, $section_id, $field->key );

		// A custom renderer owns its markup; the registry steps aside.
		if ( $field->render !== null ) {
			( $field->render )( $value, $name );
			$this->render_description( $field );
			return;
		}

		// Built-in controls: a checkbox or a single-line text input.
		if ( $field->type === 'checkbox' ) {
			printf(
				'<input type="checkbox" name="%s" value="1" %s />',
				esc_attr( $name ),
				checked( (bool) $value, true, false ),
			);
		} else {
			printf(
				'<input type="text" class="regular-text" name="%s" value="%s" />',
				esc_attr( $name ),
				esc_attr( is_scalar( $value ) ? (string) $value : '' ),
			);
		}

		$this->render_description( $field );

	}

	/**
	 * Renders a field's help text, when it has any.
	 *
	 * @since 0.1.0
	 *
	 * @param Field $field The field.
	 * @return void
	 */
	private function render_description( Field $field ): void {

		// Only emit the paragraph when there is help text to show.
		if ( $field->description !== '' ) {
			printf( '<p class="description">%s</p>', esc_html( $field->description ) );
		}

	}

}
