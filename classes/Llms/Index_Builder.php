<?php
/**
 * Assembles the llms.txt index document.
 *
 * Builds the curated, machine-readable index of the site's key content from the
 * eligible posts of the `llms.txt` types (docs/spec/llms-txt.md §4.3): an H1 site
 * name, an optional tagline blockquote, an intro line that also points at
 * /llms-full.txt, then one H2 section per type — ordered page, post, then the
 * rest — each listing `- [title](md_url): excerpt` items. Titles are escaped so a
 * `[`, `]` or backtick can never break the link; excerpts are stripped of tags
 * and shortcodes, decoded, collapsed to one line and length-capped. Every piece
 * is reachable through a filter, dependency-free, so an SEO integration can
 * substitute real titles and descriptions without this module bundling one.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Llms;

use Kntnt\Ai_Visibility\Core\Eligibility;
use Kntnt\Ai_Visibility\Core\Markdown_Alternate;

/**
 * Builds the llms.txt index Markdown.
 *
 * @since 0.2.0
 */
final class Index_Builder {

	/**
	 * The maximum excerpt length, in characters, before truncation.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private const EXCERPT_CAP = 200;

	/**
	 * Binds the builder to eligibility, the matrix and the markdown-alternate locator.
	 *
	 * @since 0.2.0
	 *
	 * @param Eligibility        $eligibility        The eligibility predicate and enumeration.
	 * @param Selected_Types     $selected_types     The llms artifact type-set resolver.
	 * @param Markdown_Alternate $markdown_alternate The markdown-alternate URL locator.
	 */
	public function __construct(
		private readonly Eligibility $eligibility,
		private readonly Selected_Types $selected_types,
		private readonly Markdown_Alternate $markdown_alternate,
	) {}

	/**
	 * Assembles and returns the llms.txt document.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function build(): string {

		// Resolve the selected types (ordered page, post, then the rest) and group
		// their eligible posts into one section per type.
		$types = $this->selected_types->resolve( 'llms' );
		$sections = $this->group( $this->eligibility->enumerate( $types ), $types );
		$sections = apply_filters( 'kntnt_ai_visibility_llms_sections', $sections );

		// Head: H1 site name, optional tagline blockquote, optional intro line.
		$blocks = [ $this->title() ];
		$summary = $this->summary();
		if ( $summary !== '' ) {
			$blocks[] = '> ' . $summary;
		}
		$intro = $this->intro();
		if ( $intro !== '' ) {
			$blocks[] = $intro;
		}

		// One H2 section per type, each with its `- [title](url): excerpt` items.
		// A type with no eligible posts contributes no section at all.
		foreach ( is_array( $sections ) ? $sections : [] as $type => $posts ) {
			$items = [];
			foreach ( is_array( $posts ) ? $posts : [] as $post ) {
				if ( $post instanceof \WP_Post ) {
					$items[] = $this->item( $post );
				}
			}
			if ( $items === [] ) {
				continue;
			}
			$blocks[] = '## ' . $this->type_label( (string) $type ) . "\n" . implode( "\n", $items );
		}

		// The assembled document, then the raw escape-hatch filter.
		$document = implode( "\n\n", $blocks ) . "\n";
		$final = apply_filters( 'kntnt_ai_visibility_llms_txt', $document );

		return is_string( $final ) ? $final : $document;

	}

	/**
	 * Returns the H1 site name, through its filter.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private function title(): string {
		$title = apply_filters( 'kntnt_ai_visibility_llms_title', (string) get_bloginfo( 'name' ) );

		return '# ' . ( is_string( $title ) ? $title : '' );

	}

	/**
	 * Returns the tagline summary, through its filter (may be empty).
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private function summary(): string {
		$summary = apply_filters( 'kntnt_ai_visibility_llms_summary', (string) get_bloginfo( 'description' ) );

		return is_string( $summary ) ? $this->one_line( $summary ) : '';

	}

	/**
	 * Returns the intro line referencing /llms-full.txt, through its filter.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	private function intro(): string {
		$default = sprintf( 'Markdown index of %s for AI agents. Full text: /llms-full.txt', (string) get_bloginfo( 'name' ) );
		$intro = apply_filters( 'kntnt_ai_visibility_llms_intro', $default );

		return is_string( $intro ) ? $this->one_line( $intro ) : '';

	}

	/**
	 * Builds one `- [title](md_url): excerpt` item line, through the entry filter.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private function item( \WP_Post $post ): string {

		// Core-first title/url/description, then the per-entry filter so an SEO
		// integration can substitute the real title and meta description.
		$entry = apply_filters(
			'kntnt_ai_visibility_llms_entry',
			[
				'title'       => $this->one_line( html_entity_decode( (string) get_the_title( $post ), ENT_QUOTES ) ),
				'url'         => $this->markdown_alternate->url_for( $post ),
				'description' => $this->description( $post ),
			],
			$post,
		);
		$entry = is_array( $entry ) ? $entry : [];

		// Assemble the link, escaping the label so a `[`/`]`/backtick cannot break it.
		$title = $this->escape_label( is_string( $entry['title'] ?? null ) ? $entry['title'] : '' );
		$url = is_string( $entry['url'] ?? null ) ? $entry['url'] : '';
		$description = is_string( $entry['description'] ?? null ) ? $entry['description'] : '';
		$line = sprintf( '- [%s](%s)', $title, $url );

		return $description === '' ? $line : $line . ': ' . $description;

	}

	/**
	 * Returns a post's one-line, capped, plain-text excerpt description.
	 *
	 * @since 0.2.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string
	 */
	private function description( \WP_Post $post ): string {

		// Strip shortcodes and tags, decode entities, collapse to one line, cap.
		$excerpt = wp_strip_all_tags( strip_shortcodes( (string) get_the_excerpt( $post ) ) );
		$excerpt = $this->one_line( html_entity_decode( $excerpt, ENT_QUOTES ) );
		if ( strlen( $excerpt ) > self::EXCERPT_CAP ) {
			$excerpt = rtrim( substr( $excerpt, 0, self::EXCERPT_CAP ) ) . '…';
		}

		return $excerpt;

	}

	/**
	 * Groups a flat, type-ordered post list into a `[type => posts]` structure.
	 *
	 * @since 0.2.0
	 *
	 * @param array<int, \WP_Post> $posts The enumerated posts.
	 * @param array<int, string>   $types The selected types, in section order.
	 * @return array<string, list<\WP_Post>>
	 */
	private function group( array $posts, array $types ): array {

		// Seed every selected type so the section order is preserved even before
		// any post is placed, then bucket each post under its type.
		$sections = [];
		foreach ( $types as $type ) {
			$sections[ $type ] = [];
		}
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && isset( $sections[ $post->post_type ] ) ) {
				$sections[ $post->post_type ][] = $post;
			}
		}

		return $sections;

	}

	/**
	 * Returns a post type's plural section label.
	 *
	 * @since 0.2.0
	 *
	 * @param string $type The post type slug.
	 * @return string
	 */
	private function type_label( string $type ): string {

		// The registered plural label, falling back to the slug.
		$object = get_post_type_object( $type );
		$name = $object->labels->name ?? '';

		return is_string( $name ) && $name !== '' ? $name : $type;

	}

	/**
	 * Escapes Markdown-significant characters in a link label.
	 *
	 * @since 0.2.0
	 *
	 * @param string $label The raw label.
	 * @return string
	 */
	private function escape_label( string $label ): string {

		// Escape backslash first, then the characters that would break the link
		// label or open a code span.
		return str_replace( [ '\\', '`', '[', ']' ], [ '\\\\', '\\`', '\\[', '\\]' ], $label );

	}

	/**
	 * Collapses all whitespace runs to a single space and trims.
	 *
	 * @since 0.2.0
	 *
	 * @param string $text The text.
	 * @return string
	 */
	private function one_line( string $text ): string {
		return trim( (string) preg_replace( '/\s+/', ' ', $text ) );
	}

}
