<?php
/**
 * Emits RFC 8288 Link headers on HTML responses.
 *
 * On every HTML page it asks each registered provider what it advertises: a
 * page-scoped Discovery_Context (the queried post) on a singular page, and a
 * site-scoped context (a null post) on every page. Per-page providers return []
 * for the null post; the site-wide llms providers return [] for a real post.
 * Relations are deduplicated by the full (href, rel, type) triple and emitted as
 * separate Link: header lines. The early router exits before WordPress on a warm
 * artifact hit, so these headers appear on HTML pages only — by design.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Links;

use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Registry;

/**
 * Emits RFC 8288 Link headers on HTML responses.
 *
 * @since 0.3.0
 */
final class Header_Emitter {

	/**
	 * Binds the emitter to the provider registry.
	 *
	 * @since 0.3.0
	 *
	 * @param Registry $registry The artifact-provider registry.
	 */
	public function __construct( private readonly Registry $registry ) {}

	/**
	 * Registers the send_headers hook.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'send_headers', [ $this, 'emit' ] );
	}

	/**
	 * Emits Link headers for the current request.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function emit(): void {

		// HTML-page discovery only: skip admin, REST, feeds, robots.txt and 404s.
		if ( is_admin()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| is_feed()
			|| is_robots()
			|| is_404()
		) {
			return;
		}

		// Per-page relations on a singular page, plus the site-wide relations on
		// every page. A null post is the site-scoped call.
		$relations = [];
		if ( is_singular() ) {
			$post = get_queried_object();
			if ( $post instanceof \WP_Post ) {
				$page_context = new Discovery_Context( $post );
				foreach ( $this->registry->providers() as $provider ) {
					array_push( $relations, ...$provider->advertise( $page_context ) );
				}
			}
		}
		$site_context = new Discovery_Context( null );
		foreach ( $this->registry->providers() as $provider ) {
			array_push( $relations, ...$provider->advertise( $site_context ) );
		}

		// Deduplicate by the full (href, rel, type) triple, then emit one Link:
		// header per relation. The false second argument appends rather than
		// replaces, so multiple Link: lines coexist (RFC 8288).
		$seen = [];
		foreach ( $relations as $relation ) {
			$key = $relation->href . '|' . $relation->rel . '|' . $relation->type;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			header(
				sprintf( 'Link: <%s>; rel="%s"; type="%s"', esc_url_raw( $relation->href ), $relation->rel, $relation->type ),
				false,
			);
		}

	}

}
