<?php
/**
 * Verify expiry through real materialisation and a controlled external clock.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Cache\Obsolete_Artifact;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Functions\when('wp_mkdir_p')->alias(static fn(string $path): bool => is_dir($path) || mkdir($path, 0700, true));
    $this->expiry_root = sys_get_temp_dir() . '/kntnt-expiry-' . uniqid();
    mkdir($this->expiry_root, 0700);
    $this->store = kntnt_test_file_store(fn(): string => $this->expiry_root . '/cache');
});

afterEach(function (): void {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->expiry_root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($this->expiry_root);
});

it('regenerates a real file older than the controlled sixty-second lifetime', function (): void {
    $identity = new Identity('markdown-alternate', 'controlled', 3);
    $this->store->write($identity, 'STALE');
    touch($this->store->path_for($identity), 2000000000);
    clearstatcache(true, $this->store->path_for($identity));
    $mtime = (int) filemtime($this->store->path_for($identity));
    $flight = new Single_Flight($this->store, $this->expiry_root, 60, static fn(): int => $mtime + 61);
    expect($flight->once($identity, static fn(): string => 'FRESH')->bytes)->toBe('FRESH');
});

it('applies the effective lifetime to page and aggregate materialisation', function (string $kind, int $age, int $ttl, string $expected): void {
    $identity = new Identity($kind, 'policy', 3);
    $this->store->write($identity, 'CACHED');
    touch($this->store->path_for($identity), 2000000000);
    clearstatcache(true, $this->store->path_for($identity));
    $mtime = (int) filemtime($this->store->path_for($identity));
    $clock = static fn(): int => $mtime + $age;
    $flight = new Single_Flight($this->store, $this->expiry_root, $ttl, $clock);
    if ($kind === 'markdown-alternate') {
        Functions\when('get_option')->justReturn('/%postname%/');
        $registry = new Artifact_Registry();
        $eligibility = new Eligibility(new Content_Matrix(), new Exclusions(static fn(): string => '', static fn(): string => 'https://example.test/'));
        $service = new Page_Markdown_Service(new Front_Matter(), $flight, new Plugin_Logger());
        $registry->register(new Page_Markdown_Provider($service, $eligibility, new Markdown_Alternate()));
        $router = new Serve_Router($this->store, $registry, ttl: $ttl, clock: $clock);
        expect($router->resolve(new Request('GET', '/policy.md')) !== null)->toBe($expected === 'CACHED');
    }
    $result = $flight->once($identity, static fn(): string => 'REGENERATED');
    expect($result->bytes)->toBe($expected);
    expect($result->persisted)->toBeTrue();
    expect($this->store->read($identity))->toBe($expected);
})->with(['markdown-alternate', 'llms-txt', 'llms-full'])->with([
    'before expiry' => [59, 60, 'CACHED'],
    'exact boundary stays fresh' => [60, 60, 'CACHED'],
    'after expiry' => [61, 60, 'REGENERATED'],
    'short override' => [45, 30, 'REGENERATED'],
    'long override' => [45, 60, 'CACHED'],
    'zero disables expiry' => [700000, 0, 'CACHED'],
    'negative disables expiry' => [700000, -1, 'CACHED'],
]);

it('refuses an expired regeneration revoked during production and recovers on a later request', function (): void {
    $identity = new Identity('markdown-alternate', 'revoked-expiry', 3);
    $this->store->write($identity, 'STALE');
    touch($this->store->path_for($identity), time() - 120);
    $flight = new Single_Flight($this->store, $this->expiry_root, 60);
    expect(fn() => $flight->once($identity, function () use ($identity): string {
        $this->store->delete($identity);
        return 'REVOKED';
    }))->toThrow(Obsolete_Artifact::class);
    expect($this->store->read($identity))->toBeNull();
    expect($flight->once($identity, static fn(): string => 'RECOVERED')->bytes)->toBe('RECOVERED');
});

it('rechecks an expired entry under flight after a successful or failed concurrent producer', function (bool $first_fails): void {
    $identity = new Identity('markdown-alternate', 'queued-expiry', 3);
    $this->store->write($identity, 'STALE');
    touch($this->store->path_for($identity), time() - 120);
    $project = dirname(__DIR__, 4);
    $first = new Process([PHP_BINARY, $project . '/tests/Integration/expiry-worker.php', $this->expiry_root . '/cache', $this->expiry_root, $first_fails ? 'first-fails' : 'first', kntnt_test_publication_directory()]);
    $queued = new Process([PHP_BINARY, $project . '/tests/Integration/expiry-worker.php', $this->expiry_root . '/cache', $this->expiry_root, 'queued', kntnt_test_publication_directory()]);
    $start = static function (Process $worker): void {
        $worker->start();
        $tracker = getenv('KNTNT_SESSION_CLEANUP_SCRIPT');
        if ($tracker !== false && $tracker !== '') {
            (new Process(['uv', 'run', $tracker, 'add', 'pid', (string) $worker->getPid(), 'issue 19 bounded expiry flight worker']))->mustRun();
        }
    };
    $wait = function (string $name): void {
        $deadline = microtime(true) + 5;
        while (!is_file($this->expiry_root . '/' . $name) && microtime(true) < $deadline) { usleep(10000); }
        expect(is_file($this->expiry_root . '/' . $name))->toBeTrue();
    };
    try {
        $start($first);
        $wait('first-producing');
        $start($queued);
        $wait('queued-selected');
        file_put_contents($this->expiry_root . '/release-first', 'release');
        $first->wait();
        $queued->wait();
        expect($first->getExitCode())->toBe(0, $first->getErrorOutput());
        expect($queued->getExitCode())->toBe(0, $queued->getErrorOutput());
        expect($first->getOutput())->toBe($first_fails ? 'PRODUCER-FAILED' : 'FRESH-FIRST');
        expect($queued->getOutput())->toBe($first_fails ? 'FRESH-QUEUED' : 'FRESH-FIRST');
        expect($this->store->read($identity))->toBe($first_fails ? 'FRESH-QUEUED' : 'FRESH-FIRST');
    } finally {
        $first->stop();
        $queued->stop();
    }
})->with(['fresh publication' => false, 'failed producer leaves expired file' => true]);
