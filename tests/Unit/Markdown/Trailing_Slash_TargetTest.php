<?php
/**
 * Observes safe normalisation through the real public Markdown handler.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;
use Kntnt\Ai_Visibility\Markdown\Request_Handler;

/**
 * Builds actual collaborators without creating any cache or lock resources.
 */
function kntnt_slash_handler(): Request_Handler
{
    $logger = new Plugin_Logger(static function (): void {});
    $store = new File_Store(static fn(): string => throw new RuntimeException('normalisation is cache-free'));
    $service = new Page_Markdown_Service(new Front_Matter(), new Single_Flight($store), $logger);
    $eligibility = new Eligibility(new Content_Matrix(), new Exclusions(static fn(): string => '', static fn(): string => 'https://example.test/sub'));
    $provider = new Page_Markdown_Provider($service, $eligibility, new Markdown_Alternate());
    $registry = new Artifact_Registry();
    $registry->register($provider);
    return new Request_Handler($provider, $service, $store, new Serve_Router($store, $registry, $logger), $logger);
}

it('does not invent a dedicated suffix redirect in plain permalink mode', function (): void {
    Functions\when('get_option')->justReturn('');

    expect(kntnt_slash_handler()->trailing_slash_target('/about.md/'))->toBeNull();
});

it('refuses a scheme-relative request path instead of returning an external target', function (): void {
    Functions\when('get_option')->justReturn('/%postname%/');

    expect(kntnt_slash_handler()->trailing_slash_target('//external.test/about.md/'))->toBeNull();
});

it('refuses ambiguous or control-bearing redirect paths', function (string $path): void {
    Functions\when('get_option')->justReturn('/%postname%/');

    expect(kntnt_slash_handler()->trailing_slash_target($path))->toBeNull();
})->with([
    'backslash authority' => ['/\\external.test/about.md/'],
    'encoded slash authority' => ['/%2fexternal.test/about.md/'],
    'encoded backslash authority' => ['/%5cexternal.test/about.md/'],
    'encoded CRLF' => ['/about%0d%0aLocation%3aevil.md/'],
]);

it('preserves complete local path encoding and prefixes while removing only trailing slashes', function (string $path, ?string $target): void {
    Functions\when('get_option')->justReturn('/%postname%/');

    expect(kntnt_slash_handler()->trailing_slash_target($path))->toBe($target);
})->with([
    'root multiple slashes' => ['/about.md///', '/about.md'],
    'installation base' => ['/sub/about.md///', '/sub/about.md'],
    'source language' => ['/sub/sv/about.md/', '/sub/sv/about.md'],
    'translated home' => ['/sub/sv/index.md///', '/sub/sv/index.md'],
    'ordinary index' => ['/sub/sv/index/index.md/', '/sub/sv/index/index.md'],
    'nested index' => ['/sub/sv/index/index/index.md//', '/sub/sv/index/index/index.md'],
    'encoded Unicode' => ['/sub/sv/%c3%b6ppettider.md///', '/sub/sv/%c3%b6ppettider.md'],
    'encoding case retained' => ['/sub/sv/%C3%B6ppettider.md/', '/sub/sv/%C3%B6ppettider.md'],
    'uppercase suffix' => ['/about.MD/', null],
    'mixed suffix' => ['/about.Md///', null],
    'absolute URL' => ['https://external.test/about.md/', null],
    'ordinary HTML path' => ['/sub/about/', null],
]);
