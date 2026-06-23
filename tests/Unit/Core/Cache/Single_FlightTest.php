<?php
/**
 * Unit tests for the single-flight materialiser.
 *
 * once() is the lock-and-cache stampede guard shared by the per-page Markdown and
 * the O(site) llms aggregates (docs/spec/llms-txt.md §3.3): a cache hit returns
 * the bytes without producing; a miss holds a per-identity advisory lock outside
 * the cache tree, re-checks the cache, runs the producer, writes and returns.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;

/**
 * Recursively removes a directory tree.
 */
function kntnt_rmtree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($it as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    rmdir($dir);
}

beforeEach(function (): void {
    Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
    $this->base    = sys_get_temp_dir() . '/kntnt-sf-' . uniqid('', true);
    $this->lockdir = sys_get_temp_dir() . '/kntnt-sf-lock-' . uniqid('', true);
    mkdir($this->lockdir, 0777, true);
    $this->store = new File_Store(fn(): string => $this->base);
});

afterEach(function (): void {
    kntnt_rmtree($this->base);
    kntnt_rmtree($this->lockdir);
});

describe('Single_Flight::once', function (): void {

    it('produces, writes the cache and returns the bytes on a miss', function (): void {
        $identity = new Identity('markdown-alternate', 'hello', 1);
        $produced = false;
        $flight = new Single_Flight($this->store, $this->lockdir);

        $bytes = $flight->once($identity, function () use (&$produced): string {
            $produced = true;
            return 'BYTES';
        });

        expect($bytes)->toBe('BYTES');
        expect($produced)->toBeTrue();
        expect($this->store->read($identity))->toBe('BYTES');
        // The lock path is exercised: a per-identity lock file was created.
        expect(glob($this->lockdir . '/*.lock'))->not->toBeEmpty();
    });

    it('returns the cached bytes without producing on a hit', function (): void {
        $identity = new Identity('markdown-alternate', 'cached', 2);
        $this->store->write($identity, 'CACHED');
        $flight = new Single_Flight($this->store, $this->lockdir);

        $bytes = $flight->once($identity, static function (): string {
            throw new RuntimeException('producer must not run on a cache hit');
        });

        expect($bytes)->toBe('CACHED');
    });

    it('still produces and caches when the lock cannot be acquired', function (): void {
        $identity = new Identity('markdown-alternate', 'nolock', 9);
        $produced = false;
        $flight = new Single_Flight($this->store, $this->base . '/does-not-exist');
        $bytes = $flight->once($identity, function () use (&$produced): string {
            $produced = true;
            return 'BYTES';
        });
        expect($bytes)->toBe('BYTES');
        expect($produced)->toBeTrue();
        expect($this->store->read($identity))->toBe('BYTES');
    });

    it('creates a plugin-owned lock directory when none is injected', function (): void {
        $managed = sys_get_temp_dir() . '/kntnt-ai-visibility-locks';
        kntnt_rmtree($managed);
        $identity = new Identity('markdown-alternate', 'managed', 7);
        $flight = new Single_Flight($this->store);
        $bytes = $flight->once($identity, static fn(): string => 'BYTES');
        expect($bytes)->toBe('BYTES');
        expect(is_dir($managed))->toBeTrue();
        expect(glob($managed . '/*.lock'))->not->toBeEmpty();
        kntnt_rmtree($managed);
    });

});
