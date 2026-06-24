<?php
/**
 * Unit tests for Signal_State enum.
 *
 * Pins the directive_value() contract: Grant maps to 'yes', Reserve to 'no',
 * and Defer to null (so the signal is omitted from the robots.txt output).
 *
 * @package Tests\Unit
 * @since   0.4.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Signals\Signal_State;

describe('Signal_State::directive_value', function (): void {

    it('returns yes for Grant', function (): void {
        expect(Signal_State::Grant->directive_value())->toBe('yes');
    });

    it('returns no for Reserve', function (): void {
        expect(Signal_State::Reserve->directive_value())->toBe('no');
    });

    it('returns null for Defer', function (): void {
        expect(Signal_State::Defer->directive_value())->toBeNull();
    });

});
