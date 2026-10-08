<?php
/**
 * Seeds public metadata and a genuine shared block in disposable Playground.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Keep controls and content integrations confined to this fixture installation.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/inline-validators-helper.php', WP_CONTENT_DIR . '/mu-plugins/inline-validators-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
$shared = wp_insert_post( [
	'post_type' => 'wp_block',
	'post_status' => 'publish',
	'post_title' => 'Public shared block',
	'post_content' => '<!-- wp:paragraph --><p>SHARED-ORIGINAL</p><!-- /wp:paragraph -->',
] );
$page = wp_insert_post( [
	'post_type' => 'page',
	'post_status' => 'publish',
	'post_name' => 'ordinary',
	'post_title' => 'Inline validator fixture',
	'post_content' => '[inline_fixture_meta]' . "\n\n" . '<!-- wp:block {"ref":' . $shared . '} /-->',
] );
update_post_meta( $page, 'fixture_public_meta', 'META-ORIGINAL' );
update_option( 'kntnt_inline_fixture_page', $page );
update_option( 'kntnt_inline_fixture_shared', $shared );
flush_rewrite_rules();
