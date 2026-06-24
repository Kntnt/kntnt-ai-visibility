<?php
/**
 * One content signal's declared state.
 *
 * The string backing is the stored and filtered form; directive_value() maps it
 * to the robots.txt token, returning null for Defer (the signal is then omitted).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Signals;

/**
 * The tri-state of a single content signal.
 *
 * @since 0.4.0
 */
enum Signal_State: string {

	case Grant   = 'grant';
	case Reserve = 'reserve';
	case Defer   = 'defer';

	/**
	 * The robots.txt directive value, or null when the signal is omitted.
	 *
	 * @since 0.4.0
	 *
	 * @return string|null 'yes' for Grant, 'no' for Reserve, null for Defer.
	 */
	public function directive_value(): ?string {
		return match ( $this ) {
			self::Grant   => 'yes',
			self::Reserve => 'no',
			self::Defer   => null,
		};
	}

}
