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
		add_action( 'init', [ self::class, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle' ], 0 );
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
		add_rewrite_rule( '^index\.md$', 'index.php?markdown_request=1', 'top' );
		add_rewrite_rule( '^(.+?)\.md$', 'index.php?markdown_request=1', 'top' );
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

		// Normalise a trailing-slashed `.md` URL with a 301 to the canonical form.
		$request = Request_Factory::from_globals();
		$target = $this->trailing_slash_target( $request->path );
		if ( $target !== null ) {
			wp_safe_redirect( home_url( $target ), 301 );
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
		if ( post_password_required( $post ) ) {
			$this->forbidden();
		}

		// Serve the negotiated form.
		if ( $mode === 'inline' ) {
			$this->serve_inline( $post, $request );
		} else {
			$this->serve_cache_grade( $identity, $post, $request );
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

		// The cache-grade forms: the advertised `.md` path and its `?format` twin.
		if ( str_ends_with( $request->path, '.md' ) ) {
			return 'cache';
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
	 * @param int     $last_modified The post's last-modified Unix time.
	 * @param Request $request       The request (for method and conditionals).
	 * @param string  $canonical_url The HTML canonical URL.
	 * @param string  $md_url        The cache-grade `.md` URL agents should prefer.
	 * @return array{status: int, headers: array<string, string>, send_body: bool}
	 */
	public function inline_response( string $bytes, int $last_modified, Request $request, string $canonical_url, string $md_url ): array {

		// Validators and the headers common to 200 and 304: Vary for the negotiated
		// form, and a Link steering agents to the cache-grade URL.
		$etag = '"' . md5( $bytes ) . '"';
		$headers = [
			'Vary'                   => 'Accept',
			'X-Content-Type-Options' => 'nosniff',
			'Last-Modified'          => gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT',
			'ETag'                   => $etag,
			'Link'                   => '<' . $canonical_url . '>; rel="canonical", <' . $md_url . '>; rel="alternate"; type="text/markdown"',
		];

		// A fresh client gets a bodyless 304; otherwise the full document.
		if ( Conditional_Request::is_fresh( $request->if_none_match, $request->if_modified_since, $etag, $last_modified ) ) {
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
	 * Reports whether an Accept header explicitly accepts Markdown.
	 *
	 * @since 0.1.0
	 *
	 * @param string $accept The Accept header.
	 * @return bool
	 */
	private function accepts_markdown( string $accept ): bool {
		return stripos( $accept, 'text/markdown' ) !== false || stripos( $accept, 'text/x-markdown' ) !== false;
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
		$this->page_markdown->materialise( $identity, $post );
		$path = $this->cache->path_for( $identity );
		if ( ! is_file( $path ) ) {
			$this->logger->warning( 'Cache file missing after materialise', [ 'key' => $identity->key ] );
			return;
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
		$response = $this->inline_response( $bytes, $this->modified_time( $post ), $request, (string) get_permalink( $post ), $md_url );
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

		// Status first, then each header verbatim.
		status_header( $status );
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}

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
	 * @return void
	 */
	private function forbidden(): void {
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'This content is password protected.';

		exit;

	}

}
