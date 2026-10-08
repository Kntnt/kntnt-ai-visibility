<?php
/**
 * Verifies the real WordPress deactivation and reactivation lifecycle.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare(strict_types=1);

it('removes owned persisted routes without disturbing native HTML or unrelated rules', function (): void {

    // Ordinary Pest runs remain offline like the other Playground entrypoints.
    if (getenv('KNTNT_RUN_PLAYGROUND') !== '1') {
        $this->markTestSkipped('Set KNTNT_RUN_PLAYGROUND=1 to run deactivation regressions.');
    }

    // The harness asserts actual PHP 8.4 at both installation bases.
    $output = [];
    $exitCode = 0;
    exec('python3 ' . escapeshellarg(__DIR__ . '/playground-deactivation.py') . ' 2>&1', $output, $exitCode);
    $joined = implode("\n", $output);
    expect($exitCode)->toBe(0, $joined);
    expect($joined)->toContain('Deactivation total: 0 failures');

})->group('e2e');
