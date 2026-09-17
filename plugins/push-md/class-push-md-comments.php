<?php

use WordPress\Git\Model\TreeEntry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles comment export as clean JSON sidecar files in comments/ on trunk.
 */
class Push_MD_Comments {

	/**
	 * Export approved WordPress comments into the repository files array.
	 *
	 * @param array $files Repository files array passed by reference.
	 * @throws Exception When JSON encoding fails.
	 */
	public static function add_comment_files( &$files ) {
		global $wpdb;

		// 1. Map posts to paths.
		$post_path_map = array();
		foreach ( $files as $path => $entry ) {
			if ( isset( $entry['post'] ) && $entry['post'] instanceof WP_Post && '.md' === substr( $path, -3 ) ) {
				$post_path_map[ (int) $entry['post']->ID ] = $path;
			}
		}

		if ( empty( $post_path_map ) ) {
			return;
		}

		// 2. Query once. Exclude pingbacks and trackbacks.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT comment_ID, comment_post_ID, comment_author, comment_author_url,
			        comment_date_gmt, comment_content, comment_parent
			 FROM {$wpdb->comments}
			 WHERE comment_approved = '1'
			   AND ( comment_type = '' OR comment_type = 'comment' )
			 ORDER BY comment_date_gmt ASC, comment_ID ASC"
		);

		if ( empty( $rows ) ) {
			return;
		}

		// 3. Group by comment_post_ID, skipping any post not in the map.
		$by_post = array();
		foreach ( $rows as $row ) {
			$post_id = (int) $row->comment_post_ID;
			if ( isset( $post_path_map[ $post_id ] ) ) {
				$by_post[ $post_id ][] = $row;
			}
		}

		if ( empty( $by_post ) ) {
			return;
		}

		// 4. Neutralise smilies without mutating filter chain.
		add_filter( 'option_use_smilies', '__return_false' );

		try {
			foreach ( $by_post as $post_id => $post_comments ) {
				$md_path      = $post_path_map[ $post_id ];
				$comment_path = 'comments/' . substr( $md_path, 0, -3 ) . '.json';

				$formatted_comments = array();
				$max_timestamp      = 0;

				foreach ( $post_comments as $row ) {
					// 5. Render comment through WordPress filter chain.
					$comment = new WP_Comment( $row );
					$html    = apply_filters( 'comment_text', $row->comment_content, $comment, array() );

					// 6. Convert HTML to Markdown with CommonMark hard breaks for <br>.
					$html = self::promote_multiline_code_blocks( $html );

					$markdown = Push_MD_HTML_Converter::with_line_break(
						"  \n",
						function () use ( $html ) {
							return Push_MD_HTML_Converter::convert_fragment( $html );
						}
					);

					$markdown = trim( $markdown );

					// Date parsing.
					$date_timestamp = self::timestamp_from_gmt_string( $row->comment_date_gmt );
					if ( false === $date_timestamp ) {
						$date_timestamp = 0;
					}
					$max_timestamp = max( $max_timestamp, $date_timestamp );
					$date_iso      = gmdate( 'Y-m-d\TH:i:s\Z', $date_timestamp );

					// 7. Clean author fields.
					$author_name = html_entity_decode( $row->comment_author, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					$author_url  = self::sanitize_author_url( $row->comment_author_url );

					// Normalise raw line endings.
					$raw_content = str_replace( array( "\r\n", "\r" ), "\n", $row->comment_content );

					$formatted_comments[] = array(
						'id'       => (int) $row->comment_ID,
						'parent'   => (int) $row->comment_parent,
						'date'     => $date_iso,
						'author'   => array(
							'name' => $author_name,
							'url'  => $author_url,
						),
						'markdown' => $markdown,
						'raw'      => $raw_content,
					);
				}

				// 8. Encode JSON.
				$json = wp_json_encode(
					$formatted_comments,
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
				);

				if ( false === $json ) {
					throw new Exception(
						'Push MD comment export failed: could not encode JSON for ' . esc_html( $comment_path ) . ': ' . json_last_error_msg()
					);
				}

				// 9. Write the entry.
				$files[ $comment_path ] = array(
					'post'               => null,
					'mode'               => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
					'content'            => $json . "\n",
					'modified_timestamp' => $max_timestamp,
				);
			}
		} finally {
			remove_filter( 'option_use_smilies', '__return_false' );
		}
	}

	/**
	 * Promote a multi-line <code> block into <pre> so it converts to a fenced block.
	 *
	 * WordPress runs wpautop over the comment, which turns the newlines inside a
	 * multi-line <code> element into <br> tags. Push_MD_HTML_Converter then preserves
	 * that element as raw HTML, because a <code> whose inner HTML contains a tag is
	 * preserved verbatim. Raw HTML in the exported Markdown defeats rendering with
	 * HTML disabled, so rewrite the element as <pre>, which the converter emits as a
	 * fenced code block.
	 *
	 * Left alone when the comment already contains a <pre>, which the converter
	 * handles correctly on its own.
	 *
	 * @param string $html Rendered comment HTML.
	 * @return string HTML with multi-line code blocks promoted to <pre>.
	 */
	private static function promote_multiline_code_blocks( $html ) {
		if ( false === stripos( $html, '<code' ) || false === stripos( $html, '<br' ) ) {
			return $html;
		}
		if ( false !== stripos( $html, '<pre' ) ) {
			return $html;
		}

		return preg_replace_callback(
			'#<code\b[^>]*>(.*?)</code>#is',
			function ( $matches ) {
				if ( ! preg_match( '/<br\s*\/?>/i', $matches[1] ) ) {
					return $matches[0];
				}

				// Collapse "<br>" plus the newline wpautop left beside it into one
				// newline, so the code keeps its original line spacing and indentation.
				$inner = preg_replace( '/<br\s*\/?>\r?\n/i', "\n", $matches[1] );
				$inner = preg_replace( '/<br\s*\/?>/i', "\n", $inner );

				return '<pre>' . $inner . '</pre>';
			},
			$html
		);
	}

	/**
	 * Parse a MySQL GMT date string into a Unix timestamp.
	 *
	 * Handles 0000-00-00 00:00:00 dates safely by returning false.
	 *
	 * @param string $gmt_string MySQL datetime string in GMT.
	 * @return int|false Unix timestamp or false on invalid/zero date.
	 */
	private static function timestamp_from_gmt_string( $gmt_string ) {
		if ( ! is_string( $gmt_string ) || '' === $gmt_string || '0000-00-00 00:00:00' === $gmt_string ) {
			return false;
		}

		return strtotime( $gmt_string . ' UTC' );
	}

	/**
	 * Validate author URL scheme. Allows only http and https.
	 *
	 * @param string $url Author URL from database.
	 * @return string Sanitized URL or empty string.
	 */
	private static function sanitize_author_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}

		$url    = trim( $url );
		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parsed['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		return esc_url_raw( $url );
	}
}
