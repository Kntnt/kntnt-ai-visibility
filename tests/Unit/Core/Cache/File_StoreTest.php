<?php
/**
 * Unit tests for the file-backed cache store.
 *
 * The store maps an Identity to a contained path under an isolated, Core-owned
 * directory and reads, writes, deletes and flushes cache files there. It writes
 * an index.html guard so the directory cannot be listed, and creates nested
 * directories for slash-bearing keys. These tests run against a real temporary
 * directory so the filesystem behaviour is genuinely exercised.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;

beforeEach(function (): void {
    // The store creates directories through wp_mkdir_p(); off WordPress, make it
    // a real recursive mkdir so the filesystem behaviour is genuinely tested.
    Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));

    $this->base = sys_get_temp_dir() . '/kntnt-store-' . uniqid('', true);
    $this->store = new File_Store(fn(): string => $this->base);
});

afterEach(function (): void {
    // Remove the temporary cache tree the test created.
    if (is_dir($this->base)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->base);
    } elseif (is_file($this->base)) {
        unlink($this->base);
    }
});

describe('File_Store', function (): void {

    it('derives a contained path under base/kind/key.md', function (): void {
        $path = $this->store->path_for(new Identity('markdown-alternate', 'about/team', 7));

        expect($path)->toBe($this->base . '/markdown-alternate/about/team.md');
    });

    it('round-trips bytes through write and read', function (): void {
        $id = new Identity('markdown-alternate', 'hello-world', 1);

        expect($this->store->has($id))->toBeFalse();
        expect($this->store->read($id))->toBeNull();

        expect($this->store->write($id, "# Hello\n"))->toBeTrue();

        expect($this->store->has($id))->toBeTrue();
        expect($this->store->read($id))->toBe("# Hello\n");
    });

    it('reports an obstructed cache path through the logger without emitting warnings', function (): void {
        file_put_contents($this->base, 'ordinary file obstructs the cache');
        Functions\when('wp_json_encode')->alias('json_encode');
        $lines = [];
        $logger = new Plugin_Logger(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $store = new File_Store(fn(): string => $this->base, $logger);

        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if (error_reporting() & $severity) {
                $warnings[] = $message;
            }
            return true;
        });
        try {
            $written = $store->write(new Identity('markdown-alternate', 'about', 1), '# About');
        } finally {
            restore_error_handler();
        }

        expect($warnings)->toBeEmpty();
        expect($written)->toBeFalse();
        expect($lines)->toHaveCount(1);
        expect(str_replace('\\/', '/', $lines[0]))->toContain('[Kntnt AI Visibility] [WARNING]', 'Cache write failed', $this->base);
        expect(file_get_contents($this->base))->toBe('ordinary file obstructs the cache');
    });

    it('removes its temporary file when atomic publication fails', function (): void {
        Functions\when('wp_json_encode')->alias('json_encode');
        $lines = [];
        $logger = new Plugin_Logger(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $store = new File_Store(fn(): string => $this->base, $logger);
        $identity = new Identity('markdown-alternate', 'occupied', 1);
        mkdir($store->path_for($identity), 0777, true);
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            if (error_reporting() & $severity) {
                $warnings[] = $message;
            }
            return true;
        });
        try {
            $written = $store->write($identity, '# Occupied');
        } finally {
            restore_error_handler();
        }

        expect($written)->toBeFalse();
        expect($warnings)->toBeEmpty();
        expect(glob(dirname($store->path_for($identity)) . '/*.tmp'))->toBe([]);
        expect($store->read($identity))->toBeNull();
        expect($lines)->toHaveCount(1);
        expect($lines[0])->toContain('Cache write failed', 'rename');
    });

    it('creates nested directories for slash-bearing keys', function (): void {
        $id = new Identity('markdown-alternate', 'a/b/c', 1);

        $this->store->write($id, 'deep');

        expect(is_file($this->base . '/markdown-alternate/a/b/c.md'))->toBeTrue();
        expect($this->store->read($id))->toBe('deep');
    });

    it('writes an index.html guard against directory listing', function (): void {
        $this->store->write(new Identity('markdown-alternate', 'x', 1), 'x');

        expect(is_file($this->base . '/index.html'))->toBeTrue();
    });

    it('deletes a single cache file without touching others', function (): void {
        $keep = new Identity('markdown-alternate', 'keep', 1);
        $drop = new Identity('markdown-alternate', 'drop', 2);
        $this->store->write($keep, 'keep');
        $this->store->write($drop, 'drop');

        $this->store->delete($drop);

        expect($this->store->has($drop))->toBeFalse();
        expect($this->store->has($keep))->toBeTrue();
    });

    it('flushes the entire cache directory', function (): void {
        $this->store->write(new Identity('markdown-alternate', 'a', 1), 'a');
        $this->store->write(new Identity('markdown-alternate', 'b/c', 2), 'bc');

        $this->store->flush_all();

        expect(is_dir($this->base))->toBeFalse();
    });

    it('treats delete of an absent file as a no-op', function (): void {
        $this->store->delete(new Identity('markdown-alternate', 'ghost', 99));

        expect(true)->toBeTrue();
    });

    it('prunes stale siblings in the kind directory but keeps the current key', function (): void {
        $this->store->write(new Identity('llms-txt', 'llms-v7', 0), 'old');
        $this->store->write(new Identity('llms-txt', 'llms-v8', 0), 'new');

        $this->store->prune_siblings(new Identity('llms-txt', 'llms-v8', 0));

        expect(is_file($this->base . '/llms-txt/llms-v7.md'))->toBeFalse();
        expect(is_file($this->base . '/llms-txt/llms-v8.md'))->toBeTrue();
    });

    it('never touches the per-page markdown-alternate files when pruning an aggregate', function (): void {
        $this->store->write(new Identity('markdown-alternate', 'about', 1), 'about');
        $this->store->write(new Identity('markdown-alternate', 'a/b/c', 2), 'nested');
        $this->store->write(new Identity('llms-txt', 'llms-v7', 0), 'old');
        $this->store->write(new Identity('llms-txt', 'llms-v8', 0), 'new');

        $this->store->prune_siblings(new Identity('llms-txt', 'llms-v8', 0));

        expect(is_file($this->base . '/markdown-alternate/about.md'))->toBeTrue();
        expect(is_file($this->base . '/markdown-alternate/a/b/c.md'))->toBeTrue();
        expect(is_file($this->base . '/llms-txt/llms-v7.md'))->toBeFalse();
    });

    it('treats prune of an absent kind directory as a no-op', function (): void {
        $this->store->prune_siblings(new Identity('llms-full', 'llms-full-v1', 0));

        expect(true)->toBeTrue();
    });

    it('refuses to prune outside the cache base for a traversing kind', function (): void {
        // A sentinel sibling of the cache base; a '..' kind resolves to the base's
        // parent, which fails the containment check, so the sentinel must survive.
        $sentinel = dirname($this->base) . '/kntnt-prune-sentinel-' . uniqid('', true) . '.md';
        file_put_contents($sentinel, 'keep me');
        $this->store->write(new Identity('llms-txt', 'llms-v8', 0), 'new');

        $this->store->prune_siblings(new Identity('..', 'whatever', 0));

        expect(is_file($sentinel))->toBeTrue();
        unlink($sentinel);
    });

});
