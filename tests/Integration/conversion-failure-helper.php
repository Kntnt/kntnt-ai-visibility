<?php
/**
 * Injects a fault through the existing service domain-provider constructor seam.
 *
 * Real handlers, providers, builders, converter, front-matter and store execute;
 * this proves their failure policy, not the incidence of converter exceptions.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Artifact\Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Llms\Full_Builder;
use Kntnt\Ai_Visibility\Llms\Full_Provider;
use Kntnt\Ai_Visibility\Llms\Selected_Types;
use Kntnt\Ai_Visibility\Markdown\Page_Markdown_Provider;
use Kntnt\Ai_Visibility\Plugin;

// Inspect public store outcomes and control recovery without replacing services.
add_action( 'init', static function (): void {
	$action = sanitize_key( (string) ( $_GET['conversion_fixture'] ?? '' ) );
	if ( $action === '' ) {
		return;
	}
	$store = new File_Store( static fn(): string => Plugin::cache_dir() );
	if ( $action === 'reset' ) {
		$store->flush_all();
		update_option( 'kntnt_conversion_fixture_fail', true );
		update_option( 'kntnt_conversion_fixture_attempts', 0 );
		update_option( 'kntnt_conversion_fixture_logs', [] );
	} elseif ( $action === 'recover' ) {
		update_option( 'kntnt_conversion_fixture_fail', false );
	}
	$locator = new Markdown_Alternate();
	$post = get_post( (int) get_option( 'kntnt_conversion_fixture_ordinary' ) );
	$prefix = get_post( (int) get_option( 'kntnt_conversion_fixture_prefix' ) );
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [
		'php' => PHP_VERSION,
		'attempts' => (int) get_option( 'kntnt_conversion_fixture_attempts', 0 ),
		'page' => $store->read( $locator->identity_for( $post ) ),
		'prefix' => $store->read( $locator->identity_for( $prefix ) ),
		'full' => $store->read( new Identity( Full_Provider::KIND, 'llms-full-v' . ( new Cache_Version() )->current() ) ),
		'logs' => get_option( 'kntnt_conversion_fixture_logs', [] ),
	] );
	exit;
}, -100 );

// Drive the real HTTP shells with only their public service constructor changed.
add_action( 'template_redirect', static function (): void {
	$logger = new Plugin_Logger( static function ( string $line ): void {
		$logs = get_option( 'kntnt_conversion_fixture_logs', [] );
		$logs[] = $line;
		update_option( 'kntnt_conversion_fixture_logs', $logs );
	} );
	$store = new File_Store( static fn(): string => Plugin::cache_dir(), $logger );
	$flight = new Single_Flight( $store );
	$service = new Page_Markdown_Service( new Front_Matter(), $flight, $logger, static function (): string {
		if ( get_the_ID() === (int) get_option( 'kntnt_conversion_fixture_ordinary' ) ) {
			update_option( 'kntnt_conversion_fixture_attempts', 1 + (int) get_option( 'kntnt_conversion_fixture_attempts', 0 ) );
			if ( get_option( 'kntnt_conversion_fixture_fail' ) ) {
				throw new RuntimeException( 'AUDIT-CONVERSION-FAULT: private diagnostic' );
			}
		}
		return home_url();
	} );
	$matrix = new Content_Matrix();
	$matrix->register_column( new Capability_Column( 'md', 'Markdown', '', static fn(): bool => true ) );
	$matrix->register_column( new Capability_Column( 'llms_full', 'Full', 'md', static fn( string $type ): bool => $type === 'page' ) );
	$eligibility = new Eligibility( $matrix, new Exclusions( static fn(): string => '', 'home_url' ) );
	$locator = new Markdown_Alternate();
	$page = new Page_Markdown_Provider( $service, $eligibility, $locator );
	$full = new Full_Provider( new Full_Builder( $eligibility, new Selected_Types( $matrix, $eligibility ), $locator, $service ), new Cache_Version(), $locator );
	$registry = new Artifact_Registry();
	$registry->register( $page );
	$registry->register( $full );
	$router = new Serve_Router( $store, $registry, $logger );
	( new Kntnt\Ai_Visibility\Markdown\Request_Handler( $page, $service, $store, $router, $logger ) )->handle();
	( new Kntnt\Ai_Visibility\Llms\Request_Handler( [ $full ], $flight, $store, $router, $logger ) )->handle();
}, -1 );
