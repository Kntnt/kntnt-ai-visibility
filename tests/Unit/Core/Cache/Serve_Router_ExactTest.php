<?php
/**
 * Adversarial unit tests for the early serve router's exact-path matching.
 *
 * Release 2 adds exact-path singletons (`/llms.txt`, `/llms-full.txt`) alongside
 * the `.md` suffix match (docs/spec/llms-txt.md §3.4). The new mode must not
 * weaken the security model: the cache key is the pattern's fixed base key plus
 * the cache-version stamp — never any byte of the URL — and the SAFE_KEY
 * whitelist and realpath containment still apply. These adversarial fixtures come
 * first and are mandatory: every traversal, encoding, null-byte, case-mismatch,
 * suffix-collision, trailing-slash and symlink-escape payload against the new
 * paths must resolve to null (fall through) or a contained file, never outside
 * the cache base.
 *
 * @package Tests\Unit
 * @since   0.2.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;

/**
 * Builds a registry exposing the `.md` suffix shape and the `/llms.txt` exact shape.
 */
function kntnt_exact_registry(): object
{
    $registry = Mockery::mock(\Kntnt\Ai_Visibility\Core\Artifact\Registry::class);
    $registry->shouldReceive('serve_patterns')->andReturn([
        Serve_Pattern::suffix('markdown-alternate', '.md'),
        Serve_Pattern::exact('llms-txt', '/llms.txt', 'llms'),
    ]);

    return $registry;
}

beforeEach(function (): void {
    Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));

    // A real cache tree with the version-stamped llms.txt aggregate at v8.
    $this->base = sys_get_temp_dir() . '/kntnt-exact-' . uniqid('', true);
    mkdir($this->base . '/llms-txt', 0777, true);
    file_put_contents($this->base . '/llms-txt/llms-v8.md', "# Site\n");
    mkdir($this->base . '/markdown-alternate/about', 0777, true);
    file_put_contents($this->base . '/markdown-alternate/about/team.md', "# Team\n");

    $this->store = new File_Store(fn(): string => $this->base);
    // Cache version 8 → the early router computes the key 'llms-v8'.
    $this->router = new Serve_Router($this->store, kntnt_exact_registry(), null, 0, null, null, fn(): int => 8);
});

afterEach(function (): void {
    if (is_dir($this->base)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        rmdir($this->base);
    }
});

/**
 * Builds a GET request for the given path.
 */
function kntnt_exact_get(string $path): Request
{
    return new Request('GET', $path);
}

describe('Serve_Router::resolve — exact-path singletons', function (): void {

    it('resolves /llms.txt to the version-stamped cache file', function (): void {
        $resolved = $this->router->resolve(kntnt_exact_get('/llms.txt'));

        expect($resolved)->not->toBeNull();
        expect($resolved->path)->toBe(realpath($this->base . '/llms-txt/llms-v8.md'));
    });

    it('carries the pattern so the serve picks plain-text and no canonical', function (): void {
        $resolved = $this->router->resolve(kntnt_exact_get('/llms.txt'));

        expect($resolved->pattern->content_type)->toBe('text/plain; charset=utf-8');
        expect($resolved->pattern->canonical)->toBeFalse();
    });

    it('misses when the cache-version stamp points at an absent file', function (): void {
        // Cache version 7 → key 'llms-v7' → no file → fall through to PHP.
        $router = new Serve_Router($this->store, kntnt_exact_registry(), null, 0, null, null, fn(): int => 7);

        expect($router->resolve(kntnt_exact_get('/llms.txt')))->toBeNull();
    });

    it('reads the cache version only when an exact-versioned path matches', function (): void {
        // A cache-version callback that explodes proves it is never consulted on an
        // ordinary .md request — only an exact-versioned match may read it.
        $boom = function (): int {
            throw new RuntimeException('cache version must not be read on an ordinary request');
        };
        $router = new Serve_Router($this->store, kntnt_exact_registry(), null, 0, null, null, $boom);

        expect($router->resolve(kntnt_exact_get('/about/team.md'))?->path)
            ->toBe(realpath($this->base . '/markdown-alternate/about/team.md'));
    });

});

describe('Serve_Router::resolve — exact-path adversarial fixtures', function (): void {

    it('falls through or contains every traversal and evasion payload', function (string $payload): void {
        $resolved = $this->router->resolve(kntnt_exact_get($payload));

        // Either a clean fall-through, or — if it resolved — a path strictly inside
        // the cache base. Never a path outside it.
        if ($resolved !== null) {
            expect($resolved->path)->toStartWith(realpath($this->base) . DIRECTORY_SEPARATOR);
        } else {
            expect($resolved)->toBeNull();
        }
    })->with([
        'parent traversal'        => '/llms.txt/../../etc/passwd',
        'encoded null byte'       => '/llms.txt%00',
        'encoded slash traversal' => '/llms.txt%2f..',
        'prefixed path'           => '/xllms.txt',
        'md suffix collision'     => '/llms.txt.md',
        'uppercase path'          => '/LLMS.TXT',
        'mixed case path'         => '/Llms.Txt',
        'trailing slash'          => '/llms.txt/',
        'double leading slash'    => '//llms.txt',
        'query-like suffix'       => '/llms.txt?foo=bar',
    ]);

    it('rejects an actual null byte in the exact path', function (): void {
        expect($this->router->resolve(kntnt_exact_get("/llms.txt\0")))->toBeNull();
    });

    it('refuses a symlinked aggregate that escapes the cache base', function (): void {
        // The version-stamped file is a symlink to a secret outside the cache;
        // realpath containment must see through it and refuse.
        $secret = sys_get_temp_dir() . '/kntnt-llms-secret-' . uniqid('', true) . '.md';
        file_put_contents($secret, 'TOP SECRET');
        unlink($this->base . '/llms-txt/llms-v8.md');
        symlink($secret, $this->base . '/llms-txt/llms-v8.md');

        $resolved = $this->router->resolve(kntnt_exact_get('/llms.txt'));

        unlink($secret);
        expect($resolved)->toBeNull();
    });

    it('resolves /sub/llms.txt on a subdirectory install, still contained', function (): void {
        $router = new Serve_Router($this->store, kntnt_exact_registry(), null, 0, null, fn(): string => '/sub', fn(): int => 8);

        expect($router->resolve(kntnt_exact_get('/sub/llms.txt'))?->path)
            ->toBe(realpath($this->base . '/llms-txt/llms-v8.md'));
    });

});
