<?php
/**
 * Integration e2e: the plugin behaves correctly on a subdirectory WordPress install.
 *
 * Drives the playground-e2e-subdir.sh harness, which boots a second Playground
 * HTTP server on PHP 8.4 with WordPress under a `/sub` subpath (set through
 * --site-url) and asserts that both the per-page `.md` and the llms singletons
 * resolve home-relative and stay contained inside the cache base. Like the root
 * e2e it downloads and runs a WASM WordPress build, so it is skipped unless
 * KNTNT_RUN_PLAYGROUND=1 is set (run-tests.sh and CI set it); a plain `pest` run
 * stays fast and offline.
 *
 * @package Tests\Integration
 * @since   0.2.0
 */

declare(strict_types=1);

it('serves artifacts correctly on a subdirectory install in WordPress Playground on PHP 8.4', function (): void {
    if (getenv('KNTNT_RUN_PLAYGROUND') !== '1') {
        $this->markTestSkipped('Set KNTNT_RUN_PLAYGROUND=1 (or run `bash run-tests.sh`) to run the Playground e2e.');
    }

    $script = __DIR__ . '/playground-e2e-subdir.sh';
    $output = [];
    $exit_code = 0;
    exec('bash ' . escapeshellarg($script) . ' 2>&1', $output, $exit_code);
    $joined = implode("\n", $output);

    expect($exit_code)->toBe(0, $joined);
    expect($joined)->toContain('subdirectory e2e summary');
    expect($joined)->toContain('0 failed');
})->group('e2e');
