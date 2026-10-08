<?php
/**
 * Serves the llms singletons on the WordPress request lifecycle.
 *
 * The PHP path the early serve router falls through to on a cold or invalidated
 * cache (docs/spec/llms-txt.md §4.5). It registers the `/llms.txt` and
 * `/llms-full.txt` rewrite rules (so WordPress loads rather than 404s) and the
 * marker query var, then on template_redirect matches the request through its
 * singleton providers. On a match it materialises the aggregate once through the
 * single-flight guard (the provider's generate() as the producer) and serves the
 * resulting cache file through the router's file-based headers — so this first
 * serve and every later early-router serve agree on the validators (ETag from the
 * file md5, Last-Modified from its mtime). There is no inline form and no password
 * gate: the singletons are site-wide public files. A non-matching request is left
 * to WordPress.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Llms;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Provider;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Http\Request_Factory;
use Kntnt\Ai_Visibility\Core\Logger;
use Kntnt\Ai_Visibility\Core\Markdown_Conversion_Failed;
use Kntnt\Ai_Visibility\Core\Public_Content_Rendering_Failed;

/**
 * Routes and serves the llms singleton requests through WordPress.
 *
 * @since 0.2.0
 */
final class Request_Handler {

	/**
	 * Owned rules shared by runtime, activation and deactivation.
	 *
	 * @since 0.5.2
	 * @var array<string, string>
	 */
	private const REWRITE_RULES = [
		'^llms\.txt$' => 'index.php?kntnt_aiv_llms=index',
		'^llms-full\.txt$' => 'index.php?kntnt_aiv_llms=full',
	];

	/**
	 * Binds the handler to its providers, single-flight, cache and router.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, Provider> $providers     The llms singleton providers (index, full).
	 * @param Single_Flight        $single_flight The single-flight materialiser.
	 * @param Store                $cache         The artifact cache store.
	 * @param Serve_Router         $router        The serve router (header builder).
	 * @param Logger               $logger        The diagnostics logger.
	 */
	public function __construct(
		private readonly array $providers,
		private readonly Single_Flight $single_flight,
		private readonly Store $cache,
		private readonly Serve_Router $router,
		private readonly Logger $logger,
	) {}

	/**
	 * Registers the rewrite rules, query var and the request hook.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'init', [ self::class, 'register_rewrite_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_action( 'template_redirect', [ $this, 'handle' ], 0 );
	}

	/**
	 * Registers the singleton rewrite rules.
	 *
	 * Each maps to a marker query var so WordPress loads rather than 404s; the
	 * provider resolves the path itself. Static and side-effect-only so
	 * activation (install.php) can register the same rules before flushing.
	 *
	 * @since 0.2.0
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
	 * Registers the plugin's llms marker query var.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, string> $vars The existing query vars.
	 * @return array<int, string>
	 */
	public function register_query_vars( array $vars ): array {
		$vars[] = 'kntnt_aiv_llms';

		return $vars;

	}

	/**
	 * Finds the first provider that matches the request, with its identity.
	 *
	 * @since 0.2.0
	 *
	 * @param Request $request The request.
	 * @return array{0: Provider, 1: Identity}|null The matched provider and identity, or null.
	 */
	public function match_provider( Request $request ): ?array {

		// First provider whose match() claims the request wins.
		foreach ( $this->providers as $provider ) {
			$identity = $provider->match( $request );
			if ( $identity !== null ) {
				return [ $provider, $identity ];
			}
		}

		return null;

	}

	/**
	 * Handles a request on template_redirect, serving an llms singleton when matched.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public function handle(): void {

		// Leave unsupported methods to the ordinary WordPress workflow on both
		// cold and warm artifact paths, before matching or materialisation.
		$request = Request_Factory::from_globals();
		if ( ! $request->is_read() ) {
			return;
		}

		// Match the request; a non-match is left to WordPress.
		$match = $this->match_provider( $request );
		if ( $match === null ) {
			return;
		}
		[ $provider, $identity ] = $match;

		// This dedicated-path shell leaves query-only providers to their handler.
		$pattern = $provider->serve_pattern();
		if ( $pattern === null ) {
			return;
		}

		// Materialise the aggregate once (single-flight), then serve the resulting
		// cache file with the router's file-based headers so the validators match
		// every later early-router serve.
		try {
			$result = $this->single_flight->once( $identity, static fn(): string => $provider->generate( $identity )->bytes );
		} catch ( Markdown_Conversion_Failed | Public_Content_Rendering_Failed ) {

			// A failed render or conversion invalidates the complete aggregate.
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
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'This content cannot produce a public artifact.';
			exit;
		}
		$path = $this->cache->path_for( $identity );
		$is_persisted = $result->persisted && is_file( $path );
		if ( ! $is_persisted ) {
			$this->logger->warning( 'Serving generated artifact without cache', [ 'key' => $identity->key ] );
		}

		// Prune the stale, version-stamped aggregates a cache-version bump orphaned
		// (e.g. llms-v7.md after a bump to v8); scoped to this kind directory, it
		// never touches the per-page markdown-alternate cache (spec §5.5).
		if ( $is_persisted ) {
			$this->cache->prune_siblings( $identity );
		}

		// Serve with the matched pattern's Content-Type; the singletons have no
		// canonical back-link.
		$response = $is_persisted
			? $this->router->headers_for( $path, $request, $pattern->content_type )
			: $this->router->headers_for_bytes( $result->bytes, time(), $request, $pattern->content_type );
		status_header( $response['status'] );
		foreach ( $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		if ( $response['send_body'] ) {
			if ( $is_persisted ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a Core-owned cache file is the point of the serve path.
				readfile( $path );
			} else {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the generated plain-text artifact must retain its exact bytes.
				echo $result->bytes;
			}
		}

		exit;

	}

}
