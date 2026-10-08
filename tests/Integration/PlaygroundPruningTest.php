<?php
/**
 * Verifies generation cleanup and immutable responses through WordPress HTTP.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'retains current aggregates and coherent responses while old paths are pruned', function (): void {
    if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
        $this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run pruning regressions.' );
    }
    $output = [];
    $exit_code = 0;
    exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-pruning.py' ) . ' 2>&1', $output, $exit_code );
    $joined = implode( "\n", $output );
    expect( $exit_code )->toBe( 0, $joined );
    expect( $joined )->toContain( 'Pruning response HTTP: 0 failures' );
} )->group( 'e2e' );
