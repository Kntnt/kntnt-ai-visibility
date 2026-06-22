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

        $this->store->write($id, "# Hello\n");

        expect($this->store->has($id))->toBeTrue();
        expect($this->store->read($id))->toBe("# Hello\n");
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

});
