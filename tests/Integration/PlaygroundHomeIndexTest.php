<?php
/**
 * Verifies separate home and literal-index identities through real HTTP.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'keeps static homes and actual index pages independently addressable', function (): void {

	// Keep ordinary Pest invocations offline like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run the home/index regressions.' );
	}

	// Include both installation matrices and their source-body evidence.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-home-index.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Home/index e2e: 0 failed' );

} )->group( 'e2e' );
