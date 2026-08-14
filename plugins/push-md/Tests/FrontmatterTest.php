<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return false !== strpos( $email, '@' );
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) {
		return isset( $GLOBALS['mock_wp_transients'][ $transient ] ) ? $GLOBALS['mock_wp_transients'][ $transient ] : false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) {
		unset( $expiration );
		$GLOBALS['mock_wp_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) {
		unset( $GLOBALS['mock_wp_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		$posts   = isset( $GLOBALS['mock_wp_posts'] ) && is_array( $GLOBALS['mock_wp_posts'] ) ? $GLOBALS['mock_wp_posts'] : array();
		$results = array();
		foreach ( $posts as $post ) {
			if ( isset( $args['post_type'] ) && $post->post_type !== $args['post_type'] ) {
				continue;
			}
			if ( isset( $args['name'] ) && $post->post_name !== $args['name'] ) {
				continue;
			}
			if ( isset( $args['post_parent'] ) && intval( $post->post_parent ) !== intval( $args['post_parent'] ) ) {
				continue;
			}
			if ( isset( $args['post_status'] ) ) {
				$statuses = is_array( $args['post_status'] ) ? $args['post_status'] : array( $args['post_status'] );
				if ( ! in_array( $post->post_status, $statuses, true ) ) {
					continue;
				}
			}
			if ( isset( $args['exclude'] ) && is_array( $args['exclude'] ) && in_array( intval( $post->ID ), $args['exclude'], true ) ) {
				continue;
			}
			if ( ! empty( $args['fields'] ) && 'ids' === $args['fields'] ) {
				$results[] = $post->ID;
			} else {
				$results[] = $post;
			}
		}

		return $results;
	}
}

$GLOBALS['mock_users']             = array();
$GLOBALS['mock_categories']        = array();
$GLOBALS['mock_tags']              = array();
$GLOBALS['mock_post_terms']        = array();
$GLOBALS['mock_cannot_edit_users'] = false;
$GLOBALS['mock_current_user_id']   = 1;

function init_mock_users() {
	$admin                = new stdClass();
	$admin->ID            = 1;
	$admin->user_login    = 'admin';
	$admin->display_name  = 'Admin User';
	$admin->first_name    = 'Admin';
	$admin->last_name     = 'User';
	$admin->user_email    = 'admin@example.com';
	$admin->user_nicename = 'admin';
	$admin->roles         = array( 'administrator' );
	$admin->description   = 'Administrator Bio';

	$author                = new stdClass();
	$author->ID            = 2;
	$author->user_login    = 'author_jane';
	$author->display_name  = 'Author Jane';
	$author->first_name    = 'Jane';
	$author->last_name     = 'Doe';
	$author->user_email    = 'jane@example.com';
	$author->user_nicename = 'author_jane';
	$author->roles         = array( 'author' );
	$author->description   = 'Jane Bio';

	$sub                = new stdClass();
	$sub->ID            = 3;
	$sub->user_login    = 'subscriber_bob';
	$sub->display_name  = 'Subscriber Bob';
	$sub->first_name    = 'Bob';
	$sub->last_name     = 'Smith';
	$sub->user_email    = 'bob@example.com';
	$sub->user_nicename = 'subscriber_bob';
	$sub->roles         = array( 'subscriber' );
	$sub->description   = 'Subscriber Bio';

	$GLOBALS['mock_users']             = array( 1 => $admin, 2 => $author, 3 => $sub );
	$GLOBALS['mock_cannot_edit_users'] = false;
	$GLOBALS['mock_current_user_id']   = 1;
}

function init_mock_terms() {
	$c1              = new stdClass();
	$c1->term_id     = 10;
	$c1->name        = 'News';
	$c1->slug        = 'news';
	$c1->description = 'News category';
	$c1->parent      = 0;

	$c2              = new stdClass();
	$c2->term_id     = 11;
	$c2->name        = 'Tech';
	$c2->slug        = 'tech';
	$c2->description = 'Tech category';
	$c2->parent      = 10;

	$GLOBALS['mock_categories'] = array( 10 => $c1, 11 => $c2 );

	$t1              = new stdClass();
	$t1->term_id     = 20;
	$t1->name        = 'WordPress';
	$t1->slug        = 'wordpress';
	$t1->description = 'WordPress tag';

	$GLOBALS['mock_tags']       = array( 20 => $t1 );
	$GLOBALS['mock_post_terms'] = array();
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return isset( $GLOBALS['mock_current_user_id'] ) ? intval( $GLOBALS['mock_current_user_id'] ) : 1;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, $arg = null ) {
		unset( $arg );
		if ( ! empty( $GLOBALS['mock_cannot_edit_users'] ) ) {
			if ( 'edit_user' === $capability || 'edit_users' === $capability || 'manage_categories' === $capability ) {
				return false;
			}
		}

		return true;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( $user, $capability ) {
		unset( $capability );
		$user_id = is_object( $user ) ? $user->ID : intval( $user );
		$u       = get_userdata( $user_id );
		if ( ! $u ) {
			return false;
		}
		if ( isset( $u->roles ) && in_array( 'subscriber', $u->roles, true ) ) {
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( $post_type ) {
		$obj                  = new stdClass();
		$obj->name            = $post_type;
		$obj->cap             = new stdClass();
		$obj->cap->edit_posts = 'page' === $post_type ? 'edit_pages' : 'edit_posts';

		return $obj;
	}
}

if ( ! function_exists( 'get_taxonomy' ) ) {
	function get_taxonomy( $taxonomy ) {
		$tax                    = new stdClass();
		$tax->name              = $taxonomy;
		$tax->cap               = new stdClass();
		$tax->cap->manage_terms = 'manage_categories';

		return $tax;
	}
}

if ( ! function_exists( 'wp_update_user' ) ) {
	function wp_update_user( $userdata ) {
		$user_id = intval( $userdata['ID'] );
		if ( isset( $GLOBALS['mock_users'][ $user_id ] ) ) {
			if ( isset( $userdata['display_name'] ) ) {
				$GLOBALS['mock_users'][ $user_id ]->display_name = $userdata['display_name'];
			}
			if ( isset( $userdata['first_name'] ) ) {
				$GLOBALS['mock_users'][ $user_id ]->first_name = $userdata['first_name'];
			}
			if ( isset( $userdata['last_name'] ) ) {
				$GLOBALS['mock_users'][ $user_id ]->last_name = $userdata['last_name'];
			}
			if ( isset( $userdata['user_email'] ) ) {
				$GLOBALS['mock_users'][ $user_id ]->user_email = $userdata['user_email'];
			}
			if ( isset( $userdata['description'] ) ) {
				$GLOBALS['mock_users'][ $user_id ]->description = $userdata['description'];
			}
		}

		return $user_id;
	}
}

if ( ! function_exists( 'wp_insert_term' ) ) {
	function wp_insert_term( $term, $taxonomy, $args = array() ) {
		if ( empty( $GLOBALS['mock_categories'] ) && empty( $GLOBALS['mock_tags'] ) ) {
			init_mock_terms();
		}
		$new_id         = rand( 100, 999 );
		$t              = new stdClass();
		$t->term_id     = $new_id;
		$t->name        = $term;
		$t->slug        = isset( $args['slug'] ) ? $args['slug'] : sanitize_title( $term );
		$t->description = isset( $args['description'] ) ? $args['description'] : '';
		$t->parent      = isset( $args['parent'] ) ? intval( $args['parent'] ) : 0;

		if ( 'category' === $taxonomy ) {
			$GLOBALS['mock_categories'][ $new_id ] = $t;
		} elseif ( 'post_tag' === $taxonomy ) {
			$GLOBALS['mock_tags'][ $new_id ] = $t;
		}

		return array( 'term_id' => $new_id, 'term_taxonomy_id' => $new_id );
	}
}

if ( ! function_exists( 'wp_update_term' ) ) {
	function wp_update_term( $term_id, $taxonomy, $args = array() ) {
		if ( empty( $GLOBALS['mock_categories'] ) && empty( $GLOBALS['mock_tags'] ) ) {
			init_mock_terms();
		}
		$term_id = intval( $term_id );
		$store   = ( 'category' === $taxonomy ) ? 'mock_categories' : 'mock_tags';

		if ( isset( $GLOBALS[ $store ][ $term_id ] ) ) {
			if ( isset( $args['name'] ) ) {
				$GLOBALS[ $store ][ $term_id ]->name = $args['name'];
			}
			if ( isset( $args['slug'] ) ) {
				$GLOBALS[ $store ][ $term_id ]->slug = $args['slug'];
			}
			if ( isset( $args['description'] ) ) {
				$GLOBALS[ $store ][ $term_id ]->description = $args['description'];
			}
			if ( isset( $args['parent'] ) ) {
				$GLOBALS[ $store ][ $term_id ]->parent = intval( $args['parent'] );
			}
		}

		return array( 'term_id' => $term_id, 'term_taxonomy_id' => $term_id );
	}
}

if ( ! function_exists( 'wp_delete_term' ) ) {
	function wp_delete_term( $term_id, $taxonomy ) {
		$term_id = intval( $term_id );
		$store   = ( 'category' === $taxonomy ) ? 'mock_categories' : 'mock_tags';
		if ( isset( $GLOBALS[ $store ][ $term_id ] ) ) {
			unset( $GLOBALS[ $store ][ $term_id ] );

			return true;
		}

		return false;
	}
}

if ( ! function_exists( 'wp_set_object_terms' ) ) {
	function wp_set_object_terms( $object_id, $terms, $taxonomy ) {
		$GLOBALS['mock_post_terms'][ $object_id ][ $taxonomy ] = (array) $terms;

		return (array) $terms;
	}
}

if ( ! function_exists( 'wp_set_post_tags' ) ) {
	function wp_set_post_tags( $post_id, $tags, $append = false ) {
		unset( $append );
		$GLOBALS['mock_post_terms'][ $post_id ]['post_tag'] = (array) $tags;

		return true;
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID                = 0;
		public $post_type         = 'post';
		public $post_name         = '';
		public $post_parent       = 0;
		public $post_author       = 1;
		public $post_title        = '';
		public $post_excerpt      = '';
		public $post_status       = 'publish';
		public $post_content      = '';
		public $post_date_gmt     = '2026-01-01 00:00:00';
		public $post_modified     = '2026-01-01 00:00:00';
		public $post_modified_gmt = '2026-01-01 00:00:00';
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		$title = strtolower( (string) $title );
		$title = preg_replace( '/[^a-z0-9_-]+/', '-', $title );

		return trim( $title, '-' );
	}
}

if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $post_type ) {
		return in_array( $post_type, array( 'post', 'page' ), true );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( $taxonomy, array( 'category', 'post_tag' ), true );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) {
		unset( $option );

		return $default;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}

$GLOBALS['wp_filter']      = array();
$GLOBALS['mock_post_meta'] = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wp_filter'][ $tag ][ $priority ][] = array(
			'function'      => $function_to_add,
			'accepted_args' => $accepted_args,
		);

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $function_to_add, $priority = 10, $accepted_args = 1 ) {
		return add_filter( $tag, $function_to_add, $priority, $accepted_args );
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
				$call_args = array_slice( $args, 0, $cb['accepted_args'] );
				$value     = call_user_func_array( $cb['function'], $call_args );
				$args[0]   = $value;
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {
		$args = func_get_args();
		array_shift( $args );
		if ( empty( $GLOBALS['wp_filter'][ $tag ] ) ) {
			return;
		}

		ksort( $GLOBALS['wp_filter'][ $tag ] );
		foreach ( $GLOBALS['wp_filter'][ $tag ] as $priority => $callbacks ) {
			foreach ( $callbacks as $cb ) {
				$call_args = array_slice( $args, 0, $cb['accepted_args'] );
				call_user_func_array( $cb['function'], $call_args );
			}
		}
	}
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( $field, $value ) {
		if ( empty( $GLOBALS['mock_users'] ) ) {
			init_mock_users();
		}
		foreach ( $GLOBALS['mock_users'] as $user ) {
			if ( 'login' === $field && $user->user_login === $value ) {
				return $user;
			}
			if ( 'slug' === $field && $user->user_nicename === $value ) {
				return $user;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) {
		if ( empty( $GLOBALS['mock_users'] ) ) {
			init_mock_users();
		}
		$id = intval( $user_id );
		if ( isset( $GLOBALS['mock_users'][ $id ] ) ) {
			return $GLOBALS['mock_users'][ $id ];
		}

		return false;
	}
}

if ( ! function_exists( 'get_users' ) ) {
	function get_users() {
		if ( empty( $GLOBALS['mock_users'] ) ) {
			init_mock_users();
		}

		return array_values( $GLOBALS['mock_users'] );
	}
}

if ( ! function_exists( 'get_user_meta' ) ) {
	function get_user_meta( $user_id, $key, $single = false ) {
		unset( $single );
		$user = get_userdata( $user_id );
		if ( $user && isset( $user->$key ) ) {
			return $user->$key;
		}

		return '';
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms( $args = array() ) {
		if ( empty( $GLOBALS['mock_categories'] ) && empty( $GLOBALS['mock_tags'] ) ) {
			init_mock_terms();
		}

		$tax   = isset( $args['taxonomy'] ) ? $args['taxonomy'] : 'category';
		$store = ( 'category' === $tax ) ? $GLOBALS['mock_categories'] : $GLOBALS['mock_tags'];

		if ( isset( $args['parent'] ) ) {
			$filtered = array();
			foreach ( $store as $term ) {
				if ( isset( $term->parent ) && intval( $term->parent ) === intval( $args['parent'] ) ) {
					$filtered[] = $term;
				}
			}

			return array_values( $filtered );
		}

		return array_values( $store );
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy = '' ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy ) );
		foreach ( $terms as $term ) {
			if ( 'slug' === $field && $term->slug === $value ) {
				return $term;
			}
			if ( 'name' === $field && 0 === strcasecmp( $term->name, $value ) ) {
				return $term;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		$terms = get_terms( array( 'taxonomy' => $taxonomy ) );
		foreach ( $terms as $term ) {
			if ( $term->term_id === intval( $term_id ) ) {
				return $term;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'get_ancestors' ) ) {
	function get_ancestors( $term_id, $taxonomy = '', $type = '' ) {
		unset( $taxonomy, $type );
		if ( 11 === intval( $term_id ) ) {
			return array( 10 );
		}

		return array();
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		$post_id = intval( $post_id );
		if ( isset( $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ) ) {
			return $GLOBALS['mock_post_meta'][ $post_id ][ $key ];
		}
		if ( 'rank_math_title' === $key || '_yoast_wpseo_title' === $key ) {
			return 'SEO Title';
		}
		if ( 'rank_math_description' === $key || '_yoast_wpseo_metadesc' === $key ) {
			return 'SEO Description';
		}
		if ( 'rank_math_focus_keyword' === $key || '_yoast_wpseo_focuskw' === $key ) {
			return 'SEO Keyword';
		}

		return '';
	}
}

if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) {
		$post_id                                       = intval( $post_id );
		$GLOBALS['mock_post_meta'][ $post_id ][ $key ] = $value;

		return true;
	}
}

if ( ! function_exists( 'get_the_terms' ) ) {
	function get_the_terms( $post_id, $taxonomy ) {
		unset( $post_id );

		return get_terms( array( 'taxonomy' => $taxonomy ) );
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post_id ) {
		unset( $post_id );

		return 50;
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		if ( 50 === intval( $attachment_id ) ) {
			return 'https://example.com/image.jpg';
		}

		return false;
	}
}

if ( ! function_exists( 'attachment_url_to_postid' ) ) {
	function attachment_url_to_postid( $url ) {
		if ( 'https://example.com/image.jpg' === $url ) {
			return 50;
		}

		return 0;
	}
}

if ( ! function_exists( 'wp_list_pluck' ) ) {
	function wp_list_pluck( $list, $field ) {
		$output = array();
		foreach ( $list as $item ) {
			if ( is_object( $item ) && isset( $item->$field ) ) {
				$output[] = $item->$field;
			}
		}

		return $output;
	}
}

require_once dirname( __DIR__ ) . '/class-push-md-html-converter.php';
require_once dirname( __DIR__ ) . '/class-push-md-markdown-producer.php';
require_once dirname( __DIR__ ) . '/class-push-md-markdown-consumer.php';
require_once dirname( __DIR__ ) . '/class-push-md-seo.php';
require_once dirname( __DIR__ ) . '/class-push-md-draft-previews.php';
require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';

class FrontmatterTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['wp_filter']      = array();
		$GLOBALS['mock_post_meta'] = array();
		init_mock_users();
		init_mock_terms();
		Push_MD_Plugin::bootstrap();
	}

	private function invoke_private( $method, $args = array() ) {
		$target_class = method_exists( Push_MD_Master_Metadata::class, $method ) ? Push_MD_Master_Metadata::class : Push_MD_Plugin::class;
		$reflection   = new ReflectionClass( $target_class );
		$m            = $reflection->getMethod( $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}

		return $m->invokeArgs( null, $args );
	}

	public function testIsMasterMetadataPathIdentifiesMasterFiles() {
		$this->assertTrue( $this->invoke_private( 'is_master_metadata_path', array( 'categories.md' ) ) );
		$this->assertTrue( $this->invoke_private( 'is_master_metadata_path', array( 'tags.md' ) ) );
		$this->assertTrue( $this->invoke_private( 'is_master_metadata_path', array( 'authors.md' ) ) );
		$this->assertFalse( $this->invoke_private( 'is_master_metadata_path', array( 'post/test.md' ) ) );
	}

	public function testResolveFrontmatterAuthorIdResolvesAdminUser() {
		$author_id = $this->invoke_private( 'resolve_frontmatter_author_id', array( 'admin' ) );
		$this->assertEquals( 1, $author_id );
	}

	public function testResolveCategoryPathToTermIdResolvesNestedCategories() {
		$parent_id = $this->invoke_private( 'resolve_category_path_to_term_id', array( 'News' ) );
		$this->assertEquals( 10, $parent_id );

		$child_id = $this->invoke_private( 'resolve_category_path_to_term_id', array( 'News > Tech' ) );
		$this->assertEquals( 11, $child_id );
	}

	public function testResolveTagNameToTermIdResolvesTag() {
		$tag_id = $this->invoke_private( 'resolve_tag_name_to_term_id', array( 'WordPress' ) );
		$this->assertEquals( 20, $tag_id );
	}

	public function testParseFrontmatterListParsesCommaSeparatedAndJsonArrays() {
		$parsed_comma = $this->invoke_private( 'parse_frontmatter_list', array( 'News > Tech, Reviews' ) );
		$this->assertEquals( array( 'News > Tech', 'Reviews' ), $parsed_comma );

		$parsed_json = $this->invoke_private( 'parse_frontmatter_list', array( '["News > Tech", "Reviews"]' ) );
		$this->assertEquals( array( 'News > Tech', 'Reviews' ), $parsed_json );
	}

	public function testValidatePostFrontmatterReferencesRejectsUnknownCategory() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'category "UnknownCategory" is incorrect or was not found' );

		$metadata = array(
			'categories' => 'UnknownCategory',
		);
		$this->invoke_private( 'validate_post_frontmatter_references', array( $metadata, 'post', array(), 'post/my-post.md' ) );
	}

	public function testValidatePostFrontmatterReferencesRejectsUnknownAuthor() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'author "unknown_user" is incorrect or was not found' );

		$metadata = array(
			'author' => 'unknown_user',
		);
		$this->invoke_private( 'validate_post_frontmatter_references', array( $metadata, 'post', array(), 'post/my-post.md' ) );
	}

	public function testValidatePostFrontmatterReferencesRejectsUnknownTagIncludesFilePathAndReferenceGuide() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Push rejected in file "post/test.md" because tag "NonExistentTag" is incorrect or was not found in tags.md or WordPress. Please refer to tags.md in your local repository for correct values.' );

		$metadata = array(
			'tags' => 'NonExistentTag',
		);
		$this->invoke_private( 'validate_post_frontmatter_references', array( $metadata, 'post', array(), 'post/test.md' ) );
	}

	public function testNormalizeSupportedFrontmatterRejectsUnsupportedFieldWithSupportedListAndFilePath() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Push rejected in file "post/sample.md" because Markdown front matter field "invalid_field" is not supported. Supported front matter fields are: title, status, author.' );

		$metadata     = array( 'invalid_field' => 'value' );
		$allowed_keys = array( 'title', 'status', 'author' );
		$this->invoke_private( 'normalize_supported_frontmatter', array( $metadata, $allowed_keys, 'post/sample.md' ) );
	}

	public function testFrontmatterDateToMysqlGmtRejectsInvalidFormatWithFilePathAndFormatHint() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Push rejected in file "post/bad-date.md" because Markdown front matter date "invalid-date" is invalid. Expected format is YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.' );

		$metadata = array( 'date' => 'invalid-date' );
		$this->invoke_private( 'frontmatter_date_to_mysql_gmt', array( $metadata, 'post/bad-date.md' ) );
	}

	public function testExportCategoriesMarkdownIncludesParentSlugInYamlList() {
		$markdown = $this->invoke_private( 'export_categories_markdown' );
		$this->assertStringContainsString( 'categories:', $markdown );
		$this->assertStringContainsString( 'id: "10"', $markdown );
		$this->assertStringContainsString( 'slug: "tech"', $markdown );
		$this->assertStringContainsString( 'parent: "news"', $markdown );
	}

	public function testExportAuthorsMarkdownIncludesUserFieldsInYamlListAndExcludesSubscribers() {
		$markdown = $this->invoke_private( 'export_authors_markdown' );
		$this->assertStringContainsString( 'authors:', $markdown );
		$this->assertStringContainsString( '- user_login: "admin"', $markdown );
		$this->assertStringContainsString( 'display_name: "Admin User"', $markdown );
		$this->assertStringContainsString( 'first_name: "Admin"', $markdown );
		$this->assertStringContainsString( 'last_name: "User"', $markdown );
		$this->assertStringContainsString( '- user_login: "author_jane"', $markdown );
		$this->assertStringNotContainsString( 'subscriber_bob', $markdown );
		$this->assertStringNotContainsString( 'roles', $markdown );
	}

	public function testSyncAuthorsFromMarkdownUpdatesUserDataAndFirstNameLastNameInYamlList() {
		$authors_md = <<<MD
---
authors:
  - user_login: "admin"
    display_name: "Jane Doe"
    first_name: "Jane"
    last_name: "Doe"
    user_email: "jane@example.com"
    user_nicename: "admin"
    description: "Updated Bio Text"
---

# WordPress Authors Master Reference
MD;

		$this->invoke_private( 'sync_authors_from_markdown', array( $authors_md ) );

		$user = get_userdata( 1 );
		$this->assertEquals( 'Jane Doe', $user->display_name );
		$this->assertEquals( 'Jane', $user->first_name );
		$this->assertEquals( 'Doe', $user->last_name );
		$this->assertEquals( 'jane@example.com', $user->user_email );
		$this->assertEquals( 'Updated Bio Text', $user->description );

		$exported = $this->invoke_private( 'export_authors_markdown' );
		$this->assertStringContainsString( 'display_name: "Jane Doe"', $exported );
		$this->assertStringContainsString( 'first_name: "Jane"', $exported );
		$this->assertStringContainsString( 'last_name: "Doe"', $exported );
		$this->assertStringContainsString( 'user_email: "jane@example.com"', $exported );
		$this->assertStringContainsString( 'description: "Updated Bio Text"', $exported );
	}

	public function testAuthorCanUpdateOwnProfileButCannotUpdateOtherAuthor() {
		$GLOBALS['mock_current_user_id']   = 2;
		$GLOBALS['mock_cannot_edit_users'] = true;

		$own_author_md = <<<MD
---
authors:
  - user_login: "author_jane"
    display_name: "Jane Updated Bio"
    first_name: "Jane"
    last_name: "Doe"
    user_email: "jane@example.com"
    user_nicename: "author_jane"
    description: "My Updated Bio"
---

# WordPress Authors Master Reference
MD;

		$this->invoke_private( 'assert_can_sync_authors', array( $own_author_md ) );

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'do not have permission to edit author "admin"' );

		$other_author_md = <<<MD
---
authors:
  - user_login: "admin"
    display_name: "Attempted Admin Edit"
    user_email: "admin@example.com"
    user_nicename: "admin"
    description: "Hacked Bio"
---

# WordPress Authors Master Reference
MD;

		$this->invoke_private( 'assert_can_sync_authors', array( $other_author_md ) );
	}

	public function testSyncCategoriesFromMarkdownAddsUpdatesAndDeletesCategories() {
		$categories_md = <<<MD
---
categories:
  - id: "10"
    name: "News Updated"
    slug: "news"
    description: "Updated News Desc"
    parent: ""
  - name: "Sports"
    slug: "sports"
    description: "Sports category"
    parent: "news"
---

# WordPress Categories Master Reference
MD;

		$this->invoke_private( 'sync_categories_from_markdown', array( $categories_md ) );

		$news = get_term( 10, 'category' );
		$this->assertNotNull( $news );
		$this->assertEquals( 'News Updated', $news->name );
		$this->assertEquals( 'Updated News Desc', $news->description );

		$tech = get_term( 11, 'category' );
		$this->assertFalse( $tech );

		$sports = get_term_by( 'slug', 'sports', 'category' );
		$this->assertNotNull( $sports );
		$this->assertEquals( 'Sports category', $sports->description );

		$exported = $this->invoke_private( 'export_categories_markdown' );
		$this->assertStringContainsString( 'name: "News Updated"', $exported );
		$this->assertStringContainsString( 'slug: "sports"', $exported );
		$this->assertStringNotContainsString( 'slug: "tech"', $exported );
	}

	public function testSyncTagsFromMarkdownAddsUpdatesAndDeletesTags() {
		$tags_md = <<<MD
---
tags:
  - id: "20"
    name: "WordPress Pro"
    slug: "wordpress"
    description: "Updated WP Tag"
  - name: "PHP 8"
    slug: "php-8"
    description: "PHP 8 programming"
---

# WordPress Tags Master Reference
MD;

		$this->invoke_private( 'sync_tags_from_markdown', array( $tags_md ) );

		$wp_tag = get_term( 20, 'post_tag' );
		$this->assertNotNull( $wp_tag );
		$this->assertEquals( 'WordPress Pro', $wp_tag->name );
		$this->assertEquals( 'Updated WP Tag', $wp_tag->description );

		$php_tag = get_term_by( 'slug', 'php-8', 'post_tag' );
		$this->assertNotNull( $php_tag );
		$this->assertEquals( 'PHP 8 programming', $php_tag->description );

		$exported = $this->invoke_private( 'export_tags_markdown' );
		$this->assertStringContainsString( 'name: "WordPress Pro"', $exported );
		$this->assertStringContainsString( 'slug: "php-8"', $exported );
	}

	public function testAssignPostCategoriesAndTagsUpdatesPostTerms() {
		$this->invoke_private( 'assign_post_categories', array( 100, 'News > Tech' ) );
		$this->assertEquals( array( 11 ), $GLOBALS['mock_post_terms'][100]['category'] );

		$this->invoke_private( 'assign_post_tags', array( 100, 'WordPress' ) );
		$this->assertEquals( array( 'WordPress' ), $GLOBALS['mock_post_terms'][100]['post_tag'] );

		$this->invoke_private( 'assign_post_categories', array( 100, '' ) );
		$this->assertEquals( array(), $GLOBALS['mock_post_terms'][100]['category'] );
	}

	private function create_dummy_post( $args = array() ) {
		$post = new WP_Post();

		foreach ( $args as $key => $val ) {
			$post->$key = $val;
		}

		if ( ! isset( $args['ID'] ) ) {
			$post->ID = 100;
		}
		if ( ! isset( $args['post_name'] ) ) {
			$post->post_name = 'test-post';
		}
		if ( ! isset( $args['post_title'] ) ) {
			$post->post_title = 'Test Post';
		}
		if ( ! isset( $args['post_status'] ) ) {
			$post->post_status = 'publish';
		}
		if ( ! isset( $args['post_content'] ) ) {
			$post->post_content = 'Content';
		}

		return $post;
	}

	public function testExportPostToMarkdownIncludesSlugAndOnlyDescription() {
		$post     = $this->create_dummy_post(
			array(
				'ID'           => 55,
				'post_name'    => 'my-custom-post-slug',
				'post_title'   => 'My Post',
				'post_excerpt' => 'This is my excerpt text.',
			)
		);
		$markdown = Push_MD_Plugin::export_post_to_markdown( $post );
		$this->assertStringContainsString( 'slug: "my-custom-post-slug"', $markdown );
		$this->assertStringContainsString( 'description: "This is my excerpt text."', $markdown );
		$this->assertStringNotContainsString( 'excerpt:', $markdown );
	}

	public function testSeoFrontmatterExportAndImport() {
		$GLOBALS['mock_post_meta'][101] = array(
			'_yoast_wpseo_title'    => 'SEO Title',
			'_yoast_wpseo_metadesc' => 'SEO Description',
			'_yoast_wpseo_focuskw'  => 'SEO Keyword',
		);
		$post = $this->create_dummy_post(
			array(
				'ID'         => 101,
				'post_title' => 'SEO Post',
			)
		);

		$markdown = Push_MD_Plugin::export_post_to_markdown( $post );
		$this->assertStringContainsString( 'seo_title: "SEO Title"', $markdown );
		$this->assertStringContainsString( 'seo_description: "SEO Description"', $markdown );
		$this->assertStringContainsString( 'SEO Keyword', $markdown );
		$this->assertStringNotContainsString( 'seo_focus_keyword', $markdown );
		$this->assertStringNotContainsString( 'rank_math_title', $markdown );

		$metadata = array(
			'seo_title'       => 'New SEO Title',
			'seo_description' => 'New SEO Description',
			'seo_keywords'    => array( 'Primary KW', 'Secondary KW' ),
		);
		Push_MD_SEO::import_frontmatter( 101, $metadata );

		$this->assertEquals( 'New SEO Title', $GLOBALS['mock_post_meta'][101]['_yoast_wpseo_title'] );
		if ( function_exists( 'rank_math' ) && isset( $GLOBALS['mock_post_meta'][101]['rank_math_title'] ) ) {
			$this->assertEquals( 'New SEO Title', $GLOBALS['mock_post_meta'][101]['rank_math_title'] );
		}
		$this->assertEquals( 'New SEO Description', $GLOBALS['mock_post_meta'][101]['_yoast_wpseo_metadesc'] );
		if ( function_exists( 'rank_math' ) && isset( $GLOBALS['mock_post_meta'][101]['rank_math_description'] ) ) {
			$this->assertEquals( 'New SEO Description', $GLOBALS['mock_post_meta'][101]['rank_math_description'] );
		}
		$this->assertEquals( 'Primary KW', $GLOBALS['mock_post_meta'][101]['_yoast_wpseo_focuskw'] );
		$this->assertEquals( '[{"keyword":"Secondary KW","score":"ok"}]', $GLOBALS['mock_post_meta'][101]['_yoast_wpseo_focuskeywords'] );
		if ( function_exists( 'rank_math' ) && isset( $GLOBALS['mock_post_meta'][101]['rank_math_focus_keyword'] ) ) {
			$this->assertEquals( 'Primary KW, Secondary KW', $GLOBALS['mock_post_meta'][101]['rank_math_focus_keyword'] );
		}
	}

	public function test_seo_keywords_pull_and_rank_math_priority() {
		$GLOBALS['mock_post_meta'][202] = array(
			'rank_math_focus_keyword' => 'digital marketing, SEO tips, wordpress plugin',
		);

		$post     = $this->create_dummy_post( array( 'ID' => 202 ) );
		$markdown = Push_MD_Plugin::export_post_to_markdown( $post );

		$this->assertStringContainsString( 'digital marketing', $markdown );
		$this->assertStringContainsString( 'SEO tips', $markdown );
		$this->assertStringContainsString( 'wordpress plugin', $markdown );

		$metadata = array(
			'seo_keywords' => 'marketing, optimization',
		);
		Push_MD_SEO::import_frontmatter( 303, $metadata );
		$this->assertEquals( 'marketing, optimization', $GLOBALS['mock_post_meta'][303]['rank_math_focus_keyword'] );

		$normalized = $this->invoke_private( 'normalize_supported_frontmatter', array( array( 'seo_keywords' => array( 'Workflow' ) ), array( 'seo_keywords' ) ) );
		$this->assertEquals( array( 'Workflow' ), $normalized['seo_keywords'] );
	}

	public function test_yaml_list_single_and_multi_items_frontmatter() {
		$markdown_single = "---\ntitle: \"Test\"\nseo_keywords:\n  - \"Workflow\"\n---\n\nContent";
		$parsed_single   = $this->invoke_private( 'parse_frontmatter_block_local', array( $markdown_single ) );
		$this->assertEquals( array( 'Workflow' ), $parsed_single['seo_keywords'] );

		$normalized_single = $this->invoke_private( 'normalize_supported_frontmatter', array( $parsed_single, array( 'title', 'seo_keywords' ) ) );
		$this->assertEquals( array( 'Workflow' ), $normalized_single['seo_keywords'] );

		$markdown_multi = "---\ntitle: \"Test\"\nseo_keywords:\n  - \"Workflow\"\n  - \"Automation\"\n---\n\nContent";
		$parsed_multi   = $this->invoke_private( 'parse_frontmatter_block_local', array( $markdown_multi ) );
		$this->assertEquals( array( 'Workflow', 'Automation' ), $parsed_multi['seo_keywords'] );

		$normalized_multi = $this->invoke_private( 'normalize_supported_frontmatter', array( $parsed_multi, array( 'title', 'seo_keywords' ) ) );
		$this->assertEquals( array( 'Workflow', 'Automation' ), $normalized_multi['seo_keywords'] );
	}

	public function test_last_modified_frontmatter_export_and_import() {
		$post = $this->create_dummy_post(
			array(
				'ID'                => 505,
				'post_modified_gmt' => '2026-08-04 23:50:00',
				'post_modified'     => '2026-08-05 05:20:00',
			)
		);

		$markdown = Push_MD_Plugin::export_post_to_markdown( $post );
		$this->assertStringContainsString( 'last_modified: "2026-08-04T23:50:00Z"', $markdown );

		$metadata = array(
			'last_modified' => '2026-08-04T23:50:00Z',
		);
		$parsed   = $this->invoke_private( 'frontmatter_modified_date_to_mysql_gmt', array( $metadata ) );
		$this->assertEquals( '2026-08-04 23:50:00', $parsed );
	}

	public function testDevHooksExecutionForFrontmatterAndAuthor() {
		add_filter(
			'push_md_export_frontmatter',
			function ( $metadata, $post ) {
				unset( $post );
				$metadata['custom_dev_key'] = array( 'dev_value' );
				return $metadata;
			},
			10,
			2
		);

		$imported_custom_meta = array();
		add_action(
			'push_md_import_frontmatter',
			function ( $post_id, $metadata ) use ( &$imported_custom_meta ) {
				if ( isset( $metadata['custom_dev_key'] ) ) {
					$imported_custom_meta[ $post_id ] = $metadata['custom_dev_key'];
				}
			},
			10,
			2
		);

		$exported_author_meta = array();
		add_filter(
			'push_md_export_author',
			function ( $author_data, $user ) use ( &$exported_author_meta ) {
				unset( $user );
				$author_data['twitter'] = '@custom_author';
				$exported_author_meta[] = $author_data;
				return $author_data;
			},
			10,
			2
		);

		$imported_author_meta = array();
		add_action(
			'push_md_import_author',
			function ( $user_id, $item ) use ( &$imported_author_meta ) {
				if ( isset( $item['twitter'] ) ) {
					$imported_author_meta[ $user_id ] = $item['twitter'];
				}
			},
			10,
			2
		);

		$post     = $this->create_dummy_post( array( 'ID' => 202 ) );
		$markdown = Push_MD_Plugin::export_post_to_markdown( $post );
		$this->assertStringContainsString( 'custom_dev_key: "dev_value"', $markdown );

		do_action( 'push_md_import_frontmatter', 202, array( 'custom_dev_key' => 'dev_value' ), array(), null );
		$this->assertEquals( 'dev_value', $imported_custom_meta[202] );

		$authors_md = $this->invoke_private( 'export_authors_markdown' );
		$this->assertStringContainsString( 'twitter: "@custom_author"', $authors_md );

		do_action( 'push_md_import_author', 1, array( 'twitter' => '@custom_author' ) );
		$this->assertEquals( '@custom_author', $imported_author_meta[1] );
	}

	public function test_multiline_yaml_lists() {
		$markdown = "---\ntags:\n  - \"Opt-in Forms\"\n  - \"Lead Capture\"\n---\n\nPost body content.\n";
		$metadata = $this->invoke_private( 'parse_frontmatter_block_local', array( $markdown ) );

		$this->assertArrayHasKey( 'tags', $metadata );
		$this->assertEquals( array( 'Opt-in Forms', 'Lead Capture' ), $metadata['tags'] );
	}

	public function test_author_voice_override_custom_field() {
		$saved_user_meta = array();

		add_filter(
			'push_md_export_author',
			function ( $author_data, $user ) {
				unset( $user );
				$voice_override = 'formal_executive';
				if ( '' !== $voice_override ) {
					$author_data['voice_override'] = $voice_override;
				}
				return $author_data;
			},
			10,
			2
		);

		add_action(
			'push_md_import_author',
			function ( $user_id, $item ) use ( &$saved_user_meta ) {
				if ( isset( $item['voice_override'] ) ) {
					$saved_user_meta[ $user_id ] = $item['voice_override'];
				}
			},
			10,
			2
		);

		$authors_md = $this->invoke_private( 'export_authors_markdown' );
		$this->assertStringContainsString( 'voice_override: "formal_executive"', $authors_md );

		do_action( 'push_md_import_author', 42, array( 'voice_override' => 'formal_executive' ) );
		$this->assertArrayHasKey( 42, $saved_user_meta );
		$this->assertEquals( 'formal_executive', $saved_user_meta[42] );
	}

	public function test_author_meta_keys_declarative_filter() {
		add_filter(
			'push_md_author_meta_keys',
			function ( $keys ) {
				$keys[] = 'voice_override';
				return $keys;
			}
		);

		$authors_md = $this->invoke_private( 'export_authors_markdown' );
		$this->assertStringContainsString( 'authors:', $authors_md );
	}

	public function testSortFrontmatterKeysPreservesTopPriorityAndAlphabetizesRest() {
		$metadata = array(
			'categories'   => array( 'Tech' ),
			'title'        => 'My Title',
			'custom_beta'  => 'val_b',
			'id'           => '10',
			'author'       => 'admin',
			'status'       => 'publish',
			'custom_alpha' => 'val_a',
			'slug'         => 'my-title',
			'date'         => '2026-08-09',
		);

		$sorted      = Push_MD_Plugin::sort_frontmatter_keys( $metadata );
		$sorted_keys = array_keys( $sorted );

		$expected_keys = array(
			'id',
			'slug',
			'title',
			'date',
			'status',
			'author',
			'categories',
			'custom_alpha',
			'custom_beta',
		);

		$this->assertEquals( $expected_keys, $sorted_keys );
	}

	public function testSortFrontmatterKeysHandlesPartialPriorityKeys() {
		$metadata = array(
			'tags'   => array( 'WordPress' ),
			'status' => 'draft',
			'author' => 'admin',
			'title'  => 'Draft Title',
		);

		$sorted      = Push_MD_Plugin::sort_frontmatter_keys( $metadata );
		$sorted_keys = array_keys( $sorted );

		$expected_keys = array(
			'title',
			'status',
			'author',
			'tags',
		);

		$this->assertEquals( $expected_keys, $sorted_keys );
	}
}
