<?php
/**
 * Unit tests for the path-exclusion settings section.
 *
 * The section is field-based: a single textarea whose sanitiser keeps only the
 * lines that compile to a valid regular expression and reports the rest as a
 * settings error. A change to the stored patterns turns the cache over (version
 * bump plus flush) so the exclusion applies on the next request, while a save
 * that leaves the patterns untouched does not.
 *
 * @package Tests\Unit
 * @since   0.5.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Content\Exclusion_Settings;
use Kntnt\Ai_Visibility\Core\Settings\Field;
use Kntnt\Ai_Visibility\Core\Settings\Section;

/**
 * Builds an Exclusion_Settings over mock cache collaborators.
 */
function kntnt_exclusion_settings(?Store $store = null, ?Cache_Version $version = null): Exclusion_Settings
{
    return new Exclusion_Settings(
        $store ?? Mockery::mock(Store::class),
        $version ?? Mockery::mock(Cache_Version::class),
    );
}

beforeEach(function (): void {
    Functions\when('__')->returnArg();
    Functions\when('esc_attr')->returnArg();
    Functions\when('esc_textarea')->returnArg();
});

describe('Exclusion_Settings::section', function (): void {

    it('builds a field-based exclusions section with one path-patterns field', function (): void {
        $section = kntnt_exclusion_settings()->section();

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

describe('Exclusion_Settings cache turnover', function (): void {

    it('bumps and flushes when an update changed the patterns', function (): void {
        $store = Mockery::mock(Store::class);
        $version = Mockery::mock(Cache_Version::class);
        $store->shouldReceive('flush_all')->once();
        $version->shouldReceive('bump')->once();

        kntnt_exclusion_settings($store, $version)->on_option_update(
            ['exclusions' => ['paths' => '']],
            ['exclusions' => ['paths' => '/cookiepolicy/']],
        );
    });

    it('does not flush when an update left the patterns unchanged', function (): void {
        $store = Mockery::mock(Store::class);
        $version = Mockery::mock(Cache_Version::class);
        $store->shouldNotReceive('flush_all');
        $version->shouldNotReceive('bump');

        kntnt_exclusion_settings($store, $version)->on_option_update(
            ['exclusions' => ['paths' => '/cookiepolicy/'], 'content_types' => ['post' => ['md' => false]]],
            ['exclusions' => ['paths' => '/cookiepolicy/'], 'content_types' => ['post' => ['md' => true]]],
        );
    });

    it('bumps and flushes when the first save already carries patterns', function (): void {
        $store = Mockery::mock(Store::class);
        $version = Mockery::mock(Cache_Version::class);
        $store->shouldReceive('flush_all')->once();
        $version->shouldReceive('bump')->once();

        kntnt_exclusion_settings($store, $version)->on_option_add(
            'kntnt_ai_visibility',
            ['exclusions' => ['paths' => '/cookiepolicy/']],
        );
    });

    it('does not flush when the first save carries no patterns', function (): void {
        $store = Mockery::mock(Store::class);
        $version = Mockery::mock(Cache_Version::class);
        $store->shouldNotReceive('flush_all');
        $version->shouldNotReceive('bump');

        kntnt_exclusion_settings($store, $version)->on_option_add(
            'kntnt_ai_visibility',
            ['exclusions' => ['paths' => '']],
        );
    });

});
