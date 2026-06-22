<?php
/**
 * Unit tests for conditional-request freshness.
 *
 * Both the early serve router and the Markdown request handler answer
 * conditional requests with 304, so the rule lives in one place: a matching (or
 * wildcard) If-None-Match is authoritative over the date; otherwise an
 * If-Modified-Since no older than the resource is fresh.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Kntnt\Ai_Visibility\Core\Http\Conditional_Request;

describe('Conditional_Request::is_fresh', function (): void {

    it('is fresh when If-None-Match equals the etag', function (): void {
        expect(Conditional_Request::is_fresh('"abc"', '', '"abc"', 1_700_000_000))->toBeTrue();
    });

    it('is fresh for a wildcard If-None-Match', function (): void {
        expect(Conditional_Request::is_fresh('*', '', '"abc"', 1_700_000_000))->toBeTrue();
    });

    it('is stale when If-None-Match differs', function (): void {
        expect(Conditional_Request::is_fresh('"old"', '', '"abc"', 1_700_000_000))->toBeFalse();
    });

    it('is fresh when If-Modified-Since is no older than the resource', function (): void {
        expect(Conditional_Request::is_fresh('', 'Tue, 14 Nov 2023 22:13:20 GMT', '"abc"', 1_700_000_000))->toBeTrue();
    });

    it('is stale when If-Modified-Since predates the resource', function (): void {
        expect(Conditional_Request::is_fresh('', 'Mon, 01 Jan 2001 00:00:00 GMT', '"abc"', 1_700_000_000))->toBeFalse();
    });

    it('prefers If-None-Match over If-Modified-Since', function (): void {
        // A non-matching ETag wins even when the date would say fresh.
        expect(Conditional_Request::is_fresh('"old"', 'Tue, 14 Nov 2023 22:13:20 GMT', '"abc"', 1_700_000_000))->toBeFalse();
    });

    it('is stale when no conditional headers are present', function (): void {
        expect(Conditional_Request::is_fresh('', '', '"abc"', 1_700_000_000))->toBeFalse();
    });

});
