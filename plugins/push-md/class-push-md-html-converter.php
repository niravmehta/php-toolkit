<?php

use WordPress\DataLiberation\DataLiberationHTMLProcessor;

require_once __DIR__ . '/class-push-md-directives.php';

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

		// Normalize <a href="..."><figure>...</figure></a> to <figure><a href="...">...</a></figure>
		// to ensure the figure block structure is preserved in Markdown and survives the round-trip.
		$html = preg_replace( '#(<a\b[^>]*>)\s*(<figure\b[^>]*>)(.*?)(</figure>)\s*</a>#is', '$2$1$3</a>$4', $html );

		// Convert hybrid HTML image-markdown links `[<img...src="SRC"...>](URL)` to `<a href="URL"><img ...></a>`.
		$html = preg_replace( '/\[\s*(<img[^>]+>)\s*\]\s*\(([^)]+)\)/i', '<a href="$2">$1</a>', $html );

		$html = self::wpautop( $html );

		return self::convert_fragment( $html );
	}

	/**
	 * Inner recursive converter.
	 *
	 * @param string $html HTML fragment.
	 * @return string Markdown.
	 */
	public static function convert_fragment( $html ) {
		$processor = DataLiberationHTMLProcessor::create_fragment( $html );
		$output    = '';

		// Inline formatting state: prevent duplicate markers for nested identical tags.
		$active_inlines = array();

		// Inline elements that were emitted as raw HTML to avoid merging Markdown
		// delimiter runs (e.g. <strong>one</strong><strong>cta</strong> would
		// otherwise become "**one****cta**", which CommonMark mis-parses).
		$html_inline_stack = array();

		// List nesting: each entry is array( 'type' => 'ul'|'ol', 'count' => int ).
		$list_stack = array();

		// Link href stack for nested anchors.
		$link_stack = array();

		// Block tag nesting stack to prevent splitting text inside <p>, headings, list items, etc.
		$block_stack = array();

		while ( $processor->next_token() ) {
			$token_type = $processor->get_token_type();

			if ( '#text' === $token_type ) {
				$text = $processor->get_modifiable_text();

				if ( '' === trim( $text ) ) {
					// Ignore pure whitespace text nodes at line/block boundaries.
					if ( '' === $output || "\n" === substr( $output, -1 ) ) {
						continue;
					}
					$text = ' ';
				} else {
					$text = preg_replace( '/[ \t\r\n\f]+/', ' ', $text );
				}

				// If we are not inside a code block, escape literal < and > to prevent Markdown
				// from parsing them as raw HTML tags.
				if ( ! in_array( 'CODE', $active_inlines, true ) ) {
					$text = str_replace( array( '<', '>' ), array( '\<', '\>' ), $text );
					// Escape ordered list markers (e.g. "1. " or "2) ") at the beginning of lines or text nodes
					// to prevent them from being parsed as Markdown ordered lists (which would cause the number to be lost).
					$text = preg_replace( '/^(\s*\d+)([\.\)])(\s+)/m', '$1\\\\$2$3', $text );
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
				$custom_markdown = class_exists( 'Push_MD_Directives' ) ? Push_MD_Directives::convert_node( $processor, static::class ) : null;
				if ( null !== $custom_markdown ) {
					$output .= $custom_markdown;
					$processor->skip_to_closer();
					continue;
				}

				// --- Opener ---
				$block_tags = array( 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'LI', 'TD', 'TH', 'CAPTION', 'FIGCAPTION', 'BLOCKQUOTE', 'PRE' );
				if ( in_array( $tag, $block_tags, true ) ) {
					array_push( $block_stack, $tag );
				}

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
						} else {
							$output = rtrim( $output ) . "\n\n> ";
						}
						break;

					case 'PRE':
						$inner = $processor->get_inner_html();
						if ( false !== $inner ) {
							$inner  = preg_replace( '/<br\s*\/?>/i', "\n", $inner );
							$code   = wp_strip_all_tags( $inner );
							$code   = html_entity_decode( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
							$output = rtrim( $output ) . "\n\n```\n" . $code . "\n```\n\n";
							$processor->skip_to_closer();
						}
						break;

					case 'TABLE':
						$inner = $processor->get_inner_html();
						if ( false !== $inner ) {
							$table_md = self::table_to_markdown( $inner );
							if ( false === $table_md ) {
								$output = rtrim( $output ) . "\n\n" . $processor->get_outer_html() . "\n\n";
							} elseif ( '' !== $table_md ) {
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
							if ( '*' === substr( $output, -1 ) ) {
								// Adjacent delimiter runs (e.g. "**one****cta**") are
								// ambiguous in CommonMark, so use raw HTML instead.
								$output             .= '<strong>';
								$html_inline_stack[] = 'STRONG';
							} else {
								$output .= '**';
							}
							$active_inlines[] = 'STRONG';
						}
						break;

					case 'EM':
					case 'I':
						if ( ! in_array( 'EM', $active_inlines, true ) ) {
							if ( '*' === substr( $output, -1 ) ) {
								$output             .= '<em>';
								$html_inline_stack[] = 'EM';
							} else {
								$output .= '*';
							}
							$active_inlines[] = 'EM';
						}
						break;

					case 'DEL':
					case 'S':
						if ( ! in_array( 'DEL', $active_inlines, true ) ) {
							if ( '~' === substr( $output, -1 ) ) {
								$output             .= '<del>';
								$html_inline_stack[] = 'DEL';
							} else {
								$output .= '~~';
							}
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
						if ( null !== $href && '' !== trim( $href ) ) {
							array_push(
								$link_stack,
								array(
									'href' => self::escape_url( $href ),
									'start_len' => strlen( $output ) + 1,
								)
							);
							$output .= '[';
						}
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
				$block_tags = array( 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'LI', 'TD', 'TH', 'CAPTION', 'FIGCAPTION', 'BLOCKQUOTE', 'PRE' );
				if ( in_array( $tag, $block_tags, true ) ) {
					$pos = array_search( $tag, $block_stack, true );
					if ( false !== $pos ) {
						array_splice( $block_stack, $pos, 1 );
					}
				}

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
							$output = rtrim( $output, "\r\n" ) . "\n\n";
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
						if ( empty( $link_stack ) ) {
							$output = rtrim( $output ) . "\n\n";
						}
						break;

					case 'STRONG':
					case 'B':
						$pos = array_search( 'STRONG', $active_inlines, true );
						if ( false !== $pos ) {
							array_splice( $active_inlines, $pos, 1 );
							$html_pos = array_search( 'STRONG', $html_inline_stack, true );
							if ( false !== $html_pos ) {
								array_splice( $html_inline_stack, $html_pos, 1 );
								$output .= '</strong>';
							} else {
								self::append_inline_closer( $output, '**' );
							}
						}
						break;

					case 'EM':
					case 'I':
						$pos = array_search( 'EM', $active_inlines, true );
						if ( false !== $pos ) {
							array_splice( $active_inlines, $pos, 1 );
							$html_pos = array_search( 'EM', $html_inline_stack, true );
							if ( false !== $html_pos ) {
								array_splice( $html_inline_stack, $html_pos, 1 );
								$output .= '</em>';
							} else {
								self::append_inline_closer( $output, '*' );
							}
						}
						break;

					case 'DEL':
					case 'S':
						$pos = array_search( 'DEL', $active_inlines, true );
						if ( false !== $pos ) {
							array_splice( $active_inlines, $pos, 1 );
							$html_pos = array_search( 'DEL', $html_inline_stack, true );
							if ( false !== $html_pos ) {
								array_splice( $html_inline_stack, $html_pos, 1 );
								$output .= '</del>';
							} else {
								self::append_inline_closer( $output, '~~' );
							}
						}
						break;

					case 'CODE':
						$pos = array_search( 'CODE', $active_inlines, true );
						if ( false !== $pos ) {
							array_splice( $active_inlines, $pos, 1 );
							self::append_inline_closer( $output, '`' );
						}
						break;

					case 'A':
						if ( empty( $link_stack ) ) {
							break;
						}
						$link_data     = array_pop( $link_stack );
						$href          = $link_data['href'];
						$start_len     = $link_data['start_len'];
						$link_text_len = strlen( $output ) - $start_len;
						$link_text     = $link_text_len > 0 ? substr( $output, -$link_text_len ) : '';

						// Shift leading/trailing whitespace inside anchor text outside the link brackets.
						$rtrimmed    = rtrim( $link_text, " \t" );
						$trailing_ws = substr( $link_text, strlen( $rtrimmed ) );
						$ltrimmed    = ltrim( $rtrimmed, " \t" );
						$leading_ws  = substr( $rtrimmed, 0, strlen( $rtrimmed ) - strlen( $ltrimmed ) );

						$prefix        = substr( $output, 0, -$link_text_len - 1 );
						$output        = $prefix . $leading_ws . '[' . $ltrimmed;
						$link_text     = $ltrimmed;
						$link_text_len = strlen( $link_text );

						// Markdown links cannot span blocks or contain newlines.
						if ( false !== strpos( $link_text, "\n" ) ) {
							$clean_text    = preg_replace( '/\s+/', ' ', $link_text );
							$clean_text    = trim( $clean_text );
							$output        = substr( $output, 0, -$link_text_len ) . $clean_text;
							$link_text     = $clean_text;
							$link_text_len = strlen( $link_text );
						}

						// If the link text matches the href, output it as an autolink `<url>`.
						if ( '' === trim( $link_text ) ) {
							$output  = substr( $output, 0, -( $link_text_len + 1 ) ); // Remove '['.
							$output .= '<a href="' . $href . '">' . $link_text . '</a>' . $trailing_ws;
						} elseif ( $link_text === $href && '' !== $href ) {
							$output = substr( $output, 0, -( $link_text_len + 1 ) ) . '<' . $href . '>' . $trailing_ws;
						} else {
							$output .= '](' . $href . ')' . $trailing_ws;
						}
						break;
				}
			}
		}

		// Normalize: collapse 3+ consecutive newlines to a single blank line.
		$output = preg_replace( "/\n{3,}/", "\n\n", $output );
		$output = self::normalize_markdown( $output );

		return $output;
	}

	/**
	 * Normalize a Markdown string to fix common formatting and validation issues:
	 * 1. Convert non-breaking spaces (\u{00A0}, &nbsp;) to standard spaces.
	 * 2. Shift leading/trailing spaces inside inline formatting delimiters (***, **, *, ~~, `) outside the delimiters
	 *    (e.g., "**Ask questions **" -> "**Ask questions** ").
	 * 3. Remove space before punctuation directly following formatting delimiters.
	 * 4. Preserve fenced code blocks without altering code within them.
	 *
	 * @param string $markdown Markdown text.
	 * @return string Normalized Markdown text.
	 */
	public static function normalize_markdown( $markdown ) {
		if ( '' === $markdown ) {
			return '';
		}

		// Replace non-breaking spaces and &nbsp; with regular space.
		$markdown = str_replace( array( "\xC2\xA0", "\u{00A0}", '&nbsp;' ), ' ', $markdown );

		// Preserve fenced code blocks (```...```) by splitting and only normalizing non-code parts.
		$parts = preg_split( '/(```[\s\S]*?```)/u', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return self::normalize_inline_delimiters( $markdown );
		}

		foreach ( $parts as $i => $part ) {
			// Skip odd parts (fenced code blocks).
			if ( 1 === $i % 2 ) {
				continue;
			}
			$parts[ $i ] = self::normalize_inline_delimiters( $part );
		}

		return implode( '', $parts );
	}

	/**
	 * Inner helper to normalize inline formatting delimiter spaces.
	 *
	 * @param string $text Markdown fragment outside code blocks.
	 * @return string Normalized fragment.
	 */
	private static function normalize_inline_delimiters( $text ) {
		$text = self::shift_delimiter_spaces( $text, '/\*\*\*([^\*\r\n]+?)\*\*\*/u', '***' );
		$text = self::shift_delimiter_spaces( $text, '/\*\*([^\*\r\n]+?)\*\*/u', '**' );
		$text = self::shift_delimiter_spaces( $text, '/(?<!\*)\*([^\*\r\n]+?)\*(?!\*)/u', '*' );
		$text = self::shift_delimiter_spaces( $text, '/(?<!~)~~([^~\r\n]+?)~~(?!~)/u', '~~' );
		$text = self::shift_delimiter_spaces( $text, '/(?<!`)`([^`\r\n]+?)`(?!`)/u', '`' );

		// Shift leading/trailing spaces inside Markdown link brackets outside the brackets.
		$text = preg_replace_callback(
			'/\[([^\]\r\n]+?)\]\(([^)\r\n]+?)\)/u',
			function ( $matches ) {
				$inner = $matches[1];
				$url   = $matches[2];
				if ( '' === trim( $inner ) ) {
					return $matches[0];
				}
				$trimmed_left   = ltrim( $inner );
				$leading_space  = substr( $inner, 0, strlen( $inner ) - strlen( $trimmed_left ) );
				$trimmed_both   = rtrim( $trimmed_left );
				$trailing_space = substr( $trimmed_left, strlen( $trimmed_both ) );
				return $leading_space . '[' . $trimmed_both . '](' . $url . ')' . $trailing_space;
			},
			$text
		);

		// Clean up trailing space before punctuation directly following delimiters and collapse multiple spaces (except indentation).
		$text = preg_replace( '/(?<!^|\n)[ \t]{2,}/m', ' ', $text );
		$text = preg_replace( '/(\*\*|\*|~~|`)[ \t]+([.,?!;:])/u', '$1$2', $text );

		return $text;
	}

	/**
	 * Helper to shift spaces inside formatting delimiters outside the delimiters.
	 *
	 * @param string $text      Target markdown text.
	 * @param string $pattern   Regex pattern matching the delimited string with capture group 1 as inner text.
	 * @param string $delimiter Formatting delimiter (e.g., '**', '*', '~~', '`').
	 * @return string Text with shifted spaces.
	 */
	private static function shift_delimiter_spaces( $text, $pattern, $delimiter ) {
		return preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $delimiter ) {
				$inner = $matches[1];
				if ( '' === trim( $inner ) ) {
					return $matches[0];
				}
				$trimmed_left   = ltrim( $inner );
				$leading_space  = substr( $inner, 0, strlen( $inner ) - strlen( $trimmed_left ) );
				$trimmed_both   = rtrim( $trimmed_left );
				$trailing_space = substr( $trimmed_left, strlen( $trimmed_both ) );
				return $leading_space . $delimiter . $trimmed_both . $delimiter . $trailing_space;
			},
			$text
		);
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
		$caption     = '';

		while ( $processor->next_token() ) {
			if ( '#tag' !== $processor->get_token_type() ) {
				continue;
			}
			$tag       = $processor->get_tag();
			$is_closer = $processor->is_tag_closer();

			if ( 'CAPTION' === $tag && ! $is_closer ) {
				$caption_html = $processor->get_inner_html();
				if ( false !== $caption_html && '' !== trim( $caption_html ) ) {
					$caption = trim( self::convert_fragment( $caption_html ) );
				}
			} elseif ( 'THEAD' === $tag && ! $is_closer ) {
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
				$cell_md       = false !== $cell_html ? trim( self::convert_fragment( $cell_html ) ) : '';
				$current_row[] = str_replace( array( "\r\n", "\r", "\n" ), '<br>', $cell_md );
			}
		}

		if ( empty( $header ) && ! empty( $rows ) ) {
			$header = array_shift( $rows );
		}
		if ( empty( $header ) ) {
			return false;
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

		if ( '' !== $caption ) {
			$markdown = '*' . $caption . "*\n\n" . $markdown;
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
	 * Append an inline formatting closing delimiter, moving any trailing spaces outside.
	 *
	 * @param string $output    Reference to current markdown output string.
	 * @param string $delimiter Closing delimiter (e.g. '**', '*', '~~', '`').
	 */
	private static function append_inline_closer( &$output, $delimiter ) {
		$trailing_space = '';
		if ( preg_match( '/[ \t]+$/', $output, $matches ) ) {
			$trailing_space = $matches[0];
			$output         = substr( $output, 0, -strlen( $trailing_space ) );
		}
		$output .= $delimiter . $trailing_space;
	}





	/**
	 * Reconstruct outer HTML string for the current tag element.
	 *
	 * @param DataLiberationHTMLProcessor $processor HTML processor.
	 * @param string                      $tag       The tag name.
	 * @return string Outer HTML element string.
	 */
	public static function get_outer_html( $processor, $tag ) {
		$tag        = strtolower( (string) $tag );
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
			if ( in_array( $tag, array( 'style', 'script', 'textarea' ), true ) ) {
				$inner = $processor->get_modifiable_text();
			}
		}
		if ( false === $inner || null === $inner ) {
			return '<' . $tag . $attr_str . '></' . $tag . '>';
		}

		return '<' . $tag . $attr_str . '>' . $inner . '</' . $tag . '>';
	}

	/**
	 * Replaces double line breaks with paragraph elements.
	 *
	 * Uses WordPress core's wpautop when available; falls back to pure PHP implementation.
	 *
	 * @param string $text Content to format.
	 * @return string Formatted text wrapped in <p> tags.
	 */
	private static function wpautop( $text ) {
		if ( function_exists( 'wpautop' ) ) {
			return wpautop( $text );
		}

		if ( '' === trim( $text ) ) {
			return '';
		}

		$pre_tags = array();
		$text     = $text . "\n";

		if ( false !== strpos( $text, '<pre' ) ) {
			$text_parts = explode( '</pre>', $text );
			$last_part  = array_pop( $text_parts );
			$text       = '';
			$i          = 0;
			foreach ( $text_parts as $text_part ) {
				$start = strpos( $text_part, '<pre' );
				if ( false === $start ) {
					$text .= $text_part;
					continue;
				}
				$name              = "<pre wp-pre-tag-$i></pre>";
				$pre_tags[ $name ] = substr( $text_part, $start ) . '</pre>';
				$text             .= substr( $text_part, 0, $start ) . $name;
				++$i;
			}
			$text .= $last_part;
		}

		$text = preg_replace( '|<br\s*/?>\s*<br\s*/?>|', "\n\n", $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

		$allblocks = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary)';

		$pees = preg_split( '/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$text = '';

		foreach ( $pees as $t ) {
			$trimmed = trim( $t );
			if ( ! preg_match( '/^<' . $allblocks . '/i', $trimmed ) ) {
				$text .= '<p>' . trim( $t, "\n" ) . "</p>\n";
			} else {
				$text .= $t . "\n";
			}
		}

		if ( ! empty( $pre_tags ) ) {
			$text = str_replace( array_keys( $pre_tags ), array_values( $pre_tags ), $text );
		}

		return $text;
	}
}
