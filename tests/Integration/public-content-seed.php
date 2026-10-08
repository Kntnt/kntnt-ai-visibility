<?php
/**
 * Seeds a theme-visible field body separately from ordinary block content.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install the same explicit renderer used by this fixture's HTML template.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/public-content-helper.php', WP_CONTENT_DIR . '/mu-plugins/public-content-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
$sources = [];
foreach ( [ 'field', 'ordinary', 'empty', 'draft' ] as $name ) {

	// Store public and internal fields without assuming stored data is output.
	$id = wp_insert_post( [
		'post_type' => 'page',
		'post_status' => $name === 'draft' ? 'draft' : 'publish',
		'post_name' => 'public-content-' . $name,
		'post_title' => 'Public content ' . $name,
		'post_content' => $name === 'ordinary' ? '<!-- wp:paragraph --><p>ORDINARY-BLOCK-TEXT</p><!-- /wp:paragraph -->[public_content_fixture]' : '',
		'meta_input' => [
			'public_content_fixture' => $name,
			'public_section' => $name === 'field' ? '<h2>FIELD-PUBLIC-HEADING</h2><p>FIELD-PUBLIC-BODY <a href="/public-link">field link</a></p>' : '',
			'internal_notes' => 'INTERNAL-NEVER-PUBLISH-' . $name,
			'unpublished_section' => 'UNPUBLISHED-NEVER-PUBLISH-' . $name,
		],
	] );
	$sources[ $name ] = $id;

}
update_option( 'kntnt_public_content_sources', $sources );
flush_rewrite_rules();
