<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
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
		unset( $taxonomy );

		return false;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		$post_id = intval( $post_id );
		if ( isset( $GLOBALS['mock_wp_posts'][ $post_id ] ) ) {
			return $GLOBALS['mock_wp_posts'][ $post_id ];
		}

		return null;
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

require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';

class PMD_Export_Path_Test extends TestCase {

	/** @before */
	public function reset_mock_posts() {
		$GLOBALS['mock_wp_posts'] = array();
	}

	public function testKnowledgeIsOptionalWhenItsStorageIsUnavailable() {
		$this->assertNotContains( 'wp_knowledge', Push_MD_Plugin::get_supported_post_types() );
		$this->assertSame( array(), Push_MD_Plugin::get_default_agent_guidance_preview_files() );
	}

	public function testPushMdRegistersTheKnowledgeSkillType() {
		$types = Push_MD_Plugin::register_knowledge_types(
			array(
				'note' => array( 'title' => 'Note' ),
			)
		);

		$this->assertSame( 'Note', $types['note']['title'] );
		$this->assertSame( 'Skill', $types['skill']['title'] );
	}

	public function testPostWithEmptySlugUsesStableIdFallbackPath() {
		$this->assertSame(
			'post/post-4937.md',
			Push_MD_Plugin::build_markdown_path( $this->post( 4937, 'post', '' ) )
		);
	}

	public function testPageWithEmptySlugUsesStableIdFallbackPath() {
		$this->assertSame(
			'page/page-3814.md',
			Push_MD_Plugin::build_markdown_path( $this->post( 3814, 'page', '' ) )
		);
	}

	public function testExistingSlugStillDefinesExportPath() {
		$this->assertSame(
			'post/amazing-potatoes.md',
			Push_MD_Plugin::build_markdown_path( $this->post( 4163, 'post', 'amazing-potatoes' ) )
		);
	}

	public function testCurrentSluglessFallbackPathIsAccepted() {
		$this->assertTrue(
			$this->assert_id_fallback_path_is_current(
				'post/post-4937.md',
				$this->post( 4937, 'post', '' )
			)
		);
	}

	public function testCurrentSluglessFallbackPathDoesNotWriteFallbackAsPostSlug() {
		$this->assertTrue(
			$this->is_current_slugless_fallback_path(
				'post/post-4937.md',
				$this->post( 4937, 'post', '' )
			)
		);
	}

	public function testStaleFallbackPathIsRejectedAfterPostReceivesSlug() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'fallback filename is stale' );

		$post = $this->post( 4937, 'post', 'test-post-from-cli' );
		$GLOBALS['mock_wp_posts'][ $post->ID ] = $post;

		$this->assert_id_fallback_path_is_current(
			'post/post-4937.md',
			$post
		);
	}

	public function testFallbackShapedRealSlugIsAcceptedWhenItIsCurrent() {
		$this->assertTrue(
			$this->assert_id_fallback_path_is_current(
				'post/post-4937.md',
				$this->post( 4937, 'post', 'post-4937' )
			)
		);
	}

	public function testDuplicateDraftSlugsExportWithIdFallback() {
		$post1              = $this->post( 101, 'post', 'sendgrid-alternatives-2' );
		$post1->post_status = 'draft';
		$post2              = $this->post( 202, 'post', 'sendgrid-alternatives-2' );
		$post2->post_status = 'draft';

		$path1 = Push_MD_Plugin::build_markdown_path( $post1 );
		$this->assertSame( 'post/sendgrid-alternatives-2.md', $path1 );

		$method = new ReflectionMethod( Push_MD_Plugin::class, 'build_id_fallback_markdown_path' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$fallback_path2 = $method->invoke( null, $post2 );
		$this->assertSame( 'post/post-202.md', $fallback_path2 );
	}

	public function testDuplicateDraftSlugsFallbackPathIsAccepted() {
		$post1              = $this->post( 101, 'post', 'sendgrid-alternatives-2' );
		$post1->post_status = 'draft';
		$post2              = $this->post( 202, 'post', 'sendgrid-alternatives-2' );
		$post2->post_status = 'draft';

		$GLOBALS['mock_wp_posts'][ $post1->ID ] = $post1;
		$GLOBALS['mock_wp_posts'][ $post2->ID ] = $post2;

		$this->assertTrue(
			$this->assert_id_fallback_path_is_current(
				'post/post-202.md',
				$post2
			)
		);
	}

	public function testDraftCollidingWithPublishedSlugFallbackPathIsAccepted() {
		$published_post              = $this->post( 101, 'post', 'sendgrid-alternatives' );
		$published_post->post_status = 'publish';
		$draft_post                  = $this->post( 202, 'post', 'sendgrid-alternatives' );
		$draft_post->post_status     = 'draft';

		$GLOBALS['mock_wp_posts'][ $published_post->ID ] = $published_post;
		$GLOBALS['mock_wp_posts'][ $draft_post->ID ]     = $draft_post;

		$this->assertTrue(
			$this->assert_id_fallback_path_is_current(
				'post/post-202.md',
				$draft_post
			)
		);
	}

	public function testPublicSlugCollisionIsRejected() {
		$published_post              = $this->post( 101, 'post', 'sendgrid-alternatives' );
		$published_post->post_status = 'publish';
		$draft_post                  = $this->post( 202, 'post', 'sendgrid-alternatives' );
		$draft_post->post_status     = 'draft';

		$GLOBALS['mock_wp_posts'][ $published_post->ID ] = $published_post;
		$GLOBALS['mock_wp_posts'][ $draft_post->ID ]     = $draft_post;

		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Push rejected because a published WordPress post already uses the slug "sendgrid-alternatives"' );

		$method = new ReflectionMethod( Push_MD_Plugin::class, 'assert_no_public_slug_collision' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$method->invoke( null, 'post', 'sendgrid-alternatives', $draft_post );
	}

	private function post( $id, $post_type, $post_name ) {
		$post              = new WP_Post();
		$post->ID          = $id;
		$post->post_type   = $post_type;
		$post->post_name   = $post_name;
		$post->post_parent = 0;

		return $post;
	}

	private function assert_id_fallback_path_is_current( $path, WP_Post $post ) {
		$method = new ReflectionMethod( Push_MD_Plugin::class, 'assert_id_fallback_path_is_current' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}
		$method->invoke( null, $path, $post );

		return true;
	}

	private function is_current_slugless_fallback_path( $path, WP_Post $post ) {
		$method = new ReflectionMethod( Push_MD_Plugin::class, 'is_current_slugless_fallback_path' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true );
		}

		return $method->invoke( null, $path, $post );
	}
}
