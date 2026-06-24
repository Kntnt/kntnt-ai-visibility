<?php
/**
 * Unit tests for Signals\Settings.
 *
 * Covers sanitize() (key coercion, missing/invalid keys, injected keys) and
 * policy() (saved value overrides default, the developer filter overrides saved,
 * a malformed filter return falls back to the signal's default).
 *
 * @package Tests\Unit
 * @since   0.4.0
 */

declare(strict_types=1);

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Signals\Policy;
use Kntnt\Ai_Visibility\Signals\Settings;
use Kntnt\Ai_Visibility\Signals\Signal_State;

beforeEach(function (): void {
    Functions\when('__')->returnArg();
    Functions\when('esc_html')->returnArg();
    Functions\when('esc_attr')->returnArg();
    Functions\when('esc_html__')->returnArg();

    // Silence printf / echo during render tests.
    Functions\when('esc_html__')->returnArg();
});

// ---------------------------------------------------------------------------
// sanitize()
// ---------------------------------------------------------------------------

describe('Settings::sanitize', function (): void {

    it('coerces each known key to its Signal_State value', function (): void {
        $settings = new Settings();
        $clean = $settings->sanitize([
            'search'   => 'grant',
            'ai_input' => 'reserve',
            'ai_train' => 'defer',
        ]);

        expect($clean['search'])->toBe('grant');
        expect($clean['ai_input'])->toBe('reserve');
        expect($clean['ai_train'])->toBe('defer');
    });

    it('falls back to the default state when a key is missing', function (): void {
        $settings = new Settings();
        // No keys submitted — each should land on its canonical default.
        $clean = $settings->sanitize([]);

        // Defaults: search=defer, ai_input=grant, ai_train=defer.
        expect($clean['search'])->toBe('defer');
        expect($clean['ai_input'])->toBe('grant');
        expect($clean['ai_train'])->toBe('defer');
    });

    it('falls back to the default state when a value is invalid', function (): void {
        $settings = new Settings();
        $clean = $settings->sanitize([
            'search'   => 'INVALID',
            'ai_input' => '',
            'ai_train' => '1',
        ]);

        expect($clean['search'])->toBe('defer');
        expect($clean['ai_input'])->toBe('grant');
        expect($clean['ai_train'])->toBe('defer');
    });

    it('drops unknown injected keys', function (): void {
        $settings = new Settings();
        $clean = $settings->sanitize([
            'search'   => 'grant',
            'evil_key' => 'payload',
        ]);

        expect($clean)->not->toHaveKey('evil_key');
        expect($clean)->toHaveKey('search');
    });

    it('handles non-array input gracefully, returning all defaults', function (): void {
        $settings = new Settings();
        $clean = $settings->sanitize(null);

        expect($clean['search'])->toBe('defer');
        expect($clean['ai_input'])->toBe('grant');
        expect($clean['ai_train'])->toBe('defer');
    });

});

// ---------------------------------------------------------------------------
// policy()
// ---------------------------------------------------------------------------

describe('Settings::policy', function (): void {

    it('returns the default policy when no option is saved', function (): void {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->returnArg(2);

        $policy = (new Settings())->policy();

        expect($policy)->toBeInstanceOf(Policy::class);
        expect($policy->search)->toBe(Signal_State::Defer);
        expect($policy->ai_input)->toBe(Signal_State::Grant);
        expect($policy->ai_train)->toBe(Signal_State::Defer);
    });

    it('reads the saved values and overrides the defaults', function (): void {
        Functions\when('get_option')->justReturn([
            'content_signals' => [
                'search'   => 'reserve',
                'ai_input' => 'reserve',
                'ai_train' => 'grant',
            ],
        ]);
        Functions\when('apply_filters')->returnArg(2);

        $policy = (new Settings())->policy();

        expect($policy->search)->toBe(Signal_State::Reserve);
        expect($policy->ai_input)->toBe(Signal_State::Reserve);
        expect($policy->ai_train)->toBe(Signal_State::Grant);
    });

    it('applies the developer filter override', function (): void {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value): mixed {
                if ($hook === Settings::FILTER) {
                    return ['search' => 'grant', 'ai_input' => 'defer', 'ai_train' => 'reserve'];
                }
                return $value;
            },
        );

        $policy = (new Settings())->policy();

        expect($policy->search)->toBe(Signal_State::Grant);
        expect($policy->ai_input)->toBe(Signal_State::Defer);
        expect($policy->ai_train)->toBe(Signal_State::Reserve);
    });

    it('falls back to the default when the filter returns a malformed value', function (): void {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value): mixed {
                if ($hook === Settings::FILTER) {
                    // Return a non-array — the resolver must ignore it and keep defaults.
                    return 'not-an-array';
                }
                return $value;
            },
        );

        $policy = (new Settings())->policy();

        // No override: defaults remain.
        expect($policy->search)->toBe(Signal_State::Defer);
        expect($policy->ai_input)->toBe(Signal_State::Grant);
        expect($policy->ai_train)->toBe(Signal_State::Defer);
    });

    it('falls back per-key when the filter returns an array with bad values', function (): void {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->alias(
            static function (string $hook, mixed $value): mixed {
                if ($hook === Settings::FILTER) {
                    // Only search is valid; the other two are bad.
                    return ['search' => 'grant', 'ai_input' => 'NOPE', 'ai_train' => ''];
                }
                return $value;
            },
        );

        $policy = (new Settings())->policy();

        expect($policy->search)->toBe(Signal_State::Grant);
        expect($policy->ai_input)->toBe(Signal_State::Grant);  // default
        expect($policy->ai_train)->toBe(Signal_State::Defer);  // default
    });

});

// ---------------------------------------------------------------------------
// section() + render
// ---------------------------------------------------------------------------

describe('Settings::section', function (): void {

    it('builds a custom content_signals section with a sanitise closure', function (): void {
        $section = (new Settings())->section();

        expect($section->id)->toBe(Settings::SECTION_ID);
        expect($section->fields)->toBe([]);
        expect($section->sanitize)->not->toBeNull();
        expect($section->render)->not->toBeNull();
    });

    it('section sanitise closure delegates to sanitize()', function (): void {
        $section = (new Settings())->section();

        // Submitting a valid value should round-trip through the sanitiser.
        $clean = ($section->sanitize)(['search' => 'grant', 'ai_input' => 'reserve', 'ai_train' => 'defer']);

        expect($clean['search'])->toBe('grant');
        expect($clean['ai_input'])->toBe('reserve');
        expect($clean['ai_train'])->toBe('defer');
    });

    it('render closure emits the select controls for each signal', function (): void {
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->returnArg(2);

        $section = (new Settings())->section();

        ob_start();
        ($section->render)();
        $html = (string) ob_get_clean();

        // The three signal selects must be present.
        expect($html)->toContain('name="kntnt_ai_visibility[content_signals][search]"');
        expect($html)->toContain('name="kntnt_ai_visibility[content_signals][ai_input]"');
        expect($html)->toContain('name="kntnt_ai_visibility[content_signals][ai_train]"');

        // The three option values must be present.
        expect($html)->toContain('value="grant"');
        expect($html)->toContain('value="reserve"');
        expect($html)->toContain('value="defer"');
    });

    it('render closure shows the search=reserve error warning', function (): void {
        Functions\when('get_option')->justReturn([
            'content_signals' => ['search' => 'reserve', 'ai_input' => 'grant', 'ai_train' => 'defer'],
        ]);
        Functions\when('apply_filters')->returnArg(2);

        $section = (new Settings())->section();

        ob_start();
        ($section->render)();
        $html = (string) ob_get_clean();

        expect($html)->toContain('notice-error');
    });

    it('render closure shows the search=grant mild note', function (): void {
        Functions\when('get_option')->justReturn([
            'content_signals' => ['search' => 'grant', 'ai_input' => 'grant', 'ai_train' => 'defer'],
        ]);
        Functions\when('apply_filters')->returnArg(2);

        $section = (new Settings())->section();

        ob_start();
        ($section->render)();
        $html = (string) ob_get_clean();

        expect($html)->toContain('notice-warning');
    });

    it('render closure shows the ai_input=reserve mild note', function (): void {
        Functions\when('get_option')->justReturn([
            'content_signals' => ['search' => 'defer', 'ai_input' => 'reserve', 'ai_train' => 'defer'],
        ]);
        Functions\when('apply_filters')->returnArg(2);

        $section = (new Settings())->section();

        ob_start();
        ($section->render)();
        $html = (string) ob_get_clean();

        expect($html)->toContain('notice-warning');
    });

    it('render closure emits no warnings for the all-defer policy', function (): void {
        Functions\when('get_option')->justReturn([
            'content_signals' => ['search' => 'defer', 'ai_input' => 'defer', 'ai_train' => 'defer'],
        ]);
        Functions\when('apply_filters')->returnArg(2);

        $section = (new Settings())->section();

        ob_start();
        ($section->render)();
        $html = (string) ob_get_clean();

        expect($html)->not->toContain('notice-error');
        expect($html)->not->toContain('notice-warning');
    });

});
