<?php
/**
 * The Markdown-alternate artifact provider.
 *
 * A rule, not an enumeration: this one provider covers every eligible page
 * (docs/adr/0008). It resolves a request to an eligible post and its Identity
 * (match), produces the artifact bytes through the shared Page-Markdown service
 * (generate), advertises the page's `.md` alternate (advertise) and declares the
 * `.md` serve shape for the router allowlist (serve_pattern).
 *
 * Resolution is permalink-driven: it hands the reconstructed URL to
 * url_to_postid(), which handles nested and dated permalinks for free, and maps
 * the canonical home and its reserved index.md to the static front. Ordinary
 * index leaves carry one extra index segment in their dedicated paths. Both
 * forms remain source-language-aware and installation-relative.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Markdown;

use Kntnt\Ai_Visibility\Core\Artifact\Artifact;
use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Link_Relation;
use Kntnt\Ai_Visibility\Core\Artifact\Provider;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;
use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown;
use Kntnt\Ai_Visibility\Core\Site_Url;

/**
 * Provides per-page Markdown alternates.
 *
 * @since 0.1.0
 */
final class Page_Markdown_Provider implements Provider {

	/**
	 * The content type every Markdown alternate is served with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CONTENT_TYPE = 'text/markdown; charset=utf-8';

	/**
	 * Binds the provider to the page-markdown service, eligibility and locator.
	 *
	 * @since 0.1.0
	 *
	 * @param Page_Markdown      $page_markdown      The shared page-to-Markdown service.
	 * @param Eligibility        $eligibility        The eligibility rule.
	 * @param Markdown_Alternate $markdown_alternate The Core markdown-alternate identity/URL locator.
	 */
	public function __construct(
		private readonly Page_Markdown $page_markdown,
		private readonly Eligibility $eligibility,
		private readonly Markdown_Alternate $markdown_alternate,
	) {}

	/**
	 * Declares the markdown-alternate `.md` serve shape.
	 *
	 * @since 0.1.0
	 *
	 * @return Serve_Pattern
	 */
	public function serve_pattern(): Serve_Pattern {
		return Serve_Pattern::suffix( Markdown_Alternate::KIND, '.md' );
	}

	/**
	 * Resolves a request to an eligible post and its identity.
	 *
	 * @since 0.1.0
	 *
	 * @param Request $request The incoming request.
	 * @return Identity|null The matched identity, or null when not served.
	 */
	public function match( Request $request ): ?Identity {

		// Resolve the target post, then gate it on eligibility.
		$post = $this->resolve_post( $request->path );
		if ( $post === null || ! $this->eligibility->is_eligible( $post ) ) {
			return null;
		}

		// Dedicated paths must name the source's actual advertised alternate.
		// This rejects shorter index aliases after reversing the index escape.
		if ( str_ends_with( $request->path, '.md' ) ) {
			$advertised = (string) wp_parse_url( $this->markdown_alternate->url_for( $post ), PHP_URL_PATH );
			if ( rawurldecode( $request->path ) !== rawurldecode( $advertised ) ) {
				return null;
			}
		}

		return $this->identity_for_post( $post );

	}

	/**
	 * Builds the cache identity for a post.
	 *
	 * Shared by match() and the invalidation hooks so the cache key derivation
	 * lives in one place. It does not check eligibility — deleting the cache for
	 * an ineligible post is a harmless no-op.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post.
	 * @return Identity
	 */
	public function identity_for_post( \WP_Post $post ): Identity {
		return $this->markdown_alternate->identity_for( $post );
	}

	/**
	 * Produces the artifact bytes and serve metadata for an identity.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The identity to generate.
	 * @return Artifact The generated artifact.
	 */
	public function generate( Identity $identity ): Artifact {

		// Render the source post to Markdown via the shared service, stamping the
		// artifact with the post's GMT modified time for conditional requests.
		$post = get_post( $identity->source_id );
		$bytes = $post instanceof \WP_Post ? $this->page_markdown->for_post( $post ) : '';
		$modified = $post instanceof \WP_Post ? get_post_modified_time( 'U', true, $post ) : false;
		$last_modified = is_numeric( $modified ) ? (int) $modified : 0;

		return new Artifact( $bytes, self::CONTENT_TYPE, $last_modified );

	}

	/**
	 * Advertises the page's Markdown alternate as a discovery relation.
	 *
	 * @since 0.1.0
	 *
	 * @param Discovery_Context $context The page being decorated.
	 * @return list<Link_Relation>
	 */
	public function advertise( Discovery_Context $context ): array {

		// Advertise only for pages that actually have an alternate, so a generic
		// discovery walk over all providers needs no eligibility knowledge of its own.
		if ( $context->post === null || ! $this->eligibility->is_eligible( $context->post ) ) {
			return [];
		}

		return [ new Link_Relation( $this->markdown_alternate->url_for( $context->post ), 'alternate', 'text/markdown' ) ];

	}

	/**
	 * Resolves a request path to its target post, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The request path (a `.md` or canonical URL path).
	 * @return \WP_Post|null
	 */
	private function resolve_post( string $path ): ?\WP_Post {

		// Reject requests outside the configured installation before fallbacks
		// can turn their leaf into a legitimate installation-relative identity.
		$base = rtrim( (string) wp_parse_url( Site_Url::home(), PHP_URL_PATH ), '/' );
		if ( $base !== '' && ! str_starts_with( $path, $base . '/' ) ) {
			return null;
		}

		// Reduce a `.md` request to its HTML path, taken relative to the
		// WordPress home so resolution works identically on a subdirectory
		// install (a canonical path passes through unchanged on a root install).
		$is_suffix = str_ends_with( $path, '.md' );
		$html_path = $is_suffix ? substr( $path, 0, -3 ) : $path;
		$relative = $this->markdown_alternate->home_relative( (string) wp_parse_url( $html_path, PHP_URL_PATH ) );
		$slug = trim( $relative, '/' );

		// Canonical roots and their reserved /index.md represent only the home.
		// Canonical /index/ remains an ordinary page, even with a static front.
		$home_path = trim( $this->markdown_alternate->home_relative( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ), '/' );
		if ( $slug === $home_path || ( $is_suffix && $slug === ltrim( $home_path . '/index', '/' ) ) ) {
			return $this->resolve_home( $home_path );
		}

		// Reverse exactly one escape segment on an ordinary index alternate.
		if ( $is_suffix && str_ends_with( '/' . $slug, '/index/index' ) ) {
			$relative = substr( $relative, 0, -strlen( '/index' ) );
			$slug = trim( $relative, '/' );
		}

		// Let url_to_postid() resolve the permalink first, trying the
		// trailing-slash and bare forms so dated and nested post permalinks work.
		// Use the installation base; the candidate already includes its language.
		foreach ( [ trailingslashit( $relative ), untrailingslashit( $relative ) ] as $candidate ) {
			$id = url_to_postid( Site_Url::home( $candidate ) );
			if ( $id > 0 ) {
				$post = get_post( $id );
				if ( $post instanceof \WP_Post && $this->matches_path( $post, $relative ) ) {
					return $post;
				}
			}
		}

		// Fall back to a hierarchical page lookup. When a `.md` request steers
		// WordPress's main query to the front page, url_to_postid() can shadow a
		// page slug with the post-name rule and return 0; a page's path resolves
		// the same regardless of the current query (handles nested pages too).
		$page = get_page_by_path( $slug );
		if ( $page instanceof \WP_Post && $this->matches_path( $page, $relative ) ) {
			return $page;
		}

		// Last, resolve a published post or custom post type by its final slug
		// segment — the case the page tree cannot cover when url_to_postid() has
		// likewise missed in the steered .md context.
		return $this->resolve_by_slug( $slug );

	}

	/**
	 * Resolves a published post (any public type) by its final path segment.
	 *
	 * @since 0.1.0
	 *
	 * @param string $slug The trimmed request path (its last segment is the slug).
	 * @return \WP_Post|null
	 */
	private function resolve_by_slug( string $slug ): ?\WP_Post {

		// Posts use a flat `%postname%`, so the last segment is the post slug.
		$segments = explode( '/', $slug );
		$name = (string) end( $segments );
		if ( $name === '' ) {
			return null;
		}

		// Shared leaf slugs can belong to different hierarchies or languages.
		// Keep language query filters and require the complete canonical path.
		$posts = get_posts(
			[
				'name'           => $name,
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'suppress_filters' => false,
			],
		);
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && $this->matches_path( $post, $slug ) ) {
				return $post;
			}
		}

		return null;

	}

	/**
	 * Resolves only the configured static front in the current language.
	 *
	 * @since 0.1.0
	 *
	 * @param string $home_path The current language's installation-relative home.
	 * @return \WP_Post|null
	 */
	private function resolve_home( string $home_path ): ?\WP_Post {

		// Listings have no per-page alternate; an index-slugged page cannot
		// substitute for either a blog home or another page chosen as the front.
		if ( get_option( 'show_on_front' ) === 'page' ) {
			$front_id = get_option( 'page_on_front' );
			$front = is_numeric( $front_id ) ? (int) $front_id : 0;
			if ( $front > 0 ) {
				$post = get_post( $front );
				return $post instanceof \WP_Post && $this->matches_path( $post, $home_path ) ? $post : null;
			}
		}

		return null;

	}

	/**
	 * Rejects aliases and wrong-language results from WordPress's slug fallbacks.
	 *
	 * @since 0.5.2
	 * @param \WP_Post $post Candidate post.
	 * @param string   $path Installation-relative HTML path.
	 * @return bool
	 */
	private function matches_path( \WP_Post $post, string $path ): bool {
		$canonical = $this->markdown_alternate->home_relative( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) );

		return trim( rawurldecode( $canonical ), '/' ) === trim( rawurldecode( $path ), '/' );

	}

}
