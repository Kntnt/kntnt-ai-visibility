<?php
/**
 * The artifact registry contract.
 *
 * Modules contribute providers through register(); Core reads them back through
 * the consumer accessors. The registry is the single source of truth the serve
 * router (serve_patterns, the security allowlist), the request handler and the
 * discovery mechanism (providers) all read (docs/adr/0008). It stays deep: the
 * ISP decomposition into serving / discovery / allowlist concerns is an
 * internal matter, not a proliferation of public interfaces.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * Collects artifact providers and exposes them to Core's consumers.
 *
 * @since 0.1.0
 */
interface Registry {

	/**
	 * Registers a provider.
	 *
	 * @since 0.1.0
	 *
	 * @param Provider $provider The provider to register.
	 * @return void
	 */
	public function register( Provider $provider ): void;

	/**
	 * Returns every registered provider, in registration order.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Provider>
	 */
	public function providers(): array;

	/**
	 * Returns the serve-pattern allowlist — one pattern per provider.
	 *
	 * @since 0.1.0
	 *
	 * @return list<Serve_Pattern>
	 */
	public function serve_patterns(): array;

}
