<?php
/**
 * Exercises the real downstream form and cold/warm artifact method policy.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'keeps unsupported artifact methods in the ordinary WordPress workflow', function (): void {

	// Keep an ordinary Pest invocation offline, as for the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run the Playground method regressions.' );
	}

	// Exercise the actual HTTP lifecycle and include its evidence on failure.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-methods.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Methods e2e: 0 failed' );

} )->group( 'e2e' );
