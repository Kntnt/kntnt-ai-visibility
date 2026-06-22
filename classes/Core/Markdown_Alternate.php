<?php
/**
 * The markdown-alternate identity/URL locator.
 *
 * The cache key and the advertised `.md` URL for a post are the identity of the
 * markdown-alternate kind. Core owns the kind's storage and serving, so it owns
 * the scheme (docs/spec/llms-txt.md §3.2). The Markdown provider delegates its
 * key derivation and `.md` URL to this locator; the llms index builder calls
 * url_for() for each link, and the llms full builder calls identity_for() to
 * materialise each page's Markdown. The key is home-relative, so root and
 * subdirectory installs derive the same key (and the router strips the same
 * base before serving).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;

/**
 * Derives the cache identity and `.md` URL for a post.
 *
 * @since 0.2.0
 */
final class Markdown_Alternate {

	/**
	 * The artifact kind this scheme identifies.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KIND = 'markdown-alternate';

	/**
	 * Builds the cache identity for a post.
	 *
	 * Does not check eligibility — the caller gates that; deriving an identity for
	 * an ineligible post is harmless.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post The post.
	 * @return Identity The KIND, the home-relative permalink key ('index' for the home), and the post ID.
	 */
	public function identity_for( \WP_Post $post ): Identity {
		return new Identity( self::KIND, $this->key_for( $post ), $post->ID );
	}

	/**
	 * Builds the absolute `.md` URL advertised and linked for a post.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	public function url_for( \WP_Post $post ): string {

		// The home alternate lives at /index.md; every other page appends `.md`
		// to its permalink (minus the trailing slash).
		return $this->key_for( $post ) === 'index'
			? home_url( '/index.md' )
			: rtrim( (string) get_permalink( $post ), '/' ) . '.md';

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
	 * @since 0.2.0
	 *
	 * @param string $path A path that may carry the install's base prefix.
	 * @return string The path relative to the WordPress home (leading slash kept).
	 */
	public function home_relative( string $path ): string {

		// Remove the base prefix only when the path actually sits under it.
		$base = rtrim( (string) wp_parse_url( (string) home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( $base !== '' && str_starts_with( $path, $base . '/' ) ) {
			return substr( $path, strlen( $base ) );
		}

		return $path;

	}

	/**
	 * Derives the path-safe cache key for a post from its permalink.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string The cache key; 'index' for the slug-less home.
	 */
	private function key_for( \WP_Post $post ): string {

		// The key is the permalink path relative to the WordPress home, without
		// surrounding slashes; the slug-less home maps to the 'index' key the
		// router serves at /index.md. Keeping the key home-relative means the same
		// key is derived on root and subdirectory installs.
		$path = $this->home_relative( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) );
		$key = trim( $path, '/' );

		return $key === '' ? 'index' : $key;

	}

}
