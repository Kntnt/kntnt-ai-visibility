<?php
/**
 * Unit tests for Signals\Robots_Decorator.
 *
 * Covers decorate(): non-public suppression, all-defer pass-through, the
 * default policy splice into an existing User-agent: * group, a multi-signal
 * policy, and the fallback group when no User-agent: * exists.
 *
 * @package Tests\Unit
 * @since   0.4.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Signals\Policy;
use Kntnt\Ai_Visibility\Signals\Robots_Decorator;
use Kntnt\Ai_Visibility\Signals\Signal_State;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Builds a decorator whose policy resolver always returns the given policy.
 */
function make_decorator(Policy $policy): Robots_Decorator
{
    return new Robots_Decorator(static fn(): Policy => $policy);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('Robots_Decorator::decorate', function (): void {

    it('returns output unchanged when the site is non-public', function (): void {
        $decorator = make_decorator(Policy::default());
        $output = "User-agent: *\nDisallow:\n";

        expect($decorator->decorate($output, false))->toBe($output);
    });

    it('returns output unchanged when every signal defers', function (): void {
        $all_defer = new Policy(Signal_State::Defer, Signal_State::Defer, Signal_State::Defer);
        $decorator = make_decorator($all_defer);
        $output = "User-agent: *\nDisallow:\n";

        expect($decorator->decorate($output, true))->toBe($output);
    });

    it('splices Content-Signal after User-agent: * and preserves existing lines', function (): void {
        // Default policy: only ai-input=yes.
        $decorator = make_decorator(Policy::default());
        $output = "User-agent: *\nDisallow: /wp-admin/\n";

        $result = $decorator->decorate($output, true);

        expect($result)->toContain('Content-Signal: ai-input=yes');
        expect($result)->toContain('Disallow: /wp-admin/');
        // The Content-Signal line must come between User-agent and Disallow.
        $ua_pos = strpos($result, 'User-agent: *');
        $cs_pos = strpos($result, 'Content-Signal:');
        $di_pos = strpos($result, 'Disallow: /wp-admin/');
        expect($ua_pos)->toBeLessThan($cs_pos);
        expect($cs_pos)->toBeLessThan($di_pos);
    });

    it('emits search=no, ai-input=yes, ai-train=no for a fully non-defer policy', function (): void {
        $policy = new Policy(Signal_State::Reserve, Signal_State::Grant, Signal_State::Reserve);
        $decorator = make_decorator($policy);
        $output = "User-agent: *\nDisallow:\n";

        $result = $decorator->decorate($output, true);

        expect($result)->toContain('Content-Signal: search=no, ai-input=yes, ai-train=no');
    });

    it('appends a fresh User-agent: * group when none exists in the output', function (): void {
        $decorator = make_decorator(Policy::default());
        $output = "# No user-agent line here\n";

        $result = $decorator->decorate($output, true);

        expect($result)->toContain('User-agent: *');
        expect($result)->toContain('Content-Signal: ai-input=yes');
    });

    it('registers on the robots_txt filter via register()', function (): void {
        $registered = [];
        Functions\when('add_filter')->alias(function (string $hook) use (&$registered): bool {
            $registered[] = $hook;
            return true;
        });

        make_decorator(Policy::default())->register();

        expect($registered)->toContain('robots_txt');
    });

});
