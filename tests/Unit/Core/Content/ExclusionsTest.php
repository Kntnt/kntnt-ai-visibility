<?php
/**
 * Unit tests for the path-exclusion gate.
 *
 * Exclusions decides whether a post's home-relative path matches any configured
 * pattern. Patterns are written without delimiters or flags; the service wraps
 * each in `#…#iu`, matches against the rawurldecoded, home-relative path only
 * (never the host), and is inert on a pattern that fails to compile. The pure
 * path test and the static helpers are pinned here; the permalink-driven
 * is_excluded() wrapper and the developer filter are covered too.
 *
 * @package Tests\Unit
 * @since   0.5.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;

/**
 * Builds an Exclusions over the given pattern text and home URL.
 */
function kntnt_make_exclusions(string $text, string $home = 'https://example.test'): Exclusions
{
    return new Exclusions(static fn(): string => $text, static fn(): string => $home);
}

beforeEach(function (): void {
    Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
    Functions\when('wp_parse_url')->alias(fn(string $url, int $component) => parse_url($url, $component));
});

describe('Exclusions static helpers', function (): void {

    it('splits text into trimmed, non-empty lines across newline conventions', function (): void {
        expect(Exclusions::split_patterns("  /a/  \r\n\n/b/\n   "))->toBe(['/a/', '/b/']);
    });

    it('wraps a pattern body in the hash delimiter with Unicode and case flags', function (): void {
        expect(Exclusions::compile('/cookiepolicy/'))->toBe('#/cookiepolicy/#iu');
    });

    it('accepts a valid pattern body and rejects a malformed one', function (): void {
        expect(Exclusions::is_valid('/cookiepolicy/'))->toBeTrue();
        expect(Exclusions::is_valid('('))->toBeFalse();
    });

});

describe('Exclusions::path_excluded', function (): void {

    it('matches a substring pattern against the path', function (): void {
        expect(kntnt_make_exclusions('/cookiepolicy/')->path_excluded('/cookiepolicy/'))->toBeTrue();
    });

    it('does not match an unrelated path', function (): void {
        expect(kntnt_make_exclusions('/cookiepolicy/')->path_excluded('/about/'))->toBeFalse();
    });

    it('matches case-insensitively', function (): void {
        expect(kntnt_make_exclusions('/cookiepolicy/')->path_excluded('/CookiePolicy/'))->toBeTrue();
    });

    it('honours a start anchor against the path', function (): void {
        $exclusions = kntnt_make_exclusions('^/auto/');
        expect($exclusions->path_excluded('/auto/page/'))->toBeTrue();
        expect($exclusions->path_excluded('/news/auto/'))->toBeFalse();
    });

    it('matches a non-ASCII slug in its decoded form', function (): void {
        expect(kntnt_make_exclusions('öppettider')->path_excluded('/%C3%B6ppettider/'))->toBeTrue();
    });

    it('never matches the host, only the path', function (): void {
        expect(kntnt_make_exclusions('example\.test')->path_excluded('/cookiepolicy/'))->toBeFalse();
    });

    it('strips the install base path so a root pattern works on a subdirectory install', function (): void {
        $exclusions = kntnt_make_exclusions('^/cookiepolicy/', 'https://example.test/blog');
        expect($exclusions->path_excluded('/blog/cookiepolicy/'))->toBeTrue();
    });

    it('matches nothing when no pattern is configured', function (): void {
        expect(kntnt_make_exclusions('')->path_excluded('/anything/'))->toBeFalse();
    });

    it('drops an invalid pattern instead of failing at match time', function (): void {
        expect(kntnt_make_exclusions('(')->path_excluded('/anything/'))->toBeFalse();
    });

    it('lets a later valid pattern match even when an earlier one is invalid', function (): void {
        expect(kntnt_make_exclusions("(\n/cookiepolicy/")->path_excluded('/cookiepolicy/'))->toBeTrue();
    });

});

describe('Exclusions::is_excluded', function (): void {

    it('excludes a post whose permalink path matches', function (): void {
        Functions\when('get_permalink')->justReturn('https://example.test/cookiepolicy/');

        expect(kntnt_make_exclusions('/cookiepolicy/')->is_excluded(new WP_Post()))->toBeTrue();
    });

    it('does not resolve the permalink when no pattern is configured', function (): void {
        Functions\when('get_permalink')->alias(function (): never {
            throw new RuntimeException('get_permalink must not be called for an empty pattern set');
        });

        expect(kntnt_make_exclusions('')->is_excluded(new WP_Post()))->toBeFalse();
    });

    it('lets the is_excluded filter force a verdict', function (): void {
        Functions\when('get_permalink')->justReturn('https://example.test/about/');
        Functions\when('apply_filters')->alias(
            fn(string $hook, mixed $value): mixed => $hook === 'kntnt_ai_visibility_is_excluded' ? true : $value,
        );

        expect(kntnt_make_exclusions('/cookiepolicy/')->is_excluded(new WP_Post()))->toBeTrue();
    });

});
