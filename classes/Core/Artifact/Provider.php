<?php
/**
 * The artifact provider contract.
 *
 * A provider is the unit a feature module registers with the Core registry to
 * contribute one kind of discoverable artifact (CONTEXT.md). It is a deep
 * object with three responsibilities — match a request, generate the bytes,
 * advertise itself — plus the serve pattern that feeds the router allowlist. A
 * provider is a rule, not an enumeration: one provider covers every eligible
 * page (docs/adr/0008).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * One kind of discoverable artifact: how to match, generate and advertise it.
 *
 * @since 0.1.0
 */
interface Provider {

	/**
	 * Decides whether this provider owns the current request.
	 *
	 * @since 0.1.0
	 *
	 * @param Request $request The incoming request.
	 * @return Identity|null The matched artifact identity, or null when this
	 *                       provider does not serve the request.
	 */
	public function match( Request $request ): ?Identity;

	/**
	 * Produces the artifact for an identity.
	 *
	 * Pure of HTTP and caching: it returns the bytes and serve metadata; the
	 * router and request handler emit headers and write the cache.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The identity to generate.
	 * @return Artifact The generated artifact.
	 */
	public function generate( Identity $identity ): Artifact;

	/**
	 * Lists the discovery link relations this provider contributes for a page.
	 *
	 * @since 0.1.0
	 *
	 * @param Discovery_Context $context The page being decorated.
	 * @return list<Link_Relation> The relations to advertise (possibly empty).
	 */
	public function advertise( Discovery_Context $context ): array;

	/**
	 * The request shape this provider serves, for the router allowlist.
	 *
	 * @since 0.1.0
	 *
	 * @return Serve_Pattern
	 */
	public function serve_pattern(): Serve_Pattern;

}
