<?php

use PHPUnit\Framework\TestCase;
use WordPress\Git\Model\TreeEntry;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! class_exists( 'WeakReference' ) ) {
	class WeakReference {
		private $target;
		public function __construct( $target ) {
			$this->target = $target;
		}
		public static function create( $target ) {
			return new self( $target );
		}
		public function get() {
			return $this->target;
		}
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID          = 0;
		public $post_type   = 'post';
		public $post_name   = '';
		public $post_status = 'publish';
	}
}

if ( ! class_exists( 'WP_Comment' ) ) {
	class WP_Comment {
		public $comment_ID         = 0;
		public $comment_post_ID    = 0;
		public $comment_author     = '';
		public $comment_author_url = '';
		public $comment_date_gmt   = '';
		public $comment_content    = '';
		public $comment_parent     = 0;
		public $comment_approved   = '1';
		public $comment_type       = 'comment';

		public function __construct( $row = null ) {
			if ( is_object( $row ) || is_array( $row ) ) {
				foreach ( (array) $row as $k => $v ) {
					$this->$k = $v;
				}
			}
		}
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_filter'][ $tag ][ $priority ][] = array(
			'function'      => $function_to_add,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $function_to_check = false ) {
		if ( empty( $GLOBALS['wp_filter'][ $tag ] ) ) {
			return false;
		}
		if ( false === $function_to_check ) {
			return true;
		}
		foreach ( $GLOBALS['wp_filter'][ $tag ] as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				if ( $cb['function'] === $function_to_check ) {
					return $priority;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $tag, $function_to_remove, $priority = 10 ) {
		if ( isset( $GLOBALS['wp_filter'][ $tag ][ $priority ] ) ) {
			foreach ( $GLOBALS['wp_filter'][ $tag ][ $priority ] as $idx => $cb ) {
				if ( $cb['function'] === $function_to_remove ) {
					unset( $GLOBALS['wp_filter'][ $tag ][ $priority ][ $idx ] );
					return true;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) {
		$args = func_get_args();
		array_shift( $args );
		if ( empty( $GLOBALS['wp_filter'][ $tag ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['wp_filter'][ $tag ] );
		foreach ( $GLOBALS['wp_filter'][ $tag ] as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$accepted  = isset( $cb['accepted_args'] ) ? $cb['accepted_args'] : 1;
				$call_args = array_slice( $args, 0, $accepted );
				$value     = call_user_func_array( $cb['function'], $call_args );
				$args[0]   = $value;
			}
		}

		return $value;
	}
}

if ( ! function_exists( '__return_false' ) ) {
	function __return_false() {
		return false;
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( $post_type, array( 'post', 'page', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_theme', 'wp_global_styles' ), true );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		unset( $taxonomy );
		return false;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-path-filter.php';
require_once dirname( __DIR__ ) . '/class-push-md-html-converter.php';
require_once dirname( __DIR__ ) . '/class-push-md-comments.php';

/**
 * Unit tests for Push_MD_Comments.
 */
class CommentsExportTest extends TestCase {

	/**
	 * @before
	 */
	public function setUpTest() {
		$GLOBALS['wp_filter'] = array();

		// Default comment_text mock mimicking core: wpautop + make_clickable.
		add_filter(
			'comment_text',
			function ( $content ) {
				// Simple make_clickable mock.
				$content = preg_replace( '/(https?:\/\/[^\s<]+)/i', '<a href="$1" rel="nofollow ugc">$1</a>', $content );
				// Simple wpautop mock.
				$paragraphs = explode( "\n\n", str_replace( array( "\r\n", "\r" ), "\n", $content ) );
				$out        = array();
				foreach ( $paragraphs as $p ) {
					$p = trim( $p );
					if ( '' === $p ) {
						continue;
					}
					$p     = str_replace( "\n", "<br />\n", $p );
					$out[] = '<p>' . $p . '</p>';
				}
				return implode( "\n", $out );
			},
			10,
			1
		);
	}

	/**
	 * @after
	 */
	public function tearDownTest() {
		$GLOBALS['wp_filter'] = array();
		unset( $GLOBALS['wpdb'] );
	}

	private function mock_db_with_comments( array $rows ) {
		$mock_db           = new stdClass();
		$mock_db->comments = 'wp_comments';
		$mock_db->rows     = $rows;

		$mock_db_obj = new class( $rows ) {
			public $comments = 'wp_comments';
			public $last_query = '';
			private $rows;

			public function __construct( $rows ) {
				$this->rows = $rows;
			}

			public function get_results( $query ) {
				$this->last_query = $query;
				// Filter rows matching WHERE clause: comment_approved = '1' AND ( comment_type = '' OR comment_type = 'comment' ).
				$filtered = array();
				foreach ( $this->rows as $r ) {
					$r_obj  = (object) $r;
					$status = isset( $r_obj->comment_approved ) ? $r_obj->comment_approved : '1';
					$type   = isset( $r_obj->comment_type ) ? $r_obj->comment_type : '';
					if ( '1' === $status && ( '' === $type || 'comment' === $type ) ) {
						$filtered[] = $r_obj;
					}
				}
				return $filtered;
			}
		};

		$GLOBALS['wpdb'] = $mock_db_obj;
	}

	/**
	 * Test 1: Nested page page/parent/child.md produces comments/page/parent/child.json.
	 */
	public function test_nested_page_path_derivation() {
		$post     = new WP_Post();
		$post->ID = 101;

		$files = array(
			'page/parent/child.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Child Page',
			),
		);

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 1,
					'comment_post_ID'    => 101,
					'comment_author'     => 'Alice',
					'comment_author_url' => 'https://example.com',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => 'Great article!',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => 'comment',
				),
			)
		);

		Push_MD_Comments::add_comment_files( $files );

		$this->assertArrayHasKey( 'comments/page/parent/child.json', $files );
		$decoded = json_decode( $files['comments/page/parent/child.json']['content'], true );
		$this->assertIsArray( $decoded );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 1, $decoded[0]['id'] );
		$this->assertSame( 'Alice', $decoded[0]['author']['name'] );
	}

	/**
	 * Test 2: wp_template (.html) and wp_global_styles (.json) entries are skipped.
	 */
	public function test_non_markdown_entries_skipped() {
		$template_post     = new WP_Post();
		$template_post->ID = 201;

		$styles_post     = new WP_Post();
		$styles_post->ID = 202;

		$files = array(
			'wp_template/single.html'     => array(
				'post'    => $template_post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '<!-- wp:template /-->',
			),
			'wp_global_styles/theme.json' => array(
				'post'    => $styles_post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '{}',
			),
		);

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 2,
					'comment_post_ID'    => 201,
					'comment_author'     => 'Bob',
					'comment_author_url' => '',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => 'Template comment',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => '',
				),
			)
		);

		Push_MD_Comments::add_comment_files( $files );

		$this->assertArrayNotHasKey( 'comments/wp_template/single.json', $files );
		$this->assertArrayNotHasKey( 'comments/wp_global_styles/theme.json', $files );
	}

	/**
	 * Test 3: <br> becomes "  \n" and a blank line becomes "\n\n".
	 */
	public function test_line_breaks_vs_blank_lines() {
		$post     = new WP_Post();
		$post->ID = 301;

		$files = array(
			'post/my-post.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Post',
			),
		);

		// Two lines with a single newline (producing <br />), followed by blank line and second paragraph.
		$comment_content = "Line one\nLine two\n\nSecond paragraph";

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 3,
					'comment_post_ID'    => 301,
					'comment_author'     => 'Charlie',
					'comment_author_url' => '',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => $comment_content,
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => '',
				),
			)
		);

		Push_MD_Comments::add_comment_files( $files );

		$decoded  = json_decode( $files['comments/post/my-post.json']['content'], true );
		$markdown = $decoded[0]['markdown'];

		// Hard break check: Line one followed by two spaces and a newline.
		$this->assertStringContainsString( "Line one  \nLine two", $markdown );
		// Paragraph break check: two newlines before the second paragraph.
		$this->assertStringContainsString( "\n\nSecond paragraph", $markdown );
	}

	/**
	 * Test 4: wpautop and make_clickable output survives into markdown.
	 */
	public function test_wpautop_and_make_clickable_survive_into_markdown() {
		$post     = new WP_Post();
		$post->ID = 401;

		$files = array(
			'post/links-post.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Links',
			),
		);

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 4,
					'comment_post_ID'    => 401,
					'comment_author'     => 'Dave',
					'comment_author_url' => '',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => 'Check out https://wordpress.org for details.',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => '',
				),
			)
		);

		Push_MD_Comments::add_comment_files( $files );

		$decoded  = json_decode( $files['comments/post/links-post.json']['content'], true );
		$markdown = $decoded[0]['markdown'];

		$this->assertStringContainsString( '<https://wordpress.org>', $markdown );
	}

	/**
	 * Test 5: :-) stays text with use_smilies on, and option_use_smilies is unfiltered afterwards.
	 */
	public function test_smilies_neutralised_and_restored() {
		$post     = new WP_Post();
		$post->ID = 501;

		$files = array(
			'post/smilies-post.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Smilies',
			),
		);

		// Attach convert_smilies mock filter simulating core behavior.
		add_filter(
			'comment_text',
			function ( $content ) {
				$use_smilies = apply_filters( 'option_use_smilies', true );
				if ( $use_smilies ) {
					$content = str_replace( ':-)', '<img src="http://example.org/wp-includes/images/smilies/icon_wink.gif" />', $content );
				}
				return $content;
			},
			20
		);

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 5,
					'comment_post_ID'    => 501,
					'comment_author'     => 'Eve',
					'comment_author_url' => '',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => 'Nice work :-)',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => '',
				),
			)
		);

		Push_MD_Comments::add_comment_files( $files );

		$decoded  = json_decode( $files['comments/post/smilies-post.json']['content'], true );
		$markdown = $decoded[0]['markdown'];

		$this->assertStringContainsString( ':-)', $markdown );
		$this->assertStringNotContainsString( '<img', $markdown );

		// Verify option_use_smilies filter was removed in finally block.
		$this->assertTrue( apply_filters( 'option_use_smilies', true ), 'option_use_smilies must be restored to default true' );
	}

	/**
	 * Test 6: An author name containing &raquo; decodes to ».
	 */
	public function test_author_name_decodes_entities() {
		$post     = new WP_Post();
		$post->ID = 601;

		$files = array(
			'post/entity-post.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Entities',
			),
		);

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 6,
					'comment_post_ID'    => 601,
					'comment_author'     => 'Tech &raquo; Blog',
					'comment_author_url' => '',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => 'Interesting post',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => '',
				),
			)
		);

		Push_MD_Comments::add_comment_files( $files );

		$decoded = json_decode( $files['comments/post/entity-post.json']['content'], true );
		$this->assertSame( 'Tech » Blog', $decoded[0]['author']['name'] );
	}

	/**
	 * Test 7: A javascript: author URL becomes "".
	 */
	public function test_invalid_url_scheme_becomes_empty() {
		$post     = new WP_Post();
		$post->ID = 701;

		$files = array(
			'post/xss-post.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# XSS',
			),
		);

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 7,
					'comment_post_ID'    => 701,
					'comment_author'     => 'Hacker',
					'comment_author_url' => 'javascript:alert(1)',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => 'Free money',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => '',
				),
			)
		);

		Push_MD_Comments::add_comment_files( $files );

		$decoded = json_decode( $files['comments/post/xss-post.json']['content'], true );
		$this->assertSame( '', $decoded[0]['author']['url'] );
	}

	/**
	 * Test 8: Pingbacks and trackbacks are absent from the array.
	 */
	public function test_pingbacks_and_trackbacks_excluded() {
		$post     = new WP_Post();
		$post->ID = 801;

		$files = array(
			'post/pingback-post.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Pingbacks',
			),
		);

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 80,
					'comment_post_ID'    => 801,
					'comment_author'     => 'Legit Commenter',
					'comment_author_url' => 'https://legit.org',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => 'Real comment',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => 'comment',
				),
				array(
					'comment_ID'         => 81,
					'comment_post_ID'    => 801,
					'comment_author'     => 'Other Blog',
					'comment_author_url' => 'https://otherblog.org',
					'comment_date_gmt'   => '2026-01-01 12:05:00',
					'comment_content'    => '[...] Pingback text [...]',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => 'pingback',
				),
				array(
					'comment_ID'         => 82,
					'comment_post_ID'    => 801,
					'comment_author'     => 'Trackback Blog',
					'comment_author_url' => 'https://trackback.org',
					'comment_date_gmt'   => '2026-01-01 12:10:00',
					'comment_content'    => '[...] Trackback text [...]',
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => 'trackback',
				),
			)
		);

		Push_MD_Comments::add_comment_files( $files );

		$decoded = json_decode( $files['comments/post/pingback-post.json']['content'], true );
		$this->assertCount( 1, $decoded );
		$this->assertSame( 80, $decoded[0]['id'] );
		$this->assertSame( 'Legit Commenter', $decoded[0]['author']['name'] );
	}

	/**
	 * Test 9: Malformed UTF-8 throws Exception.
	 */
	public function test_malformed_utf8_throws_exception() {
		$post     = new WP_Post();
		$post->ID = 901;

		$files = array(
			'post/malformed-post.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Malformed',
			),
		);

		$this->mock_db_with_comments(
			array(
				array(
					'comment_ID'         => 9,
					'comment_post_ID'    => 901,
					'comment_author'     => "Invalid \xB1\x31 UTF8",
					'comment_author_url' => '',
					'comment_date_gmt'   => '2026-01-01 12:00:00',
					'comment_content'    => "Bad \xB1\x31 content",
					'comment_parent'     => 0,
					'comment_approved'   => '1',
					'comment_type'       => '',
				),
			)
		);

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Push MD comment export failed' );

		Push_MD_Comments::add_comment_files( $files );
	}

	/**
	 * Test 10: Two consecutive exports produce byte-identical JSON.
	 */
	public function test_consecutive_exports_byte_identical() {
		$post     = new WP_Post();
		$post->ID = 1001;

		$comments_data = array(
			array(
				'comment_ID'         => 101,
				'comment_post_ID'    => 1001,
				'comment_author'     => 'Alice',
				'comment_author_url' => 'https://alice.org',
				'comment_date_gmt'   => '2026-01-01 10:00:00',
				'comment_content'    => "First comment\nWith line break",
				'comment_parent'     => 0,
				'comment_approved'   => '1',
				'comment_type'       => 'comment',
			),
			array(
				'comment_ID'         => 102,
				'comment_post_ID'    => 1001,
				'comment_author'     => 'Bob',
				'comment_author_url' => 'https://bob.org',
				'comment_date_gmt'   => '2026-01-01 11:00:00',
				'comment_content'    => 'Second comment reply',
				'comment_parent'     => 101,
				'comment_approved'   => '1',
				'comment_type'       => 'comment',
			),
		);

		$files1 = array(
			'post/idempotent.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Idempotent',
			),
		);
		$this->mock_db_with_comments( $comments_data );
		Push_MD_Comments::add_comment_files( $files1 );

		$files2 = array(
			'post/idempotent.md' => array(
				'post'    => $post,
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => '# Idempotent',
			),
		);
		$this->mock_db_with_comments( $comments_data );
		Push_MD_Comments::add_comment_files( $files2 );

		$this->assertSame(
			$files1['comments/post/idempotent.json']['content'],
			$files2['comments/post/idempotent.json']['content']
		);
	}

	/**
	 * Test 11: A push touching comments/ is ignored.
	 */
	public function test_push_safety_comments_ignored() {
		$comment_path = 'comments/post/hello-world.json';
		$this->assertFalse( Push_MD_Path_Filter::is_supported_path( $comment_path ) );
		$this->assertTrue( Push_MD_Path_Filter::is_ignored_path( $comment_path ) );
	}

	/**
	 * Test 12: Post export output is unchanged by the converter edit.
	 */
	public function test_post_export_unaltered_by_converter() {
		// Default converter must use \n for BR, not two spaces.
		$html     = '<p>Hello<br>World</p>';
		$markdown = Push_MD_HTML_Converter::convert( $html );
		$this->assertSame( "Hello\nWorld", trim( $markdown ) );
	}
}
