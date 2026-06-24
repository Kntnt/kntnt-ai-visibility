<?php
/**
 * The path-exclusion curation gate.
 *
 * A site owner can curate individual entries out of every artifact — the
 * per-page `.md`, llms.txt and llms-full.txt — by listing path patterns under
 * Settings, one regular expression per line. This service owns the single
 * question the eligibility predicate asks: does a post's home-relative path
 * match any pattern? The matrix decides which content *types* are exposed; this
 * decides which individual *paths* are pulled back out, the per-URL curation the
 * type matrix cannot express.
 *
 * Patterns are matched against the home-relative path only (not the host), so a
 * pattern is portable across hosts and a careless one cannot exclude the whole
 * site by matching the domain. Each line is a PCRE body the user writes without
 * delimiters or flags: the service wraps it in `#…#iu`, a delimiter that cannot
 * occur literally in a URL path (it would start the fragment) and the Unicode
 * and case-insensitive flags a path match naturally needs. The matched subject
 * is rawurldecoded, so a non-ASCII slug is matched in its readable form.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.5.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core\Content;

/**
 * Decides whether a post's path is excluded by the configured patterns.
 *
 * @since 0.5.0
 */
final class Exclusions {

	/**
	 * The PCRE delimiter wrapped around every user pattern.
	 *
	 * A literal `#` cannot occur in a URL path — it begins the fragment — so it
	 * never collides with the path bodies the user writes, and it carries no
	 * special meaning in ordinary (non-extended) PCRE.
	 *
	 * @since 0.5.0
	 *
	 * @var string
	 */
	private const DELIMITER = '#';

	/**
	 * The flags appended to every user pattern: Unicode and case-insensitive.
	 *
	 * `u` makes a non-ASCII slug match correctly against the rawurldecoded path;
	 * `i` is the natural mode for path matching, where case rarely matters.
	 *
	 * @since 0.5.0
	 *
	 * @var string
	 */
	private const FLAGS = 'iu';

	/**
	 * Reads the raw, newline-separated pattern text from its source.
	 *
	 * @since 0.5.0
	 *
	 * @var callable(): string
	 */
	private $text_reader;

	/**
	 * Returns the WordPress home URL, resolved lazily on first match.
	 *
	 * @since 0.5.0
	 *
	 * @var callable(): string
	 */
	private $home_url;

	/**
	 * The compiled, validated patterns, memoised on first use within a request.
	 *
	 * @since 0.5.0
	 *
	 * @var list<string>|null
	 */
	private ?array $compiled = null;

	/**
	 * Binds the gate to its pattern source and the home-URL resolver.
	 *
	 * @since 0.5.0
	 *
	 * @param callable(): string $text_reader Returns the raw newline-separated pattern text.
	 * @param callable(): string $home_url    Returns the WordPress home URL.
	 */
	public function __construct( callable $text_reader, callable $home_url ) {
		$this->text_reader = $text_reader;
		$this->home_url = $home_url;
	}

	/**
	 * Reports whether a post is excluded by any configured pattern.
	 *
	 * Short-circuits before resolving the permalink when no pattern is set, so
	 * the common zero-pattern install pays nothing. The boolean filter is the
	 * developer escape hatch for inclusion/exclusion logic the patterns cannot
	 * express.
	 *
	 * @since 0.5.0
	 *
	 * @param \WP_Post $post The candidate post.
	 * @return bool
	 */
	public function is_excluded( \WP_Post $post ): bool {

		// Resolve the permalink only when there is a pattern that could match it.
		$excluded = $this->compiled_patterns() !== [] && $this->path_excluded( $this->post_path( $post ) );

		return (bool) apply_filters( 'kntnt_ai_visibility_is_excluded', $excluded, $post );

	}

	/**
	 * Reports whether a path matches any configured pattern.
	 *
	 * The pure core of the gate: it takes a URL path, reduces it to the
	 * home-relative, rawurldecoded subject, and tests it against each pattern.
	 *
	 * @since 0.5.0
	 *
	 * @param string $path The URL path to test (the permalink's path component).
	 * @return bool
	 */
	public function path_excluded( string $path ): bool {

		// Nothing to match against when no pattern is configured.
		$patterns = $this->compiled_patterns();
		if ( $patterns === [] ) {
			return false;
		}

		// Test the home-relative, decoded subject against each pattern; the first
		// match excludes. A pattern that fails at match time (e.g. an invalid one
		// injected through the filter) is silently inert, never fatal.
		$subject = $this->home_relative( $path );
		foreach ( $patterns as $regex ) {
			if ( @preg_match( $regex, $subject ) === 1 ) {
				return true;
			}
		}

		return false;

	}

	/**
	 * Splits raw pattern text into trimmed, non-empty lines.
	 *
	 * @since 0.5.0
	 *
	 * @param string $text The raw newline-separated pattern text.
	 * @return list<string> One pattern body per line, blanks removed.
	 */
	public static function split_patterns( string $text ): array {

		// One pattern per line, across any newline convention; drop blank lines.
		$lines = preg_split( '/\R/', $text );
		$out = [];
		foreach ( is_array( $lines ) ? $lines : [] as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$out[] = $line;
			}
		}

		return $out;

	}

	/**
	 * Wraps a user pattern body into a full, flagged PCRE.
	 *
	 * @since 0.5.0
	 *
	 * @param string $pattern The delimiter-less, flag-less pattern body.
	 * @return string The compiled PCRE, e.g. `#/cookiepolicy/#iu`.
	 */
	public static function compile( string $pattern ): string {
		return self::DELIMITER . $pattern . self::DELIMITER . self::FLAGS;
	}

	/**
	 * Reports whether a user pattern body is a valid regular expression.
	 *
	 * Used by the settings sanitiser to reject a bad line at save time, so an
	 * invalid pattern can never reach the runtime match.
	 *
	 * @since 0.5.0
	 *
	 * @param string $pattern The delimiter-less, flag-less pattern body.
	 * @return bool
	 */
	public static function is_valid( string $pattern ): bool {

		// Swallow the compilation warning a bad pattern emits — preg_match still
		// returns false on a compile error — so validation is genuinely silent
		// rather than relying on the `@` operator, which a strict error handler
		// (e.g. the test runner's) would still surface.
		set_error_handler( static fn(): bool => true );
		try {
			return preg_match( self::compile( $pattern ), '' ) !== false;
		} finally {
			restore_error_handler();
		}

	}

	/**
	 * Resolves and memoises the compiled, validated patterns.
	 *
	 * Reads the raw text, exposes the parsed list to the developer filter, then
	 * keeps only the lines that compile. Memoised so an O(site) enumeration reads
	 * and parses the patterns once, not once per post.
	 *
	 * @since 0.5.0
	 *
	 * @return list<string> The compiled PCREs that are safe to run.
	 */
	private function compiled_patterns(): array {

		// Return the memoised result once resolved.
		if ( $this->compiled !== null ) {
			return $this->compiled;
		}

		// Parse the raw text, let developers amend the list, then compile and keep
		// only the valid patterns.
		$raw = apply_filters( 'kntnt_ai_visibility_exclusion_patterns', self::split_patterns( ( $this->text_reader )() ) );
		$compiled = [];
		foreach ( is_array( $raw ) ? $raw : [] as $line ) {
			if ( is_string( $line ) && self::is_valid( $line ) ) {
				$compiled[] = self::compile( $line );
			}
		}

		$this->compiled = $compiled;

		return $this->compiled;

	}

	/**
	 * Returns a post's permalink path component, or an empty string.
	 *
	 * @since 0.5.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private function post_path( \WP_Post $post ): string {

		// Reduce the full permalink to its path; the host and scheme never take
		// part in a path match.
		$url = get_permalink( $post );

		return is_string( $url ) ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';

	}

	/**
	 * Reduces a URL path to the home-relative, decoded match subject.
	 *
	 * Strips the install's base path so a pattern written for the root works on a
	 * subdirectory install too, guarantees a leading slash so `^/…` anchors hold,
	 * and rawurldecodes so a non-ASCII slug is matched in its readable form.
	 *
	 * @since 0.5.0
	 *
	 * @param string $path The permalink's path component.
	 * @return string The home-relative, decoded path.
	 */
	private function home_relative( string $path ): string {

		// Drop the install's base path (empty on a root install) so patterns are
		// written and matched relative to the site home.
		$home = rtrim( (string) wp_parse_url( ( $this->home_url )(), PHP_URL_PATH ), '/' );
		if ( $home !== '' && str_starts_with( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		// Guarantee a leading slash, then decode so `/öppettider/` matches rather
		// than its percent-encoded form.
		if ( $path === '' || $path[0] !== '/' ) {
			$path = '/' . $path;
		}

		return rawurldecode( $path );

	}

}
