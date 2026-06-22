<?php
/**
 * Value object passed to a provider's advertise().
 *
 * Discovery happens in the context of a concrete HTML page being rendered, so
 * advertise() receives the post that page represents and decides which link
 * relations to contribute for it (docs/adr/0008).
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
	 * Names the post whose HTML page is being decorated.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post whose HTML page is being decorated.
	 */
	public function __construct(
		public \WP_Post $post,
	) {}

}
