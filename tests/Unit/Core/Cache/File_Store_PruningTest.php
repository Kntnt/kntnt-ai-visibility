<?php
/**
 * Generation-safe cleanup through the real store and authoritative SQL stamp.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Tests\Helpers\Version_Database;

beforeEach(function (): void {
    Functions\when('wp_mkdir_p')->alias(static fn(string $path): bool => is_dir($path) || mkdir($path, 0700, true));
    Functions\when('wp_cache_delete')->justReturn(true);
    $this->previous_database = $GLOBALS['wpdb'] ?? null;
    $GLOBALS['wpdb'] = new Version_Database();
    $GLOBALS['wpdb']->query("INSERT INTO options VALUES ('kntnt_ai_visibility_cache_version', '2', 'off')");
    $this->base = sys_get_temp_dir() . '/kntnt-pruning-' . uniqid('', true);
    $this->store = kntnt_test_file_store(fn(): string => $this->base);
    $this->version = new Cache_Version($this->store);
    $this->current = fn(): Identity => new Identity('llms-txt', 'llms-v' . $this->version->current());
});

afterEach(function (): void {
    $this->store->flush_all();
    $GLOBALS['wpdb'] = $this->previous_database;
});

it('preserves the current aggregate when an older completed request prunes last', function (): void {
    $old = new Identity('llms-txt', 'llms-v2');
    $current = new Identity('llms-txt', 'llms-v3');
    $this->store->write($old, 'OLD TWO');
    $this->version->bump();
    $this->store->write($current, 'CURRENT THREE');

    $this->store->prune_siblings($current, $this->current);
    $this->store->prune_siblings($old, $this->current);

    expect($this->store->read($current))->toBe('CURRENT THREE');
    expect($this->store->read($old))->toBeNull();
});

it('removes only known older versions without touching newer bytes, pages or another kind', function (): void {
    $this->version->bump();
    $old = new Identity('llms-txt', 'llms-v2');
    $current = new Identity('llms-txt', 'llms-v3');
    $sentinels = [
        [new Identity('llms-txt', 'llms-v4'), 'NEWER FOUR'],
        [new Identity('llms-txt', 'unowned'), 'UNOWNED'],
        [new Identity('llms-full', 'llms-full-v2'), 'OTHER KIND'],
        [new Identity('markdown-alternate', 'about'), 'PAGE'],
        [new Identity('markdown-alternate', 'nested/child'), 'NESTED PAGE'],
    ];
    $this->store->write($old, 'OBSOLETE TWO');
    $this->store->write($current, 'CURRENT THREE');
    foreach ($sentinels as [$identity, $bytes]) { $this->store->write($identity, $bytes); }

    $this->store->prune_siblings($current, $this->current);

    expect($this->store->read($old))->toBeNull();
    expect($this->store->read($current))->toBe('CURRENT THREE');
    foreach ($sentinels as [$identity, $bytes]) { expect($this->store->read($identity))->toBe($bytes); }
});

it('cleans an old backlog in bounded lazy batches rather than one unbounded sweep', function (): void {
    $GLOBALS['wpdb']->query("UPDATE options SET option_value = '72'");
    $current = new Identity('llms-txt', 'llms-v72');
    $old = [];
    for ($version = 1; $version <= 70; ++$version) {
        $old[] = new Identity('llms-txt', 'llms-v' . $version);
        $this->store->write($old[array_key_last($old)], 'OBSOLETE');
    }
    $this->store->write($current, 'CURRENT SEVENTY TWO');
    $remaining = fn(): int => count(array_filter($old, fn(Identity $identity): bool => $this->store->has($identity)));

    $this->store->prune_siblings($current, $this->current);

    expect($remaining())->toBeGreaterThanOrEqual(38)->toBeLessThan(70);
    for ($request = 0; $request < 3; ++$request) {
        $this->store->prune_siblings($current, $this->current);
    }
    expect($remaining())->toBe(0);
    expect($this->store->read($current))->toBe('CURRENT SEVENTY TWO');
});

it('preserves the current aggregate when the older request completes before the newer one', function (): void {
    $old = new Identity('llms-txt', 'llms-v2');
    $current = new Identity('llms-txt', 'llms-v3');
    $this->store->write($old, 'OLD TWO');
    $this->store->prune_siblings($old, $this->current);
    $this->version->bump();
    $this->store->write($current, 'CURRENT THREE');

    $this->store->prune_siblings($current, $this->current);

    expect($this->store->read($current))->toBe('CURRENT THREE');
    expect($this->store->read($old))->toBeNull();
});

it('keeps captured response bytes and validators coherent after the next generation prunes its path', function (): void {
    $old = new Identity('llms-txt', 'llms-v2');
    $current = new Identity('llms-txt', 'llms-v3');
    $this->store->write($old, "OLD TWO\n");
    touch($this->store->path_for($old), 1_700_000_000, 1_700_000_000);
    $last_modified = gmdate('D, d M Y H:i:s', (int) filemtime($this->store->path_for($old))) . ' GMT';
    $router = new Serve_Router($this->store, new Artifact_Registry());
    $snapshot = $router->snapshot_for($this->store->path_for($old));
    $this->version->bump();
    $this->store->write($current, "CURRENT THREE\n");
    $this->store->prune_siblings($current, $this->current);

    expect($this->store->has($old))->toBeFalse();
    expect($snapshot?->bytes)->toBe("OLD TWO\n");
    $get = $router->headers_for_snapshot($snapshot, new Request('GET', '/llms.txt'), 'text/plain; charset=utf-8');
    expect($get['headers']['Content-Length'])->toBe('8');
    expect($get['headers']['ETag'])->toBe('"022b0bd89d70f92812c0f9450726bb2f"');
    expect($get['headers']['Last-Modified'])->toBe($last_modified);
    expect($get['status'])->toBe(200);
    expect($get['send_body'])->toBeTrue();
    $head = $router->headers_for_snapshot($snapshot, new Request('HEAD', '/llms.txt'), 'text/plain; charset=utf-8');
    expect($head['headers'])->toBe($get['headers']);
    expect($head['send_body'])->toBeFalse();
    foreach ([new Request('GET', '/llms.txt', if_none_match: '"022b0bd89d70f92812c0f9450726bb2f"'),
        new Request('GET', '/llms.txt', if_modified_since: $last_modified)] as $request) {
        $conditional = $router->headers_for_snapshot($snapshot, $request);
        expect($conditional['status'])->toBe(304);
        expect($conditional['send_body'])->toBeFalse();
    }
    expect($this->store->read($current))->toBe("CURRENT THREE\n");
});

it('reads canonical metadata from the captured inode when cleanup removes its old path during parsing', function (): void {
    $old = new Identity('llms-txt', 'llms-v2');
    $current = new Identity('llms-txt', 'llms-v3');
    $bytes = "---\ncanonical_url: \"https://example.test/about/\"\n---\nOLD SOURCE\n";
    $this->store->write($old, $bytes);
    $router = new Serve_Router($this->store, new Artifact_Registry(), canonical_origin: function () use ($current): string {
        $this->version->bump();
        $this->store->write($current, "CURRENT THREE\n");
        $this->store->prune_siblings($current, $this->current);
        return 'https://example.test';
    });

    $snapshot = $router->snapshot_for($this->store->path_for($old), true);

    expect($this->store->has($old))->toBeFalse();
    expect($this->store->read($current))->toBe("CURRENT THREE\n");
    expect($snapshot?->bytes)->toBe($bytes);
    expect($snapshot?->canonical_url)->toBe('https://example.test/about/');
    $response = $router->headers_for_snapshot($snapshot, new Request('HEAD', '/llms.txt'));
    expect($response['headers']['Link'])->toBe('<https://example.test/about/>; rel="canonical"');
    expect($response['send_body'])->toBeFalse();
});

it('omits untrustworthy canonical metadata while preserving the captured bytes', function (string $bytes): void {
    $identity = new Identity('markdown-alternate', 'about');
    $this->store->write($identity, $bytes);
    $router = new Serve_Router($this->store, new Artifact_Registry(),
        base_path: static fn(): string => '/sub', canonical_origin: static fn(): string => 'https://example.test:8443');

    $snapshot = $router->snapshot_for($this->store->path_for($identity), true);

    expect($snapshot?->bytes)->toBe($bytes);
    expect($snapshot?->canonical_url)->toBe('');
    $headers = $router->headers_for_snapshot($snapshot, new Request('GET', '/sub/about.md'))['headers'];
    expect($headers)->not->toHaveKey('Link');
})->with([
    'missing metadata' => "# Ordinary source\n",
    'unfinished metadata' => "---\ncanonical_url: \"https://example.test:8443/sub/about/\"\n",
    'wrong field type' => "---\ncanonical_url: 42\n---\nSource\n",
    'foreign origin' => "---\ncanonical_url: \"https://elsewhere.test/sub/about/\"\n---\nSource\n",
    'outside installation' => "---\ncanonical_url: \"https://example.test:8443/about/\"\n---\nSource\n",
    'ambiguous metadata' => "---\ncanonical_url: \"https://example.test:8443/sub/a/\"\ncanonical_url: \"https://example.test:8443/sub/b/\"\n---\nSource\n",
    'decoded header control' => "---\ncanonical_url: \"https://example.test:8443/sub/about/\\u000aX-Fixture:yes\"\n---\nSource\n",
    'encoded dot segments' => "---\ncanonical_url: \"https://example.test:8443/sub/%2e%2e/outside/\"\n---\nSource\n",
]);

it('treats missing and poisoned cache capture as silent misses', function (): void {
    $identity = new Identity('llms-txt', 'llms-v2');
    $router = new Serve_Router($this->store, new Artifact_Registry());
    $warnings = [];
    set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
        if (error_reporting() & $severity) { $warnings[] = $message; }
        return true;
    });
    try {
        $missing = $router->snapshot_for($this->store->path_for($identity));
        $this->store->write($identity, 'RETAINED PHYSICAL FILE');
        $this->store->publication()->poison();
        $poisoned = $router->snapshot_for($this->store->path_for($identity));
    } finally {
        restore_error_handler();
    }
    expect($missing)->toBeNull();
    expect($poisoned)->toBeNull();
    expect($warnings)->toBeEmpty();
});
