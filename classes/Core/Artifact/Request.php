<?php
/**
 * Value object describing an incoming HTTP request to a provider.
 *
 * A provider's match() receives a Request rather than touching superglobals
 * directly, so matching stays a pure function of its inputs and is trivially
 * testable. The request handler builds it from the WordPress request.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Artifact;

/**
 * The facets of an HTTP request a provider needs to decide whether it matches.
 *
 * @since 0.1.0
 */
final readonly class Request {

	/**
	 * Captures the request facets a provider matches and serves on.
	 *
	 * @since 0.1.0
	 *
	 * @param string                $method            The HTTP method, upper-cased (e.g. 'GET').
	 * @param string                $path              The request path, percent-encoding preserved.
	 * @param array<string, string> $query             The decoded query parameters.
	 * @param string                $accept            The raw Accept header, or '' when absent.
	 * @param string                $if_none_match     The If-None-Match header, or '' when absent.
	 * @param string                $if_modified_since The If-Modified-Since header, or '' when absent.
	 */
	public function __construct(
		public string $method,
		public string $path,
		public array $query = [],
		public string $accept = '',
		public string $if_none_match = '',
		public string $if_modified_since = '',
	) {}

}
