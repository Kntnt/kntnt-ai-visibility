<?php
/**
 * Unit tests for the path-exclusion settings section.
 *
 * The section is field-based: a single textarea whose sanitiser keeps only the
 * lines that compile to a valid regular expression and reports the rest as a
 * settings error. Cache turnover belongs to the unified Core exposure-option
 * lifecycle observer, exercised through real WordPress hooks over HTTP.
 *
 * @package Tests\Unit
 * @since   0.5.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Content\Exclusion_Settings;
use Kntnt\Ai_Visibility\Core\Settings\Field;
use Kntnt\Ai_Visibility\Core\Settings\Section;

beforeEach(function (): void {
    Functions\when('__')->returnArg();
    Functions\when('esc_attr')->returnArg();
    Functions\when('esc_textarea')->returnArg();
});

describe('Exclusion_Settings::section', function (): void {

    it('builds a field-based exclusions section with one path-patterns field', function (): void {
        $section = (new Exclusion_Settings())->section();

        expect($section)->toBeInstanceOf(Section::class);
        expect($section->id)->toBe(Exclusion_Settings::SECTION_ID);
        expect($section->sanitize)->toBeNull();
        expect($section->field(Exclusion_Settings::FIELD_KEY))->toBeInstanceOf(Field::class);
    });

});

describe('Exclusion_Settings::sanitize_patterns', function (): void {

    it('keeps the valid lines, joined by newlines', function (): void {
        Functions\expect('add_settings_error')->never();

        expect(Exclusion_Settings::sanitize_patterns("/cookiepolicy/\n  ^/auto/  \n"))->toBe("/cookiepolicy/\n^/auto/");
    });

    it('drops an invalid line and reports it as a settings error', function (): void {
        Functions\expect('add_settings_error')->once();

        expect(Exclusion_Settings::sanitize_patterns("/keep/\n("))->toBe('/keep/');
    });

    it('reports no error when every line is valid', function (): void {
        Functions\expect('add_settings_error')->never();

        expect(Exclusion_Settings::sanitize_patterns('/keep/'))->toBe('/keep/');
    });

    it('coerces a non-string value to an empty list', function (): void {
        Functions\expect('add_settings_error')->never();

        expect(Exclusion_Settings::sanitize_patterns(['not', 'a', 'string']))->toBe('');
    });

});
