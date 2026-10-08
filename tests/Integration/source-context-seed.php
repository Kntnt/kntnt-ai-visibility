<?php
/**
 * Seeds translated sources for the real WordPress rendering context regression.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

require '/wordpress/wp-load.php';

// Install actual shortcodes, dynamic blocks and a field-backed content filter.
wp_mkdir_p( WP_CONTENT_DIR . '/mu-plugins' );
copy( __DIR__ . '/source-context-helper.php', WP_CONTENT_DIR . '/mu-plugins/source-context-helper.php' );
update_option( 'permalink_structure', '/%postname%/' );
bogo_set_prop( 'enus_deactivated', true );
$sources = [];
foreach ( [ 'a', 'b' ] as $name ) {
	foreach ( [ 'en_GB', 'sv_SE' ] as $locale ) {
		$id = wp_insert_post( [
			'post_type' => 'page',
			'post_status' => 'publish',
			'post_name' => 'source-' . $name,
			'post_title' => 'Source ' . $name . ' ' . $locale,
			'post_content' => '<p>SOURCE-' . $name . '-' . $locale . '</p>[source_context]<!-- wp:kntnt-source/context /-->',
			'meta_input' => [ '_locale' => $locale, 'source_field' => 'PUBLIC-FIELD-' . $name . '-' . $locale ],
		] );
		wp_update_post( [ 'ID' => $id, 'post_name' => 'source-' . $name ] );
		$sources[ $name . '-' . $locale ] = [ 'id' => $id, 'name' => $name, 'locale' => $locale ];
	}
	update_post_meta( $sources[ $name . '-sv_SE' ]['id'], '_original_post', get_post_meta( $sources[ $name . '-en_GB' ]['id'], '_original_post', true ) );
}
update_option( 'kntnt_source_context_sources', $sources );
update_option( 'kntnt_source_context_user', wp_insert_user( [ 'user_login' => 'source-context-admin', 'user_pass' => 'fixture-only', 'role' => 'administrator' ] ) );
flush_rewrite_rules();
