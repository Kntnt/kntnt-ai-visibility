<?php
/**
 * Exercises the unified option lifecycle through real WordPress hooks.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'invalidates warmed artifacts on first saves, updates and settings removal', function (): void {

	// Keep ordinary Pest invocations offline, like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run exposure-option lifecycle regressions.' );
	}

	// Start from an absent option and inspect public artifacts after real saves.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-exposure-options.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Exposure options HTTP: 0 failures' );

} )->group( 'e2e' );
