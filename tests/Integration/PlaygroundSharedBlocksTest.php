<?php
/**
 * Verifies native shared-block invalidation over actual public HTTP routes.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'refreshes dependent pages and aggregates after native shared-block mutations', function (): void {
    if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
        $this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run shared-block regressions.' );
    }
    $output = [];
    $exitCode = 0;
    exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-shared-blocks.py' ) . ' 2>&1', $output, $exitCode );
    expect( $exitCode )->toBe( 0, implode( "\n", $output ) );
    expect( implode( "\n", $output ) )->toContain( 'response checks; 0 failures' );
} )->group( 'e2e' );
