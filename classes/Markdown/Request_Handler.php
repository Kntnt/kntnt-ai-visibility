<?php
/**
 * Serves Markdown alternates on the WordPress request lifecycle.
 *
 * This is the PHP path the early serve router falls through to on a cache miss
 * and the only path for the uncached Accept form. It registers the `.md` rewrite
 * rules and query vars, then on template_redirect negotiates the request,
 * resolves it through the provider, enforces password protection, and serves —
 * the cache-grade `.md`/`?format=markdown` forms from the materialised cache
 * file, the standards-correct `Accept` form inline and uncached with `Vary:
 * Accept` and a steering alternate link (docs/adr/0009, docs/spec §4).
 *
 * The decision logic (negotiate, trailing-slash target, inline response shape)
 * is pure and unit-tested; the header/readfile/exit shell is covered end-to-end.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Markdown;

use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Http\Conditional_Request;
use Kntnt\Ai_Visibility\Core\Http\Request_Factory;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Markdown_Conversion_Failed;
use Kntnt\Ai_Visibility\Core\Public_Content_Rendering_Failed;
use Kntnt\Ai_Visibility\Core\Page_Markdown;

/**
 * Routes and serves Markdown-alternate requests through WordPress.
 *
 * @since 0.1.0
 */
final class Request_Handler {

	/**
	 * The content type every Markdown alternate is served with.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private const CONTENT_TYPE = 'text/markdown; charset=utf-8';

	/**
	 * Owned rules shared by runtime, activation and deactivation.
	 *
	 * @since 0.5.2
	 * @var array<string, string>
	 */
	private const REWRITE_RULES = [
		'^index\.md$' => 'index.php?markdown_request=1',
		'^(.+?)\.md$' => 'index.php?markdown_request=1',
	];

	/**
	 * Binds the handler to the provider, services, cache and router.
	 *
	 * @since 0.1.0
	 *
	 * @param Page_Markdown_Provider $provider      The Markdown-alternate provider.
	 * @param Page_Markdown          $page_markdown The shared page-to-Markdown service.
	 * @param Store                  $cache         The artifact cache store.
	 * @param Serve_Router           $router        The serve router (header builder).
	 * @param Logger                 $logger        The diagnostics logger.
	 */
	public function __construct(
		private readonly Page_Markdown_Provider $provider,
		private readonly Page_Markdown $page_markdown,
		private readonly Store $cache,
		private readonly Serve_Router $router,
		private readonly Logger $logger,
	) {}

	/**
	 * Registers the rewrite rules, query vars and the request hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'plugins_loaded', [ $this, 'protect_negotiated_request' ], PHP_INT_MIN );
		add_action( 'litespeed_init', [ $this, 'protect_negotiated_request' ] );
		add_filter( 'wp_headers', [ $this, 'vary_canonical_headers' ] );
		add_action( 'init', [ self::class, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle' ], 0 );
	}

	/**
	 * Prevents page-cache integrations from storing a negotiated representation.
	 *
	 * Runs before ordinary plugins_loaded callbacks and again when LiteSpeed's
	 * API is ready. Caches serving before WordPress require server configuration.
	 *
	 * @since 0.5.2
	 *
	 * @return void
	 */
	public function protect_negotiated_request(): void {

		// Explicit artifact URLs retain their cache-grade policy.
		if ( $this->negotiate( Request_Factory::from_globals() ) !== 'inline' ) {
			return;
		}

		// Establish the WordPress convention before cache plugins inspect it.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'Kntnt AI Visibility negotiated Markdown' );

	}

	/**
	 * Keeps canonical HTML selection coherent with the negotiated representation.
	 *
	 * @since 0.5.2
	 *
	 * @param array<string, string> $headers WordPress's response headers.
	 * @return array<string, string>
	 */
	public function vary_canonical_headers( array $headers ): array {

		// Dedicated artifact addresses do not vary their representation on Accept.
		$request = Request_Factory::from_globals();
		if ( $this->router->is_artifact_path( $request->path ) || $this->negotiate( $request ) === 'cache' ) {
			return $headers;
		}

		// Consolidate case-insensitive Vary fields without replacing their values.
		$vary = [];
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) === 'vary' ) {
				$vary[] = $value;
				unset( $headers[ $name ] );
			}
		}
		$headers['Vary'] = $this->vary_accept( implode( ', ', $vary ) );

		return $headers;

	}

	/**
	 * Registers the `.md` rewrite rules.
	 *
	 * Both forms route to a marker query var so WordPress loads rather than 404s;
	 * the target post is resolved from the request path by the provider, which
	 * handles nested and dated permalinks for free. Static and side-effect-only
	 * so activation (install.php) can register the same rules before flushing,
	 * keeping the activation and runtime rule sets identical (docs/spec §7).
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_rewrite_rules(): void {
		foreach ( self::REWRITE_RULES as $pattern => $target ) {
			add_rewrite_rule( $pattern, $target, 'top' );
		}
	}

	/**
	 * Removes only this module's entries before the deactivation rewrite flush.
	 *
	 * Detaches runtime registration even before init: WordPress can defer its
	 * flush until wp_loaded. A different owner replacing the same pattern keeps
	 * its rule. Regeneration uses in-memory extras, not the stored option.
	 *
	 * @since 0.5.2
	 * @return void
	 */
	public static function unregister_rewrite_rules(): void {

		global $wp_rewrite;

		// A deferred flush must not reintroduce this deactivated module's rules.
		remove_action( 'init', [ self::class, 'register_rewrite_rules' ] );

		// Native deactivation may precede construction of the rewrite component.
		if ( ! $wp_rewrite instanceof \WP_Rewrite ) {
			return;
		}

		// Preserve unrelated targets even when their pattern overlaps ours.
		foreach ( self::REWRITE_RULES as $pattern => $target ) {
			if ( ( $wp_rewrite->extra_rules_top[ $pattern ] ?? null ) === $target ) {
				unset( $wp_rewrite->extra_rules_top[ $pattern ] );
			}
		}

	}

	/**
	 * Registers the plugin's public query vars.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, string> $vars The existing query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'markdown_request';
		$vars[] = 'format';

		return $vars;

	}

	/**
	 * Handles a request on template_redirect, serving Markdown when applicable.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function handle(): void {

		// Leave form submissions and other non-read methods to WordPress before
		// negotiation, redirects, generation or conditional response handling.
		$request = Request_Factory::from_globals();
		if ( ! $request->is_read() ) {
			return;
		}

		// Normalise a trailing-slashed `.md` URL with a 301 to the canonical form.
		$target = $this->trailing_slash_target( $request->path );
		if ( $target !== null ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}

		// Only markdown requests are handled; everything else is left to WordPress.
		$mode = $this->negotiate( $request );
		if ( $mode === null ) {
			return;
		}

		// Stop WordPress from canonical-redirecting a `.md` URL we are about to
		// serve (or 404) ourselves.
		$is_md_path = str_ends_with( $request->path, '.md' );
		if ( $is_md_path ) {
			add_filter( 'redirect_canonical', '__return_false' );
		}

		// Resolve to an eligible post; a `.md` miss is an explicit 404, while a
		// negotiated miss falls through to the normal HTML response.
		$identity = $this->provider->match( $request );
		if ( $identity === null ) {
			if ( $is_md_path ) {
				$this->not_found();
			}
			return;
		}

		// Password-protected content is refused with a plain-text 403.
		$post = get_post( $identity->source_id );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( $post->post_password !== '' || post_password_required( $post ) ) {
			$this->forbidden();
		}

		// Serve the negotiated form.
		try {
			if ( $mode === 'inline' ) {
				$this->serve_inline( $post, $request );
			} else {
				$this->serve_cache_grade( $identity, $post, $request );
			}
		} catch ( Markdown_Conversion_Failed | Public_Content_Rendering_Failed ) {

			// Failed rendering or conversion has no representation or validators.
			status_header( 500 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			if ( $request->method !== 'HEAD' ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This fixed translated response is plain text, never HTML.
				echo __( 'This content could not be converted to Markdown.', 'kntnt-ai-visibility' );
			}
			exit;

		} catch ( \DomainException $exception ) {
			$this->forbidden( 'This content cannot produce a public artifact.' );
		}

	}

	/**
	 * Decides the serving mode for a request, or null when it is not Markdown.
	 *
	 * Precedence: a `.md` path, then `?format=markdown`, then `Accept`.
	 *
	 * @since 0.1.0
	 *
	 * @param Request $request The request.
	 * @return string|null 'cache', 'inline', or null.
	 */
	public function negotiate( Request $request ): ?string {

		// Early cache integrations also consult this seam, before handle() runs.
		if ( ! $request->is_read() ) {
			return null;
		}

		// The cache-grade forms: the advertised `.md` path and its `?format` twin.
		if ( str_ends_with( $request->path, '.md' ) ) {
			return 'cache';
		}

		// Core's registered dedicated addresses belong to their artifact provider.
		if ( $this->router->is_artifact_path( $request->path ) ) {
			return null;
		}
		if ( ( $request->query['format'] ?? '' ) === 'markdown' ) {
			return 'cache';
		}

		// The standards-correct, uncached negotiated form.
		return $this->accepts_markdown( $request->accept ) ? 'inline' : null;

	}

	/**
	 * Returns the de-slashed `.md` path for a trailing-slash request, or null.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The request path.
	 * @return string|null
	 */
	public function trailing_slash_target( string $path ): ?string {

		// Match a `.md` URL followed by one or more trailing slashes.
		if ( preg_match( '~^(/.+\.md)/+$~', $path, $matches ) === 1 ) {
			return $matches[1];
		}

		return null;

	}

	/**
	 * Builds the inline (Accept) response: status, headers and body flag.
	 *
	 * @since 0.1.0
	 *
	 * @param string  $bytes         The Markdown bytes.
	 * @param Request $request       The request (for method and conditionals).
	 * @param string  $canonical_url The HTML canonical URL.
	 * @param string  $md_url        The cache-grade `.md` URL agents should prefer.
	 * @return array{status: int, headers: array<string, string>, send_body: bool}
	 */
	public function inline_response( string $bytes, Request $request, string $canonical_url, string $md_url ): array {

		// Validators and the headers common to 200 and 304: Vary for the negotiated
		// form, and a Link steering agents to the cache-grade URL.
		$etag = '"' . md5( $bytes ) . '"';
		$headers = [
			'Vary'                   => 'Accept',
			'Cache-Control'          => 'private, no-store, no-cache, max-age=0, must-revalidate',
			'X-Content-Type-Options' => 'nosniff',
			'ETag'                   => $etag,
			'Link'                   => '<' . $canonical_url . '>; rel="canonical", <' . $md_url . '>; rel="alternate"; type="text/markdown"',
		];

		// Dynamic metadata and shared content have no complete modification date.
		// Only the freshly rendered bytes can establish inline freshness.
		if ( Conditional_Request::is_fresh( $request->if_none_match, $request->if_modified_since, $etag, null ) ) {
			return [
				'status'    => 304,
				'headers'   => $headers,
				'send_body' => false,
			];
		}
		$headers['Content-Type'] = self::CONTENT_TYPE;
		$headers['Content-Length'] = (string) strlen( $bytes );

		return [
			'status'    => 200,
			'headers'   => $headers,
			'send_body' => $request->method !== 'HEAD',
		];

	}

	/**
	 * Reports whether explicitly requested Markdown is preferred over HTML.
	 * Exact HTML/XHTML ranges override type and then universal wildcards.
	 * HTML wins ties. Invalid or repeated quality parameters reject their range.
	 *
	 * @since 0.1.0
	 *
	 * @param string $accept The Accept header.
	 * @return bool
	 */
	private function accepts_markdown( string $accept ): bool {

		// Parse explicit Markdown preferences and the HTML alternative ranges.
		$qualities = [];
		foreach ( explode( ',', strtolower( $accept ) ) as $range ) {

			// Normalise media types and parameters independently of their order.
			$parts = array_map( 'trim', explode( ';', $range ) );
			$type = array_shift( $parts );
			$quality = 1.0;
			$has_quality = false;
			foreach ( $parts as $parameter ) {

				// Parameters other than the quality weight do not set preference.
				if ( preg_match( '/^q(?:\s*=\s*(.*))?$/', $parameter, $match ) !== 1 ) {
					continue;
				}

				// Repeated weights make the range ambiguous regardless of order.
				if ( $has_quality ) {
					$quality = 0.0;
					break;
				}

				// Reject malformed weights: RFC 9110 permits 0–1 with at most
				// three fractional digits at this untrusted header boundary.
				$has_quality = true;
				$value = $match[1] ?? '';
				$is_quality = preg_match( '/^(?:0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/D', $value ) === 1;
				$quality = $is_quality ? (float) $value : 0.0;

			}

			// Repeated media ranges have the same result in either order.
			$qualities[ $type ] = max( $qualities[ $type ] ?? 0.0, $quality );

		}

		// Wildcards can prefer HTML but never explicitly request Markdown.
		$markdown = max( $qualities['text/markdown'] ?? 0.0, $qualities['text/x-markdown'] ?? 0.0 );

		// Specific ranges determine each HTML alternative's effective quality,
		// including explicit zeroes; keep HTML when either alternative ties.
		$html = max(
			$qualities['text/html'] ?? $qualities['text/*'] ?? $qualities['*/*'] ?? 0.0,
			$qualities['application/xhtml+xml'] ?? $qualities['application/*'] ?? $qualities['*/*'] ?? 0.0,
		);

		return $markdown > 0.0 && $markdown > $html;

	}

	/**
	 * Serves the cache-grade form from the materialised cache file, then exits.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The artifact identity.
	 * @param \WP_Post $post     The resolved post.
	 * @param Request  $request  The request.
	 * @return void
	 */
	private function serve_cache_grade( Identity $identity, \WP_Post $post, Request $request ): void {

		// Materialise the cache (single-flight) and serve the resulting file with
		// the router's file-based headers, so this first serve and every later
		// router serve agree on the validators.
		$result = $this->page_markdown->materialise( $identity, $post );
		$path = $this->cache->path_for( $identity );
		if ( ! $result->persisted || ! is_file( $path ) ) {

			// A cache outage must not fall through to an unrelated HTML template.
			$this->logger->warning( 'Serving generated artifact without cache', [ 'key' => $identity->key ] );
			$response = $this->router->headers_for_bytes( $result->bytes, $this->modified_time( $post ), $request, self::CONTENT_TYPE, (string) get_permalink( $post ) );
			$this->send( $response['status'], $response['headers'] );
			if ( $response['send_body'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML escaping corrupts a text/markdown representation.
				echo $result->bytes;
			}

			exit;

		}
		$response = $this->router->headers_for( $path, $request, self::CONTENT_TYPE, (string) get_permalink( $post ) );
		$this->send( $response['status'], $response['headers'] );
		if ( $response['send_body'] ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a Core-owned cache file is the point of the serve path.
			readfile( $path );
		}

		exit;

	}

	/**
	 * Serves the inline (Accept) form uncached, then exits.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post    The resolved post.
	 * @param Request  $request The request.
	 * @return void
	 */
	private function serve_inline( \WP_Post $post, Request $request ): void {

		// Render uncached, steering agents to the cache-grade `.md` URL the
		// provider advertises.
		$bytes = $this->page_markdown->for_post( $post );
		$relations = $this->provider->advertise( new Discovery_Context( $post ) );
		$md_url = $relations === [] ? '' : $relations[0]->href;
		$response = $this->inline_response( $bytes, $request, (string) get_permalink( $post ), $md_url );

		// An HTML integration's source date cannot validate dynamic Markdown.
		header_remove( 'Last-Modified' );
		$this->send( $response['status'], $response['headers'] );
		if ( $response['send_body'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the body is Markdown served as text/markdown; HTML escaping would corrupt it.
			echo $bytes;
		}

		exit;

	}

	/**
	 * Returns a post's GMT last-modified time as a Unix timestamp.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post.
	 * @return int
	 */
	private function modified_time( \WP_Post $post ): int {
		$modified = get_post_modified_time( 'U', true, $post );

		return is_numeric( $modified ) ? (int) $modified : 0;

	}

	/**
	 * Emits the status line and headers.
	 *
	 * @since 0.1.0
	 *
	 * @param int                   $status  The HTTP status code.
	 * @param array<string, string> $headers The headers to emit.
	 * @return void
	 */
	private function send( int $status, array $headers ): void {

		// Preserve Vary fields added by WordPress and other integrations.
		status_header( $status );
		foreach ( $headers as $name => $value ) {
			if ( strtolower( $name ) === 'vary' ) {
				$value = $this->vary_accept( $value );
			}
			header( $name . ': ' . $value );
		}

	}

	/**
	 * Combines existing Vary fields with Accept, preserving wildcard semantics.
	 *
	 * @since 0.5.2
	 *
	 * @param string $value The Vary value about to be emitted.
	 * @return string
	 */
	private function vary_accept( string $value ): string {

		// PHP may contain multiple Vary lines emitted before this response.
		$values = [ $value, 'Accept' ];
		foreach ( headers_list() as $header ) {
			if ( str_starts_with( strtolower( $header ), 'vary:' ) ) {
				$values[] = substr( $header, 5 );
			}
		}

		// Deduplicate field names case-insensitively; a wildcard dominates them.
		$fields = [];
		foreach ( explode( ',', implode( ',', $values ) ) as $field ) {
			$field = trim( $field );
			if ( $field === '*' ) {
				return '*';
			}
			if ( $field !== '' ) {
				$fields[ strtolower( $field ) ] = $field;
			}
		}

		return implode( ', ', $fields );

	}

	/**
	 * Marks the request as a 404 and lets WordPress render the 404 template.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private function not_found(): void {

		// Turn the loaded query into a genuine 404 so the theme's 404 template
		// renders with the right status.
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();

	}

	/**
	 * Refuses password-protected content with a plain-text 403, then exits.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message The plain-text refusal reason.
	 * @return void
	 */
	private function forbidden( string $message = 'This content is password protected.' ): void {
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text response, never HTML.
		echo $message;

		exit;

	}

}
