<?php
/**
 * Canonical HTML cache selection through WordPress's public header filter.
 *
 * The HTTP fixture covers emission and cache-plugin timing; these checks pin
 * the filter's behaviour independently of PHP's header transport.
 *
 * @package Tests\Unit
 * @since 0.5.2
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;
use Kntnt\Ai_Visibility\Markdown\Request_Handler;

beforeEach(function (): void {
    $this->savedServer = $_SERVER;
    $this->savedGet = $_GET;
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/ordinary/', 'HTTP_ACCEPT' => 'text/html'];
    $_GET = [];
    Functions\when('wp_unslash')->returnArg();
    Functions\when('esc_url_raw')->returnArg();
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('sanitize_key')->returnArg();
    Functions\when('wp_parse_url')->alias(static fn(string $url, int $component = -1): mixed => parse_url($url, $component));
    $this->handler = new Request_Handler(
        Mockery::mock(Page_Markdown_Provider::class),
        Mockery::mock(Page_Markdown::class),
        Mockery::mock(Store::class),
        Mockery::mock(Serve_Router::class),
        Mockery::mock(Logger::class),
    );
});

afterEach(function (): void {
    $_SERVER = $this->savedServer;
    $_GET = $this->savedGet;
});

it('varies canonical HTML on Accept while preserving other cache selectors', function (): void {
    $headers = $this->handler->vary_canonical_headers([
        'Content-Type' => 'text/html; charset=UTF-8',
        'vary' => 'Cookie, Accept-Encoding',
        'Vary' => 'ACCEPT, cookie',
    ]);

    expect($headers)->not->toHaveKey('vary');
    expect($headers['Content-Type'])->toBe('text/html; charset=UTF-8');
    $fields = array_map('strtolower', array_map('trim', explode(',', $headers['Vary'])));
    sort($fields);
    expect($fields)->toBe(['accept', 'accept-encoding', 'cookie']);
});

it('retains wildcard variation on canonical HTML', function (): void {
    expect($this->handler->vary_canonical_headers(['Vary' => 'Cookie, *'])['Vary'])->toBe('*');
});

it('keeps dedicated Markdown URL header policy unchanged', function (): void {
    $_SERVER['REQUEST_URI'] = '/ordinary.md';
    $_SERVER['HTTP_ACCEPT'] = 'text/markdown';
    $headers = ['Vary' => 'Accept-Encoding'];

    expect($this->handler->vary_canonical_headers($headers))->toBe($headers);
    Functions\expect('do_action')->never();
    $this->handler->protect_negotiated_request();
});

it('keeps explicit query-format header policy unchanged', function (): void {
    $_GET['format'] = 'markdown';
    $_SERVER['HTTP_ACCEPT'] = 'text/markdown';
    $headers = ['Vary' => 'Cookie'];

    expect($this->handler->vary_canonical_headers($headers))->toBe($headers);
    Functions\expect('do_action')->never();
    $this->handler->protect_negotiated_request();
});

it('does not bypass cache integrations for an ordinary HTML request', function (): void {
    Functions\expect('do_action')->never();
    $wasDefined = defined('DONOTCACHEPAGE');

    $this->handler->protect_negotiated_request();

    expect(defined('DONOTCACHEPAGE'))->toBe($wasDefined);
});
