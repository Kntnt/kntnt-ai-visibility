<?php
/**
 * Unit tests for the single-flight materialiser.
 *
 * once() is the lock-and-cache stampede guard shared by the per-page Markdown and
 * the O(site) llms aggregates (docs/spec/llms-txt.md §3.3): a cache hit returns
 * the bytes without producing; a miss holds a per-family advisory lock outside
 * the cache tree, re-checks the cache, runs the producer, writes and returns.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Materialisation;
use Kntnt\Ai_Visibility\Core\Cache\Obsolete_Artifact;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Symfony\Component\Process\Process;

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
    $this->store = kntnt_test_file_store(fn(): string => $this->base);
});

afterEach(function (): void {
    kntnt_rmtree($this->base);
    kntnt_rmtree($this->lockdir);
});

it('preserves unrelated locks and cleans its resources when the single-flight suite runs', function (): void {

    // Emulate an installation's shared locks inside a disposable system root.
    $temporary_root = $this->base . '/system-temp';
    $shared = $temporary_root . '/kntnt-ai-visibility-locks';
    mkdir($shared, 0700, true);
    $sentinel = $shared . '/unrelated.lock';
    file_put_contents($sentinel, 'ACTIVE');

    // Limit the child to the functional tests so this regression cannot recurse.
    $project = dirname(__DIR__, 4);
    $suite = new Process([
        PHP_BINARY,
        '-d',
        'sys_temp_dir=' . $temporary_root,
        $project . '/vendor/bin/pest',
        __FILE__,
        '--filter=Single_Flight::once',
        '--no-coverage',
        '--colors=never',
        '--do-not-cache-result',
    ], $project);
    $suite->run();

    // Observe only filesystem resources owned by the fake installation/suite.
    expect($suite->getExitCode())->toBe(0, $suite->getOutput() . $suite->getErrorOutput());
    expect(is_file($sentinel))->toBeTrue();
    expect(file_get_contents($sentinel))->toBe('ACTIVE');
    expect(glob($temporary_root . '/kntnt-sf-*'))->toBe([]);

});

describe('Single_Flight::once', function (): void {

    it('keeps one owner through a waiter and a third contender even when writes are unavailable', function (): void {
        file_put_contents($this->base, 'actual cache obstruction');
        $project = dirname(__DIR__, 4);
        $workers = [];
        foreach (['first', 'second', 'third'] as $role) {
            $workers[$role] = new Process([
                PHP_BINARY, $project . '/tests/Integration/stampede-worker.php',
                $this->base, $this->lockdir, kntnt_test_publication_directory(), $role,
            ]);
        }
        $wait = static function (string $path): void {
            $deadline = microtime(true) + 3;
            while (!is_file($path) && microtime(true) < $deadline) { usleep(10000); }
            expect(is_file($path))->toBeTrue('Missing public producer/validation signal: ' . $path);
        };
        try {
            $workers['first']->start();
            $wait($this->lockdir . '/started-first');
            $workers['second']->start();
            $wait($this->lockdir . '/queued-second');
            expect(is_file($this->lockdir . '/started-second'))->toBeFalse();
            file_put_contents($this->lockdir . '/release-first', 'release');
            $workers['first']->wait();
            $wait($this->lockdir . '/started-second');
            $workers['third']->start();
            $wait($this->lockdir . '/queued-third');
            usleep(150000);
            expect(is_file($this->lockdir . '/started-third'))->toBeFalse();
            file_put_contents($this->lockdir . '/release-second', 'release');
            foreach ($workers as $role => $worker) {
                $worker->wait();
                expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
                expect(json_decode($worker->getOutput(), true))->toBe(['bytes' => strtoupper($role), 'persisted' => false]);
            }
            expect(is_file($this->lockdir . '/overlap'))->toBeFalse();
            expect(glob($this->lockdir . '/*.lock'))->toHaveCount(1);
        } finally {
            foreach ($workers as $worker) { $worker->stop(); }
            if (is_file($this->base)) { unlink($this->base); }
        }
    });

    it('lets another installation generate while the same family is busy elsewhere', function (): void {
        $otherBase = $this->base . '-other-installation';
        $arguments = [PHP_BINARY, dirname(__DIR__, 4) . '/tests/Integration/stampede-worker.php'];
        $first = new Process([...$arguments, $this->base, $this->lockdir, kntnt_test_publication_directory(), 'first']);
        $other = new Process([...$arguments, $otherBase, $this->lockdir, kntnt_test_publication_directory(), 'third']);
        $other->setTimeout(1.5);
        try {
            $first->start();
            $deadline = microtime(true) + 3;
            while (!is_file($this->lockdir . '/started-first') && microtime(true) < $deadline) { usleep(10000); }
            expect(is_file($this->lockdir . '/started-first'))->toBeTrue();
            $other->run();
            expect($other->getExitCode())->toBe(0, $other->getErrorOutput());
            expect(json_decode($other->getOutput(), true))->toBe(['bytes' => 'THIRD', 'persisted' => true]);
            expect($first->isRunning())->toBeTrue();
            file_put_contents($this->lockdir . '/release-first', 'release');
            $first->wait();
            expect($first->getExitCode())->toBe(0, $first->getErrorOutput());
            expect(is_file($this->lockdir . '/overlap'))->toBeFalse();
        } finally {
            $first->stop();
            $other->stop();
            kntnt_rmtree($otherBase);
        }
    });

    it('completes nested generation by separate same-store objects without deadlock', function (): void {
        $worker = new Process([
            PHP_BINARY, dirname(__DIR__, 4) . '/tests/Integration/stampede-worker.php',
            $this->base, $this->lockdir, kntnt_test_publication_directory(), 'nested',
        ]);
        $worker->setTimeout(1.5);
        try {
            $worker->run();
            expect($worker->getExitCode())->toBe(0, $worker->getErrorOutput());
            expect(json_decode($worker->getOutput(), true))->toBe(['bytes' => 'OUTER-INNER', 'persisted' => true]);
            expect($this->store->read(new Identity('markdown-alternate', 'inner', 2)))->toBe('INNER');
            expect($this->store->read(new Identity('markdown-alternate', 'outer', 1)))->toBe('OUTER-INNER');
        } finally {
            $worker->stop();
        }
    });

    it('isolates installations even when their injected coordination root is shared', function (): void {
        $otherBase = $this->base . '-other-installation';
        $other = kntnt_test_file_store(static fn(): string => $otherBase);
        $identity = new Identity('llms-txt', 'llms-v1');
        try {
            expect((new Single_Flight($this->store, $this->lockdir))->once($identity, fn(): string => 'ONE')->bytes)->toBe('ONE');
            expect((new Single_Flight($other, $this->lockdir))->once($identity, fn(): string => 'TWO')->bytes)->toBe('TWO');
            expect($this->store->read($identity))->toBe('ONE');
            expect($other->read($identity))->toBe('TWO');
            expect(glob($this->lockdir . '/*.lock'))->toHaveCount(2);
        } finally {
            kntnt_rmtree($otherBase);
        }
    });

    it('bounds historical aggregate locks while retaining only the current document', function (): void {
        $flight = new Single_Flight($this->store, $this->lockdir);
        $previous = null;
        for ($version = 1; $version <= 40; ++$version) {
            $identity = new Identity('llms-txt', 'llms-v' . $version);
            $result = $flight->once($identity, static fn(): string => 'CURRENT-' . $version);
            expect($result->bytes)->toBe('CURRENT-' . $version);
            if ($previous !== null) { $this->store->delete($previous); }
            $previous = $identity;
        }
        expect(glob($this->base . '/llms-txt/*.md'))->toHaveCount(1);
        expect(glob($this->lockdir . '/*.lock'))->toHaveCount(1);
    });


    it('rejects active and queued obsolete processes before a fresh writer fills the identity', function (): void {
        $project = dirname(__DIR__, 4);
        $first = new Process([PHP_BINARY, $project . '/tests/Integration/publication-worker.php', $this->base, $this->lockdir, 'first', kntnt_test_publication_directory()]);
        $queued = new Process([PHP_BINARY, $project . '/tests/Integration/publication-worker.php', $this->base, $this->lockdir, 'queued', kntnt_test_publication_directory()]);
        $wait = static function (string $path): void {
            $deadline = microtime(true) + 3;
            while (!is_file($path) && microtime(true) < $deadline) { usleep(10000); }
            expect(is_file($path))->toBeTrue();
        };
        try {
            $first->start();
            $wait($this->lockdir . '/first-ready');
            $queued->start();
            $wait($this->lockdir . '/queued-ready');
            // The public guard signal proves its old generation was captured.
            expect($queued->isRunning())->toBeTrue();
            $identity = new Identity('markdown-alternate', 'concurrent', 3);
            $this->store->delete($identity);
            file_put_contents($this->lockdir . '/release-first', 'release');
            $first->wait();
            $queued->wait();
            expect($first->getExitCode())->toBe(0, $first->getErrorOutput());
            expect($queued->getExitCode())->toBe(0, $queued->getErrorOutput());
            expect($first->getOutput())->toBe('REFUSED');
            expect($queued->getOutput())->toBe('REFUSED');
            expect($this->store->read($identity))->toBeNull();
            $flight = new Single_Flight($this->store, $this->lockdir);
            expect($flight->once($identity, static fn(): string => 'CURRENT')->bytes)->toBe('CURRENT');
        } finally {
            $first->stop();
            $queued->stop();
        }
    });

    it('refuses bytes revoked during generation instead of treating them as a storage outage', function (): void {
        $identity = new Identity('markdown-alternate', 'revoked', 3);
        $flight = new Single_Flight($this->store, $this->lockdir);
        $other_store = kntnt_test_file_store(fn(): string => $this->base);
        expect(fn() => $flight->once($identity, static function () use ($other_store, $identity): string {
            $other_store->delete($identity);
            return 'REVOKED';
        }))->toThrow(Obsolete_Artifact::class);
        expect($this->store->read($identity))->toBeNull();
        expect($flight->once($identity, static fn(): string => 'CURRENT')->bytes)->toBe('CURRENT');
    });

    it('does not return a warm file to a queued source whose validation fails', function (): void {
        $identity = new Identity('markdown-alternate', 'warm', 3);
        $this->store->write($identity, 'CURRENT');
        $flight = new Single_Flight($this->store, $this->lockdir);
        expect(fn() => $flight->once($identity, static fn(): string => 'OBSOLETE', static function (): void {
            throw new Obsolete_Artifact('Queued source is stale');
        }))->toThrow(Obsolete_Artifact::class);
        expect($this->store->read($identity))->toBe('CURRENT');
    });

    it('rejects reentrant same-store revocation during the publication operation without deadlocking', function (): void {
        $identity = new Identity('markdown-alternate', 'obstructed', 3);
        mkdir($this->base);
        mkdir($this->base . '/markdown-alternate');
        file_put_contents($this->base . '/markdown-alternate/obstructed', 'directory obstruction');
        Functions\when('wp_json_encode')->alias('json_encode');
        $other_store = kntnt_test_file_store(fn(): string => $this->base);
        $logger = new Plugin_Logger(static function () use ($other_store): void { $other_store->flush_all(); });
        $store = kntnt_test_file_store(fn(): string => $this->base, $logger);
        $flight = new Single_Flight($store, $this->lockdir);
        set_error_handler(static fn(): bool => true);
        try {
            expect(fn() => $flight->once(new Identity('markdown-alternate', 'obstructed/child', 3), static fn(): string => 'REVOKED'))
                ->toThrow(Obsolete_Artifact::class);
        } finally {
            restore_error_handler();
        }
        expect($store->read($identity))->toBeNull();
    });

    it('returns valid generated bytes separately from unavailable persistence', function (): void {
        mkdir($this->base);
        file_put_contents($this->base . '/obstructed', 'cache obstruction');
        Functions\when('wp_json_encode')->alias('json_encode');
        $store = kntnt_test_file_store(fn(): string => $this->base . '/obstructed', new Plugin_Logger(static function (): void {}));
        $flight = new Single_Flight($store, $this->lockdir);
        set_error_handler(static fn(): bool => true);
        try {
            $result = $flight->once(new Identity('markdown-alternate', 'fresh', 3), static fn(): string => 'FRESH');
        } finally {
            restore_error_handler();
        }

        expect($result)->toBeInstanceOf(Materialisation::class);
        expect($result->bytes)->toBe('FRESH');
        expect($result->persisted)->toBeFalse();
    });

    it('regenerates an expired file instead of resurrecting it after a router miss', function (): void {
        $identity = new Identity('markdown-alternate', 'expired', 3);
        $this->store->write($identity, 'STALE');
        touch($this->store->path_for($identity), time() - 120);
        $flight = new Single_Flight($this->store, $this->lockdir, 60);
        expect($flight->once($identity, static fn(): string => 'FRESH')->bytes)->toBe('FRESH');
    });

    it('produces, writes the cache and returns the bytes on a miss', function (): void {
        $identity = new Identity('markdown-alternate', 'hello', 1);
        $produced = false;
        $flight = new Single_Flight($this->store, $this->lockdir);

        $bytes = $flight->once($identity, function () use (&$produced): string {
            $produced = true;
            return 'BYTES';
        });

        expect($bytes->bytes)->toBe('BYTES');
        expect($bytes->persisted)->toBeTrue();
        expect($produced)->toBeTrue();
        expect($this->store->read($identity))->toBe('BYTES');
        // The lock path is exercised: a stable family lock file was created.
        expect(glob($this->lockdir . '/*.lock'))->not->toBeEmpty();
    });

    it('returns the cached bytes without producing on a hit', function (): void {
        $identity = new Identity('markdown-alternate', 'cached', 2);
        $this->store->write($identity, 'CACHED');
        $flight = new Single_Flight($this->store, $this->lockdir);

        $bytes = $flight->once($identity, static function (): string {
            throw new RuntimeException('producer must not run on a cache hit');
        });

        expect($bytes->bytes)->toBe('CACHED');
        expect($bytes->persisted)->toBeTrue();
    });

    it('degrades silently when its default directory cannot be created', function (): void {
        Functions\when('wp_json_encode')->alias('json_encode');
        $obstruction = $this->lockdir . '/system-temp-obstruction';
        file_put_contents($obstruction, 'ordinary file');
        $temporaryRoot = \Patchwork\redefine('sys_get_temp_dir', static fn(): string => $obstruction);
        $lines = [];
        $logger = new Plugin_Logger(static function (string $line) use (&$lines): void { $lines[] = $line; });
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if (error_reporting() & $severity) { $warnings[] = $message; }
            return true;
        });
        try {
            $result = (new Single_Flight($this->store, logger: $logger))->once(
                new Identity('llms-txt', 'llms-v1'), fn(): string => 'VALID CURRENT',
            );
            expect($result->bytes)->toBe('VALID CURRENT');
            expect($result->persisted)->toBeFalse();
            expect($warnings)->toBeEmpty();
            expect(implode("\n", $lines))->toContain('Single-flight lock unavailable', 'mkdir');
        } finally {
            restore_error_handler();
            \Patchwork\restore($temporaryRoot);
        }
    });

    it('never treats a real failed flock as successful ownership', function (): void {
        Functions\when('wp_json_encode')->alias('json_encode');
        $lines = [];
        $logger = new Plugin_Logger(static function (string $line) use (&$lines): void { $lines[] = $line; });
        // PHP's actual temporary-memory stream opens successfully but cannot flock.
        $flight = new Single_Flight($this->store, 'php://temp', logger: $logger);
        $identity = new Identity('markdown-alternate', 'unlocked-stream', 1);
        $result = $flight->once($identity, static fn(): string => 'VALID CURRENT');
        expect($result->bytes)->toBe('VALID CURRENT');
        expect($result->persisted)->toBeFalse();
        expect($this->store->read($identity))->toBeNull();
        expect(implode("\n", $lines))->toContain('Single-flight lock unavailable', 'flock');
    });

    it('returns valid uncached bytes and controlled diagnostics when lock storage is unavailable', function (): void {
        Functions\when('wp_json_encode')->alias('json_encode');
        $identity = new Identity('markdown-alternate', 'nolock', 9);
        $diagnostic = $this->lockdir . '/diagnostics.log';
        $previousLog = ini_set('error_log', $diagnostic);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if (error_reporting() & $severity) { $warnings[] = $message; }
            return true;
        });
        try {
            $flight = new Single_Flight($this->store, $this->base . '/does-not-exist');
            $result = $flight->once($identity, static fn(): string => 'VALID CURRENT');
        } finally {
            restore_error_handler();
            ini_set('error_log', $previousLog);
        }
        expect($result->bytes)->toBe('VALID CURRENT');
        expect($result->persisted)->toBeFalse();
        expect($this->store->read($identity))->toBeNull();
        expect($warnings)->toBeEmpty();
        expect(file_get_contents($diagnostic))->toContain('Single-flight lock unavailable', 'open');
    });

    it('creates a plugin-owned lock directory when none is injected', function (): void {

        // Keep the default path inside this test's root, including on failure.
        $temporary_root = $this->base . '/system-temp';
        mkdir($temporary_root, 0700, true);
        $temporary_root_mock = \Patchwork\redefine('sys_get_temp_dir', static fn(): string => $temporary_root);
        $managed = $temporary_root . '/kntnt-ai-visibility-locks-' . hash('sha256', $this->base);
        $identity = new Identity('markdown-alternate', 'managed', 7);

        // Construction stays lazy; the first miss creates the owned directory.
        try {
            $flight = new Single_Flight($this->store);
            expect(is_dir($managed))->toBeFalse();
            $bytes = $flight->once($identity, static fn(): string => 'BYTES');
            expect($bytes->bytes)->toBe('BYTES');
            expect($bytes->persisted)->toBeTrue();
            expect(is_dir($managed))->toBeTrue();
            expect(glob($managed . '/*.lock'))->not->toBeEmpty();
        } finally {
            \Patchwork\restore($temporary_root_mock);
        }

    });

});
