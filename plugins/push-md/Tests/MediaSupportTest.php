<?php

use PHPUnit\Framework\TestCase;
use WordPress\Git\Model\TreeEntry;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp-' . uniqid() . '/' );
}

require_once __DIR__ . '/../class-push-md-media.php';

/**
 * Unit and integration tests for Push MD Git Media Support (Inline & Featured Images).
 */
class MediaSupportTest extends TestCase {

	/**
	 * Transparent 1x1 PNG binary payload.
	 *
	 * @var string
	 */
	private $sample_png;

	/**
	 * Sample 1x1 GIF binary payload.
	 *
	 * @var string
	 */
	private $sample_gif;

	/** @before */
	public function set_up() {
		// Valid 1x1 PNG binary.
		$this->sample_png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' );
		// Valid 1x1 GIF binary.
		$this->sample_gif = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' );

		// Reset mock attachments if function exists.
		if ( isset( $GLOBALS['mock_attachments'] ) ) {
			$GLOBALS['mock_attachments'] = array();
		}
	}

	public function testIsMediaPath() {
		$this->assertTrue( Push_MD_Media::is_media_path( 'media/cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( '../media/cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( './media/cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( '/media/cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( 'cover.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( 'media/sub/chart.png' ) );
		$this->assertTrue( Push_MD_Media::is_media_path( 'media' ) );
		$this->assertFalse( Push_MD_Media::is_media_path( 'content/page.md' ) );
	}

	public function testNormalizeRelativeMediaPath() {
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( 'media/imagename.webp' ) );
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( '../media/imagename.webp' ) );
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( './media/imagename.webp' ) );
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( '/media/imagename.webp' ) );
		$this->assertSame( 'media/imagename.webp', Push_MD_Media::normalize_relative_media_path( 'imagename.webp' ) );
	}

	public function testValidMediaFileValidation() {
		// Should not throw exceptions.
		Push_MD_Media::validate_media_file( 'media/cover.png', $this->sample_png );
		Push_MD_Media::validate_media_file( 'media/banner.gif', $this->sample_gif );
		$this->assertTrue( true );
	}

	public function testFailClosedRejectionForInvalidExtensions() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/supported image type/' );
		Push_MD_Media::validate_media_file( 'media/malicious.php', '<?php echo "evil"; ?>' );
	}

	public function testFailClosedRejectionForPathTraversal() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/path traversal/' );
		Push_MD_Media::validate_media_file( 'media/../secret.png', $this->sample_png );
	}

	public function testFailClosedRejectionForCorruptedBinary() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessageMatches( '/empty or corrupted|MIME type/' );
		Push_MD_Media::validate_media_file( 'media/fake.png', 'this is text content not a png' );
	}

	public function testDetectMimeType() {
		$this->assertSame( 'image/png', Push_MD_Media::detect_mime_type( $this->sample_png, 'test.png' ) );
		$this->assertSame( 'image/gif', Push_MD_Media::detect_mime_type( $this->sample_gif, 'test.gif' ) );
	}

	public function testRewriteInlineImagePathsMarkdown() {
		$post_content = 'Here is a diagram: ![Architecture Diagram](../media/arch.png) and another ![Chart](media/chart.png).';

		$commit_files = array(
			'media/arch.png'  => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/chart.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 1, $post_content, $commit_files );

		$this->assertStringNotContainsString( '../media/arch.png', $rewritten );
		$this->assertStringNotContainsString( '(media/chart.png)', $rewritten );
		$this->assertStringContainsString( 'arch.png', $rewritten );
		$this->assertStringContainsString( 'chart.png', $rewritten );
	}

	public function testRewriteInlineImagePathsHtml() {
		$post_content = '<p>Check <img src="../media/photo.jpg" alt="Photo" /> for details.</p>';

		$commit_files = array(
			'media/photo.jpg' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png, // Valid image payload
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 1, $post_content, $commit_files );

		$this->assertStringNotContainsString( '../media/photo.jpg', $rewritten );
		$this->assertStringContainsString( 'photo.jpg', $rewritten );
	}

	public function testFeaturedImageRelativePathProcessing() {
		$commit_files = array(
			'media/cover.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		// Should not throw exception when processing valid relative featured image.
		Push_MD_Media::handle_featured_image( 101, '../media/cover.png', $commit_files );
		$this->assertTrue( true );
	}

	public function testRewriteInlineImagePathsPictureAndFigure() {
		$post_content = '<picture><source srcset="../media/cover-large.webp 1200w, ../media/cover-small.webp 600w" /><img src="../media/cover.png" alt="Cover" /></picture><figure class="wp-block-image"><a href="../media/full.png"><img src="../media/thumb.png" /></a></figure>';

		$commit_files = array(
			'media/cover-large.webp' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/cover-small.webp' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/cover.png'        => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/full.png'         => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/thumb.png'        => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 10, $post_content, $commit_files );

		$this->assertStringNotContainsString( '../media/cover-large.webp', $rewritten );
		$this->assertStringNotContainsString( '../media/cover-small.webp', $rewritten );
		$this->assertStringNotContainsString( '../media/cover.png', $rewritten );
		$this->assertStringNotContainsString( '../media/full.png', $rewritten );
		$this->assertStringNotContainsString( '../media/thumb.png', $rewritten );
		$this->assertStringContainsString( 'cover-large.webp 1200w', $rewritten );
	}

	public function testRewriteInlineImagePathsGutenbergBlock() {
		$post_content = '<!-- wp:image {"id":0,"url":"../media/chart.png","sizeSlug":"full"} --><figure class="wp-block-image"><img src="../media/chart.png" alt=""/></figure><!-- /wp:image -->';

		$commit_files = array(
			'media/chart.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 20, $post_content, array(), $commit_files );

		$this->assertStringNotContainsString( '../media/chart.png', $rewritten );
		$this->assertStringContainsString( 'chart.png', $rewritten );
	}

	public function testAttachmentMetadataExtraction() {
		$post_content = '![Architecture Alt Text](../media/arch-diagram.png "Architecture Title")';

		$commit_files = array(
			'media/arch-diagram.png' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		$rewritten = Push_MD_Media::rewrite_inline_image_paths( 10, $post_content, array(), $commit_files );
		$this->assertStringNotContainsString( '../media/arch-diagram.png', $rewritten );
		$this->assertStringContainsString( 'arch-diagram.png', $rewritten );
	}

	public function testFeaturedImageExternalUrlIgnored() {
		// External URLs should be safely ignored without raising errors.
		Push_MD_Media::handle_featured_image( 102, 'https://external-domain.com/untrusted-image.jpg', array() );
		$this->assertTrue( true );
	}

	public function testSimulatedGitPushCommitWithMarkdownAndMedia() {
		// Simulate commit containing both a Markdown post and an image in media/
		$markdown_content = "---\ntitle: \"Post with Git Media\"\nstatus: publish\nfeatured_image: \"../media/hero.png\"\n---\n\nCheck our diagram:\n\n![Diagram](../media/diagram.png)\n";

		$commit_files = array(
			'posts/post-with-media.md' => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $markdown_content,
			),
			'media/hero.png'           => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
			'media/diagram.png'        => array(
				'mode'    => TreeEntry::FILE_MODE_REGULAR_NON_EXECUTABLE,
				'content' => $this->sample_png,
			),
		);

		// 1. Validate all media files in push payload
		foreach ( $commit_files as $path => $entry ) {
			if ( Push_MD_Media::is_media_path( $path ) ) {
				Push_MD_Media::validate_media_file( $path, $entry['content'] );
			}
		}

		// 2. Rewrite inline relative image paths in post content
		$rewritten_markup = Push_MD_Media::rewrite_inline_image_paths( 50, $markdown_content, $commit_files );
		$this->assertStringNotContainsString( '../media/diagram.png', $rewritten_markup );

		// 3. Featured image assignment simulation for all path formats
		Push_MD_Media::handle_featured_image( 50, '../media/hero.png', array(), $commit_files );
		Push_MD_Media::handle_featured_image( 50, 'media/hero.png', array(), $commit_files );
		Push_MD_Media::handle_featured_image( 50, './media/hero.png', array(), $commit_files );
		Push_MD_Media::handle_featured_image( 50, '/media/hero.png', array(), $commit_files );
		Push_MD_Media::handle_featured_image( 50, 'hero.png', array(), $commit_files );
		$this->assertTrue( true );
	}
}
