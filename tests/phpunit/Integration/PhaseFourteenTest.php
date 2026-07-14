<?php
/**
 * Integration tests for Phase 14: list filter, count, campaign copy.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Admin\CampaignCopyPage;
use Stampy\Campaigns\CampaignPostType;
use Stampy\Installer;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Tracking\TrackingSettings;
use WP_UnitTestCase;

/**
 * Tests Phase 14 features: list filter, count display, campaign duplication.
 */
class PhaseFourteenTest extends WP_UnitTestCase {

	/**
	 * Admin user ID.
	 *
	 * @var int
	 */
	private $admin_id;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 1 );
	}

	/**
	 * Intercept wp_redirect to prevent exit by throwing.
	 *
	 * @return never
	 * @throws \RuntimeException Always.
	 */
	public function intercept_redirect(): never {
		throw new \RuntimeException( 'redirect intercepted' );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	public function tearDown(): void {
		wp_set_current_user( 0 );
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 1 );

		// Clean up campaign posts created by wp_insert_post (implicit commit
		// breaks the test transaction, so posts persist across tests).
		$posts = get_posts(
			array(
				'post_type'      => CampaignPostType::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);
		foreach ( $posts as $pid ) {
			wp_delete_post( $pid, true );
		}

		parent::tearDown();
	}

	/**
	 * Test that get_all with list_id filter returns only subscribers in that list.
	 *
	 * @return void
	 */
	public function test_get_all_filtered_by_list(): void {
		$list_repo      = new ListRepository();
		$subscriber_repo = new SubscriberRepository();
		$meta_repo      = new \Stampy\Repositories\SubscriberMetaRepository();

		$list_a = $list_repo->create( 'List A', 'list-a', 'List A' );
		$list_b = $list_repo->create( 'List B', 'list-b', 'List B' );

		$sub1 = $subscriber_repo->create_or_get( 'sub1@example.com', 'confirmed' );
		$sub2 = $subscriber_repo->create_or_get( 'sub2@example.com', 'confirmed' );
		$sub3 = $subscriber_repo->create_or_get( 'sub3@example.com', 'confirmed' );

		$list_repo->add_subscriber( (int) $sub1->id, $list_a );
		$list_repo->add_subscriber( (int) $sub2->id, $list_a );
		$list_repo->add_subscriber( (int) $sub3->id, $list_b );

		$results = $subscriber_repo->get_all(
			array(
				'list_id'  => $list_a,
				'per_page' => 100,
			)
		);

		$emails = array_map( fn( $r ) => $r->email, $results );
		$this->assertContains( 'sub1@example.com', $emails );
		$this->assertContains( 'sub2@example.com', $emails );
		$this->assertNotContains( 'sub3@example.com', $emails );
	}

	/**
	 * Test that count_filtered with list_id returns correct count.
	 *
	 * @return void
	 */
	public function test_count_filtered_by_list(): void {
		$list_repo       = new ListRepository();
		$subscriber_repo = new SubscriberRepository();

		$list_a = $list_repo->create( 'List A', 'list-a-count', 'List A' );
		$list_b = $list_repo->create( 'List B', 'list-b-count', 'List B' );

		$sub1 = $subscriber_repo->create_or_get( 'count1@example.com', 'confirmed' );
		$sub2 = $subscriber_repo->create_or_get( 'count2@example.com', 'confirmed' );
		$sub3 = $subscriber_repo->create_or_get( 'count3@example.com', 'confirmed' );

		$list_repo->add_subscriber( (int) $sub1->id, $list_a );
		$list_repo->add_subscriber( (int) $sub2->id, $list_a );
		$list_repo->add_subscriber( (int) $sub3->id, $list_b );

		$count_a = $subscriber_repo->count_filtered( array( 'list_id' => $list_a ) );
		$this->assertSame( 2, $count_a );

		$count_b = $subscriber_repo->count_filtered( array( 'list_id' => $list_b ) );
		$this->assertSame( 1, $count_b );

		$count_all = $subscriber_repo->count_filtered( array() );
		$this->assertGreaterThanOrEqual( 3, $count_all );
	}

	/**
	 * Test list filter combined with status filter.
	 *
	 * @return void
	 */
	public function test_get_all_filtered_by_list_and_status(): void {
		$list_repo       = new ListRepository();
		$subscriber_repo = new SubscriberRepository();

		$list_a = $list_repo->create( 'List A', 'list-a-status', 'List A' );

		$sub1 = $subscriber_repo->create_or_get( 'status1@example.com', 'confirmed' );
		$sub2 = $subscriber_repo->create_or_get( 'status2@example.com', 'pending' );

		$list_repo->add_subscriber( (int) $sub1->id, $list_a );
		$list_repo->add_subscriber( (int) $sub2->id, $list_a );

		$results = $subscriber_repo->get_all(
			array(
				'list_id' => $list_a,
				'status'  => 'confirmed',
				'per_page' => 100,
			)
		);

		$emails = array_map( fn( $r ) => $r->email, $results );
		$this->assertContains( 'status1@example.com', $emails );
		$this->assertNotContains( 'status2@example.com', $emails );
	}

	/**
	 * Test list filter with list_id=0 returns all subscribers.
	 *
	 * @return void
	 */
	public function test_get_all_with_zero_list_id_returns_all(): void {
		$subscriber_repo = new SubscriberRepository();

		$subscriber_repo->create_or_get( 'zero1@example.com', 'confirmed' );
		$subscriber_repo->create_or_get( 'zero2@example.com', 'confirmed' );

		$initial = $subscriber_repo->count();
		$results = $subscriber_repo->get_all(
			array(
				'list_id'  => 0,
				'per_page' => 100,
			)
		);

		$this->assertSame( $initial, count( $results ) );
	}

	/**
	 * Test campaign duplication copies content, subject, and list IDs.
	 *
	 * @return void
	 */
	public function test_campaign_copy_copies_content_subject_lists(): void {
		$list_repo = new ListRepository();
		$list_id   = $list_repo->create( 'Copy Test List', 'copy-test-list', 'Copy test' );

		$original_id = self::factory()->post->create(
			array(
				'post_type'    => CampaignPostType::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Original Campaign',
				'post_content' => '<!-- wp:paragraph --><p>Hello World</p><!-- /wp:paragraph -->',
			)
		);

		CampaignPostType::set_subject( $original_id, 'Original Subject' );
		CampaignPostType::set_list_ids( $original_id, array( $list_id ) );
		CampaignPostType::set_status( $original_id, 'draft' );

		$_REQUEST['post_id'] = $original_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'stampy_copy_campaign_' . $original_id );

		try {
			CampaignCopyPage::handle_copy_campaign();
		} catch ( \RuntimeException $e ) {
			// Expected redirect.
		}

		unset( $_REQUEST['post_id'], $_REQUEST['_wpnonce'] );

		$copies = get_posts(
			array(
				'post_type'      => CampaignPostType::POST_TYPE,
				'post_status'    => 'draft',
				'posts_per_page' => -1,
				's'              => 'Original Campaign (Copy)',
			)
		);

		$this->assertCount( 1, $copies );
		$copy = $copies[0];

		$this->assertSame( '<!-- wp:paragraph --><p>Hello World</p><!-- /wp:paragraph -->', $copy->post_content );
		$this->assertSame( 'Original Subject', CampaignPostType::get_subject( (int) $copy->ID ) );
		$this->assertSame( array( $list_id ), CampaignPostType::get_list_ids( (int) $copy->ID ) );
		$this->assertSame( 'draft', CampaignPostType::get_status( (int) $copy->ID ) );
	}

	/**
	 * Test campaign copy does not copy sending meta.
	 *
	 * @return void
	 */
	public function test_campaign_copy_does_not_copy_sending_meta(): void {
		$original_id = self::factory()->post->create(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Send Meta Test',
			)
		);

		update_post_meta( $original_id, 'stampy_campaign_html_snapshot', '<html>snapshot</html>' );
		update_post_meta( $original_id, 'stampy_campaign_started_at', '2024-01-01 00:00:00' );

		$_REQUEST['post_id'] = $original_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'stampy_copy_campaign_' . $original_id );

		try {
			CampaignCopyPage::handle_copy_campaign();
		} catch ( \RuntimeException $e ) {
			// Expected redirect.
		}

		unset( $_REQUEST['post_id'], $_REQUEST['_wpnonce'] );

		$all_campaigns = get_posts(
			array(
				'post_type'      => CampaignPostType::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		$copies = array_filter(
			$all_campaigns,
			fn( $p ) => 'Send Meta Test (Copy)' === $p->post_title
		);

		$this->assertCount( 1, $copies );
		$copy    = reset( $copies );
		$copy_id = (int) $copy->ID;

		$this->assertSame( '', get_post_meta( $copy_id, 'stampy_campaign_html_snapshot', true ) );
		$this->assertSame( '', get_post_meta( $copy_id, 'stampy_campaign_started_at', true ) );
	}

	/**
	 * Test campaign copy copies tracking override.
	 *
	 * @return void
	 */
	public function test_campaign_copy_copies_tracking_override(): void {
		$original_id = self::factory()->post->create(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => 'Tracking Override Phase14',
			)
		);

		update_post_meta( $original_id, TrackingSettings::META_OVERRIDE, 'on' );

		$_REQUEST['post_id'] = $original_id;
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'stampy_copy_campaign_' . $original_id );

		try {
			CampaignCopyPage::handle_copy_campaign();
		} catch ( \RuntimeException $e ) {
			// Expected redirect.
		}

		unset( $_REQUEST['post_id'], $_REQUEST['_wpnonce'] );

		$all_campaigns = get_posts(
			array(
				'post_type'      => CampaignPostType::POST_TYPE,
				'posts_per_page' => -1,
				'post_status'    => 'any',
			)
		);
		$copies = array_filter(
			$all_campaigns,
			fn( $p ) => 'Tracking Override Phase14 (Copy)' === $p->post_title
		);

		$this->assertCount( 1, $copies );
		$copy = reset( $copies );
		$this->assertSame( 'on', get_post_meta( (int) $copy->ID, TrackingSettings::META_OVERRIDE, true ) );
	}
}
