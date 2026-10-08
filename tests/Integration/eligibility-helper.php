<?php
/**
 * Supplies deployment-controlled filter policies on disposable Playground.
 *
 * The fixture option simulates deploying integration code with a different
 * filter return value. It deliberately uses no plugin settings option or
 * invalidation hook: the documented deployment purge is a separate step.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// Register policy before ordinary plugins and the early router load.
add_filter( 'kntnt_ai_visibility_eligible_post_types', static function ( array $types ): array {
	return match ( get_option( 'kntnt_eligibility_fixture_policy', 'no-page' ) ) {
		'open' => $types,
		'empty' => [],
		default => array_values( array_diff( $types, ['page'] ) ),
	};
} );
add_filter( 'kntnt_ai_visibility_llms_post_types', static fn(): array => ['page', 'post'] );
add_filter( 'kntnt_ai_visibility_llms_full_post_types', static fn(): array => ['page', 'post'] );

// Provide controls only in this test fixture; purge uses the real admin action.
add_action( 'init', static function (): void {
	if ( ( $_GET['eligibility_token'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = $_GET['eligibility_action'] ?? '';
	if ( in_array( $action, ['open', 'no-page', 'empty'], true ) ) {
		update_option( 'kntnt_eligibility_fixture_policy', $action );
		echo 'policy-updated';
		exit;
	}
	if ( $action === 'purge-url' && current_user_can( 'manage_options' ) ) {
		echo wp_nonce_url(
			admin_url( 'admin-post.php?action=kntnt_ai_visibility_clear_cache' ),
			'kntnt_ai_visibility_clear_cache',
		);
		exit;
	}
	if ( $action === 'ready' && get_option( 'kntnt_eligibility_fixture_ready' ) ) {
		echo 'eligibility-ready';
		exit;
	}
}, -100 );
