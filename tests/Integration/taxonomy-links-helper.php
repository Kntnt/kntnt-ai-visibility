<?php
/**
 * Observes actual archives and exercises WordPress term lookup failures.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

use Kntnt\Ai_Visibility\Core\Front_Matter;

// Exercise encoded URL query characters without changing the archive path.
add_filter( 'term_link', static function ( string $url, WP_Term $term ): string|WP_Error {
	$ids = get_option( 'kntnt_taxonomy_fixture_terms', [] );
	if ( ! in_array( $term->term_id, $ids, true ) ) {
		return $url;
	}
	if ( get_option( 'kntnt_taxonomy_fixture_link_errors', false ) ) {
		return new WP_Error( 'fixture_term_link', 'Term archive lookup unavailable.' );
	}
	return $term->taxonomy === 'post_tag' ? $url . '?label=%22%C3%85%22&path=%5Carkiv%5C' : $url;
}, 10, 2 );

// Prove followed URLs reached WordPress's actual category/tag query workflow.
add_action( 'template_redirect', static function (): void {
	if ( is_category() || is_tag() ) {
		$term = get_queried_object();
		header( 'X-Kntnt-Taxonomy-Archive: ' . $term->taxonomy );
		header( 'X-Kntnt-Taxonomy-Term: ' . $term->term_id );
	}
}, -100 );

// Keep fixed state/error controls confined to this disposable fixture.
add_action( 'wp_loaded', static function (): void {
	$action = sanitize_key( (string) ( $_GET['taxonomy_fixture'] ?? '' ) );
	if ( $action === '' ) {
		return;
	}
	$post = get_post( (int) get_option( 'kntnt_taxonomy_fixture_post' ) );
	$ids = get_option( 'kntnt_taxonomy_fixture_terms', [] );
	$data = [ 'php' => PHP_VERSION ];
	if ( $action === 'link-errors' ) {
		update_option( 'kntnt_taxonomy_fixture_link_errors', true );
		$data['link_error'] = is_wp_error( get_term_link( get_term( $ids['category'] ) ) );
		$data['frontmatter'] = ( new Front_Matter() )->build( $post );
	} elseif ( $action === 'term-errors' ) {
		add_filter( 'get_the_terms', static fn(): WP_Error => new WP_Error( 'fixture_terms', 'Term lookup unavailable.' ) );
		$data['terms_error'] = is_wp_error( get_the_terms( $post, 'category' ) );
		$data['frontmatter'] = ( new Front_Matter() )->build( $post );
	} else {
		update_option( 'kntnt_taxonomy_fixture_link_errors', false );
		foreach ( $ids as $taxonomy => $id ) {
			$term = get_term( $id );
			$data['terms'][ $taxonomy ] = [ 'id' => $id, 'name' => $term->name, 'url' => get_term_link( $term ) ];
		}
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( $data );
	exit;
}, 100 );
