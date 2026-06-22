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
 * the slug-less root (or an explicit /index) to the home entry. The home alternate
 * is supported on root installs; per-page alternates work regardless of the
 * install path.
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
use Kntnt\Ai_Visibility\Core\Page_Markdown;

/**
 * Provides per-page Markdown alternates.
 *
 * @since 0.1.0
 */
final class Page_Markdown_Provider implements Provider {

	/**
	 * The artifact kind this provider produces.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const KIND = 'markdown-alternate';

	/**
	 * The content type every Markdown alternate is served with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CONTENT_TYPE = 'text/markdown; charset=utf-8';

	/**
	 * Binds the provider to the page-markdown service and eligibility rule.
	 *
	 * @since 0.1.0
	 *
	 * @param Page_Markdown $page_markdown The shared page-to-Markdown service.
	 * @param Eligibility   $eligibility   The eligibility rule.
	 */
	public function __construct(
		private readonly Page_Markdown $page_markdown,
		private readonly Eligibility $eligibility,
	) {}

	/**
	 * Declares the markdown-alternate `.md` serve shape.
	 *
	 * @since 0.1.0
	 *
	 * @return Serve_Pattern
	 */
	public function serve_pattern(): Serve_Pattern {
		return new Serve_Pattern( self::KIND, '.md' );
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
		return new Identity( self::KIND, $this->key_for( $post ), $post->ID );
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
		if ( ! $this->eligibility->is_eligible( $context->post ) ) {
			return [];
		}

		return [ new Link_Relation( $this->md_url( $context->post ), 'alternate', 'text/markdown' ) ];

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

		// Reduce a `.md` request to its HTML path, taken relative to the
		// WordPress home so resolution works identically on a subdirectory
		// install (a canonical path passes through unchanged on a root install).
		$html_path = str_ends_with( $path, '.md' ) ? substr( $path, 0, -3 ) : $path;
		$relative = $this->home_relative( (string) wp_parse_url( $html_path, PHP_URL_PATH ) );
		$slug = trim( $relative, '/' );

		// The slug-less root, or an explicit /index, resolves to the home entry.
		if ( $slug === '' || $slug === 'index' ) {
			return $this->resolve_home();
		}

		// Let url_to_postid() resolve the permalink first, trying the
		// trailing-slash and bare forms so dated and nested post permalinks work.
		// The home-relative path keeps home_url() from doubling the base prefix.
		foreach ( [ trailingslashit( $relative ), untrailingslashit( $relative ) ] as $candidate ) {
			$id = url_to_postid( home_url( $candidate ) );
			if ( $id > 0 ) {
				$post = get_post( $id );
				if ( $post instanceof \WP_Post ) {
					return $post;
				}
			}
		}

		// Fall back to a hierarchical page lookup. When a `.md` request steers
		// WordPress's main query to the front page, url_to_postid() can shadow a
		// page slug with the post-name rule and return 0; a page's path resolves
		// the same regardless of the current query (handles nested pages too).
		$page = get_page_by_path( $slug );
		if ( $page instanceof \WP_Post ) {
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

		// One published entry of any public type with this slug; eligibility is
		// re-checked by the caller, so a wrong-type hit is harmless.
		$posts = get_posts(
			[
				'name'           => $name,
				'post_type'      => 'any',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
			],
		);
		$post = is_array( $posts ) ? ( $posts[0] ?? null ) : null;

		return $post instanceof \WP_Post ? $post : null;

	}

	/**
	 * Resolves the home entry: a real page slugged 'index', else the front page.
	 *
	 * @since 0.1.0
	 *
	 * @return \WP_Post|null
	 */
	private function resolve_home(): ?\WP_Post {

		// A real page slugged 'index' takes precedence over the configured front
		// page, mirroring how a webserver prefers an explicit index document.
		$page = get_page_by_path( 'index' );
		if ( $page instanceof \WP_Post ) {
			return $page;
		}

		// Otherwise the home alternate exists only when a static page fronts the
		// site (show_on_front === 'page').
		if ( get_option( 'show_on_front' ) === 'page' ) {
			$front_id = get_option( 'page_on_front' );
			$front = is_numeric( $front_id ) ? (int) $front_id : 0;
			if ( $front > 0 ) {
				$post = get_post( $front );
				return $post instanceof \WP_Post ? $post : null;
			}
		}

		return null;

	}

	/**
	 * Derives the path-safe cache key for a post from its permalink.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string The cache key; 'index' for the slug-less home.
	 */
	private function key_for( \WP_Post $post ): string {

		// The key is the permalink path relative to the WordPress home, without
		// surrounding slashes; the slug-less home maps to the 'index' key the
		// router serves at /index.md. Keeping the key home-relative means the same
		// key is derived on root and subdirectory installs (and the router strips
		// the same base before serving).
		$path = $this->home_relative( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) );
		$key = trim( $path, '/' );

		return $key === '' ? 'index' : $key;

	}

	/**
	 * Strips the WordPress home base path from a path.
	 *
	 * On a root install the base is empty and the path passes through; on a
	 * subdirectory install (e.g. WordPress at `/blog/`) it removes the `/blog`
	 * prefix, so keys and resolution are relative to the site root and the home
	 * collapses to `index` on both. The base comes from `home_url()` (site
	 * configuration), never the request, so it opens no traversal surface.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path A path that may carry the install's base prefix.
	 * @return string The path relative to the WordPress home (leading slash kept).
	 */
	private function home_relative( string $path ): string {

		// Remove the base prefix only when the path actually sits under it.
		$base = rtrim( (string) wp_parse_url( (string) home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $base !== '' && str_starts_with( $path, $base . '/' ) ) {
			return substr( $path, strlen( $base ) );
		}

		return $path;

	}

	/**
	 * Builds the absolute `.md` URL advertised for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private function md_url( \WP_Post $post ): string {

		// The home alternate lives at /index.md; every other page appends `.md`
		// to its permalink (minus the trailing slash).
		return $this->key_for( $post ) === 'index'
			? home_url( '/index.md' )
			: rtrim( (string) get_permalink( $post ), '/' ) . '.md';

	}

}
