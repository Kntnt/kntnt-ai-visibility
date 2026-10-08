<?php
/**
 * Exercises validators for dynamic inline Markdown over real WordPress HTTP.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'validates freshly rendered inline bytes independently of the source post date', function (): void {

	// Ordinary Pest runs stay offline, consistently with the existing suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run the inline-validator regression.' );
	}

	// Metadata and shared blocks must be exercised through real WordPress HTTP.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-inline-validators.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Inline validator HTTP: 0 failures' );

} )->group( 'e2e' );
