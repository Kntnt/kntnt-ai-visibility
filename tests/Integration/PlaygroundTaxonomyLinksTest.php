<?php
/**
 * Follows front-matter taxonomy links through real WordPress HTML archives.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'preserves real taxonomy archive URLs and handles term lookup failures', function (): void {

	// Keep ordinary Pest invocations offline, like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run taxonomy-link regressions.' );
	}

	// Follow the emitted links rather than deriving a second expected route.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-taxonomy-links.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Taxonomy links HTTP: 0 failures' );

} )->group( 'e2e' );
