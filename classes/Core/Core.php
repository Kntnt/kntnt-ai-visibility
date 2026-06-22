<?php
/**
 * The Core service facade.
 *
 * A narrow facade exposing the shared services a module may use: the artifact
 * registry, the settings registry, the page-to-Markdown service, the logger and
 * the cache store. Modules receive Core in boot() and depend on these
 * abstractions (DIP); they never touch each other (docs/adr/0006).
 *
 * The cache store is exposed alongside the four services named in the design
 * note because a provider must write its artifact on a cache miss and read it
 * back to serve (docs/spec §3.4) — the store is the Core service that ownership
 * requires. The serve router is exposed for the same reason: the Markdown
 * module's request handler builds its cache-grade response headers through the
 * router so the first PHP serve and every later router serve agree on the
 * validators (docs/spec §4.4).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Artifact\Registry as Artifact_Registry;
use Kntnt\Ai_Visibility\Core\Cache\Serve_Router;
use Kntnt\Ai_Visibility\Core\Cache\Store;
use Kntnt\Ai_Visibility\Core\Settings\Registry as Settings_Registry;

/**
 * Holds and hands out the Core services modules depend on.
 *
 * @since 0.1.0
 */
final readonly class Core {

	/**
	 * Holds the Core services for the lifetime of the request.
	 *
	 * @since 0.1.0
	 *
	 * @param Artifact_Registry $artifacts     The artifact-provider registry.
	 * @param Settings_Registry $settings      The settings registry.
	 * @param Page_Markdown     $page_markdown The shared page-to-Markdown service.
	 * @param Logger            $logger        The diagnostics logger.
	 * @param Store             $cache         The artifact cache store.
	 * @param Serve_Router      $router        The early, contained serve router.
	 */
	public function __construct(
		private Artifact_Registry $artifacts,
		private Settings_Registry $settings,
		private Page_Markdown $page_markdown,
		private Logger $logger,
		private Store $cache,
		private Serve_Router $router,
	) {}

	/**
	 * Returns the artifact-provider registry.
	 *
	 * @since 0.1.0
	 *
	 * @return Artifact_Registry
	 */
	public function artifacts(): Artifact_Registry {
		return $this->artifacts;
	}

	/**
	 * Returns the settings registry.
	 *
	 * @since 0.1.0
	 *
	 * @return Settings_Registry
	 */
	public function settings(): Settings_Registry {
		return $this->settings;
	}

	/**
	 * Returns the shared page-to-Markdown service.
	 *
	 * @since 0.1.0
	 *
	 * @return Page_Markdown
	 */
	public function page_markdown(): Page_Markdown {
		return $this->page_markdown;
	}

	/**
	 * Returns the diagnostics logger.
	 *
	 * @since 0.1.0
	 *
	 * @return Logger
	 */
	public function logger(): Logger {
		return $this->logger;
	}

	/**
	 * Returns the artifact cache store.
	 *
	 * @since 0.1.0
	 *
	 * @return Store
	 */
	public function cache(): Store {
		return $this->cache;
	}

	/**
	 * Returns the early, contained serve router.
	 *
	 * @since 0.1.0
	 *
	 * @return Serve_Router
	 */
	public function router(): Serve_Router {
		return $this->router;
	}

}
