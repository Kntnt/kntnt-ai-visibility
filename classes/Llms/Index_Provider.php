<?php
/**
 * The llms.txt singleton artifact provider.
 *
 * A singleton provider — a rule matching exactly one path, like the
 * markdown-alternate provider but for a single URL (docs/spec/llms-txt.md §4.1,
 * docs/adr/0008). It matches the home-relative `/llms.txt` and returns a
 * version-stamped identity so a cache-version bump invalidates the aggregate;
 * the early router computes the same key from its own cache-version callback, so
 * both agree on the cache file. Generation delegates to Index_Builder and is
 * served as text/plain. Release 2 advertises nothing — the file is convention-
 * discovered — but the provider still exposes its (empty) discovery descriptor so
 * Release 3 can decide without the interface changing.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Llms;

use Kntnt\Ai_Visibility\Core\Artifact\Artifact;
use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Link_Relation;
use Kntnt\Ai_Visibility\Core\Artifact\Provider;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;

/**
 * Provides the singleton llms.txt index artifact.
 *
 * @since 0.2.0
 */
final class Index_Provider implements Provider {

	/**
	 * The artifact kind this provider produces.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KIND = 'llms-txt';

	/**
	 * The fixed base cache key (version-stamped at match time).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = 'llms';

	/**
	 * The home-relative path this provider serves.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PATH = '/llms.txt';

	/**
	 * The content type the index is served with.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const CONTENT_TYPE = 'text/plain; charset=utf-8';

	/**
	 * Binds the provider to its builder, the cache-version stamp and the locator.
	 *
	 * @since 0.2.0
	 *
	 * @param Index_Builder      $builder            The llms.txt index builder.
	 * @param Cache_Version      $version            The cache-version stamp.
	 * @param Markdown_Alternate $markdown_alternate The locator, reused for its home-relative path helper.
	 */
	public function __construct(
		private readonly Index_Builder $builder,
		private readonly Cache_Version $version,
		private readonly Markdown_Alternate $markdown_alternate,
	) {}

	/**
	 * Matches the home-relative `/llms.txt` to a version-stamped identity.
	 *
	 * @since 0.2.0
	 *
	 * @param Request $request The incoming request.
	 * @return Identity|null The version-stamped identity, or null when not this path.
	 */
	public function match( Request $request ): ?Identity {

		// Exact home-relative path match; the singleton has no source post (id 0)
		// and a version-stamped key the early router computes identically.
		if ( $this->markdown_alternate->home_relative( $request->path ) !== self::PATH ) {
			return null;
		}

		return new Identity( self::KIND, self::KEY . '-v' . $this->version->current(), 0 );

	}

	/**
	 * Generates the index artifact bytes, as text/plain.
	 *
	 * @since 0.2.0
	 *
	 * @param Identity $identity The identity to generate.
	 * @return Artifact
	 */
	public function generate( Identity $identity ): Artifact {

		// The aggregate has no single source post, so the last-modified is the
		// build time; later serves validate against the cache file's mtime.
		return new Artifact( $this->builder->build(), self::CONTENT_TYPE, time() );

	}

	/**
	 * Advertises the llms.txt singleton as a site-wide link relation.
	 *
	 * @since 0.3.0
	 *
	 * @param Discovery_Context $context The discovery context.
	 * @return list<Link_Relation>
	 */
	public function advertise( Discovery_Context $context ): array {

		// Site-wide artifact: advertised only on the site-scoped discovery call
		// (a null post), never per page. The relation is provisional — no IANA
		// relation for llms.txt exists yet; revise $rel when one is registered.
		if ( $context->post !== null ) {
			return [];
		}

		return [ new Link_Relation( home_url( self::PATH ), 'related', 'text/plain' ) ];

	}

	/**
	 * Declares the exact `/llms.txt` plain-text serve shape.
	 *
	 * @since 0.2.0
	 *
	 * @return Serve_Pattern
	 */
	public function serve_pattern(): Serve_Pattern {
		return Serve_Pattern::exact( self::KIND, self::PATH, self::KEY );
	}

}
