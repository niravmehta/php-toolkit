<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-' . uniqid() . '/' );
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		$post_id = intval( $post_id );
		if ( isset( $GLOBALS['mock_post_meta'][ $post_id ][ $key ] ) ) {
			return $GLOBALS['mock_post_meta'][ $post_id ][ $key ];
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

if ( ! function_exists( 'metadata_exists' ) ) {
	function metadata_exists( $meta_type, $object_id, $meta_key ) {
		unset( $meta_type );
		$object_id = intval( $object_id );
		return isset( $GLOBALS['mock_post_meta'][ $object_id ][ $meta_key ] );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( $attachment_id ) {
		$attachment_id = intval( $attachment_id );
		if ( $attachment_id > 0 ) {
			return 'http://example.org/wp-content/uploads/attachment-' . $attachment_id . '.png';
		}
		return false;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post_id ) {
		unset( $post_id );
		return 'post';
	}
}

require_once __DIR__ . '/../class-push-md-seo.php';
require_once __DIR__ . '/../class-push-md-media.php';

/**
 * Unit tests for Push MD SEO & OpenGraph Frontmatter Support.
 */
class SeoSupportTest extends TestCase {

	/** @before */
	public function set_up() {
		$GLOBALS['mock_post_meta'] = array();
		Push_MD_SEO::bootstrap();
	}

	public function testAddSupportedFrontmatterKeys() {
		$keys   = array( 'title', 'date', 'status' );
		$merged = Push_MD_SEO::add_supported_frontmatter_keys( $keys );

		$this->assertContains( 'seo_title', $merged );
		$this->assertContains( 'seo_description', $merged );
		$this->assertContains( 'seo_keywords', $merged );
		$this->assertContains( 'og_title', $merged );
		$this->assertContains( 'og_description', $merged );
		$this->assertContains( 'og_image', $merged );
		$this->assertContains( 'canonical', $merged );
		$this->assertContains( 'schema_type', $merged );
	}

	public function testExportFrontmatterRankMathMeta() {
		$post_id                               = 10;
		$GLOBALS['mock_post_meta'][ $post_id ] = array(
			'rank_math_title'                => 'Rank Math Title',
			'rank_math_description'          => 'Rank Math Desc',
			'rank_math_focus_keyword'        => 'keyword1, keyword2',
			'rank_math_facebook_title'       => 'Rank Math OG Title',
			'rank_math_facebook_description' => 'Rank Math OG Desc',
			'rank_math_facebook_image'       => 'http://example.org/uploads/og-hero.png',
			'rank_math_canonical_url'        => 'https://example.org/canonical-page',
			'rank_math_rich_snippet'         => 'article',
		);

		$post            = new stdClass();
		$post->ID        = $post_id;
		$post->post_type = 'post';

		$exported = Push_MD_SEO::export_frontmatter( array(), $post );

		$this->assertSame( array( 'Rank Math Title' ), $exported['seo_title'] );
		$this->assertSame( array( 'Rank Math Desc' ), $exported['seo_description'] );
		$this->assertSame( array( 'keyword1', 'keyword2' ), $exported['seo_keywords'] );
		$this->assertSame( array( 'Rank Math OG Title' ), $exported['og_title'] );
		$this->assertSame( array( 'Rank Math OG Desc' ), $exported['og_description'] );
		$this->assertSame( array( 'http://example.org/uploads/og-hero.png' ), $exported['og_image'] );
		$this->assertSame( array( 'https://example.org/canonical-page' ), $exported['canonical'] );
		$this->assertSame( array( 'article' ), $exported['schema_type'] );
	}

	public function testExportFrontmatterYoastMeta() {
		$post_id                               = 20;
		$GLOBALS['mock_post_meta'][ $post_id ] = array(
			'rank_math_title'                    => '',
			'rank_math_description'              => '',
			'rank_math_focus_keyword'            => '',
			'_yoast_wpseo_title'                 => 'Yoast Title',
			'_yoast_wpseo_metadesc'              => 'Yoast Desc',
			'_yoast_wpseo_focuskw'               => 'primary-kw',
			'_yoast_wpseo_opengraph-title'       => 'Yoast OG Title',
			'_yoast_wpseo_opengraph-description' => 'Yoast OG Desc',
			'_yoast_wpseo_opengraph-image'       => 'http://example.org/uploads/yoast-og.png',
			'_yoast_wpseo_canonical'             => 'https://example.org/yoast-canonical',
			'_yoast_wpseo_schema_article_type'   => 'NewsArticle',
		);

		$post            = new stdClass();
		$post->ID        = $post_id;
		$post->post_type = 'post';

		$exported = Push_MD_SEO::export_frontmatter( array(), $post );

		$this->assertSame( array( 'Yoast Title' ), $exported['seo_title'] );
		$this->assertSame( array( 'Yoast Desc' ), $exported['seo_description'] );
		$this->assertSame( array( 'primary-kw' ), $exported['seo_keywords'] );
		$this->assertSame( array( 'Yoast OG Title' ), $exported['og_title'] );
		$this->assertSame( array( 'Yoast OG Desc' ), $exported['og_description'] );
		$this->assertSame( array( 'http://example.org/uploads/yoast-og.png' ), $exported['og_image'] );
		$this->assertSame( array( 'https://example.org/yoast-canonical' ), $exported['canonical'] );
		$this->assertSame( array( 'NewsArticle' ), $exported['schema_type'] );
	}

	public function testImportFrontmatterRankMathMeta() {
		$post_id  = 30;
		$metadata = array(
			'seo_title'       => 'Imported SEO Title',
			'seo_description' => 'Imported SEO Description',
			'seo_keywords'    => array( 'kw1', 'kw2' ),
			'og_title'        => 'Imported OG Title',
			'og_description'  => 'Imported OG Description',
			'og_image'        => 'http://example.org/uploads/og-image.png',
			'canonical'       => 'https://example.org/imported-canonical',
			'schema_type'     => 'BlogPosting',
		);

		Push_MD_SEO::import_frontmatter( $post_id, $metadata );

		// Check if updated in mock meta (either rank_math or _yoast).
		$meta = $GLOBALS['mock_post_meta'][ $post_id ];
		$title = isset( $meta['rank_math_title'] ) ? $meta['rank_math_title'] : $meta['_yoast_wpseo_title'];
		$desc  = isset( $meta['rank_math_description'] ) ? $meta['rank_math_description'] : $meta['_yoast_wpseo_metadesc'];
		$og_t  = isset( $meta['rank_math_facebook_title'] ) ? $meta['rank_math_facebook_title'] : $meta['_yoast_wpseo_opengraph-title'];
		$og_d  = isset( $meta['rank_math_facebook_description'] ) ? $meta['rank_math_facebook_description'] : $meta['_yoast_wpseo_opengraph-description'];
		$og_i  = isset( $meta['rank_math_facebook_image'] ) ? $meta['rank_math_facebook_image'] : $meta['_yoast_wpseo_opengraph-image'];
		$canon = isset( $meta['rank_math_canonical_url'] ) ? $meta['rank_math_canonical_url'] : $meta['_yoast_wpseo_canonical'];

		$this->assertSame( 'Imported SEO Title', $title );
		$this->assertSame( 'Imported SEO Description', $desc );
		$this->assertSame( 'Imported OG Title', $og_t );
		$this->assertSame( 'Imported OG Description', $og_d );
		$this->assertSame( 'http://example.org/uploads/og-image.png', $og_i );
		$this->assertSame( 'https://example.org/imported-canonical', $canon );
	}

	public function testImportFrontmatterOgImageWithAttachmentId() {
		$post_id  = 50;
		$metadata = array(
			'og_image' => '42',
		);

		Push_MD_SEO::import_frontmatter( $post_id, $metadata );

		$meta     = $GLOBALS['mock_post_meta'][ $post_id ];
		$image_url = isset( $meta['rank_math_facebook_image'] ) ? $meta['rank_math_facebook_image'] : $meta['_yoast_wpseo_opengraph-image'];
		$image_id  = isset( $meta['rank_math_facebook_image_id'] ) ? $meta['rank_math_facebook_image_id'] : $meta['_yoast_wpseo_opengraph-image-id'];

		$this->assertNotEmpty( $image_url );
		$this->assertSame( 42, $image_id );
	}
}
