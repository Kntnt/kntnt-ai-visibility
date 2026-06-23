<?php
/**
 * Unit tests for the GitHub-release update checker.
 *
 * Drives Updater::check_for_updates() through its branches: non-object and
 * unchecked transients pass through untouched; a missing or non-GitHub Plugin
 * URI is ignored; an API failure, malformed JSON, non-GitHub asset host, or
 * assetless release is skipped; a cache hit avoids the network entirely; and a
 * newer release with a GitHub-hosted ZIP asset is injected into the update
 * transient.
 *
 * @package Tests\Unit
 * @since   0.1.0
 */

declare(strict_types=1);

use Brain\Monkey\Functions;
use Kntnt\Ai_Visibility\Plugin;
use Kntnt\Ai_Visibility\Updater;

/**
 * Builds a stdClass update transient carrying a non-empty `checked` list.
 */
function checked_transient(): stdClass {
    $transient          = new stdClass();
    $transient->checked = ['kntnt-ai-visibility/kntnt-ai-visibility.php' => '0.1.0'];
    return $transient;
}

describe('Updater', function (): void {

    beforeEach(function (): void {
        kntnt_test_reset_plugin();
    });

    afterEach(function (): void {
        kntnt_test_reset_plugin();
    });

    it('passes a non-object transient straight through', function (): void {
        expect((new Updater())->check_for_updates(false))->toBeFalse();
    });

    it('returns the transient unchanged when WordPress has not checked yet', function (): void {
        $transient = new stdClass();

        $result = (new Updater())->check_for_updates($transient);

        expect($result)->toBe($transient);
        expect(isset($result->response))->toBeFalse();
    });

    it('ignores the update when the Plugin URI is not a GitHub URL', function (): void {
        kntnt_test_seed_plugin_header(['PluginURI' => 'https://example.com/plugin', 'Version' => '0.1.0']);

        $transient = checked_transient();
        $result    = (new Updater())->check_for_updates($transient);

        expect(isset($result->response))->toBeFalse();
    });

    it('skips the update when the GitHub API request fails', function (): void {
        kntnt_test_seed_plugin_header(['PluginURI' => 'https://github.com/Kntnt/kntnt-ai-visibility', 'Version' => '0.1.0']);
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn(['response' => ['code' => 500]]);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(500);

        $result = (new Updater())->check_for_updates(checked_transient());

        expect(isset($result->response))->toBeFalse();
    });

    it('skips the update when the latest release has no ZIP asset', function (): void {
        kntnt_test_seed_plugin_header(['PluginURI' => 'https://github.com/Kntnt/kntnt-ai-visibility', 'Version' => '0.1.0']);
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('set_site_transient')->justReturn(true);
        Functions\when('apply_filters')->justReturn(6 * 3600);
        Functions\when('wp_remote_get')->justReturn(['ok']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            (string) wp_json_encode_local([
                'tag_name' => 'v0.2.0',
                'html_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/tag/0.2.0',
                'assets'   => [],
            ]),
        );

        $result = (new Updater())->check_for_updates(checked_transient());

        expect(isset($result->response))->toBeFalse();
    });

    it('does not advertise an update when the installed version is current', function (): void {
        kntnt_test_seed_plugin_header(['PluginURI' => 'https://github.com/Kntnt/kntnt-ai-visibility', 'Version' => '0.2.0']);
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('set_site_transient')->justReturn(true);
        Functions\when('apply_filters')->justReturn(6 * 3600);
        Functions\when('wp_remote_get')->justReturn(['ok']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            (string) wp_json_encode_local([
                'tag_name' => 'v0.2.0',
                'assets'   => [
                    ['content_type' => 'application/zip', 'browser_download_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/download/0.2.0/kntnt-ai-visibility.zip'],
                ],
            ]),
        );

        $result = (new Updater())->check_for_updates(checked_transient());

        expect(isset($result->response))->toBeFalse();
    });

    it('injects the update record for a newer release with a ZIP asset', function (): void {
        kntnt_test_seed_plugin_header([
            'PluginURI'  => 'https://github.com/Kntnt/kntnt-ai-visibility',
            'Version'    => '0.1.0',
            'RequiresWP' => '6.7',
        ]);
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('plugin_basename')->justReturn('kntnt-ai-visibility/kntnt-ai-visibility.php');
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('set_site_transient')->justReturn(true);
        Functions\when('apply_filters')->justReturn(6 * 3600);
        Functions\when('wp_remote_get')->justReturn(['ok']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            (string) wp_json_encode_local([
                'tag_name' => 'v0.2.0',
                'html_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/tag/0.2.0',
                'assets'   => [
                    ['content_type' => 'application/octet-stream', 'browser_download_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/download/0.2.0/source.tar'],
                    ['content_type' => 'application/zip', 'browser_download_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/download/0.2.0/kntnt-ai-visibility.zip'],
                ],
            ]),
        );

        $result = (new Updater())->check_for_updates(checked_transient());

        $key = 'kntnt-ai-visibility/kntnt-ai-visibility.php';
        expect($result->response)->toHaveKey($key);
        expect($result->response[$key]->new_version)->toBe('0.2.0');
        expect($result->response[$key]->package)->toBe('https://github.com/Kntnt/kntnt-ai-visibility/releases/download/0.2.0/kntnt-ai-visibility.zip');
        expect($result->response[$key]->tested)->toBe('6.7');
    });

    it('serves the update from cache and skips the network', function (): void {
        kntnt_test_seed_plugin_header([
            'PluginURI'  => 'https://github.com/Kntnt/kntnt-ai-visibility',
            'Version'    => '0.1.0',
            'RequiresWP' => '7.0',
        ]);
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('plugin_basename')->justReturn('kntnt-ai-visibility/kntnt-ai-visibility.php');

        // Cache hit: return a valid release array so the network is never reached.
        $cached_release = [
            'tag_name' => 'v0.2.0',
            'html_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/tag/0.2.0',
            'assets'   => [
                ['content_type' => 'application/zip', 'browser_download_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/download/0.2.0/kntnt-ai-visibility.zip'],
            ],
        ];
        Functions\when('get_site_transient')->justReturn($cached_release);
        Functions\when('wp_remote_get')->alias(static function (): never {
            throw new \RuntimeException('wp_remote_get must not be called on a cache hit');
        });

        $result = (new Updater())->check_for_updates(checked_transient());

        $key = 'kntnt-ai-visibility/kntnt-ai-visibility.php';
        expect($result->response)->toHaveKey($key);
        expect($result->response[$key]->new_version)->toBe('0.2.0');
        expect($result->response[$key]->package)->toBe('https://github.com/Kntnt/kntnt-ai-visibility/releases/download/0.2.0/kntnt-ai-visibility.zip');
    });

    it('does not inject an update when the API returns malformed JSON', function (): void {
        kntnt_test_seed_plugin_header(['PluginURI' => 'https://github.com/Kntnt/kntnt-ai-visibility', 'Version' => '0.1.0']);
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('wp_remote_get')->justReturn(['ok']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn('{not json');

        $result = (new Updater())->check_for_updates(checked_transient());

        expect(isset($result->response))->toBeFalse();
    });

    it('does not inject an update when the ZIP asset host is not GitHub', function (): void {
        kntnt_test_seed_plugin_header(['PluginURI' => 'https://github.com/Kntnt/kntnt-ai-visibility', 'Version' => '0.1.0']);
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('set_site_transient')->justReturn(true);
        Functions\when('apply_filters')->justReturn(6 * 3600);
        Functions\when('wp_remote_get')->justReturn(['ok']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            (string) wp_json_encode_local([
                'tag_name' => 'v0.2.0',
                'html_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/tag/0.2.0',
                'assets'   => [
                    ['content_type' => 'application/zip', 'browser_download_url' => 'https://evil.example/kntnt-ai-visibility.zip'],
                ],
            ]),
        );

        $result = (new Updater())->check_for_updates(checked_transient());

        expect(isset($result->response))->toBeFalse();
    });

    it('injects an update when the release has a GitHub ZIP asset but no zipball_url', function (): void {
        kntnt_test_seed_plugin_header([
            'PluginURI'  => 'https://github.com/Kntnt/kntnt-ai-visibility',
            'Version'    => '0.1.0',
            'RequiresWP' => '7.0',
        ]);
        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component) => parse_url($url, $component));
        Functions\when('plugin_basename')->justReturn('kntnt-ai-visibility/kntnt-ai-visibility.php');
        Functions\when('get_site_transient')->justReturn(false);
        Functions\when('set_site_transient')->justReturn(true);
        Functions\when('apply_filters')->justReturn(6 * 3600);
        Functions\when('wp_remote_get')->justReturn(['ok']);
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn(200);
        Functions\when('wp_remote_retrieve_body')->justReturn(
            (string) wp_json_encode_local([
                'tag_name' => 'v0.2.0',
                'html_url' => 'https://github.com/Kntnt/kntnt-ai-visibility/releases/tag/0.2.0',
                'assets'   => [
                    ['content_type' => 'application/zip', 'browser_download_url' => 'https://objects.githubusercontent.com/github-production-release-asset/kntnt-ai-visibility.zip'],
                ],
            ]),
        );

        $result = (new Updater())->check_for_updates(checked_transient());

        $key = 'kntnt-ai-visibility/kntnt-ai-visibility.php';
        expect($result->response)->toHaveKey($key);
        expect($result->response[$key]->new_version)->toBe('0.2.0');
        expect($result->response[$key]->package)->toBe('https://objects.githubusercontent.com/github-production-release-asset/kntnt-ai-visibility.zip');
    });

});

/**
 * Local JSON encoder so the tests do not depend on a stubbed wp_json_encode.
 *
 * @param mixed $value Value to encode.
 * @return string|false JSON string or false on failure.
 */
function wp_json_encode_local(mixed $value): string|false {
    return json_encode($value);
}
