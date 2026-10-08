<?php
/**
 * Drives real native single-flight producers in independent processes.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

/** WordPress's filesystem boundary uses actual native directories. */
function wp_mkdir_p( string $path ): bool {
	return is_dir( $path ) || mkdir( $path, 0700, true );
}

/** WordPress's diagnostic serialisation boundary. */
function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

[ , $base, $locks, $coordination, $role ] = $argv;
$store = new File_Store( static fn(): string => $base, new Plugin_Logger( static function (): void {} ), $coordination );
$flight = new Single_Flight( $store, $locks );
if ( $role === 'nested' ) {
	$result = $flight->once( new Identity( 'markdown-alternate', 'outer', 1 ), static function () use ( $base, $locks, $coordination ): string {
		$other = new File_Store( static fn(): string => $base, barrier_directory: $coordination );
		return 'OUTER-' . ( new Single_Flight( $other, $locks ) )->once(
			new Identity( 'markdown-alternate', 'inner', 2 ), static fn(): string => 'INNER',
		)->bytes;
	} );
} else {
	$result = $flight->once( new Identity( 'llms-txt', 'llms-v1' ), static function () use ( $base, $locks, $role ): string {
		$guard = fopen( $locks . '/active-producer-' . hash( 'sha256', $base ), 'c' );
		if ( ! flock( $guard, LOCK_EX | LOCK_NB ) ) {
			file_put_contents( $locks . '/overlap', 'OVERLAP' );
		}
		file_put_contents( $locks . '/started-' . $role, 'started' );
		$deadline = microtime( true ) + 5;
		while ( in_array( $role, [ 'first', 'second' ], true )
			&& ! is_file( $locks . '/release-' . $role ) && microtime( true ) < $deadline ) {
			usleep( 10000 );
		}
		flock( $guard, LOCK_UN );
		fclose( $guard );
		return strtoupper( $role );
	}, static function () use ( $locks, $role ): void {
		file_put_contents( $locks . '/queued-' . $role, 'queued' );
	} );
}
echo json_encode( [ 'bytes' => $result->bytes, 'persisted' => $result->persisted ], JSON_THROW_ON_ERROR );
