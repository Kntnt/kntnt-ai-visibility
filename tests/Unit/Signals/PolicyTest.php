<?php
/**
 * Unit tests for the Policy value object.
 *
 * Pins Policy::default() (search=defer, ai-input=grant, ai-train=defer) and
 * Policy::directives(), which must emit only non-deferred signals in canonical
 * order (search, ai-input, ai-train).
 *
 * @package Tests\Unit
 * @since   0.4.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Signals\Policy;
use Kntnt\Ai_Visibility\Signals\Signal_State;

describe('Policy::default', function (): void {

    it('returns search=defer, ai-input=grant, ai-train=defer', function (): void {
        $policy = Policy::default();

        expect($policy->search)->toBe(Signal_State::Defer);
        expect($policy->ai_input)->toBe(Signal_State::Grant);
        expect($policy->ai_train)->toBe(Signal_State::Defer);
    });

});

describe('Policy::directives', function (): void {

    it('omits deferred signals — default policy yields only ai-input=yes', function (): void {
        $directives = Policy::default()->directives();

        expect($directives)->toBe(['ai-input' => 'yes']);
    });

    it('returns all three entries in canonical order when all are granted', function (): void {
        $policy = new Policy(Signal_State::Grant, Signal_State::Grant, Signal_State::Grant);

        expect($policy->directives())->toBe([
            'search'   => 'yes',
            'ai-input' => 'yes',
            'ai-train' => 'yes',
        ]);
    });

    it('returns an empty array when every signal defers', function (): void {
        $policy = new Policy(Signal_State::Defer, Signal_State::Defer, Signal_State::Defer);

        expect($policy->directives())->toBe([]);
    });

    it('preserves canonical order in a mixed policy', function (): void {
        $policy = new Policy(Signal_State::Reserve, Signal_State::Grant, Signal_State::Reserve);
        $directives = $policy->directives();

        expect(array_keys($directives))->toBe(['search', 'ai-input', 'ai-train']);
        expect($directives['search'])->toBe('no');
        expect($directives['ai-input'])->toBe('yes');
        expect($directives['ai-train'])->toBe('no');
    });

});
