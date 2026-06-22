<?php
/**
 * Advertises artifacts in the HTML head.
 *
 * On a singular page, this walks the registered providers and renders each
 * relation they advertise into a `<link rel="alternate" type="text/markdown">`
 * tag — the anti-regression discovery the replaced plugin shipped (docs/spec
 * §4.5). It is deliberately generic: each provider's advertise() decides whether
 * it contributes a relation for the page, so the Release-3 Link-headers module
 * can reuse the same provider data without this class changing.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Markdown;

use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Registry;

/**
 * Renders per-page artifact discovery tags on wp_head.
 *
 * @since 0.1.0
 */
final class Discovery {

	/**
	 * Binds discovery to the provider registry it walks.
	 *
	 * @since 0.1.0
	 *
	 * @param Registry $registry The artifact-provider registry.
	 */
	public function __construct( private readonly Registry $registry ) {}

	/**
	 * Registers the wp_head hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_head', [ $this, 'render' ] );
	}

	/**
	 * Renders discovery `<link>` tags for the current singular page.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function render(): void {

		// Only singular content has a per-page alternate; archives and the blog
		// index advertise nothing in Release 1.
		if ( ! is_singular() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Render every relation each provider advertises for this page.
		$context = new Discovery_Context( $post );
		foreach ( $this->registry->providers() as $provider ) {
			foreach ( $provider->advertise( $context ) as $relation ) {
				printf(
					'<link rel="%s" type="%s" href="%s" />' . "\n",
					esc_attr( $relation->rel ),
					esc_attr( $relation->type ),
					esc_url( $relation->href ),
				);
			}
		}

	}

}
