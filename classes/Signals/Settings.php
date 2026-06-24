<?php
/**
 * Resolver, sanitiser and settings section for the Content Signals module.
 *
 * Reads the three per-signal saved values, resolves the effective policy with a
 * developer filter override, sanitises a submitted settings slice, and builds the
 * custom "AI usage" settings section (three tri-state selects + inline warnings).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Signals;

use Kntnt\Ai_Visibility\Core\Settings\Section;

/**
 * Resolves and sanitises the content-signal policy and builds the settings section.
 *
 * @since 0.4.0
 */
final class Settings {

	/**
	 * The section id; namespaces the signals within the single option.
	 *
	 * @since 0.4.0
	 *
	 * @var string
	 */
	public const SECTION_ID = 'content_signals';

	/**
	 * The developer filter for the resolved policy.
	 *
	 * @since 0.4.0
	 *
	 * @var string
	 */
	public const FILTER = 'kntnt_ai_visibility_content_signals';

	/**
	 * The option key that stores all plugin settings.
	 *
	 * @since 0.4.0
	 *
	 * @var string
	 */
	private const OPTION_KEY = 'kntnt_ai_visibility';

	/**
	 * The canonical default state for each signal key.
	 *
	 * @since 0.4.0
	 *
	 * @var array<string, Signal_State>
	 */
	private const DEFAULTS = [
		'search'   => Signal_State::Defer,
		'ai_input' => Signal_State::Grant,
		'ai_train' => Signal_State::Defer,
	];

	/**
	 * Resolves the effective policy from saved settings, applying the developer filter.
	 *
	 * Reads the saved slice, coerces each key via Signal_State::tryFrom(), falling
	 * back to each signal's default. Then passes the three string values through the
	 * developer filter; a bad filter return falls back to that signal's default.
	 *
	 * @since 0.4.0
	 *
	 * @return Policy The fully resolved, filter-applied policy.
	 */
	public function policy(): Policy {

		// Read the saved slice; the section id namespaces signals within the option.
		$saved = get_option( self::OPTION_KEY, [] );
		$slice = [];
		if ( is_array( $saved ) && isset( $saved[ self::SECTION_ID ] ) && is_array( $saved[ self::SECTION_ID ] ) ) {
			$slice = $saved[ self::SECTION_ID ];
		}

		// Resolve each signal from saved data, falling back to the canonical default.
		$states = [];
		foreach ( self::DEFAULTS as $key => $default ) {
			$raw             = ( isset( $slice[ $key ] ) && is_string( $slice[ $key ] ) ) ? $slice[ $key ] : '';
			$states[ $key ]  = Signal_State::tryFrom( $raw ) ?? $default;
		}

		// Allow a developer filter to override the resolved values; re-validate
		// each value (a bad filter return falls back to that signal's default).
		$filtered = apply_filters(
			self::FILTER,
			[
				'search'   => $states['search']->value,
				'ai_input' => $states['ai_input']->value,
				'ai_train' => $states['ai_train']->value,
			],
		);

		if ( is_array( $filtered ) ) {
			foreach ( self::DEFAULTS as $key => $default ) {
				$raw            = ( isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] ) ) ? $filtered[ $key ] : '';
				$states[ $key ] = Signal_State::tryFrom( $raw ) ?? $default;
			}
		}

		return new Policy( $states['search'], $states['ai_input'], $states['ai_train'] );

	}

	/**
	 * Sanitises a submitted content_signals slice.
	 *
	 * Walks only the three known keys; injects or unknown keys are dropped. A
	 * missing or invalid value for a key falls back to that signal's canonical
	 * default state.
	 *
	 * @since 0.4.0
	 *
	 * @param mixed $input The raw $input[content_signals] slice from the submitted form.
	 * @return array<string, string> The clean slice: [ 'search' => …, 'ai_input' => …, 'ai_train' => … ].
	 */
	public function sanitize( mixed $input ): array {
		$clean = [];

		// Walk only the known signal keys; unknown keys are silently dropped.
		foreach ( self::DEFAULTS as $key => $default ) {
			$raw           = ( is_array( $input ) && isset( $input[ $key ] ) && is_string( $input[ $key ] ) ) ? $input[ $key ] : '';
			$state         = Signal_State::tryFrom( $raw ) ?? $default;
			$clean[ $key ] = $state->value;
		}

		return $clean;

	}

	/**
	 * Builds the custom "AI usage" settings section.
	 *
	 * The section renders three tri-state selects (one per signal) plus help text
	 * and inline warnings. Core hands the sanitise closure the whole
	 * $input[content_signals] slice and stores the return at $clean[content_signals].
	 *
	 * @since 0.4.0
	 *
	 * @return Section The settings section value object.
	 */
	public function section(): Section {

		// Bind $this to the closures so they share the resolver and sanitiser.
		$settings = $this;

		return new Section(
			self::SECTION_ID,
			static fn(): string => __( 'AI usage', 'kntnt-ai-visibility' ),
			render: function () use ( $settings ): void {
				$settings->render_section();
			},
			sanitize: fn( mixed $slice ): array => $this->sanitize( $slice ),
		);

	}

	/**
	 * Echoes the full "AI usage" section body.
	 *
	 * Draws the intro paragraph, three tri-state selects and their help texts,
	 * then any applicable inline warnings based on the currently effective policy.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	private function render_section(): void {

		// Resolve the current effective policy for the selected values and warnings.
		$policy = $this->policy();

		// Intro paragraph explaining the advisory nature of content signals.
		printf(
			'<p class="description">%s</p>',
			esc_html__(
				'These settings declare your AI-usage preferences as advisory Content-Signal directives in robots.txt. They are not enforced by law but signal your intent to AI crawlers and aggregators.',
				'kntnt-ai-visibility',
			),
		);

		// Render the form prefix used by all three selects.
		$prefix = sprintf( '%s[%s]', self::OPTION_KEY, self::SECTION_ID );

		// Render each signal as a labelled select with three options and help text.
		$this->render_signal(
			$prefix,
			'search',
			__( 'Search', 'kntnt-ai-visibility' ),
			$policy->search,
			__(
				'Controls whether your content appears in normal search results. Disabling may remove you from Google and other search engines.',
				'kntnt-ai-visibility',
			),
		);

		$this->render_signal(
			$prefix,
			'ai_input',
			__( 'AI input', 'kntnt-ai-visibility' ),
			$policy->ai_input,
			__(
				'Allows AI to use your content to answer questions — the core purpose of this plugin.',
				'kntnt-ai-visibility',
			),
		);

		$this->render_signal(
			$prefix,
			'ai_train',
			__( 'AI training', 'kntnt-ai-visibility' ),
			$policy->ai_train,
			__(
				'Defer leaves training to prevailing law and practice; in many jurisdictions that is effectively tacit permission. Allow grants it explicitly, Disallow reserves it explicitly.',
				'kntnt-ai-visibility',
			),
		);

		// Render inline warnings for any visibility-reducing or conflicting choices.
		$this->render_warnings( $policy );

	}

	/**
	 * Echoes one labelled tri-state select control with a help paragraph below it.
	 *
	 * @since 0.4.0
	 *
	 * @param string       $prefix  The form-name prefix (e.g. "kntnt_ai_visibility[content_signals]").
	 * @param string       $key     The signal option key (e.g. "search").
	 * @param string       $label   The human-readable signal label.
	 * @param Signal_State $current The currently effective state.
	 * @param string       $help    The per-signal help text.
	 * @return void
	 */
	private function render_signal(
		string $prefix,
		string $key,
		string $label,
		Signal_State $current,
		string $help,
	): void {

		// Each option: value => label.
		$options = [
			Signal_State::Grant->value   => __( 'Allow', 'kntnt-ai-visibility' ),
			Signal_State::Reserve->value => __( 'Disallow', 'kntnt-ai-visibility' ),
			Signal_State::Defer->value   => __( 'Defer to law and practice', 'kntnt-ai-visibility' ),
		];

		// Open the table row with the label and select.
		printf(
			'<tr><th scope="row">%s</th><td>',
			esc_html( $label ),
		);

		printf(
			'<select name="%s[%s]">',
			esc_attr( $prefix ),
			esc_attr( $key ),
		);

		// Render each option, marking the current state as selected.
		foreach ( $options as $value => $option_label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				$current->value === $value ? ' selected' : '',
				esc_html( $option_label ),
			);
		}

		echo '</select>';

		// Help text below the select.
		printf(
			'<p class="description">%s</p>',
			esc_html( $help ),
		);

		echo '</td></tr>';

	}

	/**
	 * Echoes inline warning notices for visibility-reducing or conflicting choices.
	 *
	 * These render as inline WordPress admin notice markup, self-contained — no
	 * dependency on settings_errors() or Core's render page.
	 *
	 * @since 0.4.0
	 *
	 * @param Policy $policy The currently effective policy.
	 * @return void
	 */
	private function render_warnings( Policy $policy ): void {

		// Strong warning: search=reserve may remove the site from search results
		// and the directive may be ignored; noindex is the authoritative signal.
		if ( $policy->search === Signal_State::Reserve ) {
			printf(
				'<div class="notice notice-error inline"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Warning:', 'kntnt-ai-visibility' ),
				esc_html__(
					'Setting Search to Disallow may remove your site from search results and may be ignored by crawlers. Use the noindex directive (via your SEO plugin or Settings → Reading) instead.',
					'kntnt-ai-visibility',
				),
			);
		}

		// Mild note: search=grant may conflict with a noindex set elsewhere.
		if ( $policy->search === Signal_State::Grant ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__(
					'Note: Allowing search via this directive may conflict with a noindex directive set elsewhere. The noindex directive takes precedence.',
					'kntnt-ai-visibility',
				),
			);
		}

		// Mild note: ai_input=reserve works against the plugin's core purpose.
		if ( $policy->ai_input === Signal_State::Reserve ) {
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html__(
					'Note: Disallowing AI input works against the purpose of this plugin, which is to make your content available to AI agents.',
					'kntnt-ai-visibility',
				),
			);
		}

	}

}
