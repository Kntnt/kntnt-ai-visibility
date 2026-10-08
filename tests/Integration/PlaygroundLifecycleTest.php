<?php
/**
 * Verifies sequential post, descendant and aggregate invalidation over HTTP.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'withdraws old source identities after native WordPress lifecycle changes', function (): void {

    // Keep ordinary Pest runs offline, like the other public HTTP suites.
    if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
        $this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run the lifecycle regressions.' );
    }

    // Assert both installation bases and permalink modes on actual PHP 8.4.
    $output = [];
    $exitCode = 0;
    exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-lifecycle.py' ) . ' 2>&1', $output, $exitCode );
    $joined = implode( "\n", $output );
    expect( $exitCode )->toBe( 0, $joined );
    expect( $joined )->toContain( 'Lifecycle HTTP: 0 failures' );

} )->group( 'e2e' );
