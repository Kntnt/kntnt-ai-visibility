<?php
/**
 * Unit tests for the cache-version stamp.
 *
 * The stamp lives in its own option key (per docs/adr/0010) and records cache
 * generations. Indirect changes bump it; uninstall clears it. The value is
 * always at least 1.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;

describe('Cache_Version', function (): void {

    it('reads the current version, defaulting to 1', function (): void {
        Functions\when('get_option')->justReturn(1);

        expect((new Cache_Version())->current())->toBe(1);
    });

    it('never reports a version below 1', function (): void {
        Functions\when('get_option')->justReturn(0);

        expect((new Cache_Version())->current())->toBe(1);
    });

    it('increments the stored version on bump', function (): void {
        Functions\when('get_option')->justReturn(3);
        Functions\expect('update_option')
            ->once()
            ->with('kntnt_ai_visibility_cache_version', 4, false);

        (new Cache_Version())->bump();
    });

});
