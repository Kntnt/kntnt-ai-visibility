<?php
/**
 * Verifies query identities, discovery and native permalink transitions.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'keeps plain sources distinct across supported URLs and aggregate builds', function (): void {

	// Keep ordinary Pest invocations offline like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run the plain-permalink regressions.' );
	}

	// Include root and subdirectory installations on actual PHP 8.4.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-plain-permalink.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Plain permalink HTTP: 0 failures' );

} )->group( 'e2e' );
