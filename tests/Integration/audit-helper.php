<?php
/**
 * Disposable Playground controls; never packaged in the plugin release.
 *
 * @package Tests\Integration
 * @since 0.5.2
 */

declare( strict_types = 1 );

// A dynamic render must use the source post and its locale, including in llms.
add_shortcode( 'audit_context', static fn(): string => '<p>CONTEXT-' . get_the_ID() . '-' . get_locale() . '</p>' );

// Exercise both Bogo URL policies without changing the installed application.
add_filter( 'bogo_use_implicit_lang', static fn(): bool => ! get_option( 'kntnt_audit_explicit_languages', false ) );

// The fixed test token is not an application credential; this file only exists
// in the disposable Playground instance created by the audit blueprint.
add_action( 'init', static function (): void {
	if ( ( $_GET['audit_token'] ?? '' ) !== 'fixture-only' ) {
		return;
	}
	$action = sanitize_key( (string) ( $_GET['audit_action'] ?? '' ) );
	if ( $action === 'unlock' ) {
		add_filter( 'post_password_required', '__return_false' );
		return;
	}
	if ( $action === 'explicit' ) {
		update_option( 'kntnt_audit_explicit_languages', true );
		flush_rewrite_rules();
		( new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => \Kntnt\Ai_Visibility\Plugin::cache_dir() ) )->flush_all();
	} elseif ( $action === 'front' ) {
		$ids = get_option( 'kntnt_audit_ids' );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $ids['en_GB'] );
	} elseif ( $action === 'flush' ) {
		( new \Kntnt\Ai_Visibility\Core\Cache\File_Store( static fn(): string => \Kntnt\Ai_Visibility\Plugin::cache_dir() ) )->flush_all();
	} else {
		$slug = sanitize_title( (string) ( $_GET['slug'] ?? '' ) );
		$post = get_page_by_path( $slug, OBJECT, 'post' );
		if ( ! $post ) {
			status_header( 404 );
			exit;
		}
		if ( $action === 'delete' ) {
			wp_delete_post( $post->ID, true );
		} else {
			$change = match ( $action ) {
				'draft' => [ 'post_status' => 'draft' ],
				'rename' => [ 'post_name' => 'renamed' ],
				'protect' => [ 'post_password' => 'fixture' ],
				default => [],
			};
			wp_update_post( [ 'ID' => $post->ID, ...$change ] );
		}
	}
	header( 'Content-Type: application/json' );
	echo wp_json_encode( get_option( 'kntnt_audit_ids' ) );
	exit;
}, 100 );
