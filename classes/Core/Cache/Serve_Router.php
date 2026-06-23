<?php
/**
 * The early, contained serve router — the hardened heart of the plugin.
 *
 * It runs as early as a plugin can hook: given an untrusted request, it maps a
 * cache-grade `.md` URL to a safe, contained cache file and serves it without
 * the rest of the WordPress lifecycle. Page-cache plugins have shipped
 * path-traversal CVEs on exactly this pattern, so resolution is split out as a
 * pure, exhaustively-tested function (resolve()) that whitelists the request
 * shape, validates a safe key, fixes the base directory and `.md` extension
 * itself, and realpath-contains the result strictly inside the cache base —
 * defeating traversal and symlink escape (docs/adr/0007).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Cache;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;
use Kntnt\Ai_Visibility\Core\Http\Conditional_Request;
use Kntnt\Ai_Visibility\Core\Logger;

/**
 * Resolves and serves cache-grade artifact requests from the file cache.
 *
 * @since 0.1.0
 */
final class Serve_Router {

	/**
	 * The MIME type every Markdown artifact is served with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CONTENT_TYPE = 'text/markdown; charset=utf-8';

	/**
	 * The character class a single path segment may contain.
	 *
	 * Anchored, slash-separated segments of ASCII letters, digits, hyphen and
	 * underscore. This rejects `..`, percent-encoding, backslashes, null bytes,
	 * leading/trailing/double slashes and every non-ASCII byte, so a derived key
	 * can never reach outside the cache directory.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const SAFE_KEY = '~^[A-Za-z0-9]+(?:[/_-][A-Za-z0-9]+)*$~';

	/**
	 * Memoized realpath of the cache base directory.
	 *
	 * Populated on the first resolve() call and reused on every subsequent
	 * one, avoiding a redundant realpath() on a fixed directory per request.
	 * False means realpath() returned false (directory does not exist).
	 *
	 * @since 0.2.3
	 *
	 * @var string|false|null
	 */
	private string|false|null $real_base = null;

	/**
	 * Clock used for the TTL safety net.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): int
	 */
	private $clock;

	/**
	 * Returns the WordPress home base path to strip from a request path.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): string
	 */
	private $base_path;

	/**
	 * Returns the current cache version, for version-stamped exact-path keys.
	 *
	 * Invoked lazily — only when an exact, versioned pattern actually matches — so
	 * an ordinary request (HTML, asset, `.md`) never reads the cache-version option.
	 *
	 * @since 0.2.0
	 *
	 * @var callable(): int
	 */
	private $cache_version;

	/**
	 * Binds the router to its store, provider registry, logger and TTL.
	 *
	 * @since 0.1.0
	 *
	 * @param Store                                       $store         The cache store.
	 * @param \Kntnt\Ai_Visibility\Core\Artifact\Registry $registry      The provider registry (serve allowlist).
	 * @param Logger|null                                 $logger        Optional logger for refused requests.
	 * @param int                                         $ttl           Max cache-file age in seconds; 0 disables the safety net.
	 * @param (callable(): int)|null                      $clock         Returns the current Unix time; defaults to time().
	 * @param (callable(): string)|null                   $base_path     Returns the home base path (e.g. '/blog') to strip on a
	 *                                                                   subdirectory install; defaults to '' (root install).
	 * @param (callable(): int)|null                      $cache_version Returns the cache version for version-stamped exact
	 *                                                                   paths; invoked only on an exact-versioned match. Defaults to 1.
	 */
	public function __construct(
		private readonly Store $store,
		private readonly \Kntnt\Ai_Visibility\Core\Artifact\Registry $registry,
		private readonly ?Logger $logger = null,
		private readonly int $ttl = 0,
		?callable $clock = null,
		?callable $base_path = null,
		?callable $cache_version = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
		$this->base_path = $base_path ?? static fn (): string => '';
		$this->cache_version = $cache_version ?? static fn (): int => 1;
	}

	/**
	 * Resolves an untrusted request to a safe, contained, existing cache path.
	 *
	 * Returns null whenever the request is not a cache-grade artifact request,
	 * fails validation, or has no cache file — the caller then lets WordPress
	 * proceed. A non-null return is guaranteed to be an existing file strictly
	 * inside the cache base.
	 *
	 * @since 0.1.0
	 *
	 * @param Request $request The untrusted request.
	 * @return Resolved|null The matched pattern and safe absolute cache path, or null to fall through.
	 */
	public function resolve( Request $request ): ?Resolved {

		// Only idempotent reads are ever served from the cache.
		if ( $request->method !== 'GET' && $request->method !== 'HEAD' ) {
			return null;
		}

		// Reject a null byte outright, before any string or filesystem work.
		$path = $request->path;
		if ( $path === '' || $path[0] !== '/' || str_contains( $path, "\0" ) ) {
			return null;
		}

		// Match the path against the allowlist of registered serve shapes and
		// derive a validated identity; an unmatched or malformed shape falls through.
		$match = $this->identify( $path );
		if ( $match === null ) {
			return null;
		}
		[ $identity, $pattern ] = $match;

		// Build the candidate path from the validated key, then realpath-contain
		// it strictly inside the cache base — the backstop against traversal and
		// symlink escape that survives even if the whitelist were bypassed.
		$candidate = $this->store->path_for( $identity );
		$this->real_base ??= realpath( $this->store->base_dir() );
		$real_base = $this->real_base;
		$real = realpath( $candidate );
		if ( $real_base === false || $real === false ) {
			return null;
		}
		if ( ! str_starts_with( $real, $real_base . DIRECTORY_SEPARATOR ) ) {
			$this->logger?->warning( 'Refused a cache path outside the cache base', [ 'path' => $path ] );
			return null;
		}

		// Serve only a regular file that has not aged past the TTL safety net.
		if ( ! is_file( $real ) || $this->is_expired( $real ) ) {
			return null;
		}

		return new Resolved( $real, $pattern );

	}

	/**
	 * Serves a resolved cache file and exits, or returns false to fall through.
	 *
	 * This is the thin I/O shell around resolve(): it emits headers, answers
	 * conditional requests with 304, streams the body and exits. Its behaviour
	 * is covered end-to-end; the testable logic lives in resolve() and
	 * headers_for().
	 *
	 * @since 0.1.0
	 *
	 * @param Request $request The untrusted request.
	 * @return false Returns false when the request is not served (a cache miss
	 *               or refusal); otherwise it streams the response and exits.
	 */
	public function serve( Request $request ): bool {

		// Fall through whenever the request is not a contained cache hit.
		$resolved = $this->resolve( $request );
		if ( $resolved === null ) {
			return false;
		}

		// Build the response from the matched pattern: its Content-Type, and a
		// canonical back-link only when the pattern declares one (i.e. for `.md`).
		$canonical = $resolved->pattern->canonical ? $this->canonical_for( $request->path ) : '';
		$response = $this->headers_for( $resolved->path, $request, $resolved->pattern->content_type, $canonical );
		http_response_code( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( "{$name}: {$value}" );
		}

		// Stream the body unless this is a 304 or a HEAD request.
		if ( $response['send_body'] ) {
			readfile( $resolved->path );
		}

		exit;

	}

	/**
	 * Computes the response status and headers for a cache file.
	 *
	 * Pure: given the file and the request's conditional headers, it returns the
	 * status (200 or 304), the header map and whether to send a body. Conditional
	 * requests are answered against the content ETag and the file's modified time.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $path          The cache file path.
	 * @param Request $request       The request (for method and conditionals).
	 * @param string  $content_type  The Content-Type to serve (from the matched pattern).
	 * @param string  $canonical_url The HTML canonical URL, or '' to omit the link.
	 * @return array{status: int, headers: array<string, string>, send_body: bool}
	 */
	public function headers_for( string $path, Request $request, string $content_type = self::CONTENT_TYPE, string $canonical_url = '' ): array {

		// Derive validators from the file: a last-modified time and, only when the
		// client sent an If-None-Match header, a content ETag (md5_file is skipped
		// on If-Modified-Since-only requests where the body is never read anyway).
		$last_modified = (int) filemtime( $path );
		$etag = $request->if_none_match !== '' ? '"' . md5_file( $path ) . '"' : null;
		$not_modified = Conditional_Request::is_fresh( $request->if_none_match, $request->if_modified_since, $etag ?? '', $last_modified );

		// Headers common to both 200 and 304 responses.
		$headers = [
			'X-Content-Type-Options' => 'nosniff',
			'Last-Modified'          => gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT',
		];

		// The .md points back at its HTML canonical to avoid duplicate content.
		if ( $canonical_url !== '' ) {
			$headers['Link'] = '<' . $canonical_url . '>; rel="canonical"';
		}

		// A fresh client gets a bodyless 304; an If-None-Match 304 echoes the ETag
		// back, but an If-Modified-Since-only 304 skips it (no file read needed).
		if ( $not_modified ) {
			if ( $etag !== null ) {
				$headers['ETag'] = $etag;
			}
			return [
				'status'    => 304,
				'headers'   => $headers,
				'send_body' => false,
			];
		}

		// Full 200: compute the ETag now if we skipped it above, then add all
		// content headers. HEAD never carries a body even on a 200.
		$etag ??= '"' . md5_file( $path ) . '"';
		$headers['ETag'] = $etag;
		$headers['Content-Type'] = $content_type;
		$headers['Content-Length'] = (string) filesize( $path );

		return [
			'status'    => 200,
			'headers'   => $headers,
			'send_body' => $request->method !== 'HEAD',
		];

	}

	/**
	 * Reports whether a cache file has aged past the TTL safety net.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The realpath of the cache file.
	 * @return bool True when the file is older than the configured TTL.
	 */
	private function is_expired( string $path ): bool {

		// A non-positive TTL disables the safety net entirely.
		if ( $this->ttl <= 0 ) {
			return false;
		}

		return ( ( $this->clock )() - (int) filemtime( $path ) ) > $this->ttl;

	}

	/**
	 * Matches a path against the allowlist and returns a validated identity + pattern.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The request path (leading slash, query already stripped).
	 * @return array{0: Identity, 1: Serve_Pattern}|null The validated identity and the
	 *               pattern that matched, or null when no shape matches or the key is unsafe.
	 */
	private function identify( string $path ): ?array {

		// Take the path relative to the WordPress home so a subdirectory install
		// (e.g. /blog/about.md or /blog/llms.txt) derives the same key as root.
		$path = $this->strip_base( $path );

		// Find the first registered serve shape the path matches, by its mode.
		foreach ( $this->registry->serve_patterns() as $pattern ) {
			$key = $pattern->match === 'exact'
				? $this->exact_key( $path, $pattern )
				: $this->suffix_key( $path, $pattern );
			if ( $key !== null ) {
				return [ new Identity( $pattern->kind, $key ), $pattern ];
			}
		}

		return null;

	}

	/**
	 * Derives a validated key for a suffix-matched pattern, or null.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $path    The home-relative request path.
	 * @param Serve_Pattern $pattern The suffix pattern.
	 * @return string|null The validated key, or null when the path does not match or the key is unsafe.
	 */
	private function suffix_key( string $path, Serve_Pattern $pattern ): ?string {

		// The path must carry the suffix; strip the leading slash and the suffix to
		// get the candidate key, then accept it only if it passes the whitelist.
		if ( $pattern->suffix === '' || ! str_ends_with( $path, $pattern->suffix ) ) {
			return null;
		}
		$key = substr( $path, 1, strlen( $path ) - 1 - strlen( $pattern->suffix ) );

		return preg_match( self::SAFE_KEY, $key ) === 1 ? $key : null;

	}

	/**
	 * Derives a validated key for an exact-path pattern, or null.
	 *
	 * The home-relative path must equal the pattern's path exactly (case-sensitive);
	 * the key is the pattern's fixed base key plus, when versioned, the cache-version
	 * stamp — no byte of the URL ever reaches the key. The cache-version callback is
	 * read only here, after an exact path match, so an ordinary request never reads it.
	 * The derived key is still validated against the whitelist as defence-in-depth.
	 *
	 * @since 0.2.0
	 *
	 * @param string        $path    The home-relative request path.
	 * @param Serve_Pattern $pattern The exact pattern.
	 * @return string|null The validated key, or null when the path does not match exactly.
	 */
	private function exact_key( string $path, Serve_Pattern $pattern ): ?string {

		// Exact, case-sensitive path match; only then read the cache version.
		if ( $path !== $pattern->path ) {
			return null;
		}
		$key = $pattern->key . ( $pattern->versioned ? '-v' . ( $this->cache_version )() : '' );

		return preg_match( self::SAFE_KEY, $key ) === 1 ? $key : null;

	}

	/**
	 * Best-effort reconstruction of the HTML canonical URL for a `.md` path.
	 *
	 * The early router has no resolved post, so it rebuilds the canonical from
	 * the request: the same host and scheme, the path with `.md` stripped and a
	 * trailing slash (the WordPress default), and `/` for the home. The exact
	 * canonical also lives in the file's front-matter; this is the header hint.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The `.md` request path.
	 * @return string The reconstructed canonical URL, or '' when unavailable.
	 */
	private function canonical_for( string $path ): string {

		// Without a trustworthy host there is nothing safe to point at.
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
			: '';
		if ( $host === '' ) {
			return '';
		}

		// Strip the home base and the `.md`, treat the home key specially, re-add
		// the slash, then re-prepend the base so the canonical is correct on a
		// subdirectory install too.
		$scheme = is_ssl() ? 'https' : 'http';
		$base = rtrim( ( $this->base_path )(), '/' );
		$stripped = $this->strip_base( $path );
		$key = substr( $stripped, 1, strlen( $stripped ) - 1 - strlen( '.md' ) );
		$relative = $key === 'index' ? '/' : '/' . $key . '/';

		return $scheme . '://' . $host . $base . $relative;

	}

	/**
	 * Strips the WordPress home base path from a request path.
	 *
	 * On a root install the base is empty and the path passes through; on a
	 * subdirectory install it removes the configured prefix (e.g. `/blog`). The
	 * base comes from site configuration, never the request, and the stripped
	 * remainder still passes the strict key whitelist and the realpath
	 * containment check — so this opens no traversal surface.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The raw request path.
	 * @return string The path relative to the WordPress home (leading slash kept).
	 */
	private function strip_base( string $path ): string {

		// Remove the base prefix only when the path actually sits under it.
		$base = rtrim( ( $this->base_path )(), '/' );
		if ( $base !== '' && str_starts_with( $path, $base . '/' ) ) {
			return substr( $path, strlen( $base ) );
		}

		return $path;

	}

}
