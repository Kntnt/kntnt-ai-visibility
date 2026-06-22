<?php
/**
 * The Markdown-alternate feature module.
 *
 * Wires Release 1's one feature: per-page Markdown alternates served by content
 * negotiation (docs/spec/markdown-alternate.md). boot() is the single place the
 * module's collaborators are assembled and registered against Core — its `md`
 * capability column on the Core content-type matrix, the eligibility-gated
 * provider, the request handler (rewrite rules, query vars, the
 * template_redirect serve), the discovery `<link>` tags and the per-entity cache
 * invalidation. The matrix, eligibility and the clear-cache action are Core
 * concerns now (docs/spec/llms-txt.md §2); the module reads only its own column.
 * It depends only on Core abstractions and never reaches into another module
 * (docs/adr/0006).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Markdown;

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
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

		// Contribute the `.md` capability column: on by default for every
		// viewable type. It stands alone (no dependency) and is the column the
		// llms columns require.
		$core->content_types()->register_column(
			new Capability_Column(
				'md',
				__( 'Markdown (.md)', 'kntnt-ai-visibility' ),
				'',
				static fn( string $type ): bool => true,
			),
		);

		// The eligibility-gated provider is the single rule that covers every
		// eligible page; register it as the artifact source of truth.
		$provider = new Page_Markdown_Provider( $core->page_markdown(), $core->eligibility(), $core->markdown_alternate() );
		$core->artifacts()->register( $provider );

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

		// Per-entity, delete-on-change invalidation, plus the indirect-change
		// whole-cache flush.
		( new Invalidation( $provider, $core->cache(), new Cache_Version() ) )->register();

	}

}
