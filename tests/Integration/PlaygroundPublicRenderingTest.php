<?php
/**
 * Exercises real authentication, preview and integration cache vetoes.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'publishes only anonymous public artifacts and restores rendering callers', function (): void {

	// Keep ordinary Pest runs offline, consistently with the existing e2e suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run the public-rendering regression.' );
	}

	// Authentication and cache publication must be exercised through real HTTP.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-public-rendering.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Public rendering HTTP: 0 failures' );

} )->group( 'e2e' );
