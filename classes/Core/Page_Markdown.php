<?php
/**
 * The shared page-to-Markdown service contract.
 *
 * Rendering a post to Markdown — render the content, convert HTML to GFM, build
 * front-matter, assemble — is a Core service, not module-private, because the
 * Release-2 llms.txt module concatenates the same per-page Markdown into
 * llms-full.txt rather than rendering a second time (docs/adr/0007, CONTEXT.md).
 * Designing it as a Core seam now is the committed-roadmap foresight of
 * docs/adr/0006.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;

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
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post to render.
	 * @return string The assembled Markdown document.
	 */
	public function for_post( \WP_Post $post ): string;

	/**
	 * Materialises the post's Markdown to the cache and returns the bytes.
	 *
	 * Idempotent and single-flight (docs/spec §5.5): concurrent misses do not
	 * all render. A cache hit returns the cached bytes without rendering. The
	 * identity is supplied by the caller (the matching provider derives it), so
	 * this Core service stays free of any one artifact kind's key scheme — a
	 * refinement of the spec's `materialise( post ): Identity`.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The cache identity to materialise under.
	 * @param \WP_Post $post     The post to render on a miss.
	 * @return string The cached or freshly-rendered Markdown bytes.
	 */
	public function materialise( Identity $identity, \WP_Post $post ): string;

}
