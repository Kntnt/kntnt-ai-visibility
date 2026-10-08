<?php
/**
 * Exercises real filesystem failures through both artifact HTTP handlers.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'serves generated artifacts when their cache cannot be published', function (): void {

	// Keep ordinary Pest runs offline, like the other Playground HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run cache-failure HTTP regressions.' );
	}

	// Observe the real HTTP lifecycle with actual filesystem obstructions.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-cache-failure.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Cache-failure HTTP: 0 failures' );

} )->group( 'e2e' );
