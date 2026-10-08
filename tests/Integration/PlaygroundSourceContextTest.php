<?php
/**
 * Exercises the source query, Loop and locale through real WordPress integrations.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'renders translated sources with their query and Loop and restores the caller', function (): void {

	// Keep ordinary Pest invocations offline, like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run source-context regressions.' );
	}

	// Inspect real source-aware integrations in direct and aggregate artifacts.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-source-context.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Source context HTTP: 0 failures' );

} )->group( 'e2e' );
