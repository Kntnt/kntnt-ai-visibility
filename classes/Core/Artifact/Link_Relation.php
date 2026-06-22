<?php
/**
 * Value object for one advertised discovery link.
 *
 * A provider's advertise() returns Link_Relation values describing how an
 * artifact should be surfaced to agents. In Release 1 the Markdown module
 * renders these into the per-page HTML `<link rel="alternate">` tag; the
 * Release 3 Link-headers module will render the same data into RFC 8288 HTTP
 * `Link` headers (docs/adr/0008).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * One discovery relation: where the artifact is, and how it relates.
 *
 * @since 0.1.0
 */
final readonly class Link_Relation {

	/**
	 * Describes one advertised discovery link.
	 *
	 * @since 0.1.0
	 *
	 * @param string $href The absolute URL of the artifact.
	 * @param string $rel  The link relation, e.g. 'alternate'.
	 * @param string $type The artifact's MIME type, e.g. 'text/markdown'.
	 */
	public function __construct(
		public string $href,
		public string $rel,
		public string $type,
	) {}

}
