<?php
/**
 * The llms-full.txt singleton artifact provider.
 *
 * Mirrors Index_Provider for the full-text aggregate (docs/spec/llms-txt.md
 * §4.1): a singleton rule matching the home-relative `/llms-full.txt`, returning
 * a version-stamped identity so a cache-version bump invalidates it, delegating
 * generation to Full_Builder and serving as text/plain. It advertises nothing in
 * Release 2 but exposes its (empty) discovery descriptor for Release 3.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Llms;

use Kntnt\Ai_Visibility\Core\Artifact\Artifact;
use Kntnt\Ai_Visibility\Core\Artifact\Discovery_Context;
use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Artifact\Provider;
use Kntnt\Ai_Visibility\Core\Artifact\Request;
use Kntnt\Ai_Visibility\Core\Artifact\Serve_Pattern;
use Kntnt\Ai_Visibility\Core\Cache\Cache_Version;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;

/**
 * Provides the singleton llms-full.txt full-text artifact.
 *
 * @since 0.2.0
 */
final class Full_Provider implements Provider {

	/**
	 * The artifact kind this provider produces.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	public const KIND = 'llms-full';

	/**
	 * The fixed base cache key (version-stamped at match time).
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const KEY = 'llms-full';

	/**
	 * The home-relative path this provider serves.
	 *
	 * @since 0.2.0
	 *
	 * @var string
	 */
	private const PATH = '/llms-full.txt';

	/**
	 * The content type the full file is served with.
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
	 * @param Full_Builder       $builder            The llms-full.txt full-text builder.
	 * @param Cache_Version      $version            The cache-version stamp.
	 * @param Markdown_Alternate $markdown_alternate The locator, reused for its home-relative path helper.
	 */
	public function __construct(
		private readonly Full_Builder $builder,
		private readonly Cache_Version $version,
		private readonly Markdown_Alternate $markdown_alternate,
	) {}

	/**
	 * Matches the home-relative `/llms-full.txt` to a version-stamped identity.
	 *
	 * @since 0.2.0
	 *
	 * @param Request $request The incoming request.
	 * @return Identity|null The version-stamped identity, or null when not this path.
	 */
	public function match( Request $request ): ?Identity {

		// Exact home-relative path match; source-less (id 0), version-stamped key.
		if ( $this->markdown_alternate->home_relative( $request->path ) !== self::PATH ) {
			return null;
		}

		return new Identity( self::KIND, self::KEY . '-v' . $this->version->current(), 0 );

	}

	/**
	 * Generates the full-text artifact bytes, as text/plain.
	 *
	 * @since 0.2.0
	 *
	 * @param Identity $identity The identity to generate.
	 * @return Artifact
	 */
	public function generate( Identity $identity ): Artifact {

		// The aggregate has no single source post; the build time is the
		// last-modified, and later serves validate against the cache file's mtime.
		return new Artifact( $this->builder->build(), self::CONTENT_TYPE, time() );

	}

	/**
	 * Advertises nothing — the file is convention-discovered (Release 2).
	 *
	 * @since 0.2.0
	 *
	 * @param Discovery_Context $context The page being decorated.
	 * @return list<\Kntnt\Ai_Visibility\Core\Artifact\Link_Relation>
	 */
	public function advertise( Discovery_Context $context ): array {
		return [];
	}

	/**
	 * Declares the exact `/llms-full.txt` plain-text serve shape.
	 *
	 * @since 0.2.0
	 *
	 * @return Serve_Pattern
	 */
	public function serve_pattern(): Serve_Pattern {
		return Serve_Pattern::exact( self::KIND, self::PATH, self::KEY );
	}

}
