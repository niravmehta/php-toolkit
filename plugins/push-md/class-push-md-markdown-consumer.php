<?php

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;
use WordPress\Markdown\MarkdownConsumer;

/**
 * Thin wrapper around MarkdownConsumer that optionally strips Gutenberg
 * block comment wrappers from the generated HTML.
 *
 * MarkdownConsumer always produces Gutenberg block markup
 * (<!-- wp:paragraph --><p>…</p><!-- /wp:paragraph -->).
 * When the site does not use the block editor for the post type being
 * imported, those comment wrappers are removed and clean standard HTML
 * is returned instead.
 *
 * Usage:
 *   $consumer = new Push_MD_Markdown_Consumer( $markdown, $use_block_comments );
 *   $result   = $consumer->consume();            // returns BlocksWithMetadata
 *   $markup   = $result->get_block_markup();     // Gutenberg or plain HTML
 *   $meta     = $result->get_all_metadata();
 */
class Push_MD_Markdown_Consumer {

	/**
	 * @var string
	 */
	private $markdown;

	/**
	 * @var bool Whether to keep Gutenberg block comment wrappers in the output.
	 */
	private $use_block_comments;

	/**
	 * @var BlocksWithMetadata|null
	 */
	private $result;

	/**
	 * @param string $markdown           Markdown string (may include frontmatter).
	 * @param bool   $use_block_comments True to keep Gutenberg block comments
	 *                                   (default). False to produce standard HTML.
	 */
	public function __construct( $markdown, $use_block_comments = true ) {
		$this->markdown           = $markdown;
		$this->use_block_comments = (bool) $use_block_comments;
	}

	/**
	 * Parse the Markdown and return a BlocksWithMetadata instance.
	 *
	 * When $use_block_comments is false the block_markup in the returned
	 * object contains plain HTML without <!-- wp:… --> comment wrappers.
	 *
	 * @return BlocksWithMetadata
	 */
	public function consume() {
		if ( null !== $this->result ) {
			return $this->result;
		}

		$inner_consumer = new MarkdownConsumer( $this->markdown );
		$raw_result     = $inner_consumer->consume();

		if ( $this->use_block_comments ) {
			$this->result = $raw_result;
			return $this->result;
		}

		// Strip Gutenberg block comment wrappers and return clean HTML.
		$block_markup = $this->strip_block_comments( $raw_result->get_block_markup() );
		$metadata     = $raw_result->get_all_metadata();
		$this->result = new BlocksWithMetadata( $block_markup, $metadata );

		return $this->result;
	}

	/**
	 * Remove Gutenberg block comment wrappers from markup.
	 *
	 * Strips lines such as:
	 *   <!-- wp:paragraph -->
	 *   <!-- /wp:paragraph -->
	 *   <!-- wp:list {"ordered":false} -->
	 *
	 * @param string $markup Block markup with Gutenberg comment wrappers.
	 * @return string Plain HTML without block comments.
	 */
	private function strip_block_comments( $markup ) {
		// Remove block comment lines (including any trailing newline).
		$markup = preg_replace( '/<!--\s+\/?wp:[^>]+-->\n?/', '', $markup );
		// Remove wp-specific CSS classes that only make sense in the block editor.
		$markup = preg_replace( '/ class="wp-block-[^"]*"/', '', $markup );
		// Normalise excess blank lines left by the removal.
		$markup = preg_replace( "/\n{3,}/", "\n\n", $markup );
		return trim( $markup );
	}
}
