<?php
/**
 * The Markdown-alternate feature module.
 *
 * Wires Release 1's one feature: per-page Markdown alternates served by content
 * negotiation (docs/spec/markdown-alternate.md). boot() is the single place the
 * module's collaborators are assembled and registered against Core — the
 * eligibility-gated provider, the settings section, the request handler (rewrite
 * rules, query vars, the template_redirect serve), the discovery `<link>` tags,
 * the per-entity cache invalidation, and the privileged clear-cache action. The
 * module depends only on Core abstractions and never reaches into another module
 * (docs/adr/0006).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Markdown;

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Module as Module_Contract;

/**
 * Boots and wires the Markdown-alternate module.
 *
 * @since 0.1.0
 */
final class Module implements Module_Contract {

	/**
	 * Builds the module's collaborators and registers them against Core.
	 *
	 * @since 0.1.0
	 *
	 * @param Core $core The Core service facade.
	 * @return void
	 */
	public function boot( Core $core ): void {

		// The eligibility-gated provider is the single rule that covers every
		// eligible page; register it as the artifact source of truth.
		$eligibility = new Eligibility( $core->settings() );
		$provider = new Page_Markdown_Provider( $core->page_markdown(), $eligibility );
		$core->artifacts()->register( $provider );

		// Contribute the module's settings section to the shared options page.
		$core->settings()->register_section( ( new Settings() )->section() );

		// The PHP serve path: rewrite rules, query vars and the template_redirect
		// handler the early router falls through to on a miss.
		( new Request_Handler(
			$provider,
			$core->page_markdown(),
			$core->cache(),
			$core->router(),
			$core->logger(),
		) )->register();

		// Per-page discovery `<link>` tags, walking the provider registry.
		( new Discovery( $core->artifacts() ) )->register();

		// Per-entity, delete-on-change invalidation; kept so the clear-cache
		// action can reuse its whole-cache flush.
		$invalidation = new Invalidation( $provider, $core->cache(), new Cache_Version() );
		$invalidation->register();

		// The privileged clear-cache button action, flushing through Invalidation.
		add_action(
			'admin_post_' . Settings::CLEAR_CACHE_ACTION,
			function () use ( $invalidation ): void {
				$this->handle_clear_cache( $invalidation );
			},
		);

	}

	/**
	 * Flushes the whole Markdown cache for an authorised clear-cache request.
	 *
	 * Verifies capability and nonce, flushes, then redirects back to the page the
	 * button was on so the round-trip stays on the settings screen.
	 *
	 * @since 0.1.0
	 *
	 * @param Invalidation $invalidation The cache invalidator.
	 * @return void
	 */
	private function handle_clear_cache( Invalidation $invalidation ): void {

		// Only an authorised, nonce-checked request may flush the cache.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to clear the cache.', 'kntnt-ai-visibility' ) );
		}
		check_admin_referer( Settings::CLEAR_CACHE_ACTION );

		// Flush, then return to the originating settings screen with a flag.
		$invalidation->flush();
		$referer = wp_get_referer();
		$back = $referer !== false ? $referer : admin_url();
		wp_safe_redirect( add_query_arg( 'kntnt_ai_visibility_cache_cleared', '1', $back ) );

		exit;

	}

}
