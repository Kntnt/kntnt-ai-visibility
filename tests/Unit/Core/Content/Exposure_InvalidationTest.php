<?php
/**
 * Tests the exposure lifecycle observer through real cache/version services.
 *
 * WordPress option hooks are covered over HTTP; these public callback tests
 * pin the cache outcome without mocking the observer's collaborators.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Content\Exposure_Invalidation;

beforeEach(function (): void {
    // Keep the WordPress option boundary in memory; use real Core services.
    $GLOBALS['kntnt_exposure_option_values'] = [Cache_Version::OPTION => 5];
    Functions\when('get_option')->alias(static fn(string $key, mixed $default = false): mixed => $GLOBALS['kntnt_exposure_option_values'][$key] ?? $default);
    Functions\expect('update_option')->andReturnUsing(static function (string $key, mixed $value): bool {
        $GLOBALS['kntnt_exposure_option_values'][$key] = $value;
        return true;
    });
    Functions\when('wp_mkdir_p')->alias(static fn(string $path): bool => is_dir($path) || mkdir($path, 0777, true));
    $this->base = sys_get_temp_dir() . '/kntnt-exposure-' . uniqid('', true);
    $this->store = new File_Store(fn(): string => $this->base);
    $this->version = new Cache_Version();
    $this->observer = new Exposure_Invalidation($this->store, $this->version);
    $this->page = new Identity('markdown-alternate', 'public-page', 1);
    $this->aggregate = new Identity('llms-txt', 'llms-v5');
    $this->store->write($this->page, 'PUBLIC-PAGE');
    $this->store->write($this->aggregate, 'PUBLIC-INDEX');
});

afterEach(function (): void {
    $this->store->flush_all();
    unset($GLOBALS['kntnt_exposure_option_values']);
});

it('revokes per-page bytes and aggregate generations on a first matrix-only save', function (): void {
    $this->observer->on_option_add('kntnt_ai_visibility', [
        'content_types' => ['page' => ['md' => false]],
        'exclusions' => ['paths' => ''],
    ]);

    expect($this->store->read($this->page))->toBeNull();
    expect($this->store->read($this->aggregate))->toBeNull();
    expect($this->version->current())->toBe(6);
});

it('turns over the cache once when both exposure slices change', function (): void {
    $this->observer->on_option_update(
        ['exclusions' => ['paths' => '/old/']],
        ['content_types' => ['page' => ['md' => false]], 'exclusions' => ['paths' => '/new/']],
    );

    expect($this->store->read($this->page))->toBeNull();
    expect($this->version->current())->toBe(6);
});

it('removes output generated under the previous policy after settings deletion', function (): void {
    $this->observer->on_option_delete('kntnt_ai_visibility');

    expect($this->store->read($this->page))->toBeNull();
    expect($this->store->read($this->aggregate))->toBeNull();
    expect($this->version->current())->toBe(6);
});

it('preserves artifact bytes when only content signals change', function (): void {
    $this->observer->on_option_update(['signals' => ['search' => 'yes']], ['signals' => ['search' => 'no']]);

    expect($this->store->read($this->page))->toBe('PUBLIC-PAGE');
    expect($this->store->read($this->aggregate))->toBe('PUBLIC-INDEX');
    expect($this->version->current())->toBe(5);
});

it('preserves artifact bytes for unrelated option creation and deletion', function (): void {
    $this->observer->on_option_add('unrelated', ['content_types' => ['page' => ['md' => false]]]);
    $this->observer->on_option_delete('unrelated');

    expect($this->store->read($this->page))->toBe('PUBLIC-PAGE');
    expect($this->version->current())->toBe(5);
});

it('treats missing and malformed exposure slices as the runtime defaults', function (): void {
    $this->observer->on_option_update(null, ['content_types' => false, 'exclusions' => ['paths' => []]]);

    expect($this->store->read($this->page))->toBe('PUBLIC-PAGE');
    expect($this->version->current())->toBe(5);
});
