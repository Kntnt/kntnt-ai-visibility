<?php
/**
 * Integration smoke test: the plugin boots in WordPress Playground on PHP 8.5.
 *
 * This drives the same playground-smoke.sh harness that run-tests.sh Level 2
 * and the CI e2e job use, so the Integration test suite and the shell entry
 * point share one source of truth. Because it downloads and runs a WASM
 * WordPress build over the network, it is skipped unless KNTNT_RUN_PLAYGROUND=1
 * is set (run-tests.sh and CI set it); a plain `pest` run stays fast and offline.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare(strict_types=1);

it('boots the plugin in WordPress Playground on PHP 8.5', function (): void {
    if (getenv('KNTNT_RUN_PLAYGROUND') !== '1') {
        $this->markTestSkipped('Set KNTNT_RUN_PLAYGROUND=1 (or run `bash run-tests.sh`) to run the Playground e2e.');
    }

    $script = __DIR__ . '/playground-smoke.sh';
    $output = [];
    $exit_code = 0;
    exec('bash ' . escapeshellarg($script) . ' 2>&1', $output, $exit_code);
    $joined = implode("\n", $output);

    expect($exit_code)->toBe(0, $joined);
    expect($joined)->toContain('KNTNT_AI_VISIBILITY_BOOT_OK');
})->group('e2e');
