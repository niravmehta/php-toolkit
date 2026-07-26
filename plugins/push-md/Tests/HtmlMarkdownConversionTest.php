<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-html-converter.php';
require_once dirname( __DIR__ ) . '/class-push-md-markdown-consumer.php';

use WordPress\DataLiberation\DataFormatConsumer\BlocksWithMetadata;

/**
 * Tests for Push_MD_HTML_Converter and Push_MD_Markdown_Consumer.
 */
class HtmlMarkdownConversionTest extends TestCase {

	// -------------------------------------------------------------------------
	// Push_MD_HTML_Converter tests
	// -------------------------------------------------------------------------

	public function test_headings_converted() {
		$html     = '<h2>Section Title</h2><h3>Sub Title</h3>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '## Section Title', $markdown );
		$this->assertStringContainsString( '### Sub Title', $markdown );
	}

	public function test_paragraph_has_blank_line_separator() {
		$html     = '<p>First paragraph.</p><p>Second paragraph.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( "First paragraph.\n\nSecond paragraph.", $markdown );
	}

	public function test_unordered_list_items_use_dash_bullet() {
		$html     = '<ul><li>Alpha</li><li>Beta</li><li>Gamma</li></ul>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( "- Alpha\n- Beta\n- Gamma", $markdown );
	}

	public function test_tight_list_formatting_without_extra_blank_lines() {
		$html     = "<ul>\n  <li><p>Alpha</p></li>\n  <li><p>Beta</p></li>\n</ul>";
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertEquals( "- Alpha\n- Beta", trim( $markdown ) );
	}

	public function test_nested_list_formatting() {
		$html     = "<ul>\n  <li>Parent\n    <ul>\n      <li>Child 1</li>\n      <li>Child 2</li>\n    </ul>\n  </li>\n  <li>Next Parent</li>\n</ul>";
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$expected = "- Parent\n  - Child 1\n  - Child 2\n- Next Parent";
		$this->assertEquals( $expected, trim( $markdown ) );
	}

	public function test_ordered_list_items_use_numbers() {
		$html     = '<ol><li>First</li><li>Second</li><li>Third</li></ol>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( "1. First\n2. Second\n3. Third", $markdown );
	}

	public function test_aside_rendered_as_blockquote() {
		$html     = '<aside><p>A note here.</p></aside>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '> ', $markdown );
		$this->assertStringContainsString( 'A note here.', $markdown );
	}

	public function test_blockquote_rendered_with_prefix() {
		$html     = '<blockquote><p>Famous quote.</p></blockquote>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '> ', $markdown );
		$this->assertStringContainsString( 'Famous quote.', $markdown );
	}

	public function test_bold_text() {
		$html     = '<p><strong>Bold</strong> text.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '**Bold**', $markdown );
	}

	public function test_italic_text() {
		$html     = '<p><em>Italic</em> text.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '*Italic*', $markdown );
	}

	public function test_link_converted() {
		$html     = '<p>Visit <a href="https://example.com">Example</a> now.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '[Example](https://example.com)', $markdown );
	}

	public function test_image_converted() {
		$html     = '<img src="https://example.com/img.jpg" alt="An image">';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '![An image](https://example.com/img.jpg)', $markdown );
	}

	public function test_horizontal_rule_converted() {
		$html     = '<p>Before.</p><hr><p>After.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '---', $markdown );
	}

	public function test_nested_strong_does_not_duplicate_markers() {
		// <strong><strong>text</strong></strong> should not produce ****text****.
		$html     = '<strong><strong>Bold</strong></strong>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringNotContainsString( '****', $markdown );
		$this->assertStringContainsString( '**Bold**', $markdown );
	}

	public function test_heading_followed_by_paragraph_has_blank_line() {
		$html     = '<h2>Title</h2><p>Body text here.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		// There should be a blank line between the heading and paragraph.
		$this->assertMatchesRegularExpression( '/## Title\n\nBody text here\./', $markdown );
	}

	public function test_figure_with_img_in_correct_block() {
		$html     = '<figure><img src="https://example.com/photo.jpg" alt="Photo"></figure>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '![Photo](https://example.com/photo.jpg)', $markdown );
	}

	public function test_aside_with_ordered_list() {
		$html     = '<aside><strong>Resources</strong><ol><li><a href="https://example.com/1">Link 1</a></li><li><a href="https://example.com/2">Link 2</a></li></ol></aside>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		// Content should be inside blockquote markers.
		$this->assertStringContainsString( '> ', $markdown );
		$this->assertStringContainsString( '[Link 1](https://example.com/1)', $markdown );
		$this->assertStringContainsString( '[Link 2](https://example.com/2)', $markdown );
	}

	public function test_bold_with_trailing_space_shifted_outside() {
		$html     = '<p><strong>Ask questions </strong></p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '**Ask questions** ', $markdown );
		$this->assertStringNotContainsString( '**Ask questions **', $markdown );
	}

	public function test_bold_with_leading_space_shifted_outside() {
		$html     = '<p><strong> Ask questions</strong></p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( ' **Ask questions**', $markdown );
		$this->assertStringNotContainsString( '** Ask questions**', $markdown );
	}

	public function test_bold_with_both_spaces_shifted_outside() {
		$html     = '<p><strong> Ask questions </strong></p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( ' **Ask questions** ', $markdown );
	}

	public function test_nbsp_converted_to_standard_space() {
		$html     = "<p>Ask\xC2\xA0questions&nbsp;<strong>now&nbsp;</strong></p>";
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringNotContainsString( "\xC2\xA0", $markdown );
		$this->assertStringNotContainsString( '&nbsp;', $markdown );
		$this->assertStringContainsString( 'Ask questions **now** ', $markdown );
	}

	public function test_italic_strikethrough_code_spaces_normalized() {
		$html     = '<p><em>Italic </em> <s>Strikethrough </s> <code>Code </code></p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '*Italic* ', $markdown );
		$this->assertStringContainsString( '~~Strikethrough~~ ', $markdown );
		$this->assertStringContainsString( '`Code` ', $markdown );
	}

	public function test_fenced_code_block_preserved_without_alteration() {
		$input    = "Text **Ask questions **\n\n```php\n\$x = 5 ** \$y;\n```\n";
		$normalized = Push_MD_HTML_Converter::normalize_markdown( $input );
		$this->assertStringContainsString( 'Text **Ask questions** ', $normalized );
		$this->assertStringContainsString( '$x = 5 ** $y;', $normalized );
	}

	public function test_trailing_space_before_punctuation_cleaned_up() {
		$html     = '<p><strong>Ask questions </strong>.</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertStringContainsString( '**Ask questions**.', $markdown );
	}

	// -------------------------------------------------------------------------
	// Push_MD_Markdown_Consumer tests
	// -------------------------------------------------------------------------

	public function test_consumer_keeps_block_comments_when_enabled() {
		$markdown = "---\ntitle: \"Test\"\n---\n\nHello world.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown, true );
		$result   = $consumer->consume();
		$markup   = $result->get_block_markup();
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
	}

	public function test_consumer_strips_block_comments_when_disabled() {
		$markdown = "---\ntitle: \"Test\"\n---\n\nHello world.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown, false );
		$result   = $consumer->consume();
		$markup   = $result->get_block_markup();
		$this->assertStringNotContainsString( '<!-- wp:', $markup );
		$this->assertStringContainsString( '<p>', $markup );
		$this->assertStringContainsString( 'Hello world.', $markup );
	}

	public function test_consumer_strips_block_comments_for_headings() {
		$markdown = "## My Heading\n\nSome text.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown, false );
		$result   = $consumer->consume();
		$markup   = $result->get_block_markup();
		$this->assertStringNotContainsString( '<!-- wp:heading', $markup );
		$this->assertStringContainsString( '<h2', $markup );
		$this->assertStringContainsString( 'My Heading', $markup );
	}

	public function test_consumer_preserves_metadata() {
		$markdown = "---\ntitle: \"My Title\"\nstatus: \"publish\"\n---\n\nContent here.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown, false );
		$result   = $consumer->consume();
		$metadata = array();
		foreach ( $result->get_all_metadata() as $key => $value ) {
			$metadata[ $key ] = is_array( $value ) ? reset( $value ) : $value;
		}
		$this->assertEquals( 'My Title', $metadata['title'] );
		$this->assertEquals( 'publish', $metadata['status'] );
	}

	public function test_consumer_default_uses_block_comments() {
		// Default constructor arg should keep block comments.
		$markdown = "Hello.\n";
		$consumer = new Push_MD_Markdown_Consumer( $markdown );
		$result   = $consumer->consume();
		$markup   = $result->get_block_markup();
		$this->assertStringContainsString( '<!-- wp:paragraph -->', $markup );
	}
}
