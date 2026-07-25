<?php

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wp/' );
}

require_once dirname( __DIR__ ) . '/class-push-md-plugin.php';

class GitIgnoreTest extends TestCase {

	private function invoke_private( $method, $args = array() ) {
		$reflection = new ReflectionClass( Push_MD_Plugin::class );
		$m          = $reflection->getMethod( $method );
		if ( PHP_VERSION_ID < 80100 ) {
			$m->setAccessible( true );
		}

		return $m->invokeArgs( null, $args );
	}

	public function testDefaultGitIgnoreContentStructure() {
		$content = $this->invoke_private( 'get_default_gitignore_content' );

		$this->assertStringContainsString( '*', $content );
		$this->assertStringContainsString( '!*/', $content );
		$this->assertStringContainsString( '!.gitignore', $content );
		$this->assertStringContainsString( '!AGENTS.md', $content );
		$this->assertStringContainsString( '!CLAUDE.md', $content );
		$this->assertStringContainsString( '!categories.md', $content );
		$this->assertStringContainsString( '!tags.md', $content );
		$this->assertStringContainsString( '!authors.md', $content );
		$this->assertStringContainsString( '!post/*.md', $content );
		$this->assertStringContainsString( '!page/**/*.md', $content );
		$this->assertStringContainsString( '!wp_template/**/*.html', $content );
		$this->assertStringContainsString( '!wp_template_part/**/*.html', $content );
		$this->assertStringContainsString( '!wp_navigation/*.html', $content );
		$this->assertStringContainsString( '!wp_theme/**/*.json', $content );
		$this->assertStringContainsString( '!wp_global_styles/*.json', $content );
		$this->assertStringContainsString( '!wp_guideline/**', $content );
		$this->assertStringContainsString( '!.agents/**', $content );
		$this->assertStringContainsString( '!.claude/**', $content );
	}

	public function testIsGitIgnorePathIdentifiesGitIgnore() {
		$this->assertTrue( $this->invoke_private( 'is_gitignore_path', array( '.gitignore' ) ) );
		$this->assertTrue( $this->invoke_private( 'is_gitignore_path', array( '/.gitignore' ) ) );
		$this->assertFalse( $this->invoke_private( 'is_gitignore_path', array( 'post/test.md' ) ) );
		$this->assertFalse( $this->invoke_private( 'is_gitignore_path', array( 'AGENTS.md' ) ) );
	}

	public function testRejectGitIgnoreFileChangesRejectsModification() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Push rejected because .gitignore is managed by Push MD and cannot be modified.' );

		$old_files = array(
			'.gitignore' => array(
				'mode'    => '100644',
				'content' => "original",
			),
		);
		$new_files = array(
			'.gitignore' => array(
				'mode'    => '100644',
				'content' => "modified",
			),
		);

		$this->invoke_private( 'reject_gitignore_file_changes', array( $old_files, $new_files ) );
	}

	public function testRejectGitIgnoreFileChangesRejectsDeletion() {
		$this->expectException( Exception::class );
		$this->expectExceptionMessage( 'Push rejected because .gitignore is managed by Push MD and cannot be deleted.' );

		$old_files = array(
			'.gitignore' => array(
				'mode'    => '100644',
				'content' => "original",
			),
		);
		$new_files = array();

		$this->invoke_private( 'reject_gitignore_file_changes', array( $old_files, $new_files ) );
	}

	public function testRejectGitIgnoreFileChangesAllowsUnchangedGitIgnore() {
		$old_files = array(
			'.gitignore' => array(
				'mode'    => '100644',
				'content' => "same content",
			),
		);
		$new_files = array(
			'.gitignore' => array(
				'mode'    => '100644',
				'content' => "same content",
			),
		);

		$this->invoke_private( 'reject_gitignore_file_changes', array( $old_files, $new_files ) );
		$this->assertTrue( true );
	}
}
