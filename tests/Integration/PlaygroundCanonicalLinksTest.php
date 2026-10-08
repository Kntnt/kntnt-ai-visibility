<?php
/**
 * Verifies exact and safe canonical hints through actual WordPress HTTP.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'keeps native canonical links exact across cache states and refuses unsafe metadata', function (): void {

	// Keep ordinary Pest invocations offline like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run canonical-link regressions.' );
	}

	// Exercise both installations and actual minimum-runtime response headers.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-canonical-links.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Canonical links HTTP: 0 failures' );

} )->group( 'e2e' );
