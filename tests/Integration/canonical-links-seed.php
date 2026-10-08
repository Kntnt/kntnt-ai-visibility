<?php
/**
 * Seeds canonical-link sources in disposable Playground only.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require __DIR__ . '/plain-permalink-seed.php';

// Begin with the no-trailing-slash policy reported in the ticket.
global $wp_rewrite;
$wp_rewrite->set_permalink_structure( '/%postname%' );
copy( __DIR__ . '/canonical-links-helper.php', WP_CONTENT_DIR . '/mu-plugins/canonical-links-helper.php' );
flush_rewrite_rules();
