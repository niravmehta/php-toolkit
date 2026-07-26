<?php

use WordPress\DataLiberation\DataLiberationHTMLProcessor;

/**
 * Converts a standard HTML string to clean Markdown.
 *
 * Used by Push_MD_Markdown_Producer to handle posts whose content
 * is standard HTML (classic editor, imported HTML, etc.) rather than
 * Gutenberg block markup.
 *
 * Handles:
 *   Block:  h1–h6, p, ul, ol, li, blockquote, aside, figure, hr, pre, table
 *   Inline: strong/b, em/i, del/s, code, a, img, br
 *
 * Intentionally does NOT depend on any Gutenberg or WordPress block APIs
 * beyond the HTML processor from the DataLiberation component.
 */
class Push_MD_HTML_Converter {

	/**
	 * Convert an HTML string to Markdown.
	 *
	 * @param string $html The HTML to convert.
	 * @return string Clean Markdown.
	 */
	public static function convert( $html ) {
		$filtered = apply_filters( 'push_md_html_to_markdown', null, $html );
		if ( null !== $filtered ) {
			return (string) $filtered;
		}

		return self::convert_fragment( $html );
	}

	/**
	 * Inner recursive converter.
	 *
	 * @param string $html HTML fragment.
	 * @return string Markdown.
	 */
	private static function convert_fragment( $html ) {
		$processor = DataLiberationHTMLProcessor::create_fragment( $html );
		$output    = '';

		// Inline formatting state: prevent duplicate markers for nested identical tags.
		$active_inlines = array();

		// List nesting: each entry is array( 'type' => 'ul'|'ol', 'count' => int ).
		$list_stack = array();

		// Link href stack for nested anchors.
		$link_stack = array();

		while ( $processor->next_token() ) {
			$token_type = $processor->get_token_type();

			if ( '#text' === $token_type ) {
				$text = $processor->get_modifiable_text();
				if ( ! empty( $list_stack ) && '' === trim( $text ) ) {
					// Ignore pure whitespace text nodes inside lists between tags.
					continue;
				}
				$output .= $text;
				continue;
			}

			if ( '#tag' !== $token_type ) {
				continue;
			}

			$tag       = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( ! $is_closer ) {
				// Check for raw HTML preservation based on tag name or CSS classes.
				if ( self::should_preserve_element( $processor, $tag ) ) {
					$outer  = self::get_outer_html( $processor );
					$output = rtrim( $output ) . "\n\n" . $outer . "\n\n";
					$processor->skip_to_closer();
					continue;
				}

				// --- Opener ---
				switch ( $tag ) {
					case 'H1':
					case 'H2':
					case 'H3':
					case 'H4':
					case 'H5':
					case 'H6':
						$level  = (int) substr( $tag, 1 );
						$output = rtrim( $output ) . "\n\n" . str_repeat( '#', $level ) . ' ';
						break;

					case 'P':
						if ( ! empty( $list_stack ) ) {
							// Don't add a newline if we are right at the start of a list item (after "- " or "1. ").
							if ( ! preg_match( '/(?:-|\d+\.)\s*$/', $output ) ) {
								$output = rtrim( $output, " \t" );
								if ( '' !== $output && "\n" !== substr( $output, -1 ) ) {
									$output .= "\n";
								}
							}
						} else {
							$output = rtrim( $output ) . "\n\n";
						}
						break;

					case 'UL':
						array_push(
							$list_stack,
							array(
								'type'  => 'ul',
								'count' => 1,
							)
						);
						if ( 1 === count( $list_stack ) ) {
							$output = rtrim( $output ) . "\n\n";
						}
						break;

					case 'OL':
						array_push(
							$list_stack,
							array(
								'type'  => 'ol',
								'count' => 1,
							)
						);
						if ( 1 === count( $list_stack ) ) {
							$output = rtrim( $output ) . "\n\n";
						}
						break;

					case 'LI':
						$depth  = count( $list_stack );
						$indent = str_repeat( '  ', max( 0, $depth - 1 ) );
						// Start on a new line, but don't add an extra blank line between items.
						$output = rtrim( $output, " \t" );
						if ( '' !== $output && "\n" !== substr( $output, -1 ) ) {
							$output .= "\n";
						}
						if ( ! empty( $list_stack ) ) {
							$item = &$list_stack[ $depth - 1 ];
							if ( 'ul' === $item['type'] ) {
								$output .= $indent . '- ';
							} else {
								$output .= $indent . $item['count'] . '. ';
								++$item['count'];
							}
						} else {
							$output .= '- ';
						}
						break;

					case 'BLOCKQUOTE':
					case 'ASIDE':
						$inner = $processor->get_inner_html();
						if ( false !== $inner && '' !== trim( $inner ) ) {
							$inner_md = self::convert_fragment( $inner );
							$output   = rtrim( $output ) . "\n\n";
							// Prefix every line with "> ".
							$lines = explode( "\n", rtrim( $inner_md ) );
							foreach ( $lines as $line ) {
								$output .= '> ' . $line . "\n";
							}
							$output .= "\n";
							$processor->skip_to_closer();
						}
						break;

					case 'PRE':
						$inner = $processor->get_inner_html();
						if ( false !== $inner ) {
							// Strip any inner <code> wrapper tags, keep the text.
							$code   = wp_strip_all_tags( $inner );
							$output = rtrim( $output ) . "\n\n```\n" . $code . "\n```\n\n";
							$processor->skip_to_closer();
						}
						break;

					case 'TABLE':
						$inner = $processor->get_inner_html();
						if ( false !== $inner ) {
							$table_md = self::table_to_markdown( $inner );
							if ( '' !== $table_md ) {
								$output = rtrim( $output ) . "\n\n" . $table_md . "\n";
							}
							$processor->skip_to_closer();
						}
						break;

					case 'FIGURE':
						// Figures are containers; just ensure block-level spacing.
						$output = rtrim( $output ) . "\n\n";
						break;

					case 'HR':
						$output = rtrim( $output ) . "\n\n---\n\n";
						break;

					// Inline formatters — guard against duplicate markers for nested tags.
					case 'STRONG':
					case 'B':
						if ( ! in_array( 'STRONG', $active_inlines, true ) ) {
							$output          .= '**';
							$active_inlines[] = 'STRONG';
						}
						break;

					case 'EM':
					case 'I':
						if ( ! in_array( 'EM', $active_inlines, true ) ) {
							$output          .= '*';
							$active_inlines[] = 'EM';
						}
						break;

					case 'DEL':
					case 'S':
						if ( ! in_array( 'DEL', $active_inlines, true ) ) {
							$output          .= '~~';
							$active_inlines[] = 'DEL';
						}
						break;

					case 'CODE':
						if ( ! in_array( 'CODE', $active_inlines, true ) ) {
							$output          .= '`';
							$active_inlines[] = 'CODE';
						}
						break;

					case 'A':
						$href = $processor->get_attribute( 'href' );
						if ( null === $href ) {
							$href = '';
						}
						array_push( $link_stack, self::escape_url( $href ) );
						$output .= '[';
						break;

					case 'IMG':
						$alt = $processor->get_attribute( 'alt' );
						$src = $processor->get_attribute( 'src' );
						if ( null === $alt ) {
							$alt = '';
						}
						if ( null === $src ) {
							$src = '';
						}
						$output .= '![' . $alt . '](' . self::escape_url( $src ) . ')';
						break;

					case 'BR':
						$output .= "\n";
						break;
				}
			} else {
				// --- Closer ---
				switch ( $tag ) {
					case 'H1':
					case 'H2':
					case 'H3':
					case 'H4':
					case 'H5':
					case 'H6':
						$output = rtrim( $output ) . "\n\n";
						break;

					case 'P':
						if ( ! empty( $list_stack ) ) {
							$output = rtrim( $output, " \t" ) . "\n";
						} else {
							$output = rtrim( $output ) . "\n\n";
						}
						break;

					case 'LI':
						$output = rtrim( $output, " \t" );
						if ( '' !== $output && "\n" !== substr( $output, -1 ) ) {
							$output .= "\n";
						}
						break;

					case 'UL':
					case 'OL':
						array_pop( $list_stack );
						if ( empty( $list_stack ) ) {
							$output = rtrim( $output ) . "\n\n";
						}
						break;

					case 'FIGURE':
						$output = rtrim( $output ) . "\n\n";
						break;

					case 'STRONG':
					case 'B':
						$pos = array_search( 'STRONG', $active_inlines, true );
						if ( false !== $pos ) {
							array_splice( $active_inlines, $pos, 1 );
							$output .= '**';
						}
						break;

					case 'EM':
					case 'I':
						$pos = array_search( 'EM', $active_inlines, true );
						if ( false !== $pos ) {
							array_splice( $active_inlines, $pos, 1 );
							$output .= '*';
						}
						break;

					case 'DEL':
					case 'S':
						$pos = array_search( 'DEL', $active_inlines, true );
						if ( false !== $pos ) {
							array_splice( $active_inlines, $pos, 1 );
							$output .= '~~';
						}
						break;

					case 'CODE':
						$pos = array_search( 'CODE', $active_inlines, true );
						if ( false !== $pos ) {
							array_splice( $active_inlines, $pos, 1 );
							$output .= '`';
						}
						break;

					case 'A':
						$href    = ! empty( $link_stack ) ? array_pop( $link_stack ) : '';
						$output .= '](' . $href . ')';
						break;
				}
			}
		}

		// Normalize: collapse 3+ consecutive newlines to a single blank line.
		$output = preg_replace( "/\n{3,}/", "\n\n", $output );

		return $output;
	}

	/**
	 * Convert the inner HTML of a <table> element to a Markdown pipe table.
	 *
	 * Mirrors the table-handling logic in MarkdownProducer.
	 *
	 * @param string $inner_html Inner HTML of the table element.
	 * @return string Markdown table, or empty string if the table is empty.
	 */
	private static function table_to_markdown( $inner_html ) {
		$processor   = DataLiberationHTMLProcessor::create_fragment( '<table>' . $inner_html . '</table>' );
		$header      = array();
		$rows        = array();
		$current_row = array();
		$in_header   = false;

		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}
			$tag       = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( 'THEAD' === $tag && ! $is_closer ) {
				$in_header = true;
			} elseif ( 'THEAD' === $tag && $is_closer ) {
				$in_header = false;
			} elseif ( 'TR' === $tag && $is_closer ) {
				if ( $in_header ) {
					$header = $current_row;
				} else {
					$rows[] = $current_row;
				}
				$current_row = array();
			} elseif ( ( 'TH' === $tag || 'TD' === $tag ) && ! $is_closer ) {
				$cell_html     = $processor->get_inner_html();
				$current_row[] = false !== $cell_html ? trim( self::convert_fragment( $cell_html ) ) : '';
			}
		}

		if ( empty( $header ) && ! empty( $rows ) ) {
			$header = array_shift( $rows );
		}
		if ( empty( $header ) ) {
			return '';
		}

		$col_widths = array_map( 'strlen', $header );
		foreach ( $rows as $row ) {
			foreach ( $row as $i => $cell ) {
				if ( isset( $col_widths[ $i ] ) ) {
					$col_widths[ $i ] = max( $col_widths[ $i ], strlen( $cell ) );
				}
			}
		}

		// Header row.
		$padded = array();
		foreach ( $header as $i => $cell ) {
			$padded[] = str_pad( $cell, $col_widths[ $i ] );
		}
		$markdown = '| ' . implode( ' | ', $padded ) . " |\n";

		// Separator.
		$sep = array();
		foreach ( $col_widths as $width ) {
			$sep[] = str_repeat( '-', $width + 2 );
		}
		$markdown .= '|' . implode( '|', $sep ) . "|\n";

		// Data rows.
		foreach ( $rows as $row ) {
			$padded = array();
			foreach ( $row as $i => $cell ) {
				$padded[] = str_pad( $cell, isset( $col_widths[ $i ] ) ? $col_widths[ $i ] : 0 );
			}
			$markdown .= '| ' . implode( ' | ', $padded ) . " |\n";
		}

		return $markdown;
	}

	/**
	 * Escape characters in a URL that would break Markdown link syntax.
	 *
	 * @param string $url The URL to escape.
	 * @return string The escaped URL.
	 */
	private static function escape_url( $url ) {
		$url = str_replace( ' ', '%20', $url );
		$url = str_replace( ')', '%29', $url );
		return $url;
	}

	/**
	 * Check if an HTML element should be preserved as raw HTML based on tag name or CSS classes.
	 *
	 * Filters:
	 *   - push_md_preserved_html_tags: array of tag names (e.g., array('figcaption', 'iframe', 'form', 'aside'))
	 *   - push_md_preserved_html_classes: array of CSS classes or prefixes (e.g., array('alignleft', 'alignright', 'wp-caption', 'gallery'))
	 *
	 * @param DataLiberationHTMLProcessor $processor HTML processor positioned at tag opener.
	 * @param string                      $tag       Tag name.
	 * @return bool True if element should be preserved as raw HTML.
	 */
	private static function should_preserve_element( $processor, $tag ) {
		$tag = strtolower( $tag );

		// Inline images with floating alignment (alignleft/alignright/aligncenter), media IDs, or dimensions are preserved as raw HTML.
		if ( 'img' === $tag ) {
			$class_attr = $processor->get_attribute( 'class' );
			$has_align  = $class_attr && preg_match( '/\b(alignleft|alignright|aligncenter|wp-image-\d+)\b/', $class_attr );
			$has_dims   = null !== $processor->get_attribute( 'width' ) || null !== $processor->get_attribute( 'height' );
			if ( ! $has_align && ! $has_dims ) {
				return false;
			}
		}

		// 1. Tag name preservation.
		$default_preserved_tags = array( 'figcaption', 'iframe', 'form', 'script', 'style', 'svg', 'canvas' );
		if ( function_exists( 'apply_filters' ) ) {
			$preserved_tags = apply_filters( 'push_md_preserved_html_tags', $default_preserved_tags, $tag );
		} else {
			$preserved_tags = $default_preserved_tags;
		}

		if ( in_array( $tag, $preserved_tags, true ) ) {
			return true;
		}

		// 2. CSS Class preservation.
		$class_attr = $processor->get_attribute( 'class' );
		if ( empty( $class_attr ) || ! is_string( $class_attr ) ) {
			return false;
		}

		// For links (A tags), only preserve if they are CTA buttons.
		if ( 'a' === $tag ) {
			$classes   = preg_split( '/\s+/', trim( $class_attr ) );
			$is_button = false;
			foreach ( $classes as $class ) {
				if ( in_array( $class, array( 'button', 'btn', 'cta', 'download_link' ), true ) ) {
					$is_button = true;
					break;
				}
			}
			if ( ! $is_button ) {
				return false;
			}
		}

		$default_preserved_classes = array(
			// Alignment & layout classes.
			'alignleft',
			'alignright',
			'aligncenter',
			'alignnone',
			'alignwide',
			'alignfull',
			// Captions & Media Library.
			'wp-caption',
			'wp-caption-text',
			'size-full',
			'size-large',
			'size-medium',
			'size-thumbnail',
			// Gallery classes.
			'gallery',
			'gallery-item',
			'gallery-icon',
			'gallery-caption',
			// Buttons & CTAs.
			'button',
			'btn',
			'cta',
			'download_link',
		);

		if ( function_exists( 'apply_filters' ) ) {
			$preserved_classes = apply_filters( 'push_md_preserved_html_classes', $default_preserved_classes, $tag, $class_attr );
		} else {
			$preserved_classes = $default_preserved_classes;
		}

		$classes = preg_split( '/\s+/', trim( $class_attr ) );
		foreach ( $classes as $class ) {
			if ( in_array( $class, $preserved_classes, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reconstruct outer HTML string for the current tag element.
	 *
	 * @param DataLiberationHTMLProcessor $processor HTML processor.
	 * @return string Outer HTML element string.
	 */
	private static function get_outer_html( $processor ) {
		$tag        = strtolower( $processor->get_tag() );
		$attr_names = $processor->get_attribute_names_with_prefix( '' );
		$attr_str   = '';

		if ( ! empty( $attr_names ) ) {
			foreach ( $attr_names as $name ) {
				$val       = $processor->get_attribute( $name );
				$attr_str .= ' ' . $name . '="' . htmlspecialchars( $val, ENT_QUOTES, 'UTF-8' ) . '"';
			}
		}

		// HTML5 void tags have no closing tag or inner content.
		$void_tags = array( 'img', 'br', 'hr', 'input', 'meta', 'link', 'embed', 'param', 'source', 'track', 'wbr' );
		if ( in_array( $tag, $void_tags, true ) ) {
			return '<' . $tag . $attr_str . ' />';
		}

		$inner = $processor->get_inner_html();
		if ( false === $inner || null === $inner ) {
			return '<' . $tag . $attr_str . '></' . $tag . '>';
		}

		return '<' . $tag . $attr_str . '>' . $inner . '</' . $tag . '>';
	}
}
