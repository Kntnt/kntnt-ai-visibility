<?php
/**
 * Unit tests for the shared Page-Markdown service.
 *
 * for_post() runs the pipeline: render the content (the_content), convert the
 * HTML to GFM with the full converter (absolutising URLs against the site
 * domain), prepend the front-matter and the visible H1, and assemble. The body
 * leads with the page's H1 sourced from the post title; the title element is
 * metadata only and never appears twice. materialise() is the single-flight
 * cache write: a miss renders and stores, a hit returns the cached bytes
 * without re-rendering.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;

beforeEach(function (): void {
    Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0777, true));
    $this->logger = Mockery::mock(Logger::class)->shouldIgnoreMissing();
    $this->base   = sys_get_temp_dir() . '/kntnt-pm-' . uniqid('', true);
    $this->store  = new File_Store(fn(): string => $this->base);
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

describe('Page_Markdown_Service::for_post', function (): void {

    beforeEach(function (): void {
        // the_content renders to itself in isolation; the title is the H1 source.
        Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
        Functions\when('get_the_title')->justReturn('My Title');

        $front = Mockery::mock(Front_Matter::class);
        $front->shouldReceive('build')->andReturn("---\ntitle: \"My Title\"\n---\n");

        $this->service = new Page_Markdown_Service($front, $this->store, $this->logger, fn(): string => 'https://example.com');
    });

    it('assembles front-matter, the visible H1, then the converted body', function (): void {
        $post = new WP_Post();
        $post->post_content = '<p>Hello <a href="/rel">link</a></p>';

        $markdown = $this->service->for_post($post);

        expect($markdown)->toStartWith("---\ntitle: \"My Title\"\n---\n\n# My Title\n\n");
        expect($markdown)->toContain('[link](https://example.com/rel)');
    });

    it('converts GFM tables and strikethrough and absolutises image URLs', function (): void {
        $post = new WP_Post();
        $post->post_content =
            '<p><del>old</del></p>'
            . '<table><thead><tr><th>A</th></tr></thead><tbody><tr><td>1</td></tr></tbody></table>'
            . '<p><img src="/img.png" alt="x"></p>';

        $markdown = $this->service->for_post($post);

        expect($markdown)->toContain('~~old~~');
        expect($markdown)->toContain('| A |');
        expect($markdown)->toContain('![x](https://example.com/img.png)');
    });

    it('leads the body with exactly one H1 from the post title', function (): void {
        $post = new WP_Post();
        $post->post_content = '<p>Body.</p>';

        $markdown = $this->service->for_post($post);

        expect(substr_count($markdown, "\n# My Title\n"))->toBe(1);
    });

});

describe('Page_Markdown_Service::materialise', function (): void {

    it('renders, writes the cache and returns the bytes on a miss', function (): void {
        Functions\when('apply_filters')->alias(fn(string $hook, mixed $value): mixed => $value);
        Functions\when('get_the_title')->justReturn('T');
        $front = Mockery::mock(Front_Matter::class);
        $front->shouldReceive('build')->andReturn("---\ntitle: \"T\"\n---\n");
        $service = new Page_Markdown_Service($front, $this->store, $this->logger, fn(): string => 'https://example.com');

        $identity = new Identity('markdown-alternate', 'hello', 1);
        $post = new WP_Post();
        $post->post_content = '<p>Hi</p>';

        $bytes = $service->materialise($identity, $post);

        expect($this->store->has($identity))->toBeTrue();
        expect($this->store->read($identity))->toBe($bytes);
        expect($bytes)->toContain('# T');
    });

    it('returns the cached bytes without re-rendering on a hit', function (): void {
        // No the_content / title / converter stubs: a regeneration would fail,
        // proving the hit path never re-renders. The Front_Matter mock has no
        // expectations, so calling build() would also fail.
        $identity = new Identity('markdown-alternate', 'cached', 2);
        $this->store->write($identity, 'CACHED BYTES');
        $service = new Page_Markdown_Service(Mockery::mock(Front_Matter::class), $this->store, $this->logger, fn(): string => 'https://example.com');

        $post = new WP_Post();

        expect($service->materialise($identity, $post))->toBe('CACHED BYTES');
    });

});
