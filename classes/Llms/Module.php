<?php
/**
 * The llms.txt feature module.
 *
 * Wires Release 2's feature: the singleton `/llms.txt` and `/llms-full.txt`
 * artifacts (docs/spec/llms-txt.md §4). boot() is the single place the module's
 * collaborators are assembled and registered against Core — its `llms` and
 * `llms_full` capability columns on the content-type matrix, the two singleton
 * providers, the request handler (rewrite rules, query var, the template_redirect
 * serve) and the cache-version-bump invalidation. The module depends only on Core
 * abstractions and never reaches into the Markdown module (docs/adr/0006); it
 * reuses the Core markdown-alternate locator and Page_Markdown to link and
 * concatenate the per-page alternates.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Llms;

use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Content\Capability_Column;
use Kntnt\Ai_Visibility\Core\Core;
use Kntnt\Ai_Visibility\Core\Module as Module_Contract;

/**
 * Boots and wires the llms.txt module.
 *
 * @since 0.2.0
 */
final class Module implements Module_Contract {

	/**
	 * Builds the module's collaborators and registers them against Core.
	 *
	 * @since 0.2.0
	 *
	 * @param Core $core The Core service facade.
	 * @return void
	 */
	public function boot( Core $core ): void {

		// Contribute the two capability columns: both require `.md` (the index
		// links to it, the full file concatenates it). llms.txt is on by default
		// for every type; llms-full.txt defaults to Pages only.
		$core->content_types()->register_column(
			new Capability_Column( 'llms', __( 'In llms.txt', 'kntnt-ai-visibility' ), 'md', static fn( string $type ): bool => true ),
		);
		$core->content_types()->register_column(
			new Capability_Column( 'llms_full', __( 'In llms-full.txt', 'kntnt-ai-visibility' ), 'md', static fn( string $type ): bool => $type === 'page' ),
		);

		// Build the type resolver and the two builders over the Core seams.
		$selected = new Selected_Types( $core->content_types() );
		$index_builder = new Index_Builder( $core->eligibility(), $selected, $core->markdown_alternate() );
		$full_builder = new Full_Builder( $core->eligibility(), $selected, $core->markdown_alternate(), $core->page_markdown() );

		// Build and register the two singleton providers, sharing the cache-version
		// stamp with the invalidation so both agree on the version-stamped key.
		$version = new Cache_Version();
		$index_provider = new Index_Provider( $index_builder, $version, $core->markdown_alternate() );
		$full_provider = new Full_Provider( $full_builder, $version, $core->markdown_alternate() );
		$core->artifacts()->register( $index_provider );
		$core->artifacts()->register( $full_provider );

		// The PHP serve path: rewrite rules, query var and the template_redirect
		// handler the early router falls through to on a miss.
		( new Request_Handler(
			[ $index_provider, $full_provider ],
			$core->single_flight(),
			$core->cache(),
			$core->router(),
			$core->logger(),
		) )->register();

		// Cache-version-bump invalidation on a servable post change.
		( new Invalidation( $core->eligibility(), $version ) )->register();

	}

}
