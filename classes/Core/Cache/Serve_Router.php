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

use Kntnt\Ai_Visibility\Core\Http\Failure_Response;
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
	 * Returns the canonical origin (scheme://host[:port]) for the back-link.
	 *
	 * @since 0.2.3
	 *
	 * @var callable(): string
	 */
	private $canonical_origin;

	/**
	 * Binds the router to its store, provider registry, logger and TTL.
	 *
	 * @since 0.1.0
	 *
	 * @param Store                                       $store            The cache store.
	 * @param \Kntnt\Ai_Visibility\Core\Artifact\Registry $registry         The provider registry (serve allowlist).
	 * @param Logger|null                                 $logger           Optional logger for refused requests.
	 * @param int                                         $ttl              Max cache-file age in seconds; 0 disables the safety net.
	 * @param (callable(): int)|null                      $clock            Returns the current Unix time; defaults to time().
	 * @param (callable(): string)|null                   $base_path        Returns the home base path (e.g. '/blog') to strip on a
	 *                                                                      subdirectory install; defaults to '' (root install).
	 * @param (callable(): int)|null                      $cache_version    Returns the cache version for version-stamped exact
	 *                                                                      paths; invoked only on an exact-versioned match. Defaults to 1.
	 * @param (callable(): string)|null                   $canonical_origin Returns the canonical origin (scheme://host[:port]) for the
	 *                                                                      `.md` back-link; defaults to reading HTTP_HOST.
	 */
	public function __construct(
		private readonly Store $store,
		private readonly \Kntnt\Ai_Visibility\Core\Artifact\Registry $registry,
		private readonly ?Logger $logger = null,
		private readonly int $ttl = 0,
		?callable $clock = null,
		?callable $base_path = null,
		?callable $cache_version = null,
		?callable $canonical_origin = null,
	) {
		$this->clock = $clock ?? static fn (): int => time();
		$this->base_path = $base_path ?? static fn (): string => '';
		$this->cache_version = $cache_version ?? static fn (): int => 1;
		$this->canonical_origin = $canonical_origin ?? static function (): string {
			$host = isset( $_SERVER['HTTP_HOST'] ) && is_string( $_SERVER['HTTP_HOST'] )
				? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
				: '';
			return $host === '' ? '' : ( is_ssl() ? 'https' : 'http' ) . '://' . $host;
		};
	}

	/**
	 * Recognises a registered dedicated address without requiring a cache file.
	 *
	 * Uses the same installation-relative pattern and key validation as serving.
	 * Other modules can yield these paths before canonical HTML negotiation.
	 *
	 * @since 0.5.2
	 *
	 * @param string $path The untrusted path, with the query already stripped.
	 * @return bool Whether the path identifies a registered artifact.
	 */
	public function is_artifact_path( string $path ): bool {

		// Refuse malformed input before deriving any registered artifact identity.
		if ( $path === '' || $path[0] !== '/' || str_contains( $path, "\0" ) ) {
			return false;
		}

		return $this->identify( $path, false ) !== null;

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
		if ( ! $request->is_read() ) {
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
		if ( ! $this->store->publication()->readable() ) {
			$this->logger?->warning( 'Cache refused: unverified erasure or unavailable publication state' );
			return null;
		}

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
	 * headers_for_snapshot().
	 *
	 * @since 0.1.0
	 *
	 * @param Request $request The untrusted request.
	 * @return false Returns false when the request is not served (a cache miss
	 *               or refusal); otherwise it streams the response and exits.
	 */
	public function serve( Request $request ): bool {

		// Fall through whenever the request is not a contained cache hit.
		try {
			$resolved = $this->resolve( $request );
		} catch ( Obsolete_Artifact ) {
			$this->logger?->warning( 'Refused unavailable artifact generation' );
			Failure_Response::send( $request, 403, Failure_Response::refusal_message( early: true ) );
		}
		if ( $resolved === null ) {
			return false;
		}

		// Build the response from the matched pattern: its Content-Type, and a
		// canonical back-link only when the pattern declares one (i.e. for `.md`).
		$snapshot = $this->snapshot_for( $resolved->path, $resolved->pattern->canonical );
		if ( $snapshot === null ) {
			return false;
		}
		$response = $this->headers_for_snapshot( $snapshot, $request, $resolved->pattern->content_type );
		http_response_code( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( "{$name}: {$value}" );
		}

		// Emit the captured bytes unless this is a 304 or a HEAD request.
		if ( $response['send_body'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cached plain-text bytes must match the captured response metadata.
			echo $snapshot->bytes;
		}

		exit;

	}

	/**
	 * Computes the response status and headers for a cache file.
	 *
	 * Given the file and the request's conditional headers, it captures the
	 * representation and returns the status, header map and body policy. Conditional
	 * requests are answered against the content ETag and the file's modified time.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $path          The cache file path.
	 * @param Request $request       The request (for method and conditionals).
	 * @param string  $content_type  The Content-Type to serve (from the matched pattern).
	 * @param string  $canonical_url The HTML canonical URL, or '' to omit the link.
	 * @return array{status: int, headers: array<string, string>, send_body: bool}
	 * @throws Obsolete_Artifact When the pathname no longer provides a readable representation.
	 */
	public function headers_for( string $path, Request $request, string $content_type = self::CONTENT_TYPE, string $canonical_url = '' ): array {

		$snapshot = $this->snapshot_for( $path );
		if ( $snapshot === null ) {
			throw new Obsolete_Artifact( 'The cached representation is unavailable.' );
		}
		return $this->headers_for_snapshot( $snapshot, $request, $content_type, $canonical_url );

	}

	/**
	 * Captures bytes and metadata through one opened, atomically published file.
	 *
	 * A removed or unreadable path is a cache miss. Once captured, replacement
	 * or pruning cannot alter the response. No response lock is held afterwards.
	 *
	 * @since 0.5.2
	 *
	 * @param string $path      A trusted, contained cache path.
	 * @param bool   $canonical Whether to read its exact canonical front matter.
	 * @return Snapshot|null The immutable representation, or null when unavailable.
	 */
	public function snapshot_for( string $path, bool $canonical = false ): ?Snapshot {

		// Refuse poisoned publication state before opening any representation.
		if ( ! $this->store->publication()->readable() ) {
			return null;
		}

		// Open one inode; concurrent erasure is an ordinary cache miss.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a concurrently removed file is an ordinary cache miss.
		$stream = @fopen( $path, 'rb' );
		if ( $stream === false ) {
			return null;
		}

		// Close the owned stream on every successful or refused capture.
		try {

			// Both metadata and content refer to this inode, never its later path.
			$stat = fstat( $stream );
			if ( $stat === false || ( $stat['mode'] & 0170000 ) !== 0100000 ) {
				return null;
			}

			// Read and validate the complete bytes before parsing their metadata.
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- failed reads fall through or use valid generated bytes.
			$bytes = @stream_get_contents( $stream );
			if ( $bytes === false || ! $this->store->publication()->readable() ) {
				return null;
			}

			// Parse canonical metadata from the same immutable open representation.
			rewind( $stream );
			$url = $canonical ? $this->canonical_for( $stream ) : '';
			return new Snapshot( $bytes, $stat['mtime'], $url );

		} finally {
			fclose( $stream );
		}

	}

	/**
	 * Computes cached response headers from an immutable representation.
	 *
	 * The caller emits Snapshot::bytes, never reads its old pathname afterwards.
	 * Cached GET, HEAD and conditional responses therefore describe one artifact.
	 *
	 * @since 0.5.2
	 *
	 * @param Snapshot $snapshot      The captured cached representation.
	 * @param Request  $request       The method and conditional headers.
	 * @param string   $content_type  The matched artifact's Content-Type.
	 * @param string   $canonical_url Explicit canonical URL; defaults to stored metadata.
	 * @return array{status: int, headers: array<string, string>, send_body: bool}
	 */
	public function headers_for_snapshot(
		Snapshot $snapshot,
		Request $request,
		string $content_type = self::CONTENT_TYPE,
		string $canonical_url = '',
	): array {

		// Evaluate freshness using only the captured representation.
		$last_modified = $snapshot->last_modified;
		$canonical_url = $canonical_url !== '' ? $canonical_url : $snapshot->canonical_url;
		$etag = $request->if_none_match !== '' ? '"' . md5( $snapshot->bytes ) . '"' : null;
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
		// back, but an If-Modified-Since-only 304 omits it as before.
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
		$etag ??= '"' . md5( $snapshot->bytes ) . '"';
		$headers['ETag'] = $etag;
		$headers['Content-Type'] = $content_type;
		$headers['Content-Length'] = (string) strlen( $snapshot->bytes );

		return [
			'status'    => 200,
			'headers'   => $headers,
			'send_body' => $request->method !== 'HEAD',
		];

	}

	/**
	 * Builds an uncached response when valid bytes could not be persisted.
	 *
	 * Validators and length describe the returned bytes, never a stale file left
	 * behind by a failed write. GET and HEAD share metadata; HEAD and 304 have
	 * no body. The no-store policy prevents a storage outage becoming a cached
	 * representation or error at an intermediary.
	 *
	 * @since 0.5.2
	 *
	 * @param string  $bytes         The valid generated artifact bytes.
	 * @param int     $last_modified The source modification or aggregate build time.
	 * @param Request $request       The read request and its validators.
	 * @param string  $content_type  The artifact's Content-Type.
	 * @param string  $canonical_url The HTML canonical URL, or '' for singletons.
	 * @return array{status: int, headers: array<string, string>, send_body: bool}
	 */
	public function headers_for_bytes( string $bytes, int $last_modified, Request $request, string $content_type = self::CONTENT_TYPE, string $canonical_url = '' ): array {

		// Describe only the generated representation and prevent its storage.
		$etag = '"' . md5( $bytes ) . '"';
		$headers = [
			'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
			'X-Content-Type-Options' => 'nosniff',
			'Last-Modified' => gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT',
			'ETag' => $etag,
		];
		if ( $canonical_url !== '' ) {
			$headers['Link'] = '<' . $canonical_url . '>; rel="canonical"';
		}

		// Conditional requests validate the bytes even without a backing file.
		if ( Conditional_Request::is_fresh( $request->if_none_match, $request->if_modified_since, $etag, $last_modified ) ) {
			return [
				'status' => 304,
				'headers' => $headers,
				'send_body' => false,
			];
		}
		$headers['Content-Type'] = $content_type;
		$headers['Content-Length'] = (string) strlen( $bytes );

		return [
			'status' => 200,
			'headers' => $headers,
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
	 * @param string $path      The request path (leading slash, query already stripped).
	 * @param bool   $versioned Whether serving needs the current generation.
	 * @return array{0: Identity, 1: Serve_Pattern}|null The validated identity and the
	 *               pattern that matched, or null when no shape matches or the key is unsafe.
	 */
	private function identify( string $path, bool $versioned = true ): ?array {

		// An out-of-installation path must never alias a contained cache key.
		$base = rtrim( ( $this->base_path )(), '/' );
		if ( $base !== '' && ! str_starts_with( $path, $base . '/' ) ) {
			return null;
		}

		// Take the path relative to the WordPress home so a subdirectory install
		// (e.g. /blog/about.md or /blog/llms.txt) derives the same key as root.
		$path = $this->strip_base( $path );

		// Find the first registered serve shape the path matches, by its mode.
		foreach ( $this->registry->serve_patterns() as $pattern ) {
			$key = $pattern->match === 'exact'
				? $this->exact_key( $path, $pattern, $versioned )
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
	 * @param bool          $versioned Whether serving needs the current generation.
	 * @return string|null The validated key, or null when the path does not match exactly.
	 */
	private function exact_key( string $path, Serve_Pattern $pattern, bool $versioned ): ?string {

		// Exact, case-sensitive path match; only then read the cache version.
		if ( $path !== $pattern->path ) {
			return null;
		}
		$key = $pattern->key . ( $pattern->versioned && $versioned ? '-v' . ( $this->cache_version )() : '' );

		return preg_match( self::SAFE_KEY, $key ) === 1 ? $key : null;

	}

	/**
	 * Reads the exact HTML canonical URL from the stored front matter.
	 *
	 * The early router has no source post or permalink policy. Missing or invalid
	 * metadata therefore omits the optional hint rather than inventing a URL from
	 * an artifact key, which cannot represent every canonical identity.
	 *
	 * @since 0.1.0
	 *
	 * @param resource $stream The same opened file used to capture response bytes.
	 * @return string The stored canonical URL, or '' when unavailable.
	 */
	private function canonical_for( $stream ): string {

		// Without a trustworthy origin there is nothing safe to point at.
		$origin = ( $this->canonical_origin )();
		if ( $origin === '' ) {
			return '';
		}
		$prefix = $origin . rtrim( ( $this->base_path )(), '/' ) . '/';

		// Accept only the fenced, double-quoted serialisation Core writes.
		if ( fgets( $stream ) !== "---\n" ) {
			return '';
		}
		$canonical = '';
		while ( ( $line = fgets( $stream ) ) !== false ) {

			// Require a complete fence and stop before reading the body.
			if ( $line === "---\n" ) {
				return $canonical;
			}
			if ( preg_match( '/^canonical_url[ \t]*:(.*)$/D', rtrim( $line, "\n" ), $match ) !== 1 ) {
				continue;
			}

			// Ambiguous keys, unsafe header bytes and another origin or base
			// cannot provide a trustworthy canonical hint.
			$url = json_decode( $match[1], true );
			if ( $canonical !== '' || ! is_string( $url ) || ! str_starts_with( $url, $prefix )
				|| preg_match( '/[\x00-\x20\x7f<>"\\\\]/', $url ) !== 0
				|| preg_match( '~(?:^|/)\.\.?(?:/|$)~', rawurldecode( (string) parse_url( $url, PHP_URL_PATH ) ) ) !== 0 ) {
				return '';
			}
			$canonical = $url;

		}

		return '';

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
