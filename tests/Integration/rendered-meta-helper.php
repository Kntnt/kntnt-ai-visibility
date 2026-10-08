<?php
/**
 * Exercises declared public fields through native writers in disposable WordPress.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Cache\File_Store;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Plugin;

// This declaration invalidates dependencies; it never selects content for output.
add_filter( 'kntnt_ai_visibility_public_content_meta_keys', static function ( array $keys, WP_Post $source ): array {
	return $source->post_name === 'rendered-meta' ? [ ...$keys, 'audit_value', '_rendered_section', 'public_sections_*' ] : $keys;
}, 10, 2 );
add_shortcode( 'rendered_meta', static function (): string {
	return '<p>' . esc_html( get_post_meta( get_the_ID(), 'audit_value', true ) ?: 'META-ABSENT' ) . '</p>';
} );
add_filter( 'kntnt_ai_visibility_public_content_html', static function ( string $html, WP_Post $source ): string {
	if ( $source->post_name !== 'rendered-meta' ) {
		return $html;
	}
	$value = (string) get_post_meta( $source->ID, '_rendered_section', true );
	if ( get_option( 'rendered_meta_during_render' ) ) {
		delete_option( 'rendered_meta_during_render' );
		update_post_meta( $source->ID, '_rendered_section', 'PANEL-FRESH' );
	}
	return $html . '<section><p>' . esc_html( $value ?: 'PANEL-ABSENT' ) . '</p><p>'
		. esc_html( get_post_meta( $source->ID, 'public_sections_0_body', true ) ?: 'FAMILY-ABSENT' ) . '</p></section>';
}, 10, 2 );
add_action( 'init', static function (): void {
	register_post_meta( 'page', 'audit_value', [ 'single' => true, 'type' => 'string', 'show_in_rest' => true ] );
} );

// Inherited dates must still be removed from dynamically rendered inline output.
add_action( 'send_headers', static function (): void {
	if ( is_page( 'rendered-meta' ) ) {
		header( 'Last-Modified: Mon, 01 Jan 2001 00:00:00 GMT' );
	}
} );
add_action( 'template_redirect', static function (): void {
	if ( ! isset( $_GET['rendered_meta_fixture'] ) ) {
		return;
	}
	$action = sanitize_key( wp_unslash( $_GET['rendered_meta_fixture'] ) );
	$post = get_page_by_path( 'rendered-meta' );
	$id = $post->ID;
	$store = new File_Store( Plugin::cache_dir( ... ) );
	if ( $action === 'add' ) {
		add_post_meta( $id, 'audit_value', 'META-ADDED', true );
	}
	if ( $action === 'update' ) {
		update_post_meta( $id, 'audit_value', 'META-UPDATED' );
	}
	if ( $action === 'delete' ) {
		delete_post_meta( $id, 'audit_value' );
	}
	if ( $action === 'rest' ) {
		wp_set_current_user( 1 );
		$request = new WP_REST_Request( 'POST', '/wp/v2/pages/' . $id );
		$request->set_param( 'title', 'Rendered metadata' );
		$request->set_param( 'meta', [ 'audit_value' => 'META-REST-FINAL' ] );
		$response = rest_do_request( $request );
		if ( $response->get_status() !== 200 ) {
			wp_die( wp_json_encode( $response->get_data() ) );
		}
	}
	if ( $action === 'late-stage' ) {
		wp_update_post( [ 'ID' => $id, 'post_content' => '[rendered_meta]' ] );
		update_post_meta( $id, 'audit_value', 'META-INTERMEDIATE' );
	}
	if ( $action === 'late-final' ) {
		update_post_meta( $id, 'audit_value', 'META-LATE-FINAL' );
	}
	if ( $action === 'panel' ) {
		update_post_meta( $id, '_rendered_section', 'PANEL-CURRENT' );
	}
	if ( $action === 'family-add' ) {
		add_post_meta( $id, 'public_sections_0_body', 'FAMILY-ADDED', true );
	}
	if ( $action === 'family-update' ) {
		update_post_meta( $id, 'public_sections_0_body', 'FAMILY-UPDATED' );
	}
	if ( $action === 'family-delete' ) {
		delete_post_meta( $id, 'public_sections_0_body' );
	}
	if ( $action === 'thumbnail-one' || $action === 'thumbnail-two' ) {
		set_post_thumbnail( $id, (int) get_option( 'rendered_meta_' . $action ) );
	}
	if ( $action === 'thumbnail-remove' ) {
		delete_post_thumbnail( $id );
	}
	if ( $action === 'irrelevant' ) {
		update_post_meta( $id, '_edit_lock', 'irrelevant bookkeeping' );
		update_post_meta( $id, '_edit_last', 1 );
		update_post_meta( $id, 'private_secret', 'PRIVATE-DO-NOT-EXPOSE' );
	}
	if ( $action === 'revision' || $action === 'autosave' ) {
		$revision = wp_insert_post( [
			'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $id,
			'post_name' => $id . ( $action === 'autosave' ? '-autosave-v1' : '-revision-v1' ),
		] );
		// update_metadata keeps the native revision owner; update_post_meta redirects to parent.
		update_metadata( 'post', $revision, 'audit_value', 'REVISION-ONLY' );
		update_metadata( 'post', $revision, '_thumbnail_id', 123 );
	}
	if ( $action === 'during-render' ) {
		update_post_meta( $id, '_rendered_section', 'PANEL-BEFORE' );
		$store->flush_all();
		update_option( 'rendered_meta_during_render', true );
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( [
		'php' => PHP_VERSION, 'ready' => true, 'version' => ( new Cache_Version( $store ) )->current(),
		'modified' => get_post( $id )->post_modified_gmt,
		'thumbnail' => get_the_post_thumbnail_url( $id, 'full' ),
	] );
	exit;
}, -1000 );
