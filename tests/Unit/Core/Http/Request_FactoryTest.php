<?php
/**
 * Unit tests for building a Request from the superglobals.
 *
 * The factory is the single place superglobals are read. The path is taken with
 * esc_url_raw( wp_unslash() ) so percent-encoded non-ASCII slugs survive; the
 * method is upper-cased; the format query arg, Accept header and conditional
 * headers are captured.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Core\Http\Request_Factory;

beforeEach(function (): void {
    Functions\when('wp_unslash')->returnArg();
    Functions\when('esc_url_raw')->returnArg();
    Functions\when('sanitize_text_field')->returnArg();
    Functions\when('sanitize_key')->alias(fn(string $v): string => strtolower($v));
    Functions\when('wp_parse_url')->alias(fn(string $url, int $component = -1): mixed => parse_url($url, $component));

    $_SERVER = [];
    $_GET = [];
});

afterEach(function (): void {
    $_SERVER = [];
    $_GET = [];
});

describe('Request_Factory::from_globals', function (): void {

    it('captures method, path, format, accept and conditionals', function (): void {
        $_SERVER['REQUEST_METHOD'] = 'get';
        $_SERVER['REQUEST_URI'] = '/about/team.md?format=markdown';
        $_SERVER['HTTP_ACCEPT'] = 'text/markdown';
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"abc"';
        $_SERVER['HTTP_IF_MODIFIED_SINCE'] = 'Tue, 14 Nov 2023 22:13:20 GMT';
        $_GET['format'] = 'markdown';

        $request = Request_Factory::from_globals();

        expect($request->method)->toBe('GET');
        expect($request->path)->toBe('/about/team.md');
        expect($request->query)->toBe(['format' => 'markdown']);
        expect($request->accept)->toBe('text/markdown');
        expect($request->if_none_match)->toBe('"abc"');
        expect($request->if_modified_since)->toBe('Tue, 14 Nov 2023 22:13:20 GMT');
    });

    it('defaults sensibly when superglobals are absent', function (): void {
        $request = Request_Factory::from_globals();

        expect($request->method)->toBe('GET');
        expect($request->path)->toBe('');
        expect($request->query)->toBe([]);
        expect($request->accept)->toBe('');
    });

});
