<?php
/**
 * The resolved, site-wide content-signal policy.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Signals;

/**
 * The three content signals' resolved states.
 *
 * @since 0.4.0
 */
final readonly class Policy {

	/**
	 * Constructs a resolved policy from the three signal states.
	 *
	 * @since 0.4.0
	 *
	 * @param Signal_State $search   The search-indexing signal.
	 * @param Signal_State $ai_input The AI-answer-input signal.
	 * @param Signal_State $ai_train The AI-training signal.
	 */
	public function __construct(
		public Signal_State $search,
		public Signal_State $ai_input,
		public Signal_State $ai_train,
	) {}

	/**
	 * The zero-config default: search=defer, ai-input=grant, ai-train=defer.
	 *
	 * @since 0.4.0
	 *
	 * @return self
	 */
	public static function default(): self {
		return new self( Signal_State::Defer, Signal_State::Grant, Signal_State::Defer );
	}

	/**
	 * The signals to emit, as [ directive-name => 'yes'|'no' ], canonical order,
	 * omitting deferred signals (so an all-defer policy returns []).
	 *
	 * @since 0.4.0
	 *
	 * @return array<string, string>
	 */
	public function directives(): array {
		$out = [];

		// Walk the three signals in canonical order; omit any that defer.
		foreach ( [
			'search'   => $this->search,
			'ai-input' => $this->ai_input,
			'ai-train' => $this->ai_train,
		] as $name => $state ) {
			$value = $state->directive_value();
			if ( $value !== null ) {
				$out[ $name ] = $value;
			}
		}

		return $out;
	}

}
