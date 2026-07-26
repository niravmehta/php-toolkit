<?php

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownProducer;

/**
 * Thin wrapper around MarkdownProducer that adds support for posts whose
 * content is standard HTML (classic editor, raw HTML, imported HTML)
 * rather than Gutenberg block markup.
 *
 * Detection strategy:
 *   - has_blocks( $content ) === true  → content contains Gutenberg block
 *     comment delimiters → delegate entirely to MarkdownProducer (unchanged).
 *   - has_blocks( $content ) === false → standard HTML or plain text →
 *     convert body with Push_MD_HTML_Converter and emit our own frontmatter.
 *
 * Mixed posts (some Gutenberg blocks, some classic HTML) are handled by
 * MarkdownProducer: its default case already calls html_to_markdown() for
 * null-blockName fragments, which covers inline text. Block-level standard
 * HTML inside a Gutenberg-wrapped post is an unusual edge case.
 */
class Push_MD_Markdown_Producer {

	/**
	 * @var BlocksWithMetadata
	 */
	private $blocks_with_meta;

	/**
	 * @var string|null
	 */
	private $markdown;

	/**
	 * @param BlocksWithMetadata $blocks_with_meta
	 */
	public function __construct( BlocksWithMetadata $blocks_with_meta ) {
		$this->blocks_with_meta = $blocks_with_meta;
	}

	/**
	 * Produce the Markdown string (frontmatter + body).
	 *
	 * @return string
	 */
	public function produce() {
		if ( null !== $this->markdown ) {
			return $this->markdown;
		}

		$content = $this->blocks_with_meta->get_block_markup();

		if ( $this->content_has_blocks( $content ) ) {
			// Gutenberg content: delegate to MarkdownProducer and normalize output.
			$producer       = new MarkdownProducer( $this->blocks_with_meta );
			$this->markdown = Push_MD_HTML_Converter::normalize_markdown( $producer->produce() );
		} else {
			// Standard HTML or plain-text content.
			$metadata       = $this->blocks_with_meta->get_all_metadata( array( 'first_value_only' => true ) );
			$this->markdown = $this->frontmatter( $metadata )
				. Push_MD_HTML_Converter::convert( $content );
		}

		return $this->markdown;
	}

	/**
	 * Build the YAML frontmatter block.
	 *
	 * Mirrors the private frontmatter() method in MarkdownProducer so the
	 * output format is identical.
	 *
	 * @param array $metadata Key → scalar value pairs.
	 * @return string Frontmatter string including surrounding --- fences,
	 *                or empty string when metadata is empty.
	 */
	/**
	 * Detect whether post content contains Gutenberg block comment delimiters.
	 *
	 * Uses WordPress core's has_blocks() when available; falls back to a
	 * strpos() check so the class works in unit-test environments that run
	 * outside of WordPress.
	 *
	 * @param string $content Raw post_content.
	 * @return bool
	 */
	private function content_has_blocks( $content ) {
		if ( function_exists( 'has_blocks' ) ) {
			return has_blocks( $content );
		}
		// Fallback: Gutenberg block markup always contains <!-- wp: delimiters.
		return false !== strpos( $content, '<!-- wp:' );
	}

	private function frontmatter( $metadata ) {
		if ( empty( $metadata ) ) {
			return '';
		}
		$frontmatter = '';
		foreach ( $metadata as $key => $value ) {
			$frontmatter .= $key . ': ' . json_encode( $value ) . "\n";
		}
		return "---\n" . $frontmatter . "---\n\n";
	}
}
