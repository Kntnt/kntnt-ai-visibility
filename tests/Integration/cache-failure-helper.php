<?php
/**
 * Controls cache obstructions in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Plugin;

// Fixed fixture tokens never ship as an installed production plugin.
add_action( 'init', static function (): void {
	if ( ( $_GET['cache_failure_fixture'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['action'] ?? '' ) );
	$base = Plugin::cache_dir();
	$store = new File_Store( static fn(): string => $base );
	if ( in_array( $action, [ 'restore', 'obstruct' ], true ) ) {
		$store->flush_all();
		if ( is_file( $base ) ) {
			unlink( $base );
		}
		if ( $action === 'obstruct' ) {
			wp_mkdir_p( dirname( $base ) );
			file_put_contents( $base, 'ordinary file obstructs the cache' );
		}
	}
	if ( $action === 'stale' ) {

		// Keep expired wrong bytes readable while making their replacement fail.
		$entries = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $entries as $entry ) {
			if ( $entry->isFile() && $entry->getExtension() === 'md' ) {
				file_put_contents( $entry->getPathname(), 'STALE-WRONG-CONTENT' );
				touch( $entry->getPathname(), time() - WEEK_IN_SECONDS - 60 );
			}
		}
		unlink( $base . '/index.html' );
		mkdir( $base . '/index.html' );

	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [ 'php' => PHP_VERSION, 'obstructed' => is_file( $base ) ] );
	exit;
}, -100 );
