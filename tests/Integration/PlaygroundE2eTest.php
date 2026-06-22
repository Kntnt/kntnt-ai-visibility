<?php
/**
 * Integration e2e: the Markdown alternate behaves correctly in WordPress Playground.
 *
 * Drives the playground-e2e.sh harness, which boots a real Playground HTTP
 * server on PHP 8.4 with the plugin mounted and fixtures seeded, then exercises
 * the `.md` request lifecycle over HTTP (real `.md`, `?format=markdown`, `Accept`
 * negotiation, `/index.md`, 404/403/301, traversal payloads). Because it
 * downloads and runs a WASM WordPress build over the network it is skipped
 * unless KNTNT_RUN_PLAYGROUND=1 is set (run-tests.sh and CI set it); a plain
 * `pest` run stays fast and offline.
 *
 * @package Tests\Integration
 * @since   0.1.0
 */

declare(strict_types=1);

it('serves Markdown alternates correctly in WordPress Playground on PHP 8.4', function (): void {
    if (getenv('KNTNT_RUN_PLAYGROUND') !== '1') {
        $this->markTestSkipped('Set KNTNT_RUN_PLAYGROUND=1 (or run `bash run-tests.sh`) to run the Playground e2e.');
    }

    $script = __DIR__ . '/playground-e2e.sh';
    $output = [];
    $exit_code = 0;
    exec('bash ' . escapeshellarg($script) . ' 2>&1', $output, $exit_code);
    $joined = implode("\n", $output);

    expect($exit_code)->toBe(0, $joined);
    expect($joined)->toContain('e2e summary');
    expect($joined)->toContain('0 failed');
})->group('e2e');
