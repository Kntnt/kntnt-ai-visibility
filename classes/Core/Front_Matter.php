<?php
/**
 * Builds the YAML front-matter for a page's Markdown alternate.
 *
 * The block carries parity metadata plus the canonical URL, in a fixed key
 * order: title, canonical_url, date, author, featured_image, categories, tags
 * (docs/spec §4.3). The last three are conditional, omitted when empty. Category
 * and tag URLs point at the term's `.md` path. The title is page metadata — it
 * lives only here and is never injected into the body. The assembled lines are
 * filterable before serialisation.
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

/**
 * Serialises a post's metadata to a YAML front-matter block.
 *
 * @since 0.1.0
 */
final class Front_Matter {

	/**
	 * Builds the fenced YAML front-matter block for a post.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post.
	 * @return string The block, from the opening `---` to the closing `---\n`.
	 */
	public function build( \WP_Post $post ): string {

		// The four always-present keys, in order.
		$lines = [
			'title: ' . $this->quote( html_entity_decode( get_the_title( $post ), ENT_QUOTES ) ),
			'canonical_url: ' . $this->quote( (string) get_permalink( $post ) ),
			'date: ' . get_the_date( 'Y-m-d', $post ),
			'author: ' . $this->quote( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
		];

		// The featured image, only when the post has one.
		$thumbnail = get_the_post_thumbnail_url( $post, 'full' );
		if ( is_string( $thumbnail ) && $thumbnail !== '' ) {
			$lines[] = 'featured_image: ' . $this->quote( $thumbnail );
		}

		// Categories and tags as name/.md-url lists, each omitted when empty.
		$lines = [ ...$lines, ...$this->term_lines( 'categories', get_the_terms( $post, 'category' ) ) ];
		$lines = [ ...$lines, ...$this->term_lines( 'tags', get_the_terms( $post, 'post_tag' ) ) ];

		// Let developers rewrite the lines before serialisation.
		$filtered = apply_filters( 'kntnt_ai_visibility_markdown_frontmatter', $lines, $post );
		$lines = $this->as_strings( is_array( $filtered ) ? $filtered : $lines );

		return "---\n" . implode( "\n", $lines ) . "\n---\n";

	}

	/**
	 * Builds the YAML lines for a taxonomy's terms, or none when empty.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key   The front-matter key ('categories' or 'tags').
	 * @param mixed  $terms The get_the_terms() result.
	 * @return list<string> The YAML lines, or an empty array.
	 */
	private function term_lines( string $key, mixed $terms ): array {

		// Nothing to emit unless the post actually has terms in this taxonomy.
		if ( ! is_array( $terms ) || $terms === [] ) {
			return [];
		}

		// One name/url pair per term, the url being the term's `.md` path.
		$lines = [ $key . ':' ];
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			$link = get_term_link( $term );
			$url = is_string( $link ) ? rtrim( $link, '/' ) . '.md' : '';
			$lines[] = '  - name: ' . $this->quote( $term->name );
			$lines[] = '    url: ' . $this->quote( $url );
		}

		return $lines;

	}

	/**
	 * Coerces a list of possibly-mixed values to a list of strings.
	 *
	 * The front-matter filter may return arbitrary values; this keeps only the
	 * scalar lines, stringified, so serialisation stays well-typed.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $values The raw values.
	 * @return list<string>
	 */
	private function as_strings( array $values ): array {

		// Keep scalar entries, stringified; drop anything that is not a line.
		$strings = [];
		foreach ( $values as $value ) {
			if ( is_string( $value ) ) {
				$strings[] = $value;
			} elseif ( is_scalar( $value ) ) {
				$strings[] = (string) $value;
			}
		}

		return $strings;

	}

	/**
	 * Wraps a scalar in a double-quoted YAML string, escaping the dangerous pair.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The raw value.
	 * @return string The quoted, escaped value.
	 */
	private function quote( string $value ): string {
		return '"' . str_replace( [ '\\', '"' ], [ '\\\\', '\\"' ], $value ) . '"';
	}

}
