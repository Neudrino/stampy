<?php
/**
 * Integration tests for the Campaign CPT and email renderer.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Campaigns\CampaignPostType;
use Stampy\Campaigns\EmailRenderer;
use WP_UnitTestCase;

/**
 * Tests campaign CPT registration, postmeta, and email rendering.
 */
final class CampaignPostTypeTest extends WP_UnitTestCase {

	/**
	 * Ensure meta is registered (init may have fired before plugin loaded).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		CampaignPostType::register_meta();
	}

	/**
	 * Test that the CPT is registered.
	 *
	 * @return void
	 */
	public function test_cpt_is_registered(): void {
		$this->assertTrue( post_type_exists( CampaignPostType::POST_TYPE ) );
	}

	/**
	 * Test that the CPT is not publicly queryable.
	 *
	 * @return void
	 */
	public function test_cpt_is_not_public(): void {
		$cpt = get_post_type_object( CampaignPostType::POST_TYPE );
		$this->assertNotNull( $cpt );
		$this->assertFalse( $cpt->public );
		$this->assertTrue( $cpt->show_ui );
	}

	/**
	 * Test that the CPT shows in REST.
	 *
	 * @return void
	 */
	public function test_cpt_shows_in_rest(): void {
		$cpt = get_post_type_object( CampaignPostType::POST_TYPE );
		$this->assertNotNull( $cpt );
		$this->assertTrue( $cpt->show_in_rest );
		$this->assertSame( 'stampy-campaigns', $cpt->rest_base );
	}

	/**
	 * Test that the CPT supports the expected features.
	 *
	 * @return void
	 */
	public function test_cpt_supports_expected_features(): void {
		$this->assertTrue( post_type_supports( CampaignPostType::POST_TYPE, 'title' ) );
		$this->assertTrue( post_type_supports( CampaignPostType::POST_TYPE, 'editor' ) );
		$this->assertTrue( post_type_supports( CampaignPostType::POST_TYPE, 'revisions' ) );
	}

	/**
	 * Test that the CPT menu is under the Stampy parent.
	 *
	 * @return void
	 */
	public function test_cpt_menu_parent(): void {
		$cpt = get_post_type_object( CampaignPostType::POST_TYPE );
		$this->assertNotNull( $cpt );
		$this->assertSame( 'stampy-subscribers', $cpt->show_in_menu );
	}

	/**
	 * Test subject meta registration and getter/setter.
	 *
	 * @return void
	 */
	public function test_subject_meta(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Test Campaign',
			)
		);

		$this->assertSame( '', CampaignPostType::get_subject( $post_id ) );

		CampaignPostType::set_subject( $post_id, 'My Newsletter #1' );
		$this->assertSame( 'My Newsletter #1', CampaignPostType::get_subject( $post_id ) );
	}

	/**
	 * Test list_ids meta registration and getter/setter.
	 *
	 * @return void
	 */
	public function test_list_ids_meta(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Test Campaign',
			)
		);

		$this->assertSame( array(), CampaignPostType::get_list_ids( $post_id ) );

		CampaignPostType::set_list_ids( $post_id, array( 3, 7, 12 ) );
		$this->assertSame( array( 3, 7, 12 ), CampaignPostType::get_list_ids( $post_id ) );
	}

	/**
	 * Test status meta registration and getter/setter.
	 *
	 * @return void
	 */
	public function test_status_meta(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Test Campaign',
			)
		);

		$this->assertSame( 'draft', CampaignPostType::get_status( $post_id ) );

		CampaignPostType::set_status( $post_id, 'sending' );
		$this->assertSame( 'sending', CampaignPostType::get_status( $post_id ) );

		CampaignPostType::set_status( $post_id, 'sent' );
		$this->assertSame( 'sent', CampaignPostType::get_status( $post_id ) );

		CampaignPostType::set_status( $post_id, 'cancelled' );
		$this->assertSame( 'cancelled', CampaignPostType::get_status( $post_id ) );
	}

	/**
	 * Test that invalid status is rejected.
	 *
	 * @return void
	 */
	public function test_invalid_status_rejected(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => 'Test Campaign',
			)
		);

		$result = CampaignPostType::set_status( $post_id, 'invalid' );
		$this->assertFalse( $result );
		$this->assertSame( 'draft', CampaignPostType::get_status( $post_id ) );
	}

	/**
	 * Test that postmeta is registered for REST.
	 *
	 * @return void
	 */
	public function test_meta_registered_for_rest(): void {
		$meta_keys = get_registered_meta_keys( 'post', CampaignPostType::POST_TYPE );
		$this->assertNotEmpty( $meta_keys );
		$this->assertArrayHasKey( CampaignPostType::META_SUBJECT, $meta_keys );
		$this->assertArrayHasKey( CampaignPostType::META_LIST_IDS, $meta_keys );
		$this->assertArrayHasKey( CampaignPostType::META_STATUS, $meta_keys );
	}

	/**
	 * Test that the block restriction filter is registered.
	 *
	 * @return void
	 */
	public function test_block_restriction_filter_registered(): void {
		$this->assertTrue( has_filter( 'allowed_block_types_all' ) !== false );
	}

	/**
	 * Test that the allowed blocks list is returned for campaigns.
	 *
	 * @return void
	 */
	public function test_allowed_blocks_list(): void {
		$this->assertContains( 'core/paragraph', CampaignPostType::ALLOWED_BLOCKS );
		$this->assertContains( 'core/heading', CampaignPostType::ALLOWED_BLOCKS );
		$this->assertContains( 'core/image', CampaignPostType::ALLOWED_BLOCKS );
		$this->assertContains( 'core/buttons', CampaignPostType::ALLOWED_BLOCKS );
		$this->assertContains( 'core/list', CampaignPostType::ALLOWED_BLOCKS );
		$this->assertContains( 'core/separator', CampaignPostType::ALLOWED_BLOCKS );
	}
}
