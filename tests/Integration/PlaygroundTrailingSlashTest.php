<?php
/**
 * Exercises complete safe trailing-slash Locations through actual WordPress.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'normalises supported alternate paths once while preserving native method and query policy', function (): void {

	// Keep ordinary Pest invocations offline, like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run trailing-slash regressions.' );
	}

	// Inspect complete Location fields instead of following redirects silently.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-trailing-slash.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Trailing slash HTTP: 0 failures' );

} )->group( 'e2e' );
