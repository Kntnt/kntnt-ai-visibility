<?php
/**
 * Assembles the llms-full.txt full-text document.
 *
 * Concatenates each selected page's per-page Markdown alternate — never a second
 * render (docs/spec/llms-txt.md §4.4): a minimal site header first, then for each
 * eligible page of the `llms-full.txt` types its Markdown via Page_Markdown,
 * which serves the per-page cache file when warm and renders+caches it once when
 * cold. Entries are joined with a blank line; the per-page `---` YAML front-matter
 * is the natural record boundary, so an HR `---` separator is deliberately
 * avoided (it would collide with the front-matter fence). The scope defaults to
 * Pages only. Password-protected pages are absent because enumerate() excludes
 * them; the loop also skips any post that still carries a password, so the
 * aggregation never concatenates protected content nor caches its alternate.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Llms;

use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;
use Kntnt\Ai_Visibility\Core\Page_Markdown;

/**
 * Builds the llms-full.txt full-text Markdown.
 *
 * @since 0.2.0
 */
final class Full_Builder {

	/**
	 * Binds the builder to eligibility, the type resolver, the locator and Page_Markdown.
	 *
	 * @since 0.2.0
	 *
	 * @param Eligibility        $eligibility        The eligibility predicate and enumeration.
	 * @param Selected_Types     $selected_types     The llms artifact type-set resolver.
	 * @param Markdown_Alternate $markdown_alternate The markdown-alternate identity locator.
	 * @param Page_Markdown      $page_markdown      The shared page-to-Markdown service.
	 */
	public function __construct(
		private readonly Eligibility $eligibility,
		private readonly Selected_Types $selected_types,
		private readonly Markdown_Alternate $markdown_alternate,
		private readonly Page_Markdown $page_markdown,
	) {}

	/**
	 * Assembles and returns the llms-full.txt document.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function build(): string {

		// The site header identifies the file's origin, like the index head.
		$parts = [ $this->header() ];

		// Each eligible page's Markdown, materialised through the per-page cache —
		// never re-rendered. Skip any post that still carries a password.
		$types = $this->selected_types->resolve( 'llms_full' );
		foreach ( $this->eligibility->enumerate( $types ) as $post ) {
			if ( ! $post instanceof \WP_Post || $post->post_password !== '' ) {
				continue;
			}
			$parts[] = $this->page_markdown->materialise( $this->markdown_alternate->identity_for( $post ), $post );
		}

		// Join with a blank line; the per-page front-matter is the record boundary.
		$document = implode( "\n\n", $parts ) . "\n";
		$final = apply_filters( 'kntnt_ai_visibility_llms_full_txt', $document );

		return is_string( $final ) ? $final : $document;

	}

	/**
	 * Builds the minimal site header: the H1 name and the tagline blockquote.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private function header(): string {

		// H1 site name, then the tagline blockquote when the site has one.
		$blocks = [ '# ' . (string) get_bloginfo( 'name' ) ];
		$tagline = (string) get_bloginfo( 'description' );
		if ( $tagline !== '' ) {
			$blocks[] = '> ' . $tagline;
		}

		return implode( "\n\n", $blocks );

	}

}
