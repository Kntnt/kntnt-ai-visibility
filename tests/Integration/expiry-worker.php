<?php
/**
 * Coordinate expired requests through the public single-flight boundary.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

/**
 * WordPress filesystem boundary for the actual store.
 *
 * @since 0.5.2
 *
 * @param string $path The fixture-owned directory.
 * @return bool Whether the directory is available.
 */
function wp_mkdir_p( string $path ): bool {
	return is_dir( $path ) || mkdir( $path, 0700, true );
}

$base = $argv[1];
$locks = $argv[2];
$role = $argv[3];
$store = new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => $base, barrier_directory: $argv[4] );
$flight = new \Kntnt\Ai_Visibility\Core\Cache\Single_Flight( $store, $locks, 60 );
$identity = new \Kntnt\Ai_Visibility\Core\Artifact\Identity( 'markdown-alternate', 'queued-expiry', 3 );
try {
	$result = $flight->once( $identity, static function () use ( $locks, $role ): string {
		if ( str_starts_with( $role, 'first' ) ) {
			file_put_contents( $locks . '/first-producing', 'ready' );
			$deadline = microtime( true ) + 5;
			while ( ! is_file( $locks . '/release-first' ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
		}
		if ( $role === 'first-fails' ) {
			throw new \RuntimeException( 'Controlled producer failure' );
		}
		return $role === 'first' ? 'FRESH-FIRST' : 'FRESH-QUEUED';
	}, static function () use ( $locks, $role ): void {
		if ( $role === 'queued' ) {
			file_put_contents( $locks . '/queued-selected', 'ready' );
		}
	} );
	echo $result->bytes;
} catch ( \RuntimeException ) {
	echo 'PRODUCER-FAILED';
}
