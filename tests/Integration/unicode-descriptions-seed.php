<?php
/**
 * Seeds excerpt boundary examples in disposable WordPress only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Give each Unicode boundary its own observable llms.txt entry.
update_option( 'permalink_structure', '/%postname%/' );
$excerpts = [
	'Odd boundary' => str_repeat( 'a', 199 ) . 'ö',
	'Odd over limit' => str_repeat( 'a', 199 ) . 'öZ',
	'All multibyte exact' => str_repeat( '界', 200 ),
	'All multibyte over limit' => str_repeat( '界', 201 ),
	'Mixed characters' => str_repeat( 'a', 198 ) . 'ö🙂Z',
	'Decoded entity' => str_repeat( 'a', 199 ) . '&ouml;',
	'Cleaned excerpt' => '<p>[gallery] ' . str_repeat( '&#246;', 199 ) . "</p>\r\n<span>&amp;Z</span>",
];
foreach ( $excerpts as $title => $excerpt ) {
	wp_insert_post( [
		'post_type' => 'page',
		'post_status' => 'publish',
		'post_title' => $title,
		'post_name' => sanitize_title( $title ),
		'post_content' => '<p>UNICODE-EXCERPT-SOURCE</p>',
		'post_excerpt' => $excerpt,
	] );
}

// Expose runtime facts without changing any builder or excerpt behaviour.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/unicode-descriptions-helper.php', WP_CONTENT_DIR . '/mu-plugins/unicode-descriptions-helper.php' );
flush_rewrite_rules();
