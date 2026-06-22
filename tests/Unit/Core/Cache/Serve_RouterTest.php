<?php
/**
 * Adversarial unit tests for the early serve router's path resolution.
 *
 * The router is the hardened heart of the plugin: page-cache plugins have
 * shipped path-traversal / arbitrary-file-disclosure CVEs on exactly this
 * pattern. resolve() is the pure security function — it turns an untrusted
 * request into a safe, contained cache path or refuses. These tests come first
 * and are mandatory: every traversal, encoding, null-byte, backslash, absolute
 * and symlink-escape payload must resolve to null, and only a clean request to
 * an existing, contained, allowlisted cache file may resolve to a path.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;

beforeEach(function (): void {
    Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));

    // A real cache tree with one legitimate, contained cache file.
    $this->base = sys_get_temp_dir() . '/kntnt-router-' . uniqid('', true);
    mkdir($this->base . '/markdown-alternate/about', 0777, true);
    file_put_contents($this->base . '/markdown-alternate/about/team.md', "# Team\n");
    file_put_contents($this->base . '/markdown-alternate/index.md', "# Home\n");

    $store = new File_Store(fn(): string => $this->base);

    // A registry whose only allowlisted shape is the Markdown `.md` suffix.
    $registry = Mockery::mock(\Kntnt\Ai_Visibility\Core\Artifact\Registry::class);
    $registry->shouldReceive('serve_patterns')->andReturn([new Serve_Pattern('markdown-alternate', '.md')]);

    $this->router = new Serve_Router($store, $registry);
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
function kntnt_get(string $path): Request
{
    return new Request('GET', $path);
}

describe('Serve_Router::resolve — legitimate requests', function (): void {

    it('resolves a clean .md request to the contained cache file', function (): void {
        $path = $this->router->resolve(kntnt_get('/about/team.md'));

        // resolve() returns the canonical realpath; compare like for like.
        expect($path)->toBe(realpath($this->base . '/markdown-alternate/about/team.md'));
    });

    it('resolves /index.md to the home cache file', function (): void {
        $path = $this->router->resolve(kntnt_get('/index.md'));

        expect($path)->toBe(realpath($this->base . '/markdown-alternate/index.md'));
    });

    it('resolves a HEAD request like a GET', function (): void {
        $path = $this->router->resolve(new Request('HEAD', '/about/team.md'));

        expect($path)->toBe(realpath($this->base . '/markdown-alternate/about/team.md'));
    });

});

describe('Serve_Router::resolve — subdirectory install', function (): void {

    it('strips the home base path before deriving the key', function (): void {
        $store = new File_Store(fn(): string => $this->base);
        $registry = Mockery::mock(\Kntnt\Ai_Visibility\Core\Artifact\Registry::class);
        $registry->shouldReceive('serve_patterns')->andReturn([new Serve_Pattern('markdown-alternate', '.md')]);
        $router = new Serve_Router($store, $registry, null, 0, null, fn(): string => '/blog');

        expect($router->resolve(kntnt_get('/blog/about/team.md')))
            ->toBe(realpath($this->base . '/markdown-alternate/about/team.md'));
        expect($router->resolve(kntnt_get('/blog/index.md')))
            ->toBe(realpath($this->base . '/markdown-alternate/index.md'));
    });

    it('still rejects traversal once the base is stripped', function (): void {
        $store = new File_Store(fn(): string => $this->base);
        $registry = Mockery::mock(\Kntnt\Ai_Visibility\Core\Artifact\Registry::class);
        $registry->shouldReceive('serve_patterns')->andReturn([new Serve_Pattern('markdown-alternate', '.md')]);
        $router = new Serve_Router($store, $registry, null, 0, null, fn(): string => '/blog');

        expect($router->resolve(kntnt_get('/blog/../../../etc/passwd.md')))->toBeNull();
        expect($router->resolve(kntnt_get('/blog/about%2f..%2fsecret.md')))->toBeNull();
    });

});

describe('Serve_Router::resolve — refusals', function (): void {

    it('returns null on a cache miss for a clean but absent key', function (): void {
        expect($this->router->resolve(kntnt_get('/about/missing.md')))->toBeNull();
    });

    it('ignores non-.md paths', function (): void {
        expect($this->router->resolve(kntnt_get('/about/team/')))->toBeNull();
        expect($this->router->resolve(kntnt_get('/about/team')))->toBeNull();
    });

    it('rejects an uppercase .MD suffix', function (): void {
        expect($this->router->resolve(kntnt_get('/about/team.MD')))->toBeNull();
    });

    it('refuses non-GET/HEAD methods', function (): void {
        expect($this->router->resolve(new Request('POST', '/about/team.md')))->toBeNull();
        expect($this->router->resolve(new Request('DELETE', '/about/team.md')))->toBeNull();
    });

    it('serves nothing when no provider allowlists the shape', function (): void {
        $store = new File_Store(fn(): string => $this->base);
        $empty = Mockery::mock(\Kntnt\Ai_Visibility\Core\Artifact\Registry::class);
        $empty->shouldReceive('serve_patterns')->andReturn([]);
        $router = new Serve_Router($store, $empty);

        expect($router->resolve(kntnt_get('/about/team.md')))->toBeNull();
    });

    it('rejects every path-traversal payload', function (string $payload): void {
        expect($this->router->resolve(kntnt_get($payload)))->toBeNull();
    })->with([
        'parent traversal'          => '/../../../etc/passwd.md',
        'mid-path traversal'        => '/about/../../secret.md',
        'encoded dot traversal'     => '/%2e%2e/%2e%2e/etc/passwd.md',
        'encoded slash traversal'   => '/about%2f..%2fsecret.md',
        'backslash traversal'       => '/about\\..\\secret.md',
        'leading double slash'      => '//etc/passwd.md',
        'absolute-ish path'         => '/etc/passwd.md',
        'bare dotdot'               => '/...md',
        'empty key'                 => '/.md',
        'percent-encoded slug'      => '/l%C3%A4sa.md',
        'trailing dot segment'      => '/about/./team.md',
    ]);

    it('rejects a null byte in the path', function (): void {
        expect($this->router->resolve(kntnt_get("/about/team\0.md")))->toBeNull();
    });

    it('treats a cache file older than the TTL as a miss', function (): void {
        $store = new File_Store(fn(): string => $this->base);
        $registry = Mockery::mock(\Kntnt\Ai_Visibility\Core\Artifact\Registry::class);
        $registry->shouldReceive('serve_patterns')->andReturn([new Serve_Pattern('markdown-alternate', '.md')]);
        // A clock 200s ahead of the file, with a 100s TTL, makes the file stale.
        $mtime = (int) filemtime($this->base . '/markdown-alternate/about/team.md');
        $router = new Serve_Router($store, $registry, null, 100, fn(): int => $mtime + 200);

        expect($router->resolve(kntnt_get('/about/team.md')))->toBeNull();
    });

    it('serves a cache file within the TTL', function (): void {
        $store = new File_Store(fn(): string => $this->base);
        $registry = Mockery::mock(\Kntnt\Ai_Visibility\Core\Artifact\Registry::class);
        $registry->shouldReceive('serve_patterns')->andReturn([new Serve_Pattern('markdown-alternate', '.md')]);
        $mtime = (int) filemtime($this->base . '/markdown-alternate/about/team.md');
        $router = new Serve_Router($store, $registry, null, 100, fn(): int => $mtime + 50);

        expect($router->resolve(kntnt_get('/about/team.md')))->toBe(realpath($this->base . '/markdown-alternate/about/team.md'));
    });

    it('refuses a symlink that escapes the cache base', function (): void {
        // A secret outside the cache, reachable only via a symlink planted
        // inside it — realpath() must see through it and the router must refuse.
        $secret = sys_get_temp_dir() . '/kntnt-secret-' . uniqid('', true) . '.md';
        file_put_contents($secret, 'TOP SECRET');
        symlink($secret, $this->base . '/markdown-alternate/leak.md');

        $resolved = $this->router->resolve(kntnt_get('/leak.md'));

        unlink($secret);
        expect($resolved)->toBeNull();
    });

});
