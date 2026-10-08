<?php
/**
 * Exercises WordPress activation APIs independently of the plugin lifecycle.
 *
 * Copied to mu-plugins only in disposable Playground. No production route or
 * test backdoor is registered by the plugin itself.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// An unrelated owner must survive the plugin's rewrite cleanup.
add_action( 'init', static function (): void {
	add_rewrite_rule( '^fixture-keep$', 'index.php?deactivation_fixture=keep', 'top' );
} );
add_filter( 'query_vars', static function ( array $vars ): array {
	$vars[] = 'deactivation_fixture';
	return $vars;
} );
add_action( 'template_redirect', static function (): void {

	// This independent route also proves persisted rule usability over HTTP.
	if ( get_query_var( 'deactivation_fixture' ) === 'keep' ) {
		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'UNRELATED-ROUTE';
		exit;
	}

}, -100 );

// A valid native deactivation can also precede construction of WP_Rewrite.
add_action( 'plugins_loaded', static function (): void {

	// The final control waits for cleanup after the missing rewrite object exists.
	$token = isset( $_GET['deactivation_token'] ) ? sanitize_text_field( wp_unslash( $_GET['deactivation_token'] ) ) : '';
	$action = isset( $_GET['deactivation_action'] ) ? sanitize_key( wp_unslash( $_GET['deactivation_action'] ) ) : '';
	if ( $token === 'fixture-only' && $action === 'deactivate-bootstrap' ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( 'kntnt-ai-visibility/kntnt-ai-visibility.php' );
	}

} );

// WP_Rewrite exists after theme setup, before init registers module routes.
add_action( 'after_setup_theme', static function (): void {

	// The normal control reports only after WordPress's deferred flush runs.
	$token = isset( $_GET['deactivation_token'] ) ? sanitize_text_field( wp_unslash( $_GET['deactivation_token'] ) ) : '';
	$action = isset( $_GET['deactivation_action'] ) ? sanitize_key( wp_unslash( $_GET['deactivation_action'] ) ) : '';
	if ( $token === 'fixture-only' && $action === 'deactivate-early' ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( 'kntnt-ai-visibility/kntnt-ai-visibility.php' );
	}

} );

// Run normal administrative operations after init has registered every rule.
add_action( 'wp_loaded', static function (): void {

	// A fixed fixture token confines mutation to this disposable test setup.
	$token = isset( $_GET['deactivation_token'] ) ? sanitize_text_field( wp_unslash( $_GET['deactivation_token'] ) ) : '';
	if ( $token !== 'fixture-only' ) {
		return;
	}
	$action = isset( $_GET['deactivation_action'] ) ? sanitize_key( wp_unslash( $_GET['deactivation_action'] ) ) : 'state';
	$plugin = 'kntnt-ai-visibility/kntnt-ai-visibility.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	if ( $action === 'native-baseline' ) {

		// The plain-mode reference deliberately bypasses OUR cleanup. With an
		// already empty native rewrite option, later requests are WordPress-only.
		deactivate_plugins( $plugin, true );

	} elseif ( $action === 'deactivate-overlap' ) {

		// An independent owner may replace a pattern, without owning our marker.
		add_rewrite_rule( '^index\.md$', 'index.php?deactivation_fixture=keep', 'top' );
		add_rewrite_rule( '^llms\.txt$', 'index.php?deactivation_fixture=keep', 'top' );
		deactivate_plugins( $plugin );

	} elseif ( $action === 'deactivate' ) {
		deactivate_plugins( $plugin );
	} elseif ( $action === 'activate' ) {
		$result = activate_plugin( $plugin );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 500 );
		}
	} elseif ( $action === 'pretty' || $action === 'plain' ) {
		$GLOBALS['wp_rewrite']->set_permalink_structure( $action === 'pretty' ? '/%postname%/' : '' );
		flush_rewrite_rules();
	} elseif ( $action === 'update-inactive' ) {

		// With the ordinary plugin unloaded, no plugin invalidation can mask a
		// failed deactivation cache cleanup before the next activation.
		if ( is_plugin_active( $plugin ) ) {
			wp_send_json_error( 'Expected an inactive plugin before source mutation.', 500 );
		}
		$sources = get_option( 'kntnt_deactivation_sources' );
		$version = (int) get_option( 'kntnt_deactivation_version', 0 ) + 1;
		wp_update_post( [
			'ID' => $sources['ordinary'],
			'post_content' => '<p>DEACTIVATION-ordinary-UPDATED-' . $version . '</p>',
		] );
		update_option( 'kntnt_deactivation_version', $version );
	}

	// The persisted rules and public settings are explicit ticket test seams.
	wp_send_json( [
		'php' => PHP_VERSION,
		'active' => is_plugin_active( $plugin ),
		'rules' => get_option( 'rewrite_rules', [] ),
		'sources' => get_option( 'kntnt_deactivation_sources' ),
		'settings' => get_option( 'kntnt_ai_visibility' ),
		'version' => (int) get_option( 'kntnt_deactivation_version', 0 ),
	] );

}, PHP_INT_MAX );
