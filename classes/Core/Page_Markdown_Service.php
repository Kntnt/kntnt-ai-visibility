<?php
/**
 * The shared page-to-Markdown service.
 *
 * Runs the Release-1 pipeline (docs/spec §4.3): render the post content through
 * `the_content`, convert the HTML to GitHub-Flavored Markdown with the full
 * converter — base, commonmark, table and strikethrough plugins — absolutising
 * relative URLs against the site domain, then assemble the front-matter, the
 * page's visible H1 (the post title) and the converted body. materialise() adds
 * the single-flight cache write the serve paths rely on.
 *
 * The converter is an internal collaborator, not a public seam, and is not a
 * sanitiser — acceptable because the input is the site's own rendered content,
 * not untrusted HTML (docs/spec §4.3).
 *
 * @package Kntnt\Ai_Visibility
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ai_Visibility\Core;

use Kntnt\Ai_Visibility\Core\Artifact\Identity;
use Kntnt\Ai_Visibility\Core\Cache\Single_Flight;
use Kntnt\HtmlToMarkdown\Converter\Converter;
use Kntnt\HtmlToMarkdown\Converter\Options;
use Kntnt\HtmlToMarkdown\Plugin\Base\BasePlugin;
use Kntnt\HtmlToMarkdown\Plugin\Commonmark\CommonmarkPlugin;
use Kntnt\HtmlToMarkdown\Plugin\Strikethrough\StrikethroughPlugin;
use Kntnt\HtmlToMarkdown\Plugin\Table\TablePlugin;

/**
 * Renders posts to Markdown and materialises them to the cache.
 *
 * @since 0.1.0
 */
final class Page_Markdown_Service implements Page_Markdown {

	/**
	 * Resolves the site domain relative URLs are absolutised against.
	 *
	 * @since 0.1.0
	 *
	 * @var callable(): string
	 */
	private $domain_provider;

	/**
	 * Binds the service to its front-matter builder, single-flight, logger and domain.
	 *
	 * @since 0.1.0
	 *
	 * @param Front_Matter            $front_matter    The front-matter builder.
	 * @param Single_Flight           $single_flight   The single-flight cache materialiser.
	 * @param Logger                  $logger          The diagnostics logger.
	 * @param callable(): string|null $domain_provider Resolves the absolutising domain; defaults to home_url().
	 */
	public function __construct(
		private readonly Front_Matter $front_matter,
		private readonly Single_Flight $single_flight,
		private readonly Logger $logger,
		?callable $domain_provider = null,
	) {
		$this->domain_provider = $domain_provider ?? 'home_url';
	}

	/**
	 * Renders a post to its Markdown alternate — front-matter plus body.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post to render.
	 * @return string The assembled Markdown document.
	 */
	public function for_post( \WP_Post $post ): string {

		// Render the content (shortcodes, blocks) and convert it to Markdown.
		$rendered = apply_filters( 'the_content', $post->post_content );
		$body = $this->convert( is_string( $rendered ) ? $rendered : '' );

		// Assemble: front-matter, a blank line, the visible H1 from the post
		// title, a blank line, then the converted body.
		$front = $this->front_matter->build( $post );
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES );

		return $front . "\n# " . $title . "\n\n" . $body;

	}

	/**
	 * Materialises a post's Markdown to the cache and returns the bytes.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The cache identity to materialise under.
	 * @param \WP_Post $post     The post to render on a miss.
	 * @return string
	 */
	public function materialise( Identity $identity, \WP_Post $post ): string {

		// Single-flight: serve the cache when warm, else render once under a
		// per-identity lock and store. The lock and re-check live in Single_Flight.
		return $this->single_flight->once( $identity, fn(): string => $this->for_post( $post ) );

	}

	/**
	 * Converts rendered HTML to GitHub-Flavored Markdown.
	 *
	 * @since 0.1.0
	 *
	 * @param string $html The rendered HTML.
	 * @return string The Markdown body, or '' when conversion fails.
	 */
	private function convert( string $html ): string {

		// The full converter gives GFM tables and strikethrough; the domain
		// absolutises relative links and images so the .md is self-contained.
		$converter = new Converter( [ new BasePlugin(), new CommonmarkPlugin(), new TablePlugin(), new StrikethroughPlugin() ] );
		try {
			return $converter->convertString( $html, new Options( domain: ( $this->domain_provider )() ) );
		} catch ( \Throwable $exception ) {
			$this->logger->error( 'Markdown conversion failed', [ 'error' => $exception->getMessage() ] );
			return '';
		}

	}

}
