<?php
/**
 * Drives actual cache materialisation in independent native PHP processes.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

/** WordPress filesystem boundary used by the actual File_Store. */
function wp_mkdir_p( string $path ): bool {
	return is_dir( $path ) || mkdir( $path, 0700, true );
}

$base = $argv[1];
$locks = $argv[2];
$role = $argv[3];
$store = new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => $base, barrier_directory: $argv[4] );
$flight = new \Kntnt\Ai_Visibility\Core\Cache\Single_Flight( $store, $locks );
$identity = new \Kntnt\Ai_Visibility\Core\Artifact\Identity( 'markdown-alternate', 'concurrent', 3 );
try {
	$result = $flight->once( $identity, static function () use ( $locks, $role ): string {
		if ( $role === 'first' ) {
			file_put_contents( $locks . '/first-ready', 'ready' );
			$deadline = microtime( true ) + 5;
			while ( ! is_file( $locks . '/release-first' ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
		}
		return 'OBSOLETE-' . $role;
	}, static function () use ( $locks, $role ): void {
		// once() has captured its generation before invoking this public guard.
		if ( $role === 'queued' ) {
			file_put_contents( $locks . '/queued-ready', 'ready' );
		}
	} );
	echo $result->bytes;
} catch ( \Kntnt\Ai_Visibility\Core\Cache\Obsolete_Artifact ) {
	echo 'REFUSED';
}
