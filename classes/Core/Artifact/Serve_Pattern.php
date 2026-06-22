<?php
/**
 * Value object declaring the URL shape a provider serves.
 *
 * A provider publishes its Serve_Pattern so the early serve router can both match
 * a path and synthesise headers without consulting the provider (the early serve
 * must stay provider-free for speed) — only a shape a registered provider claims
 * is ever served from the cache directory (docs/adr/0007, docs/adr/0008). It
 * carries two match modes: a Release-1 `.md` **suffix** shape (the key is derived
 * from the path, a canonical HTML back-link applies, Markdown content type) and a
 * Release-2 **exact-path** singleton (a fixed home-relative path and a fixed base
 * key never reflected from the URL, no canonical, plain-text, version-stamped by
 * default so a cache-version bump invalidates it) — docs/spec/llms-txt.md §3.4.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * The recognisable URL shape of one artifact kind, for the serve allowlist.
 *
 * @since 0.1.0
 */
final readonly class Serve_Pattern {

	/**
	 * Builds a serve pattern; use the suffix() and exact() factories.
	 *
	 * @since 0.1.0
	 *
	 * @param string $match        The match mode: 'suffix' or 'exact'.
	 * @param string $kind         The artifact kind this shape produces.
	 * @param string $suffix       The path suffix (suffix mode), e.g. '.md'.
	 * @param string $path         The exact home-relative path (exact mode), e.g. '/llms.txt'.
	 * @param string $key          The fixed base cache key (exact mode), e.g. 'llms'.
	 * @param string $content_type The Content-Type the router serves this shape with.
	 * @param bool   $canonical    Whether a `rel="canonical"` HTML back-link applies.
	 * @param bool   $versioned    Whether the cache key carries the cache-version stamp.
	 */
	private function __construct(
		public string $match,
		public string $kind,
		public string $suffix,
		public string $path,
		public string $key,
		public string $content_type,
		public bool $canonical,
		public bool $versioned,
	) {}

	/**
	 * Declares a suffix-matched shape (Release-1 `.md`).
	 *
	 * The key is derived from the path with the suffix stripped, a canonical HTML
	 * back-link applies, and the Content-Type defaults to Markdown.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind         The artifact kind.
	 * @param string $suffix       The case-sensitive path suffix that identifies it, e.g. '.md'.
	 * @param string $content_type The served Content-Type.
	 * @return self
	 */
	public static function suffix(
		string $kind,
		string $suffix,
		string $content_type = 'text/markdown; charset=utf-8',
	): self {
		return new self( 'suffix', $kind, $suffix, '', '', $content_type, true, false );
	}

	/**
	 * Declares an exact-path singleton (Release-2 `/llms.txt`).
	 *
	 * A fixed home-relative path and a fixed base key (never reflected from the
	 * URL), no canonical back-link, and a version-stamped key by default so a
	 * cache-version bump invalidates it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $kind         The artifact kind.
	 * @param string $path         The home-relative path, e.g. '/llms.txt'.
	 * @param string $key          The fixed base cache key, e.g. 'llms'.
	 * @param string $content_type The served Content-Type.
	 * @param bool   $versioned    Whether the cache key carries the cache-version stamp.
	 * @return self
	 */
	public static function exact(
		string $kind,
		string $path,
		string $key,
		string $content_type = 'text/plain; charset=utf-8',
		bool $versioned = true,
	): self {
		return new self( 'exact', $kind, '', $path, $key, $content_type, false, $versioned );
	}

}
