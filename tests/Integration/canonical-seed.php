<?php
/**
 * Seeds full-path fixtures in the disposable Bogo Playground installation.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require __DIR__ . '/audit-seed.php';

// Include posts in the full aggregate so dated identities are covered there.
update_option( 'kntnt_ai_visibility', [ 'content_types' => [ 'post' => [ 'llms_full' => true ] ] ] );

// Preserve the audited translations and add paths whose parents are significant.
$ids = get_option( 'kntnt_audit_ids' );
$ids['parent'] = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'parent', 'post_title' => 'Parent' ] );
foreach ( [
    'nested' => [ 'post_type' => 'page', 'post_name' => 'child', 'post_parent' => $ids['parent'] ],
    'encoded' => [ 'post_type' => 'page', 'post_name' => 'l%c3%a4sa' ],
    'ordinary' => [ 'post_type' => 'post', 'post_name' => 'ordinary', 'post_date' => '2026-10-08 12:00:00' ],
    'language' => [ 'post_type' => 'page', 'post_name' => 'language-change' ],
] as $key => $post ) {
    $ids[ $key ] = wp_insert_post( [
        ...$post,
        'post_status' => 'publish',
        'post_title' => 'Canonical ' . $key,
        'post_content' => '<p>CANONICAL-' . $key . '</p>',
        'meta_input' => [ '_locale' => 'en_GB' ],
    ] );
}
update_option( 'kntnt_canonical_ids', $ids );
copy( __DIR__ . '/canonical-helper.php', WP_CONTENT_DIR . '/mu-plugins/canonical-helper.php' );
flush_rewrite_rules();
