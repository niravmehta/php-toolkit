<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SEO & OpenGraph Frontmatter Handler for Push MD.
 *
 * Supports seo_title, seo_description, seo_keywords, og_title, og_description,
 * og_image, canonical, and schema_type mapped to Rank Math and Yoast SEO plugins.
 */
class Push_MD_SEO {

	/**
	 * Whitelisted SEO & OpenGraph frontmatter keys.
	 *
	 * @var array
	 */
	public static $supported_keys = array(
		'seo_title',
		'seo_description',
		'seo_keywords',
		'og_title',
		'og_description',
		'og_image',
		'canonical',
		'schema_type',
	);

	/**
	 * Register hooks for SEO frontmatter handling.
	 */
	public static function bootstrap() {
		add_filter( 'push_md_supported_frontmatter_keys', array( __CLASS__, 'add_supported_frontmatter_keys' ) );
		add_filter( 'push_md_export_frontmatter', array( __CLASS__, 'export_frontmatter' ), 10, 2 );
		add_action( 'push_md_import_frontmatter', array( __CLASS__, 'import_frontmatter' ), 10, 4 );
	}

	/**
	 * Check if Yoast SEO is active.
	 *
	 * @return bool
	 */
	public static function is_yoast_seo_active() {
		return defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) || defined( 'WPSEO_FILE' );
	}

	/**
	 * Check if Rank Math is active.
	 *
	 * @return bool
	 */
	public static function is_rank_math_active() {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
	}

	/**
	 * Register SEO & OG keys into Push MD's supported frontmatter key registry.
	 *
	 * @param array $keys Existing keys array.
	 * @return array Updated keys array.
	 */
	public static function add_supported_frontmatter_keys( $keys ) {
		if ( ! is_array( $keys ) ) {
			return self::$supported_keys;
		}

		return array_values( array_unique( array_merge( $keys, self::$supported_keys ) ) );
	}

	/**
	 * Export post SEO and OpenGraph metadata into Markdown frontmatter array.
	 *
	 * @param array   $metadata Frontmatter metadata array.
	 * @param WP_Post $post     WordPress post object.
	 * @return array Exported metadata array.
	 */
	public static function export_frontmatter( $metadata, $post ) {
		if ( ! is_array( $metadata ) || ! is_object( $post ) || empty( $post->ID ) ) {
			return $metadata;
		}

		$post_id = (int) $post->ID;

		// 1. Meta Title
		$seo_title = self::get_post_seo_field_meta( $post_id, 'title' );
		if ( '' !== $seo_title ) {
			$metadata['seo_title'] = array( $seo_title );
		}

		// 2. Meta Description
		$seo_desc = self::get_post_seo_field_meta( $post_id, 'description' );
		if ( '' !== $seo_desc ) {
			$metadata['seo_description'] = array( $seo_desc );
		}

		// 3. Focus Keywords
		$keywords = self::get_post_seo_keywords( $post_id );
		if ( ! empty( $keywords ) ) {
			$metadata['seo_keywords'] = $keywords;
		}

		// 4. OpenGraph Title
		$og_title = self::get_post_seo_field_meta( $post_id, 'og_title' );
		if ( '' !== $og_title ) {
			$metadata['og_title'] = array( $og_title );
		}

		// 5. OpenGraph Description
		$og_desc = self::get_post_seo_field_meta( $post_id, 'og_description' );
		if ( '' !== $og_desc ) {
			$metadata['og_description'] = array( $og_desc );
		}

		// 6. OpenGraph Image
		$og_image = self::get_post_og_image_meta( $post_id );
		if ( '' !== $og_image ) {
			$metadata['og_image'] = array( $og_image );
		}

		// 7. Canonical URL
		$canonical = self::get_post_seo_field_meta( $post_id, 'canonical' );
		if ( '' !== $canonical ) {
			$metadata['canonical'] = array( $canonical );
		}

		// 8. Schema Type
		$schema_type = self::get_post_schema_type_meta( $post_id, $post->post_type );
		if ( '' !== $schema_type ) {
			$metadata['schema_type'] = array( $schema_type );
		}

		return $metadata;
	}

	/**
	 * Import SEO and OpenGraph metadata from Markdown frontmatter into WordPress post meta.
	 *
	 * @param int     $post_id       WordPress post ID.
	 * @param array   $metadata      Parsed Markdown frontmatter metadata.
	 * @param array   $postarr       Sanitized post array passed to wp_insert_post/wp_update_post.
	 * @param WP_Post $existing_post Existing post object if updating.
	 */
	public static function import_frontmatter( $post_id, $metadata, $postarr = array(), $existing_post = null ) {
		unset( $postarr, $existing_post );

		$post_id = intval( $post_id );
		if ( ! is_array( $metadata ) || $post_id <= 0 ) {
			return;
		}

		// 1. Meta Title
		if ( isset( $metadata['seo_title'] ) ) {
			self::update_post_seo_field_meta( $post_id, 'title', $metadata['seo_title'] );
		}

		// 2. Meta Description
		if ( isset( $metadata['seo_description'] ) ) {
			self::update_post_seo_field_meta( $post_id, 'description', $metadata['seo_description'] );
		}

		// 3. Focus Keywords
		if ( isset( $metadata['seo_keywords'] ) ) {
			self::update_post_seo_keywords( $post_id, $metadata['seo_keywords'] );
		}

		// 4. OpenGraph Title
		if ( isset( $metadata['og_title'] ) ) {
			self::update_post_seo_field_meta( $post_id, 'og_title', $metadata['og_title'] );
		}

		// 5. OpenGraph Description
		if ( isset( $metadata['og_description'] ) ) {
			self::update_post_seo_field_meta( $post_id, 'og_description', $metadata['og_description'] );
		}

		// 6. OpenGraph Image
		if ( isset( $metadata['og_image'] ) ) {
			self::update_post_og_image_meta( $post_id, $metadata['og_image'] );
		}

		// 7. Canonical URL
		if ( isset( $metadata['canonical'] ) ) {
			self::update_post_seo_field_meta( $post_id, 'canonical', $metadata['canonical'] );
		}

		// 8. Schema Type
		if ( isset( $metadata['schema_type'] ) ) {
			$post_type = function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : 'post';
			self::update_post_schema_type_meta( $post_id, $metadata['schema_type'], $post_type ? $post_type : 'post' );
		}
	}

	/**
	 * Backward compatibility alias for export_frontmatter.
	 *
	 * @param array   $metadata Frontmatter metadata array.
	 * @param WP_Post $post     WordPress post object.
	 * @return array Exported metadata array.
	 */
	public static function handle_seo_export_frontmatter( $metadata, $post ) {
		return self::export_frontmatter( $metadata, $post );
	}

	/**
	 * Backward compatibility alias for import_frontmatter.
	 *
	 * @param int     $post_id       WordPress post ID.
	 * @param array   $metadata      Parsed Markdown frontmatter metadata.
	 * @param array   $postarr       Sanitized post array.
	 * @param WP_Post $existing_post Existing post object.
	 */
	public static function handle_seo_import_frontmatter( $post_id, $metadata, $postarr = array(), $existing_post = null ) {
		self::import_frontmatter( $post_id, $metadata, $postarr, $existing_post );
	}

	/**
	 * Check if post meta key exists for a given post.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key.
	 * @return bool True if meta exists.
	 */
	private static function seo_meta_exists( $post_id, $meta_key ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( isset( $GLOBALS['mock_post_meta'] ) && is_array( $GLOBALS['mock_post_meta'] ) ) {
			return isset( $GLOBALS['mock_post_meta'][ $post_id ][ $meta_key ] );
		}
		if ( function_exists( 'metadata_exists' ) && metadata_exists( 'post', $post_id, $meta_key ) ) {
			return true;
		}
		if ( function_exists( 'get_post_meta' ) ) {
			$val = get_post_meta( $post_id, $meta_key, true );
			return '' !== $val && false !== $val && null !== $val;
		}
		return false;
	}

	/**
	 * Get generic SEO or OpenGraph field string value from Rank Math or Yoast SEO.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $field   Field name ('title', 'description', 'og_title', 'og_description', 'canonical').
	 * @return string Meta string value or empty string.
	 */
	private static function get_post_seo_field_meta( $post_id, $field ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$keys = self::get_meta_keys_for_field( $field );
		if ( empty( $keys['rm'] ) ) {
			return '';
		}

		$rm_val = get_post_meta( $post_id, $keys['rm'], true );
		if ( '' !== trim( (string) $rm_val ) ) {
			return trim( (string) $rm_val );
		}

		$yoast_val = get_post_meta( $post_id, $keys['yoast'], true );
		if ( '' !== trim( (string) $yoast_val ) ) {
			return trim( (string) $yoast_val );
		}

		return '';
	}

	/**
	 * Update generic SEO or OpenGraph field string value in Rank Math and/or Yoast SEO.
	 *
	 * @param int          $post_id Post ID.
	 * @param string       $field   Field name.
	 * @param string|array $val     Value from frontmatter.
	 */
	private static function update_post_seo_field_meta( $post_id, $field, $val ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$val  = is_array( $val ) ? reset( $val ) : $val;
		$val  = trim( (string) $val );
		$keys = self::get_meta_keys_for_field( $field );
		if ( empty( $keys['rm'] ) ) {
			return;
		}

		$yoast_active   = self::is_yoast_seo_active();
		$rm_active      = self::is_rank_math_active();
		$has_rm_meta    = self::seo_meta_exists( $post_id, $keys['rm'] );
		$has_yoast_meta = self::seo_meta_exists( $post_id, $keys['yoast'] );

		$update_rm    = $rm_active || $has_rm_meta || ( ! $yoast_active && ! $has_yoast_meta );
		$update_yoast = $yoast_active || $has_yoast_meta || ( ! $rm_active && ! $has_rm_meta );

		if ( $update_rm ) {
			update_post_meta( $post_id, $keys['rm'], $val );
		}
		if ( $update_yoast ) {
			update_post_meta( $post_id, $keys['yoast'], $val );
		}
	}

	/**
	 * Get Rank Math and Yoast meta keys for a field.
	 *
	 * @param string $field Field slug.
	 * @return array Array with 'rm' and 'yoast' meta keys.
	 */
	private static function get_meta_keys_for_field( $field ) {
		switch ( $field ) {
			case 'title':
				return array(
					'rm'    => 'rank_math_title',
					'yoast' => '_yoast_wpseo_title',
				);
			case 'description':
				return array(
					'rm'    => 'rank_math_description',
					'yoast' => '_yoast_wpseo_metadesc',
				);
			case 'og_title':
				return array(
					'rm'    => 'rank_math_facebook_title',
					'yoast' => '_yoast_wpseo_opengraph-title',
				);
			case 'og_description':
				return array(
					'rm'    => 'rank_math_facebook_description',
					'yoast' => '_yoast_wpseo_opengraph-description',
				);
			case 'canonical':
				return array(
					'rm'    => 'rank_math_canonical_url',
					'yoast' => '_yoast_wpseo_canonical',
				);
			default:
				return array(
					'rm'    => '',
					'yoast' => '',
				);
		}
	}

	/**
	 * Get OpenGraph image URL from Rank Math or Yoast SEO.
	 *
	 * @param int $post_id Post ID.
	 * @return string Image URL string or empty string.
	 */
	private static function get_post_og_image_meta( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$rm_val = get_post_meta( $post_id, 'rank_math_facebook_image', true );
		if ( '' !== trim( (string) $rm_val ) ) {
			return trim( (string) $rm_val );
		}

		$yoast_val = get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true );
		if ( '' !== trim( (string) $yoast_val ) ) {
			return trim( (string) $yoast_val );
		}

		return '';
	}

	/**
	 * Update OpenGraph image URL & Attachment ID in Rank Math and Yoast SEO.
	 * Resolves relative media paths (media/cover.png, ../media/cover.png) to uploaded URLs.
	 *
	 * @param int          $post_id Post ID.
	 * @param string|array $val     Image path or URL from frontmatter.
	 */
	private static function update_post_og_image_meta( $post_id, $val ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$val = is_array( $val ) ? reset( $val ) : $val;
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return;
		}

		$image_url     = $val;
		$attachment_id = 0;

		// 1. Check if numeric attachment ID passed.
		if ( is_numeric( $val ) ) {
			$attachment_id = (int) $val;
			if ( function_exists( 'wp_get_attachment_url' ) ) {
				$fetched_url = wp_get_attachment_url( $attachment_id );
				if ( $fetched_url ) {
					$image_url = $fetched_url;
				}
			}
		} elseif ( class_exists( 'Push_MD_Media' ) ) {
			// 2. Resolve relative media path or URL via Push_MD_Media.
			$info = Push_MD_Media::resolve_media_url_info( $val, $post_id );
			if ( $info && ! empty( $info['url'] ) ) {
				$image_url = $info['url'];
				if ( ! empty( $info['id'] ) ) {
					$attachment_id = (int) $info['id'];
				}
			} else {
				// Fallback lookup by filename if attachment exists.
				$fn            = basename( $val );
				$attachment_id = Push_MD_Media::find_existing_attachment_id_by_filename( $fn );
				$existing_url  = Push_MD_Media::find_existing_attachment_url_by_filename( $fn );
				if ( $existing_url ) {
					$image_url = $existing_url;
				}
			}
		}

		// Fallback attachment ID resolution via core function if still 0.
		if ( 0 === $attachment_id && function_exists( 'attachment_url_to_postid' ) && ( 0 === strpos( $image_url, 'http://' ) || 0 === strpos( $image_url, 'https://' ) ) ) {
			$found_id = attachment_url_to_postid( $image_url );
			if ( $found_id > 0 ) {
				$attachment_id = (int) $found_id;
			}
		}

		$yoast_active   = self::is_yoast_seo_active();
		$rm_active      = self::is_rank_math_active();
		$has_rm_meta    = self::seo_meta_exists( $post_id, 'rank_math_facebook_image' );
		$has_yoast_meta = self::seo_meta_exists( $post_id, '_yoast_wpseo_opengraph-image' );

		$update_rm    = $rm_active || $has_rm_meta || ( ! $yoast_active && ! $has_yoast_meta );
		$update_yoast = $yoast_active || $has_yoast_meta;

		if ( $update_rm ) {
			update_post_meta( $post_id, 'rank_math_facebook_image', $image_url );
			if ( $attachment_id > 0 ) {
				update_post_meta( $post_id, 'rank_math_facebook_image_id', $attachment_id );
			}
		}

		if ( $update_yoast ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $image_url );
			if ( $attachment_id > 0 ) {
				update_post_meta( $post_id, '_yoast_wpseo_opengraph-image-id', $attachment_id );
			}
		}
	}

	/**
	 * Get schema type string from Rank Math or Yoast SEO.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @return string Schema type string or empty string.
	 */
	private static function get_post_schema_type_meta( $post_id, $post_type = 'post' ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return '';
		}

		$rm_val = get_post_meta( $post_id, 'rank_math_rich_snippet', true );
		if ( '' !== trim( (string) $rm_val ) ) {
			return trim( (string) $rm_val );
		}

		$yoast_key = 'page' === $post_type ? '_yoast_wpseo_schema_page_type' : '_yoast_wpseo_schema_article_type';
		$yoast_val = get_post_meta( $post_id, $yoast_key, true );
		if ( '' !== trim( (string) $yoast_val ) ) {
			return trim( (string) $yoast_val );
		}

		return '';
	}

	/**
	 * Update schema type string in Rank Math and Yoast SEO.
	 *
	 * @param int          $post_id   Post ID.
	 * @param string|array $val       Schema type string e.g. "Article", "BlogPosting", "NewsArticle", "Product".
	 * @param string       $post_type Post type slug.
	 */
	private static function update_post_schema_type_meta( $post_id, $val, $post_type = 'post' ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$val = is_array( $val ) ? reset( $val ) : $val;
		$val = trim( (string) $val );
		if ( '' === $val ) {
			return;
		}

		$rm_schema    = strtolower( $val );
		$yoast_schema = self::normalize_yoast_schema_type( $val );

		$yoast_key      = 'page' === $post_type ? '_yoast_wpseo_schema_page_type' : '_yoast_wpseo_schema_article_type';
		$yoast_active   = self::is_yoast_seo_active();
		$rm_active      = self::is_rank_math_active();
		$has_rm_meta    = self::seo_meta_exists( $post_id, 'rank_math_rich_snippet' );
		$has_yoast_meta = self::seo_meta_exists( $post_id, $yoast_key );

		$update_rm    = $rm_active || $has_rm_meta || ( ! $yoast_active && ! $has_yoast_meta );
		$update_yoast = $yoast_active || $has_yoast_meta;

		if ( $update_rm ) {
			update_post_meta( $post_id, 'rank_math_rich_snippet', $rm_schema );
		}
		if ( $update_yoast ) {
			update_post_meta( $post_id, $yoast_key, $yoast_schema );
		}
	}

	/**
	 * Normalize schema type string for Yoast SEO enum conventions.
	 *
	 * @param string $val Schema string.
	 * @return string Normalized Yoast schema type string.
	 */
	private static function normalize_yoast_schema_type( $val ) {
		$lower = strtolower( trim( (string) $val ) );
		switch ( $lower ) {
			case 'article':
				return 'Article';
			case 'blogposting':
			case 'blog_posting':
			case 'blog':
				return 'BlogPosting';
			case 'newsarticle':
			case 'news_article':
			case 'news':
				return 'NewsArticle';
			case 'techarticle':
			case 'tech_article':
				return 'TechArticle';
			case 'webpage':
			case 'web_page':
			case 'page':
				return 'WebPage';
			case 'itempage':
			case 'item_page':
				return 'ItemPage';
			case 'aboutpage':
			case 'about_page':
				return 'AboutPage';
			case 'contactpage':
			case 'contact_page':
				return 'ContactPage';
			case 'faqpage':
			case 'faq_page':
				return 'FAQPage';
			case 'qapage':
			case 'qa_page':
				return 'QAPage';
			case 'profilepage':
			case 'profile_page':
				return 'ProfilePage';
			default:
				return ucfirst( trim( (string) $val ) );
		}
	}

	/**
	 * Get focus keywords array from Rank Math or Yoast SEO.
	 *
	 * @param int $post_id Post ID.
	 * @return array Array of keywords strings.
	 */
	private static function get_post_seo_keywords( $post_id ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'get_post_meta' ) ) {
			return array();
		}

		$keywords = array();

		$rm_val = get_post_meta( $post_id, 'rank_math_focus_keyword', true );
		if ( '' !== trim( (string) $rm_val ) ) {
			$split = explode( ',', (string) $rm_val );
			foreach ( $split as $kw ) {
				$kw = trim( $kw );
				if ( '' !== $kw && ! in_array( $kw, $keywords, true ) ) {
					$keywords[] = $kw;
				}
			}
			if ( ! empty( $keywords ) ) {
				return $keywords;
			}
		}

		$primary = get_post_meta( $post_id, '_yoast_wpseo_focuskw', true );
		if ( '' !== trim( (string) $primary ) ) {
			$keywords[] = trim( (string) $primary );
		}
		$additional_json = get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true );
		if ( ! empty( $additional_json ) && is_string( $additional_json ) ) {
			$decoded = json_decode( $additional_json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $item ) {
					if ( is_array( $item ) && ! empty( $item['keyword'] ) ) {
						$kw = trim( (string) $item['keyword'] );
						if ( '' !== $kw && ! in_array( $kw, $keywords, true ) ) {
							$keywords[] = $kw;
						}
					}
				}
			}
		}

		return $keywords;
	}

	/**
	 * Update focus keywords in Rank Math and/or Yoast SEO.
	 *
	 * @param int          $post_id Post ID.
	 * @param string|array $val     Keywords input from frontmatter.
	 */
	private static function update_post_seo_keywords( $post_id, $val ) {
		$post_id = intval( $post_id );
		if ( $post_id <= 0 || ! function_exists( 'update_post_meta' ) ) {
			return;
		}

		$keywords       = self::parse_seo_keywords_input( $val );
		$yoast_active   = self::is_yoast_seo_active();
		$rm_active      = self::is_rank_math_active();
		$has_rm_meta    = self::seo_meta_exists( $post_id, 'rank_math_focus_keyword' );
		$has_yoast_meta = self::seo_meta_exists( $post_id, '_yoast_wpseo_focuskw' );

		$primary_kw     = ! empty( $keywords ) ? $keywords[0] : '';
		$additional_kws = count( $keywords ) > 1 ? array_slice( $keywords, 1 ) : array();

		$update_rm    = $rm_active || $has_rm_meta || ( ! $yoast_active && ! $has_yoast_meta );
		$update_yoast = $yoast_active || $has_yoast_meta || ( ! $rm_active && ! $has_rm_meta );

		if ( $update_rm ) {
			$rm_str = implode( ', ', $keywords );
			update_post_meta( $post_id, 'rank_math_focus_keyword', $rm_str );
		}

		if ( $update_yoast ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $primary_kw );

			$existing_scores = array();
			$existing_json   = get_post_meta( $post_id, '_yoast_wpseo_focuskeywords', true );
			if ( ! empty( $existing_json ) && is_string( $existing_json ) ) {
				$decoded = json_decode( $existing_json, true );
				if ( is_array( $decoded ) ) {
					foreach ( $decoded as $item ) {
						if ( is_array( $item ) && isset( $item['keyword'], $item['score'] ) ) {
							$existing_scores[ (string) $item['keyword'] ] = (string) $item['score'];
						}
					}
				}
			}

			$yoast_additional = array();
			foreach ( $additional_kws as $kw ) {
				$score              = isset( $existing_scores[ $kw ] ) ? $existing_scores[ $kw ] : 'ok';
				$yoast_additional[] = array(
					'keyword' => $kw,
					'score'   => $score,
				);
			}

			$json_val = ! empty( $yoast_additional ) ? wp_json_encode( $yoast_additional, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : '[]';
			update_post_meta( $post_id, '_yoast_wpseo_focuskeywords', $json_val );
		}
	}

	/**
	 * Parse focus keywords input string or array.
	 *
	 * @param string|array $input Frontmatter value.
	 * @return array Unique non-empty keyword strings.
	 */
	private static function parse_seo_keywords_input( $input ) {
		$keywords = array();
		if ( is_string( $input ) ) {
			$input = array( $input );
		}
		if ( is_array( $input ) ) {
			foreach ( $input as $item ) {
				$item_str = (string) $item;
				$split    = explode( ',', $item_str );
				foreach ( $split as $kw ) {
					$kw = trim( $kw );
					if ( '' !== $kw && ! in_array( $kw, $keywords, true ) ) {
						$keywords[] = $kw;
					}
				}
			}
		}

		return $keywords;
	}
}
