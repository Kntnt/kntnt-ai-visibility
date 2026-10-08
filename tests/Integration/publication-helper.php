<?php
/**
 * Revokes the source through native hooks while its body is being rendered.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Content\Content_Matrix;
use Kntnt\Ai_Visibility\Core\Content\Exclusions;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Front_Matter;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown_Service;
use Kntnt\Ai_Visibility\Core\Plugin_Logger;
use Kntnt\Ai_Visibility\Core\Publication_Source;
use Kntnt\Ai_Visibility\Plugin;

/** Makes only the authoritative cache-version SELECT fail in the real adapter. */
function publication_sql_failure(): void {
	global $wpdb;
	$wpdb->show_errors();
	add_filter( 'query', static function ( string $query ): string {
		return str_starts_with( $query, 'SELECT option_value FROM ' )
			&& str_contains( $query, "'kntnt_ai_visibility_cache_version'" )
			? 'SELECT option_value FROM publication_missing_options'
			: $query;
	} );
}

if ( ( $_SERVER['HTTP_X_PUBLICATION_SQL_FAILURE'] ?? '' ) === 'early' ) {
	publication_sql_failure();
} elseif ( ( $_SERVER['HTTP_X_PUBLICATION_SQL_FAILURE'] ?? '' ) === 'late' ) {
	add_action( 'template_redirect', 'publication_sql_failure', -50 );
}

/** Builds the real public service graph for deliberately queued source work. */
function publication_service(): Page_Markdown_Service {
	$matrix = new Content_Matrix( static fn(): array => get_option( 'kntnt_ai_visibility', [] )['content_types'] ?? [] );
	$matrix->register_column( new Capability_Column( 'md', 'Markdown', '', static fn(): bool => true ) );
	$state = new Publication_Source(
		static fn(): Eligibility => new Eligibility(
			$matrix,
			new Exclusions( static fn(): string => get_option( 'kntnt_ai_visibility', [] )['exclusions']['paths'] ?? '', home_url( ... ) ),
		),
		new Markdown_Alternate(),
	);
	$store = new File_Store( Plugin::cache_dir( ... ) );
	return new Page_Markdown_Service( new Front_Matter(), new Single_Flight( $store ), new Plugin_Logger(), publication_source: $state );
}

/** Queues work, changes native state, optionally fills the current identity. */
function publication_queued( string $action ): array {
	[ , $mode, $case, $temperature ] = explode( '-', $action );
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( $mode === 'plain' ? '' : '/%postname%/' );
	update_option( 'show_on_front', 'posts' );
	update_option( 'kntnt_ai_visibility', [] );
	$parent_id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Queue parent', 'post_name' => $action . '-parent' ] );
	$id = wp_insert_post( [
		'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Queued source',
		'post_name' => $action, 'post_content' => 'OBSOLETE-QUEUED-BODY',
		'post_parent' => $case === 'parent' ? $parent_id : 0,
	] );
	if ( $case === 'front' ) {
		update_option( 'page_on_front', $id );
		update_option( 'show_on_front', 'page' );
	}
	$source = get_post( $id );
	$parent = get_post( $parent_id );
	$locator = new Markdown_Alternate();
	$old_identity = $locator->identity_for( $source );
	$old_url = $locator->url_for( $source );
	$old_options = wp_load_alloptions();
	if ( $case === 'parent' ) {
		wp_update_post( [ 'ID' => $parent_id, 'post_name' => $action . '-current-parent' ] );
	} elseif ( $case === 'front' ) {
		update_option( 'show_on_front', 'posts' );

	} else {
		wp_update_post( [ 'ID' => $id, 'post_name' => $case === 'rename' ? $action . '-current' : $action, 'post_content' => 'CURRENT-QUEUED-BODY' ] );
	}
	$current = get_post( $id );
	$current_url = $locator->url_for( $current );
	$service = publication_service();
	if ( $temperature === 'warm' ) {
		$service->materialise( $locator->identity_for( $current ), $current );
	}

	// Inject the stale cache state another request may have left behind.
	// The queued WP_Post is genuinely retained across the native mutation above.
	wp_cache_set( $id, $source, 'posts' );
	wp_cache_set( $parent_id, $parent, 'posts' );
	wp_cache_set( 'alloptions', $old_options, 'options' );
	$cache = $GLOBALS['wp_object_cache'];
	$refused = false;
	try {
		$service->materialise( $old_identity, $source );
	} catch ( \Kntnt\Ai_Visibility\Core\Cache\Obsolete_Artifact ) {
		$refused = true;
	}
	$restored = $GLOBALS['wp_object_cache'] === $cache;
	return [ 'refused' => $refused, 'restored' => $restored, 'old' => $old_url, 'current' => $current_url, 'case' => $case ];
}

add_filter( 'kntnt_ai_visibility_is_excluded', static function ( bool $excluded, WP_Post $post ): bool {
	// Enumeration already holds this WP_Post when another writer changes it.
	if ( $post->post_name === 'publication-queued' && get_option( 'publication_queue' ) ) {
		delete_option( 'publication_queue' );
		wp_update_post( [ 'ID' => $post->ID, 'post_name' => 'publication-current', 'post_content' => 'CURRENT-SOURCE' ] );
	}
	return $excluded;
}, 10, 2 );

foreach ( [ 'kntnt_ai_visibility_llms_txt' => 'index', 'kntnt_ai_visibility_llms_full_txt' => 'full' ] as $hook => $kind ) {
	add_filter( $hook, static function ( string $document ) use ( $kind ): string {
		$final = get_option( 'publication_final' );
		if ( is_array( $final ) && $final['kind'] === $kind ) {
			delete_option( 'publication_final' );
			wp_update_post( $kind === 'full'
				? [ 'ID' => $final['id'], 'post_password' => 'revoked-final-secret' ]
				: [ 'ID' => $final['id'], 'post_status' => 'draft' ] );
		}
		return $document;
	} );
}

add_shortcode( 'publication_revoke', static function ( array|string $attributes ): string {

	// This is the actual production save path, entered re-entrantly by rendering.
	$case = is_array( $attributes ) ? ( $attributes['case'] ?? 'draft' ) : 'draft';
	$id = get_the_ID();
	if ( $case === 'password' ) {
		wp_update_post( [ 'ID' => $id, 'post_password' => 'revoked-secret' ] );
	} elseif ( $case === 'exclusion' ) {
		$options = get_option( 'kntnt_ai_visibility', [] );
		$options['exclusions']['paths'] = '/publication-exclusion/';
		update_option( 'kntnt_ai_visibility', $options );
	} elseif ( $case === 'whole' ) {
		// A separate same-base store is a real deactivation/clear-cache callsite.
		( new \Kntnt\Ai_Visibility\Core\Cache\File_Store( \Kntnt\Ai_Visibility\Plugin::cache_dir( ... ) ) )->flush_all();
	} else {
		wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
	}
	return 'REVOKED-DURING-RENDER';

} );

add_action( 'template_redirect', static function (): void {
	if ( ( $_GET['publication_token'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	if ( ( $_GET['publication_action'] ?? '' ) === 'queue' ) {
		wp_insert_post( [
			'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'publication-queued',
			'post_title' => 'Queued source', 'post_content' => 'OBSOLETE-QUEUED-SOURCE',
		] );
		update_option( 'publication_queue', true );
	}
	$action = (string) ( $_GET['publication_action'] ?? '' );
	if ( str_starts_with( $action, 'sql-policy-' ) ) {
		global $wpdb;
		$before = str_contains( $action, '-quiet' );
		$wpdb->suppress_errors( $before );
		$failed = str_ends_with( $action, '-failure' );
		if ( $failed ) {
			publication_sql_failure();
		}
		$refused = false;
		try {
			( new \Kntnt\Ai_Visibility\Core\Cache\Cache_Version() )->current();
		} catch ( \Kntnt\Ai_Visibility\Core\Cache\Obsolete_Artifact ) {
			$refused = true;
		}
		header( 'Content-Type: application/json' );
		echo wp_json_encode( [
			'before' => $before, 'after' => $wpdb->suppress_errors, 'refused' => $refused,
			'debug' => WP_DEBUG, 'display' => WP_DEBUG_DISPLAY,
		] );
		exit;
	}
	if ( $action === 'sql-prepare' ) {
		$post = get_page_by_path( 'publication-sql-source' );
		wp_insert_post( [
			'ID' => $post?->ID ?? 0, 'post_type' => 'page', 'post_name' => 'publication-sql-source',
			'post_status' => 'publish', 'post_title' => 'CURRENT-SQL-SOURCE', 'post_content' => 'CURRENT-SQL-SOURCE',
		] );
		( new File_Store( Plugin::cache_dir( ... ) ) )->flush_all();
	}
	if ( str_starts_with( $action, 'queued-' ) ) {
		header( 'Content-Type: application/json' );
		echo wp_json_encode( publication_queued( $action ) );
		exit;
	}
	if ( in_array( $action, [ 'inline', 'password', 'exclusion', 'whole', 'head', 'conditional' ], true ) ) {
		wp_insert_post( [
			'post_type' => 'post', 'post_status' => 'publish', 'post_name' => 'publication-' . $action,
			'post_title' => 'Publication ' . $action,
			'post_content' => '[publication_revoke case="' . $action . '"]',
		] );
	}
	if ( str_starts_with( $action, 'final-' ) ) {
		$kind = $action === 'final-index' ? 'index' : 'full';
		$id = wp_insert_post( [
			'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'publication-' . $action,
			'post_title' => 'REVOKED-FINAL-' . $action, 'post_content' => 'REVOKED-FINAL-' . $action,
		] );
		update_option( 'publication_final', [ 'kind' => $kind, 'id' => $id ] );
	}
	if ( ( $_GET['publication_action'] ?? '' ) === 'version' ) {
		// Emulate another writer after this request has primed WordPress options.
		global $wpdb;
		$version = new \Kntnt\Ai_Visibility\Core\Cache\Cache_Version();
		$before = $version->current();
		get_option( $version::OPTION );
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s", $before + 7, $version::OPTION ) );
		$current = $version->current();
		$version->bump();
		header( 'Content-Type: application/json' );
		echo wp_json_encode( [ 'before' => $before, 'current' => $current, 'after' => $version->current() ] );
		exit;
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [ 'php' => PHP_VERSION, 'ready' => get_option( 'publication_ready' ) ] );
	exit;
}, -100 );
