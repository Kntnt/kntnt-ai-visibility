<?php
/**
 * The shared page-to-Markdown service contract.
 *
 * Rendering a post to Markdown — render the content, convert HTML to GFM, build
 * front-matter, assemble — is a Core service, not module-private, because the
 * Release-2 llms.txt module concatenates the same per-page Markdown into
 * llms-full.txt rather than rendering a second time (docs/adr/0007, GLOSSARY.md).
 * Designing it as a Core seam now is the committed-roadmap foresight of
 * docs/adr/0006.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Materialisation;

/**
 * Renders a post to its Markdown alternate and materialises it to the cache.
 *
 * @since 0.1.0
 */
interface Page_Markdown {

	/**
	 * Returns the post's Markdown — front-matter plus body.
	 *
	 * Pure of HTTP and caching: it renders, converts, builds front-matter and
	 * assembles. Used directly by the Markdown module and concatenated by the
	 * llms.txt module.
	 * The public HTML filter receives the source and the ordinary rendered HTML.
	 * It may select theme-visible field content before the shared conversion.
	 * All content and metadata filters run as an anonymous WordPress user with
	 * visitor credentials and request data hidden. Content and metadata run in
	 * the source's isolated singular main query, active Loop and Bogo locale.
	 * Links and images resolve against that source's complete canonical URL,
	 * preserving URI-reference path, query and fragment semantics in all forms.
	 * Mutable caller post/query/Loop/locale state and audience state are
	 * restored after success or failure; private previews are never rendered.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post to render.
	 * @return string The assembled Markdown document.
	 * @throws \DomainException When a password, preview or content cache veto prevents public rendering.
	 * @throws Markdown_Conversion_Failed When conversion fails; no bytes are valid.
	 * @throws Public_Content_Rendering_Failed When the public HTML adapter fails or returns a non-string.
	 */
	public function for_post( \WP_Post $post ): string;

	/**
	 * Materialises the post's Markdown and reports its persistence independently.
	 *
	 * Idempotent and single-flight (docs/spec §5.5): concurrent misses do not
	 * all render. A cache hit returns the cached bytes without rendering. The
	 * identity is supplied by the caller (the matching provider derives it), so
	 * this Core service stays free of any one artifact kind's key scheme.
	 * Unavailable persistence returns valid bytes with persisted=false; it does
	 * not mean generation failed and cannot authorise serving an older file.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The cache identity to materialise under.
	 * @param \WP_Post $post     The post to render on a miss.
	 * @return Materialisation The valid bytes and independent persistence outcome.
	 * @throws \DomainException When a password, preview or content cache veto prevents public publication.
	 * @throws Markdown_Conversion_Failed When conversion fails on a miss.
	 * @throws Public_Content_Rendering_Failed When public rendering fails on a miss.
	 */
	public function materialise( Identity $identity, \WP_Post $post ): Materialisation;

}
