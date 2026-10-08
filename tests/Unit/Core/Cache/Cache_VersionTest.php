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
use Kntnt\Ai_Visibility\Core\Cache\Obsolete_Artifact;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Tests\Helpers\Version_Database;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->previous_database = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new Version_Database();
    Functions\when('wp_cache_delete')->justReturn(true);
});

afterEach(function (): void {
    $GLOBALS['wpdb'] = $this->previous_database;
});

describe('Cache_Version', function (): void {

    it('refuses an unavailable database lookup instead of selecting generation one', function (): void {
        $GLOBALS['wpdb']->query("INSERT INTO options VALUES ('kntnt_ai_visibility_cache_version', '2', 'off')");
        $GLOBALS['wpdb']->query('DROP TABLE options');
        expect(fn() => (new Cache_Version())->current())->toThrow(Obsolete_Artifact::class);
    });

    it('removes public artifacts under the shared barrier when version advancement fails', function (): void {
        Functions\when('wp_mkdir_p')->alias(static fn(string $path): bool => is_dir($path) || mkdir($path, 0700, true));
        $base = kntnt_test_publication_directory() . '/failed-advance-cache';
        $store = kntnt_test_file_store(static fn(): string => $base);
        $identity = new Identity('llms-txt', 'llms-v1');
        $page = new Identity('markdown-alternate', 'revoked-source', 3);
        $store->write($identity, 'REVOKED-INDEX');
        $store->write($page, 'REVOKED-PAGE');
        try {
            $GLOBALS['wpdb']->query('DROP TABLE options');
            expect(fn() => (new Cache_Version($store))->bump())->toThrow(Obsolete_Artifact::class);
            // Database recovery must not make the old generation readable again.
            $GLOBALS['wpdb'] = new Version_Database();
            expect((new Cache_Version())->current())->toBe(1);
            expect($store->read($identity))->toBeNull();
            expect($store->read($page))->toBeNull();
        } finally {
            $store->flush_all();
        }
    });

    it('reads the current version, defaulting to 1', function (): void {
        expect((new Cache_Version())->current())->toBe(1);
    });

    it('never reports a version below 1', function (): void {
        $GLOBALS['wpdb']->query("INSERT INTO options VALUES ('kntnt_ai_visibility_cache_version', '0', 'off')");

        expect((new Cache_Version())->current())->toBe(1);
    });

    it('increments the stored version on bump', function (): void {
        $GLOBALS['wpdb']->query("INSERT INTO options VALUES ('kntnt_ai_visibility_cache_version', '3', 'off')");
        (new Cache_Version())->bump();
        expect((new Cache_Version())->current())->toBe(4);
    });

    it('preserves every concurrent invalidation and ignores cached option values', function (): void {
        $path = tempnam(sys_get_temp_dir(), 'kntnt-version-');
        $GLOBALS['wpdb'] = new Version_Database($path);
        Functions\when('get_option')->justReturn(1);
        $project = dirname(__DIR__, 4);
        $code = <<<'PHP'
require $argv[1] . '/tests/bootstrap.php';
function wp_cache_delete(...$arguments) { return true; }
$GLOBALS['wpdb'] = new Tests\Helpers\Version_Database($argv[2]);
$version = new Kntnt\Ai_Visibility\Core\Cache\Cache_Version();
for ($i = 0; $i < 25; ++$i) { $version->bump(); }
PHP;
        $workers = [];
        try {
            for ($i = 0; $i < 8; ++$i) {
                $worker = new Process([PHP_BINARY, '-r', $code, $project, $path]);
                $worker->start();
                $workers[] = $worker;
            }
            foreach ($workers as $worker) {
                $worker->wait();
                expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
            }
            expect((new Cache_Version())->current())->toBe(201);
        } finally {
            foreach ($workers as $worker) { $worker->stop(); }
            unlink($path);
        }
    });

});
