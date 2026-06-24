<?php
/**
 * Splices the Content-Signal directive into the virtual robots.txt.
 *
 * Hooks robots_txt. Returns the output unchanged when the site is non-public
 * (blog_public = 0) or when the resolved policy defers every signal. Otherwise it
 * inserts a comment and one Content-Signal line into the existing `User-agent: *`
 * group (appending a fresh group only if none exists), never reordering or
 * removing an existing line. A physical robots.txt bypasses this filter — the
 * documented limitation (docs/spec/content-signals.md §3.5).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.4.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Signals;

/**
 * Decorates robots.txt with the content-signal block.
 *
 * @since 0.4.0
 */
final class Robots_Decorator {

	/**
	 * The policy resolver; called on each robots_txt filter invocation.
	 *
	 * @since 0.4.0
	 *
	 * @var \Closure(): Policy
	 */
	private \Closure $resolve;

	/**
	 * Constructs the decorator with a policy resolver closure.
	 *
	 * @since 0.4.0
	 *
	 * @param \Closure $resolve Resolves the effective policy (saved → default → filter); returns Policy.
	 */
	public function __construct( \Closure $resolve ) {
		$this->resolve = $resolve;
	}

	/**
	 * Registers the robots_txt filter.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'robots_txt', [ $this, 'decorate' ], 10, 2 );
	}

	/**
	 * Decorates the robots.txt output with the content-signal block.
	 *
	 * @since 0.4.0
	 *
	 * @param string $output    The robots.txt content so far.
	 * @param bool   $is_public WordPress's public flag for the site.
	 * @return string The decorated output.
	 */
	public function decorate( string $output, bool $is_public ): string {

		// Suppress entirely on a non-public site: the owner has globally
		// discouraged crawlers, so declare nothing.
		if ( ! $is_public ) {
			return $output;
		}

		// Nothing to add when every signal defers.
		$directives = ( $this->resolve )()->directives();
		if ( $directives === [] ) {
			return $output;
		}

		// Build the comment + Content-Signal line.
		$pairs = [];
		foreach ( $directives as $name => $value ) {
			$pairs[] = $name . '=' . $value;
		}
		$block = '# Kntnt AI Visibility — AI usage content signals' . "\n"
			. 'Content-Signal: ' . implode( ', ', $pairs );

		// Splice into the existing `User-agent: *` group; append a fresh group if
		// (unusually) none is present. Never touch any existing line.
		$pattern = '/^(User-agent:\s*\*\s*)$/mi';
		if ( preg_match( $pattern, $output ) === 1 ) {
			return (string) preg_replace( $pattern, "$1\n" . $block, $output, 1 );
		}

		return rtrim( $output ) . "\n\n" . 'User-agent: *' . "\n" . $block . "\n";

	}

}
