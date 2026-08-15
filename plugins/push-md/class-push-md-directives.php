<?php

/**
 * Registry and processor for Generic Directives (:::).
 *
 * Handles mapping between Markdown `:::` blocks and HTML/Gutenberg blocks.
 * Also serves as the central authority for raw HTML preservation rules.
 */
class Push_MD_Directives {

	private static $registry = array();

	/**
	 * Register a generic directive.
	 *
	 * Config options:
	 *  - class: The CSS class to match (Pattern 1/3) and output. Defaults to $name.
	 *  - id: Optional ID to match.
	 *  - tag: The HTML tag to use (Pattern 1/3). Defaults to 'div'.
	 *  - block: The Gutenberg block name (Pattern 2). If invalid, falls back to Pattern 1.
	 *  - slots: Array of inner slots for declarative mapping (Pattern 3).
	 *
	 * @param string $name   The directive name used in `:::name`.
	 * @param array  $config Configuration array.
	 */
	public static function register( $name, $config = array() ) {
		$parsed = array(
			'name'  => $name,
			'class' => isset( $config['class'] ) ? $config['class'] : $name,
			'id'    => isset( $config['id'] ) ? $config['id'] : null,
			'tag'   => isset( $config['tag'] ) ? $config['tag'] : 'div',
			'slots' => isset( $config['slots'] ) && is_array( $config['slots'] ) ? $config['slots'] : array(),
			'block' => null,
		);

		if ( isset( $config['block'] ) && is_string( $config['block'] ) && false !== strpos( $config['block'], '/' ) ) {
			$parsed['block'] = $config['block'];
		}

		self::$registry[ $name ] = $parsed;
	}

	/**
	 * Get all registered directives.
	 */
	public static function get_directives() {
		return self::$registry;
	}

	/**
	 * Main entry point for Push_MD_HTML_Converter.
	 * Determines how an HTML node should be processed back into Markdown.
	 *
	 * @param \WordPress\DataLiberation\DataLiberationHTMLProcessor $processor The HTML processor at the current token.
	 * @param string                                                $converter_class The class name to call for recursive conversion.
	 * @return string|null Markdown/HTML string if handled, null to continue normal processing.
	 */
	public static function convert_node( $processor, $converter_class ) {
		$tag = $processor->get_tag();

		// 1. Check if the node matches a registered directive (Pattern 1 or 3).
		$matched = self::match_html( $processor );
		if ( $matched ) {
			$inner_html = $processor->get_inner_html();
			if ( false !== $inner_html ) {
				$inner_md = call_user_func( array( $converter_class, 'convert_fragment' ), $inner_html );
				$inner_md = trim( $inner_md );

				$args_str = '';
				// Collect attributes that aren't the tag/class/id standard to the directive.
				$attr_names = $processor->get_attribute_names_with_prefix( '' );
				$args       = array();
				if ( ! empty( $attr_names ) ) {
					foreach ( $attr_names as $attr_name ) {
						if ( 'class' === $attr_name ) {
							$classes  = explode( ' ', trim( $processor->get_attribute( 'class' ) ) );
							$filtered = array_diff( $classes, array( $matched['class'] ) );
							if ( ! empty( $filtered ) ) {
								$args['class'] = implode( ' ', $filtered );
							}
						} elseif ( 'id' === $attr_name ) {
							if ( $matched['id'] !== $processor->get_attribute( 'id' ) ) {
								$args['id'] = $processor->get_attribute( 'id' );
							}
						} else {
							$args[ $attr_name ] = $processor->get_attribute( $attr_name );
						}
					}
				}

				if ( ! empty( $args ) ) {
					$args_str = ' ' . self::format_args( $args );
				}

				return "\n\n:::" . $matched['name'] . $args_str . "\n" . $inner_md . "\n:::\n\n";
			}
		}

		// 2. Check for raw HTML preservation
		if ( self::should_preserve_element( $processor, $tag ) ) {
			$container_tags = array( 'div', 'aside', 'section', 'article', 'header', 'footer', 'nav', 'main', 'figure', 'blockquote' );
			if ( in_array( strtolower( $tag ), $container_tags, true ) ) {
				$inner = $processor->get_inner_html();
				if ( false !== $inner ) {
					$attr_names = $processor->get_attribute_names_with_prefix( '' );
					$attr_str   = '';
					if ( ! empty( $attr_names ) ) {
						foreach ( $attr_names as $attr_name ) {
							$val       = $processor->get_attribute( $attr_name );
							$attr_str .= ' ' . $attr_name . '="' . htmlspecialchars( $val, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
						}
					}
					$opening_tag = '<' . strtolower( $tag ) . $attr_str . '>';
					$closing_tag = '</' . strtolower( $tag ) . '>';

					$inner_md = call_user_func( array( $converter_class, 'convert_fragment' ), $inner );
					return "\n\n" . $opening_tag . "\n\n" . trim( $inner_md ) . "\n\n" . $closing_tag . "\n\n";
				}
			}

			// Inline elements get preserved raw.
			// DataLiberationHTMLProcessor does not have get_outer_html natively in this version unless patched.
			// The original code used self::get_outer_html. We must call it from $converter_class.
			$outer       = call_user_func( array( $converter_class, 'get_outer_html' ), $processor, $tag );
			$outer       = preg_replace( '/^[ \t]+/m', '', $outer );
			$inline_tags = array( 'a', 'span', 'b', 'i', 'strong', 'em', 'code', 's', 'del', 'sub', 'sup' );

			if ( in_array( strtolower( $tag ), $inline_tags, true ) ) {
				return $outer;
			} else {
				$outer_clean = preg_replace( "/\n{2,}/", "\n", $outer );
				return "\n\n" . $outer_clean . "\n\n";
			}
		}

		return null;
	}

	/**
	 * Checks if the current processor node matches a registered directive.
	 */
	private static function match_html( $processor ) {
		$tag   = strtolower( $processor->get_tag() );
		$class = $processor->get_attribute( 'class' );
		$id    = $processor->get_attribute( 'id' );

		$classes = $class ? explode( ' ', trim( $class ) ) : array();

		foreach ( self::$registry as $directive ) {
			// Pattern 2 directives are Gutenberg blocks, not matched here.
			if ( $directive['block'] ) {
				continue;
			}

			// Must match tag.
			if ( strtolower( $directive['tag'] ) !== $tag ) {
				continue;
			}

			// Match ID if required.
			if ( $directive['id'] && $id === $directive['id'] ) {
				return $directive;
			}

			// Match Class.
			if ( $directive['class'] && in_array( $directive['class'], $classes, true ) ) {
				return $directive;
			}
		}

		return false;
	}

	/**
	 * Determines if a tag should be preserved as raw HTML.
	 */
	private static function should_preserve_element( $processor, $tag ) {
		$tag = strtolower( $tag );

		if ( 'img' === $tag ) {
			$class_attr = $processor->get_attribute( 'class' );
			$has_align  = $class_attr && preg_match( '/\b(alignleft|alignright|aligncenter|wp-image-\d+)\b/', $class_attr );
			$has_dims   = null !== $processor->get_attribute( 'width' ) || null !== $processor->get_attribute( 'height' );
			if ( $has_align || $has_dims ) {
				return true;
			}
			return false;
		}

		if ( 'figure' === $tag ) {
			$inner = $processor->get_inner_html();
			if ( false === $inner || false === strpos( strtolower( $inner ), '<figcaption' ) ) {
				return false;
			}
			return true;
		}

		if ( 'a' === $tag ) {
			$attr_names = $processor->get_attribute_names_with_prefix( 'on' );
			if ( ! empty( $attr_names ) ) {
				return true;
			}
		}

		if ( 'code' === $tag ) {
			$inner = $processor->get_inner_html();
			if ( false !== $inner && preg_match( '/<[a-z][a-z0-9]*/i', $inner ) ) {
				return true;
			}
			return false;
		}

		$default_preserved_tags = array( 'figure', 'figcaption', 'iframe', 'form', 'script', 'style', 'svg', 'canvas', 'video', 'audio', 'picture', 'source', 'track' );
		if ( function_exists( 'apply_filters' ) ) {
			$preserved_tags = apply_filters( 'push_md_preserved_html_tags', $default_preserved_tags, $tag );
		} else {
			$preserved_tags = $default_preserved_tags;
		}

		if ( in_array( $tag, $preserved_tags, true ) ) {
			return true;
		}

		if ( null !== $processor->get_attribute( 'id' ) ) {
			if ( ! preg_match( '/^h[1-6]$/i', $tag ) ) {
				return true;
			}
		}

		if ( null !== $processor->get_attribute( 'style' ) ) {
			return true;
		}

		$class_attr = $processor->get_attribute( 'class' );
		$classes    = $class_attr ? explode( ' ', trim( $class_attr ) ) : array();

		$default_preserved_classes = array( 'button', 'btn', 'cta', 'download_link' );
		if ( function_exists( 'apply_filters' ) ) {
			$preserved_classes = apply_filters( 'push_md_preserved_html_classes', $default_preserved_classes, $tag, $classes );
		} else {
			$preserved_classes = $default_preserved_classes;
		}

		$standard_wp_classes = array( 'alignleft', 'alignright', 'aligncenter', 'alignnone', 'wp-caption', 'wp-caption-text', 'gallery', 'gallery-caption' );
		$preserved_classes   = array_merge( $preserved_classes, $standard_wp_classes );

		if ( $class_attr ) {
			foreach ( $classes as $c ) {
				if ( in_array( $c, $preserved_classes, true ) ) {
					return true;
				}
				foreach ( $preserved_classes as $pc ) {
					if ( 0 === strpos( $c, $pc . '-' ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Pre-processes Markdown to convert `:::` directives to raw HTML or Gutenberg blocks.
	 */
	public static function process_markdown_to_html( $markdown, $use_block_comments ) {
		if ( false === strpos( $markdown, ':::' ) ) {
			return $markdown;
		}

		$lines         = explode( "\n", $markdown );
		$output        = array();
		$in_code_block = false;
		$code_fence    = '';
		$stack         = array();

		foreach ( $lines as $line ) {
			if ( ! $in_code_block && preg_match( '/^(\`\`\`|~~~)/', ltrim( $line ), $matches ) ) {
				$in_code_block = true;
				$code_fence    = $matches[1];
				$output[]      = $line;
				continue;
			} elseif ( $in_code_block && 0 === strpos( ltrim( $line ), $code_fence ) ) {
				$in_code_block = false;
				$output[]      = $line;
				continue;
			}

			if ( ! $in_code_block && preg_match( '/^:::([a-zA-Z0-9_-]+)(?:[ \t]+(.*))?$/', trim( $line ), $matches ) ) {
				$name = $matches[1];
				$args = isset( $matches[2] ) ? self::parse_args( $matches[2] ) : array();

				if ( isset( self::$registry[ $name ] ) ) {
					$stack[] = array(
						'directive' => self::$registry[ $name ],
						'args'      => $args,
						'inner'     => array(),
					);
					continue;
				}
			}

			if ( ! $in_code_block && preg_match( '/^:::$/', trim( $line ) ) ) {
				if ( ! empty( $stack ) ) {
					$current  = array_pop( $stack );
					$inner_md = implode( "\n", $current['inner'] );

					// Convert inner markdown recursively.
					if ( class_exists( 'Push_MD_Markdown_Consumer' ) ) {
						$consumer     = new Push_MD_Markdown_Consumer( $inner_md, $use_block_comments );
						$inner_blocks = $consumer->consume();
						$inner_html   = $inner_blocks ? $inner_blocks->get_block_markup() : '';
					} else {
						// Fallback if class not available (e.g. testing).
						$inner_html = $inner_md;
					}

					$directive = $current['directive'];

					if ( $directive['block'] ) {
						// Pattern 2: Gutenberg Block.
						$block_name = $directive['block'];
						$attrs_json = empty( $current['args'] ) ? '{}' : json_encode( $current['args'] );
						$html       = '';
						if ( $use_block_comments ) {
							$html .= '<!-- wp:' . $block_name . ' ' . $attrs_json . ' -->' . "\n";
						}
						$class = 'wp-block-' . str_replace( '/', '-', $block_name );
						$html .= '<div class="' . $class . '">' . "\n" . trim( $inner_html ) . "\n" . '</div>' . "\n";
						if ( $use_block_comments ) {
							$html .= '<!-- /wp:' . $block_name . ' -->';
						}
					} else {
						// Pattern 1: HTML Tag.
						$tag      = $directive['tag'];
						$attr_str = 'class="' . htmlspecialchars( $directive['class'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
						foreach ( $current['args'] as $k => $v ) {
							if ( 'class' === $k ) {
								$attr_str = 'class="' . htmlspecialchars( $directive['class'] . ' ' . $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
							} else {
								$attr_str .= ' ' . htmlspecialchars( $k, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '="' . htmlspecialchars( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '"';
							}
						}
						$html = '<' . $tag . ' ' . $attr_str . '>' . "\n" . trim( $inner_html ) . "\n" . '</' . $tag . '>';
					}

					if ( empty( $stack ) ) {
						$output[] = $html;
					} else {
						$stack[ count( $stack ) - 1 ]['inner'][] = $html;
					}
					continue;
				}
			}

			if ( empty( $stack ) ) {
				$output[] = $line;
			} else {
				$stack[ count( $stack ) - 1 ]['inner'][] = $line;
			}
		}

		return implode( "\n", $output );
	}

	/**
	 * Post-processes Gutenberg export to convert unknown blocks serialized as fences back to :::.
	 */
	public static function process_gutenberg_to_markdown( $markdown ) {
		$pattern = '/```gutenberg\r?\n<!-- wp:([a-zA-Z0-9_-]+\/[a-zA-Z0-9_-]+)(?: (.*?))? -->\r?\n(.*?)\r?\n<!-- \/wp:\1 -->\r?\n```/ms';

		return preg_replace_callback(
			$pattern,
			function ( $matches ) {
				$block_name = $matches[1];
				$attrs_json = $matches[2] ? trim( $matches[2] ) : '{}';
				$inner_html = $matches[3];

				$directive = null;
				foreach ( self::$registry as $reg ) {
					if ( $reg['block'] === $block_name ) {
						$directive = $reg;
						break;
					}
				}

				if ( ! $directive ) {
					return $matches[0];
				}

				$args = json_decode( $attrs_json, true );
				if ( ! is_array( $args ) ) {
					$args = array();
				}

				$args_str = empty( $args ) ? '' : ' ' . self::format_args( $args );

				$inner_html = preg_replace( '/^<div class="wp-block-[^>]+>\s*(.*?)\s*<\/div>$/is', '$1', $inner_html );
				$inner_md   = Push_MD_HTML_Converter::convert( $inner_html );

				return ':::' . $directive['name'] . $args_str . "\n" . trim( $inner_md ) . "\n:::";
			},
			$markdown
		);
	}

	public static function parse_args( $arg_string ) {
		$args = array();
		if ( preg_match_all( '/([a-zA-Z0-9_-]+)="([^"]*)"/', $arg_string, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$args[ $match[1] ] = $match[2];
			}
		}
		return $args;
	}

	public static function format_args( $args ) {
		$str = '';
		foreach ( $args as $k => $v ) {
			$str .= $k . '="' . htmlspecialchars( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) . '" ';
		}
		return trim( $str );
	}

	/**
	 * Pre-processes HTML content before Markdown conversion to convert registered HTML directives (Pattern 1/3) into ::: directive syntax.
	 *
	 * @param string $content HTML content or post markup.
	 * @return string Processed content.
	 */
	public static function process_html_directives_in_content( $content ) {
		if ( empty( self::$registry ) || false === strpos( $content, '<' ) ) {
			return $content;
		}

		foreach ( self::$registry as $directive ) {
			if ( $directive['block'] ) {
				continue;
			}

			$tag   = preg_quote( $directive['tag'], '#' );
			$class = preg_quote( $directive['class'], '#' );

			$pattern = '#<' . $tag . '\b[^>]*class="[^"]*\b' . $class . '\b[^"]*"[^>]*>(.*?)</' . $tag . '>#is';

			if ( preg_match( $pattern, $content ) ) {
				$content = preg_replace_callback(
					$pattern,
					function ( $matches ) {
						return Push_MD_HTML_Converter::convert( $matches[0] );
					},
					$content
				);
			}
		}

		return $content;
	}
}
