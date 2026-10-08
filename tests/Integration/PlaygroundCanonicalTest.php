<?php
/**
 * Verifies language-aware identities, complete paths and their lifecycle.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'serves only complete canonical paths in each source language', function (): void {

	// Keep ordinary Pest invocations offline, like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run the canonical-path regressions.' );
	}

	// Include the real HTTP evidence when either installation matrix fails.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-canonical.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Canonical paths e2e: 0 failed' );

} )->group( 'e2e' );
