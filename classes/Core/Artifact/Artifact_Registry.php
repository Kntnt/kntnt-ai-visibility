<?php
/**
 * The in-memory artifact registry.
 *
 * Holds the providers registered during module boot for the lifetime of the
 * request. There is no persistence: providers are code, re-registered on every
 * load, so the registry is a simple ordered collection (docs/adr/0008).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * Default Registry: an ordered, in-memory list of providers.
 *
 * @since 0.1.0
 */
final class Artifact_Registry implements Registry {

	/**
	 * The registered providers, in registration order.
	 *
	 * @since 0.1.0
	 *
	 * @var list<Provider>
	 */
	private array $providers = [];

	/**
	 * Appends a provider to the collection.
	 *
	 * @since 0.1.0
	 *
	 * @param Provider $provider The provider to register.
	 * @return void
	 */
	public function register( Provider $provider ): void {
		$this->providers[] = $provider;
	}

	/**
	 * Returns every registered provider, in registration order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Provider>
	 */
	public function providers(): array {
		return $this->providers;
	}

	/**
	 * Returns one serve pattern per provider as the security allowlist.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Serve_Pattern>
	 */
	public function serve_patterns(): array {

		// One allowlist entry per provider, derived from its declared shape.
		return array_map( static fn( Provider $provider ): Serve_Pattern => $provider->serve_pattern(), $this->providers );

	}

}
