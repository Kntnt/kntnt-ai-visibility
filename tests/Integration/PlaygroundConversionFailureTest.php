<?php
/**
 * Verifies conversion failure and recovery over the real WordPress HTTP shell.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'refuses incomplete conversion artifacts and retries after recovery', function (): void {

	// Ordinary Pest runs stay offline, consistently with the other e2e suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run conversion-failure regressions.' );
	}

	// Exercise actual handlers, conversion, aggregation and store over HTTP.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-conversion-failure.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Conversion failure HTTP: 0 failures' );

} )->group( 'e2e' );
