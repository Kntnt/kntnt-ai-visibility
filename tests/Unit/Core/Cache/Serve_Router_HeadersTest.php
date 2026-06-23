<?php
/**
 * Unit tests for the serve router's response-header computation.
 *
 * headers_for() is pure given a file and a request: it returns the status, the
 * header map and whether to send a body. The tests pin the 200 shape, the
 * canonical back-link, conditional 304s by ETag and by modified time, and the
 * bodyless HEAD response.
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

    $this->base = sys_get_temp_dir() . '/kntnt-headers-' . uniqid('', true);
    mkdir($this->base . '/markdown-alternate', 0777, true);
    $this->file = $this->base . '/markdown-alternate/about.md';
    file_put_contents($this->file, "# About\n");
    touch($this->file, 1_700_000_000);
    clearstatcache(true, $this->file);

    $store = new File_Store(fn(): string => $this->base);
    $registry = Mockery::mock(\Kntnt\Ai_Visibility\Core\Artifact\Registry::class);
    $registry->shouldReceive('serve_patterns')->andReturn([Serve_Pattern::suffix('markdown-alternate', '.md')]);
    $this->router = new Serve_Router($store, $registry);

    // Derive validators from the file as written, so the assertions do not
    // depend on the filesystem's touch() precision.
    $this->mtime = (int) filemtime($this->file);
    $this->last_modified = gmdate('D, d M Y H:i:s', $this->mtime) . ' GMT';
    $this->etag = '"' . md5_file($this->file) . '"';
});

afterEach(function (): void {
    if (is_dir($this->base)) {
        array_map('unlink', glob($this->base . '/markdown-alternate/*') ?: []);
        rmdir($this->base . '/markdown-alternate');
        rmdir($this->base);
    }
});

describe('Serve_Router::headers_for', function (): void {

    it('builds a 200 with content type, length, etag, last-modified and nosniff', function (): void {
        $response = $this->router->headers_for($this->file, new Request('GET', '/about.md'));

        expect($response['status'])->toBe(200);
        expect($response['send_body'])->toBeTrue();
        expect($response['headers']['Content-Type'])->toBe('text/markdown; charset=utf-8');
        expect($response['headers']['Content-Length'])->toBe((string) filesize($this->file));
        expect($response['headers']['ETag'])->toBe($this->etag);
        expect($response['headers']['X-Content-Type-Options'])->toBe('nosniff');
        expect($response['headers']['Last-Modified'])->toBe($this->last_modified);
    });

    it('adds a canonical back-link when given a canonical URL', function (): void {
        $response = $this->router->headers_for($this->file, new Request('GET', '/about.md'), 'text/markdown; charset=utf-8', 'https://example.com/about/');

        expect($response['headers']['Link'])->toBe('<https://example.com/about/>; rel="canonical"');
    });

    it('serves the Content-Type the matched pattern declares', function (): void {
        $response = $this->router->headers_for($this->file, new Request('GET', '/llms.txt'), 'text/plain; charset=utf-8');

        expect($response['headers']['Content-Type'])->toBe('text/plain; charset=utf-8');
    });

    it('omits the body for a HEAD request', function (): void {
        $response = $this->router->headers_for($this->file, new Request('HEAD', '/about.md'));

        expect($response['status'])->toBe(200);
        expect($response['send_body'])->toBeFalse();
        expect($response['headers']['Content-Length'])->toBe((string) filesize($this->file));
    });

    it('answers a matching If-None-Match with a bodyless 304', function (): void {
        $request = new Request('GET', '/about.md', if_none_match: $this->etag);

        $response = $this->router->headers_for($this->file, $request);

        expect($response['status'])->toBe(304);
        expect($response['send_body'])->toBeFalse();
        expect($response['headers'])->not->toHaveKey('Content-Length');
    });

    it('answers a wildcard If-None-Match with a 304', function (): void {
        $response = $this->router->headers_for($this->file, new Request('GET', '/about.md', if_none_match: '*'));

        expect($response['status'])->toBe(304);
    });

    it('answers If-Modified-Since with a 304 when the file is no newer', function (): void {
        $request = new Request('GET', '/about.md', if_modified_since: $this->last_modified);

        $response = $this->router->headers_for($this->file, $request);

        expect($response['status'])->toBe(304);
    });

    it('omits the ETag on an If-Modified-Since-only 304', function (): void {
        $request = new Request('GET', '/about.md', if_modified_since: $this->last_modified);
        $response = $this->router->headers_for($this->file, $request);
        expect($response['status'])->toBe(304);
        expect($response['headers'])->not->toHaveKey('ETag');
    });

    it('serves a 200 when If-Modified-Since predates the file', function (): void {
        $request = new Request('GET', '/about.md', if_modified_since: 'Mon, 01 Jan 2001 00:00:00 GMT');

        $response = $this->router->headers_for($this->file, $request);

        expect($response['status'])->toBe(200);
    });

});
