<?php
/**
 * Seeds the real two-generation response/pruning tracer.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/pruning-helper.php', WP_CONTENT_DIR . '/mu-plugins/pruning-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
wp_insert_post( [
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_name' => 'pruning-source',
    'post_title' => 'Pruning source',
    'post_content' => 'ORDINARY SOURCE',
] );
update_option( 'pruning_ready', true );
flush_rewrite_rules();
