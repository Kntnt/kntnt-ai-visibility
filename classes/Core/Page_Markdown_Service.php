<?php
/**
 * The shared page-to-Markdown service.
 *
 * Runs the Release-1 pipeline (docs/spec §4.3): render the post content through
 * `the_content` and the explicit public HTML filter, convert the HTML to
 * GitHub-Flavored Markdown with the full converter — base, commonmark, table
 * and strikethrough plugins — absolutising relative URLs against the source's
 * canonical URL, then assemble front-matter, the page's visible H1 (the post
 * title) and the converted body. materialise() adds the single-flight cache
 * write the serve paths rely on.
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
use Kntnt\Ai_Visibility\Core\Cache\Materialisation;
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
	 * Overrides the canonical source URL used as the conversion base.
	 *
	 * @since 0.1.0
	 *
	 * @var (callable(): string)|null
	 */
	private $domain_provider;

	/**
	 * Binds the pipeline and an optional conversion-base override.
	 *
	 * @since 0.1.0
	 *
	 * @param Front_Matter            $front_matter    The front-matter builder.
	 * @param Single_Flight           $single_flight   The single-flight cache materialiser.
	 * @param Logger                  $logger          The diagnostics logger.
	 * @param callable(): string|null $domain_provider Overrides the base URL; defaults to the source canonical URL.
	 * @param Publication_Source|null $publication_source Authoritative guard used by the production service graph.
	 */
	public function __construct(
		private readonly Front_Matter $front_matter,
		private readonly Single_Flight $single_flight,
		private readonly Logger $logger,
		?callable $domain_provider = null,
		private readonly ?Publication_Source $publication_source = null,
	) {
		$this->domain_provider = $domain_provider;
	}

	/**
	 * Renders a post to its Markdown alternate — front-matter plus body.
	 * Conversion failures propagate without assembling a successful document.
	 *
	 * @since 0.1.0
	 *
	 * @param \WP_Post $post The post to render.
	 * @return string The assembled Markdown document.
	 * @throws \DomainException When public rendering is refused.
	 * @phpstan-throws \DomainException|Markdown_Conversion_Failed|Public_Content_Rendering_Failed
	 */
	public function for_post( \WP_Post $post ): string {

		// A visitor's password cookie cannot make a source public.
		if ( $post->post_password !== '' ) {
			throw new \DomainException( 'Password-protected posts cannot produce public Markdown.' );
		}

		return Public_Rendering::run(
			function () use ( $post ): string {
				$produce = fn(): string => Post_Context::render( $post, fn(): string => $this->render_post( $post ) );
				if ( $this->publication_source === null ) {
						return $produce();
				}
				$identity = ( new Markdown_Alternate() )->identity_for( $post );
				return $this->single_flight->uncached( $produce, fn() => $this->publication_source->verify( $identity, $post ) );
			}
		);

	}

	/**
	 * Renders within the source post's isolated WordPress context.
	 *
	 * @since 0.5.2
	 *
	 * @param \WP_Post $post The source post.
	 * @return string The assembled Markdown document.
	 * @throws Public_Content_Rendering_Failed When the public HTML adapter fails.
	 * @throws \DomainException When an adapter refuses public publication.
	 */
	private function render_post( \WP_Post $post ): string {

		// Render ordinary blocks/shortcodes, then the explicit public theme body.
		$rendered = apply_filters( 'the_content', $post->post_content );
		$rendered = is_string( $rendered ) ? $rendered : '';
		try {
			/**
			 * Selects the actual public body HTML for this source.
			 *
			 * Runs once per page generation inside the anonymous, source-specific
			 * query/Loop/locale scope. Return a string (including a valid empty
			 * string); never return all stored metadata or visitor-captured HTML.
			 *
			 * @since 0.5.2
			 *
			 * @param string   $rendered Default block/shortcode HTML from the_content.
			 * @param \WP_Post $post     The source whose public body is being rendered.
			 */
			$rendered = apply_filters( 'kntnt_ai_visibility_public_content_html', $rendered, $post );
		} catch ( \DomainException $exception ) {
			throw $exception;
		} catch ( \Throwable $exception ) {
			$this->logger->error( 'Public content rendering failed', [ 'error' => $exception->getMessage() ] );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception stays internal; HTTP shells emit a fixed message.
			throw new Public_Content_Rendering_Failed( 'Public content rendering failed.', 0, $exception );
		}
		if ( ! is_string( $rendered ) ) {
			$this->logger->error( 'Public content renderer returned non-string HTML' );
			throw new Public_Content_Rendering_Failed( 'Public content renderer must return HTML as a string.' );
		}
		$body = $this->convert( $rendered, $post );

		// Assemble: front-matter, a blank line, the visible H1 from the post
		// title, a blank line, then the converted body.
		$front = $this->front_matter->build( $post );
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES );

		return $front . "\n# " . $title . "\n\n" . $body;

	}

	/**
	 * Materialises a post's Markdown with an independent persistence outcome.
	 * Conversion failures propagate without a result or a cache write.
	 *
	 * @since 0.1.0
	 *
	 * @param Identity $identity The cache identity to materialise under.
	 * @param \WP_Post $post     The post to render on a miss.
	 * @return Materialisation The valid bytes and independent persistence outcome.
	 * @throws \DomainException When public publication is refused, even on a hit.
	 * @phpstan-throws \DomainException|Markdown_Conversion_Failed|Public_Content_Rendering_Failed
	 */
	public function materialise( Identity $identity, \WP_Post $post ): Materialisation {

		// Do not let a preview context consult or populate the shared file cache.
		Public_Rendering::assert_public_request( true );

		// A warm file must not bypass the source's stored password.
		if ( $post->post_password !== '' ) {
			throw new \DomainException( 'Password-protected posts cannot produce public Markdown.' );
		}

		// Single-flight: serve the cache when warm, else render once under a
		// per-identity lock and store. The lock and re-check live in Single_Flight.
		return $this->single_flight->once(
			$identity,
			fn(): string => $this->for_post( $post ),
			function () use ( $identity, $post ): void {
				Public_Rendering::run(
					function () use ( $identity, $post ): string {
						$this->publication_source?->verify( $identity, $post );
						return '';
					},
					persistent: true
				);
			},
		);

	}

	/**
	 * Converts rendered HTML to GitHub-Flavored Markdown.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $html The rendered HTML.
	 * @param \WP_Post $post The source whose canonical URL supplies the base.
	 * @return string The Markdown body, which may legitimately be empty.
	 * @throws Markdown_Conversion_Failed When conversion fails; no bytes are valid.
	 */
	private function convert( string $html, \WP_Post $post ): string {

		// Resolve inside the source context, preserving its path/query/language.
		$converter = new Converter( [ new BasePlugin(), new CommonmarkPlugin(), new TablePlugin(), new StrikethroughPlugin() ] );
		try {
			$base = $this->domain_provider === null
				? ( new Markdown_Alternate() )->canonical_url_for( $post )
				: ( $this->domain_provider )();
			return $converter->convertString( $html, new Options( domain: $base ) );
		} catch ( \Throwable $exception ) {
			$this->logger->error( 'Markdown conversion failed', [ 'error' => $exception->getMessage() ] );
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The previous exception stays internal; HTTP shells emit a fixed message.
			throw new Markdown_Conversion_Failed( 'Markdown conversion failed.', 0, $exception );
		}

	}

}
