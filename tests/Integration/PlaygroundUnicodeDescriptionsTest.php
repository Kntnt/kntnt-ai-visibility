<?php
/**
 * Verifies the UTF-8 bytes of actual llms.txt excerpt responses.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

it( 'serves intact Unicode boundaries and character-capped excerpts', function (): void {

	// Keep ordinary Pest invocations offline, like the other HTTP suites.
	if ( getenv( 'KNTNT_RUN_PLAYGROUND' ) !== '1' ) {
		$this->markTestSkipped( 'Set KNTNT_RUN_PLAYGROUND=1 to run Unicode-description regressions.' );
	}

	// Strictly decode the whole served artifact before checking its excerpts.
	$output = [];
	$exit_code = 0;
	exec( 'python3 ' . escapeshellarg( __DIR__ . '/playground-unicode-descriptions.py' ) . ' 2>&1', $output, $exit_code );
	$joined = implode( "\n", $output );
	expect( $exit_code )->toBe( 0, $joined );
	expect( $joined )->toContain( 'Unicode descriptions HTTP: 0 failures' );

} )->group( 'e2e' );
