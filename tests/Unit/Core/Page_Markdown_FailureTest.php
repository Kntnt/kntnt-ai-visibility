<?php
/**
 * Conversion failure and recovery through the real page service and file store.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Materialisation;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Markdown_Conversion_Failed;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;

beforeEach(function (): void {
    Functions\when('setup_postdata')->justReturn(true);
    Functions\when('wp_get_current_user')->justReturn((object) ['ID' => 0]);
    Functions\when('wp_set_current_user')->justReturn((object) ['ID' => 0]);
    Functions\when('add_action')->justReturn(true);
    Functions\when('remove_action')->justReturn(true);
    Functions\when('add_filter')->justReturn(true);
    Functions\when('remove_filter')->justReturn(true);
    Functions\when('apply_filters')->alias(static fn(string $hook, mixed $value): mixed => $value);
    Functions\when('wp_mkdir_p')->alias(static fn(string $dir): bool => is_dir($dir) || mkdir($dir, 0700, true));
    Functions\when('wp_json_encode')->alias(static fn(mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR));
    Functions\when('get_the_title')->justReturn('Public page');
    Functions\when('get_permalink')->justReturn('https://example.test/public/');
    Functions\when('get_the_date')->justReturn('2026-10-08');
    Functions\when('get_the_author_meta')->justReturn('Author');
    Functions\when('get_the_post_thumbnail_url')->justReturn(false);
    Functions\when('get_the_terms')->justReturn(false);
    $this->base = sys_get_temp_dir() . '/kntnt-conversion-' . uniqid('', true);
    mkdir($this->base . '/locks', 0700, true);
    $this->store = new File_Store(fn(): string => $this->base);
    $this->single_flight = new Single_Flight($this->store, $this->base . '/locks');
    $this->records = [];
    $this->logger = new Plugin_Logger(function (string $line): void { $this->records[] = $line; });
});

afterEach(function (): void {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($this->base);
});

it('does not publish a conversion failure and retries the same identity on recovery', function (): void {
    $attempts = 0;
    $domain = static function () use (&$attempts): string {
        if (++$attempts === 1) {
            throw new RuntimeException('AUDIT-CONVERSION-FAULT');
        }
        return 'https://example.test';
    };
    $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger, $domain);
    $identity = new Identity('markdown-alternate', 'public', 36);
    $post = new WP_Post();
    $post->post_content = '<p>PUBLIC-BODY</p>';

    expect(fn(): Materialisation => $service->materialise($identity, $post))->toThrow(Markdown_Conversion_Failed::class);
    expect($this->store->read($identity))->toBeNull();
    expect($this->records)->toHaveCount(1);
    expect($this->records[0])->toContain('Markdown conversion failed', 'AUDIT-CONVERSION-FAULT');

    $recovered = $service->materialise($identity, $post);
    expect($recovered->bytes)->toContain('PUBLIC-BODY');
    expect($recovered->persisted)->toBeTrue();
    expect($this->store->read($identity))->toBe($recovered->bytes);
    expect($attempts)->toBe(2);
});

it('publishes a legitimately empty converted body as a complete metadata and title document', function (): void {
    $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger, static fn(): string => 'https://example.test');
    $identity = new Identity('markdown-alternate', 'empty', 37);
    $post = new WP_Post();
    $post->post_content = '';

    $result = $service->materialise($identity, $post);

    expect($result->bytes)->toEndWith("# Public page\n\n");
    expect($result->bytes)->toContain('canonical_url: "https://example.test/public/"');
    expect($result->persisted)->toBeTrue();
    expect($this->store->read($identity))->toBe($result->bytes);
    expect($this->records)->toBe([]);
});

it('uses a fresh artifact but never substitutes expired bytes for a failed conversion', function (): void {
    $identity = new Identity('markdown-alternate', 'expired', 38);
    $this->store->write($identity, 'PREVIOUS-VALID-ARTIFACT');
    $service = new Page_Markdown_Service(new Front_Matter(), $this->single_flight, $this->logger, static function (): never {
        throw new RuntimeException('AUDIT-CONVERSION-FAULT');
    });
    $post = new WP_Post();
    $post->post_content = '<p>PUBLIC-BODY</p>';

    expect($service->materialise($identity, $post)->bytes)->toBe('PREVIOUS-VALID-ARTIFACT');
    touch($this->store->path_for($identity), time() - WEEK_IN_SECONDS - 60);
    expect(fn(): Materialisation => $service->materialise($identity, $post))->toThrow(Markdown_Conversion_Failed::class);
    expect($this->store->read($identity))->toBe('PREVIOUS-VALID-ARTIFACT');
});
