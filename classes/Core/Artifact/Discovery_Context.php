<?php
/**
 * Value object passed to a provider's advertise().
 *
 * Discovery can be either page-scoped (a non-null post, when decorating the HTML
 * page for that post) or site-scoped (a null post, when collecting site-wide
 * artifacts on every HTML response). Providers branch on $post === null to decide
 * which relations to return for each scope (docs/adr/0008).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * The context in which a provider is asked to advertise itself.
 *
 * @since 0.1.0
 */
final readonly class Discovery_Context {

	/**
	 * Names the post whose HTML page is being decorated, or null for site-wide discovery.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post|null $post The post whose HTML page is being decorated, or null on
	 *                            the site-scoped discovery call (e.g. for site-wide artifacts).
	 */
	public function __construct(
		public \WP_Post|null $post,
	) {}

}
