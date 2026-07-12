<?php
/**
 * Integration tests for the email renderer.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Campaigns\CampaignPostType;
use Stampy\Campaigns\EmailRenderer;
use WP_UnitTestCase;

/**
 * Tests email HTML and plain-text rendering from campaign blocks.
 */
final class EmailRendererTest extends WP_UnitTestCase {

	/**
	 * Renderer instance.
	 *
	 * @var EmailRenderer
	 */
	private EmailRenderer $renderer;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->renderer = new EmailRenderer();
	}

	/**
	 * Create a campaign post with given content.
	 *
	 * @param string $content Block content.
	 * @return int
	 */
	private function create_campaign( string $content ): int {
		return self::factory()->post->create(
			array(
				'post_type'    => CampaignPostType::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Test Campaign',
				'post_content' => $content,
			)
		);
	}

	/**
	 * Test rendering a single paragraph block to HTML.
	 *
	 * @return void
	 */
	public function test_render_paragraph_html(): void {
		$post_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hello, World!</p><!-- /wp:paragraph -->' );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( 'Hello, World!', $html );
		$this->assertStringContainsString( '<table', $html );
		$this->assertStringContainsString( 'font-family:Arial,Helvetica,sans-serif', $html );
	}

	/**
	 * Test rendering a heading block to HTML.
	 *
	 * @return void
	 */
	public function test_render_heading_html(): void {
		$post_id = $this->create_campaign( '<!-- wp:heading --><h2>My Heading</h2><!-- /wp:heading -->' );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( 'My Heading', $html );
		$this->assertStringContainsString( 'font-weight:bold', $html );
	}

	/**
	 * Test rendering a list block to HTML.
	 *
	 * @return void
	 */
	public function test_render_list_html(): void {
		$content = '<!-- wp:list --><ul><!-- wp:list-item --><li>Item 1</li><!-- /wp:list-item --><!-- wp:list-item --><li>Item 2</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
		$post_id = $this->create_campaign( $content );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( 'Item 1', $html );
		$this->assertStringContainsString( 'Item 2', $html );
		$this->assertStringContainsString( '<ul', $html );
	}

	/**
	 * Test rendering a separator block to HTML.
	 *
	 * @return void
	 */
	public function test_render_separator_html(): void {
		$post_id = $this->create_campaign( '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->' );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( 'border-top:1px solid', $html );
	}

	/**
	 * Test rendering a button block to HTML.
	 *
	 * @return void
	 */
	public function test_render_button_html(): void {
		$content = '<!-- wp:buttons --><!-- wp:button --><div class="wp-block-button"><a href="https://example.com" class="wp-block-button__link">Click Here</a></div><!-- /wp:button --><!-- /wp:buttons -->';
		$post_id = $this->create_campaign( $content );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( 'Click Here', $html );
		$this->assertStringContainsString( 'href="https://example.com"', $html );
		$this->assertStringContainsString( 'background-color:#2271b1', $html );
	}

	/**
	 * Test that the email template wrapper is present.
	 *
	 * @return void
	 */
	public function test_template_wrapper(): void {
		$post_id = $this->create_campaign( '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->' );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( '<!DOCTYPE html', $html );
		$this->assertStringContainsString( '<html', $html );
		$this->assertStringContainsString( 'background-color:#f4f4f4', $html );
		$this->assertStringContainsString( 'width="600"', $html );
	}

	/**
	 * Test that the unsubscribe footer is auto-appended when missing.
	 *
	 * @return void
	 */
	public function test_auto_append_footer_when_missing(): void {
		$post_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->' );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( '{unsubscribe_url}', $html );
		$this->assertStringContainsString( 'Unsubscribe', $html );
	}

	/**
	 * Test that the footer is NOT appended when {unsubscribe_url} is present.
	 *
	 * @return void
	 */
	public function test_no_footer_when_unsubscribe_present(): void {
		$content = '<!-- wp:paragraph --><p>Check out <a href="{unsubscribe_url}">unsubscribe</a>.</p><!-- /wp:paragraph -->';
		$post_id = $this->create_campaign( $content );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$footer_count = substr_count( strtolower( $html ), 'unsubscribe' );
		$this->assertGreaterThanOrEqual( 1, $footer_count );
	}

	/**
	 * Test that physical address appears in footer when set.
	 *
	 * @return void
	 */
	public function test_physical_address_in_footer(): void {
		update_option( 'stampy_physical_address', '123 Main St, Anytown, USA' );

		$post_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->' );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( '123 Main St, Anytown, USA', $html );

		delete_option( 'stampy_physical_address' );
	}

	/**
	 * Test plain-text rendering of a paragraph.
	 *
	 * @return void
	 */
	public function test_render_text_paragraph(): void {
		$post_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hello, World!</p><!-- /wp:paragraph -->' );
		$post    = get_post( $post_id );
		$text    = $this->renderer->render_text( $post );

		$this->assertStringContainsString( 'Hello, World!', $text );
		$this->assertStringNotContainsString( '<table', $text );
		$this->assertStringNotContainsString( '<p>', $text );
	}

	/**
	 * Test plain-text rendering of a heading (uppercased).
	 *
	 * @return void
	 */
	public function test_render_text_heading_uppercased(): void {
		$post_id = $this->create_campaign( '<!-- wp:heading --><h2>My Heading</h2><!-- /wp:heading -->' );
		$post    = get_post( $post_id );
		$text    = $this->renderer->render_text( $post );

		$this->assertStringContainsString( 'MY HEADING', $text );
	}

	/**
	 * Test plain-text rendering of a list.
	 *
	 * @return void
	 */
	public function test_render_text_list(): void {
		$content = '<!-- wp:list --><ul><!-- wp:list-item --><li>Apple</li><!-- /wp:list-item --><!-- wp:list-item --><li>Banana</li><!-- /wp:list-item --></ul><!-- /wp:list -->';
		$post_id = $this->create_campaign( $content );
		$post    = get_post( $post_id );
		$text    = $this->renderer->render_text( $post );

		$this->assertStringContainsString( '* Apple', $text );
		$this->assertStringContainsString( '* Banana', $text );
	}

	/**
	 * Test plain-text rendering of a separator.
	 *
	 * @return void
	 */
	public function test_render_text_separator(): void {
		$post_id = $this->create_campaign( '<!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator -->' );
		$post    = get_post( $post_id );
		$text    = $this->renderer->render_text( $post );

		$this->assertStringContainsString( '---', $text );
	}

	/**
	 * Test plain-text rendering of a button as [text](url).
	 *
	 * @return void
	 */
	public function test_render_text_button(): void {
		$content = '<!-- wp:buttons --><!-- wp:button --><div class="wp-block-button"><a href="https://example.com" class="wp-block-button__link">Click Here</a></div><!-- /wp:button --><!-- /wp:buttons -->';
		$post_id = $this->create_campaign( $content );
		$post    = get_post( $post_id );
		$text    = $this->renderer->render_text( $post );

		$this->assertStringContainsString( '[Click Here](https://example.com)', $text );
	}

	/**
	 * Test plain-text auto-appends unsubscribe when missing.
	 *
	 * @return void
	 */
	public function test_render_text_auto_appends_unsubscribe(): void {
		$post_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->' );
		$post    = get_post( $post_id );
		$text    = $this->renderer->render_text( $post );

		$this->assertStringContainsString( '{unsubscribe_url}', $text );
	}

	/**
	 * Test rendering multiple blocks in sequence.
	 *
	 * @return void
	 */
	public function test_render_multiple_blocks(): void {
		$content = '<!-- wp:heading --><h2>Welcome</h2><!-- /wp:heading --><!-- wp:paragraph --><p>This is a test.</p><!-- /wp:paragraph --><!-- wp:separator --><hr class="wp-block-separator"/><!-- /wp:separator --><!-- wp:paragraph --><p>Goodbye!</p><!-- /wp:paragraph -->';
		$post_id = $this->create_campaign( $content );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( 'Welcome', $html );
		$this->assertStringContainsString( 'This is a test.', $html );
		$this->assertStringContainsString( 'Goodbye!', $html );
	}

	/**
	 * Test rendering a campaign with empty content.
	 *
	 * @return void
	 */
	public function test_render_empty_content(): void {
		$post_id = $this->create_campaign( '' );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( '{unsubscribe_url}', $html );
		$this->assertStringContainsString( '<!DOCTYPE html', $html );
	}

	/**
	 * Test that the subject appears in the <title> tag.
	 *
	 * @return void
	 */
	public function test_subject_in_title_tag(): void {
		$post_id = $this->create_campaign( '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->' );
		CampaignPostType::set_subject( $post_id, 'My Subject Line' );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( '<title>My Subject Line</title>', $html );
	}

	/**
	 * Test image rendering with absolute URL.
	 *
	 * @return void
	 */
	public function test_render_image_html(): void {
		$content = '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.com/image.jpg" alt="Test Image"/></figure><!-- /wp:image -->';
		$post_id = $this->create_campaign( $content );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$this->assertStringContainsString( 'src="https://example.com/image.jpg"', $html );
		$this->assertStringContainsString( 'alt="Test Image"', $html );
		$this->assertStringContainsString( '<img', $html );
	}

	/**
	 * Test that relative image URLs are made absolute.
	 *
	 * @return void
	 */
	public function test_image_relative_url_made_absolute(): void {
		$content = '<!-- wp:image --><figure class="wp-block-image"><img src="/wp-content/uploads/2024/image.jpg" alt="Test"/></figure><!-- /wp:image -->';
		$post_id = $this->create_campaign( $content );
		$post    = get_post( $post_id );
		$html    = $this->renderer->render_html( $post );

		$home = home_url();
		$this->assertStringContainsString( 'src="' . $home . '/wp-content/uploads/2024/image.jpg"', $html );
	}
}
