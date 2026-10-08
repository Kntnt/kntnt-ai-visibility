<?php
/**
 * Seeds real Unicode category/tag archives in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Give each real taxonomy archive a published source to display.
update_option( 'permalink_structure', '/%postname%/' );
$category = wp_insert_term( 'Nyheter "Å, Ä & Ö"', 'category', [ 'slug' => 'nyheter-åäö' ] );
$tag = wp_insert_term( 'Råd & tips 😀', 'post_tag', [ 'slug' => 'råd-tips' ] );
$post = wp_insert_post( [
	'post_type' => 'post',
	'post_status' => 'publish',
	'post_name' => 'taxonomy-source',
	'post_title' => 'Taxonomy source',
	'post_content' => '<p>TAXONOMY-ARCHIVE-FIXTURE-BODY</p>',
] );
wp_set_object_terms( $post, [ (int) $category['term_id'] ], 'category' );
wp_set_object_terms( $post, [ (int) $tag['term_id'] ], 'post_tag' );
update_option( 'kntnt_taxonomy_fixture_post', $post );
update_option( 'kntnt_taxonomy_fixture_terms', [ 'category' => (int) $category['term_id'], 'post_tag' => (int) $tag['term_id'] ] );

// Install fixture controls only in this temporary WordPress filesystem.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/taxonomy-links-helper.php', WP_CONTENT_DIR . '/mu-plugins/taxonomy-links-helper.php' );
flush_rewrite_rules();
