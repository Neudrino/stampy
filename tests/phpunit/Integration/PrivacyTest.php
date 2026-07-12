<?php
/**
 * Integration tests for the GDPR privacy exporters and erasers.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Campaigns\CampaignPostType;
use Stampy\Campaigns\SendingEngine;
use Stampy\Installer;
use Stampy\Privacy;
use Stampy\Repositories\CampaignRecipientRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Tracking\Tracking;
use Stampy\Tracking\TrackingEndpoints;
use Stampy\Tracking\TrackingSettings;
use WP_UnitTestCase;

/**
 * Tests personal-data export and erase for Stampy subscriber data.
 */
final class PrivacyTest extends WP_UnitTestCase {

	/**
	 * List ID for testing.
	 *
	 * @var int
	 */
	private int $list_id;

	/**
	 * Set up before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Installer::install();
		CampaignPostType::register_meta();
		TrackingEndpoints::add_query_vars( array() );
		TrackingEndpoints::add_rewrite_rules();

		// Use a unique list slug so we don't collide with other test
		// classes that create a 'newsletter' list. The list persists
		// across test classes due to the dbDelta() implicit commit.
		$list_repo = new ListRepository();
		$existing  = $list_repo->find_by_slug( 'privacy-test-list' );
		if ( null !== $existing ) {
			$this->list_id = (int) $existing->id;
		} else {
			$this->list_id = $list_repo->create( 'Privacy Test List', 'privacy-test-list', 'Test list' );
		}

		unset( $GLOBALS['phpmailer_mock_sent'] );

		TrackingSettings::set_globally_enabled( false );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		TrackingSettings::set_globally_enabled( false );
		unset( $GLOBALS['phpmailer_mock_sent'] );

		parent::tearDown();

		// Clean up AFTER parent::tearDown() to avoid the WP test
		// framework's transaction rollback undoing our deletes.
		// Due to the dbDelta() implicit commit, all data persists
		// across test methods and classes.

		// Clean up subscribers created by test methods.
		$repo    = new SubscriberRepository();
		$emails  = array(
			'alice@example.com',
			'bob@example.com',
			'carol@example.com',
			'dave@example.com',
			'eve@example.com',
			'frank@example.com',
		);
		foreach ( $emails as $email ) {
			$sub = $repo->find_by_email( $email );
			if ( null !== $sub ) {
				$repo->delete( (int) $sub->id );
			}
		}

		// Clean up campaigns created by test methods.
		$campaigns = get_posts(
			array(
				'post_type'      => CampaignPostType::POST_TYPE,
				'numberposts'    => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
			)
		);
		foreach ( $campaigns as $cid ) {
			wp_delete_post( (int) $cid, true );
		}

		// Delete ALL lists (both privacy-test-list and newsletter)
		// so other test classes can recreate them cleanly.
		global $wpdb;
		$junction  = \Stampy\Schema::table( 'subscriber_lists', $wpdb );
		$lists_tbl  = \Stampy\Schema::table( 'lists', $wpdb );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM $junction" );
		$wpdb->query( "DELETE FROM $lists_tbl" );
		// phpcs:enable
	}

	/**
	 * Create a confirmed subscriber in a list, or return an existing one.
	 *
	 * Uses find_by_email first to avoid duplicate key errors from the
	 * dbDelta() implicit commit that persists data across tests.
	 *
	 * @param string $email Email address.
	 * @return int Subscriber ID.
	 */
	private function create_subscriber( string $email ): int {
		$repo       = new SubscriberRepository();
		$list_repo  = new ListRepository();

		$subscriber = $repo->find_by_email( $email );
		if ( null === $subscriber ) {
			$subscriber = $repo->create_or_get( $email, 'confirmed' );
		}
		$repo->update_status( (int) $subscriber->id, 'confirmed' );
		$list_repo->add_subscriber( (int) $subscriber->id, $this->list_id );

		return (int) $subscriber->id;
	}

	/**
	 * Create a campaign post with given content.
	 *
	 * @param string $content Post content (block HTML).
	 * @param string $subject Email subject.
	 * @return int Campaign post ID.
	 */
	private function create_campaign( string $content, string $subject ): int {
		$campaign_id = self::factory()->post->create(
			array(
				'post_type'    => CampaignPostType::POST_TYPE,
				'post_title'   => $subject,
				'post_content' => $content,
				'post_status'  => 'publish',
			)
		);

		CampaignPostType::set_subject( $campaign_id, $subject );
		CampaignPostType::set_list_ids( $campaign_id, array( $this->list_id ) );
		CampaignPostType::set_status( $campaign_id, 'draft' );

		return $campaign_id;
	}

	/**
	 * Exporter and eraser should be registered with WP.
	 *
	 * @return void
	 */
	public function test_exporter_and_eraser_registered(): void {
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );

		$found_exporter = false;
		$found_eraser   = false;

		foreach ( $exporters as $exporter ) {
			if ( 'Stampy Subscriber Data' === $exporter['exporter_friendly_name'] ) {
				$found_exporter = true;
				break;
			}
		}

		foreach ( $erasers as $eraser ) {
			if ( 'Stampy Subscriber Data' === $eraser['eraser_friendly_name'] ) {
				$found_eraser = true;
				break;
			}
		}

		$this->assertTrue( $found_exporter, 'Stampy exporter not registered.' );
		$this->assertTrue( $found_eraser, 'Stampy eraser not registered.' );
	}

	/**
	 * Export should return empty when no subscriber exists.
	 *
	 * @return void
	 */
	public function test_export_returns_empty_for_nonexistent_email(): void {
		$result = Privacy::export_data( 'nobody@example.com' );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Export should return subscriber profile data.
	 *
	 * @return void
	 */
	public function test_export_returns_subscriber_profile(): void {
		$this->create_subscriber( 'alice@example.com' );

		$result = Privacy::export_data( 'alice@example.com' );

		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['data'] );

		$profile_group = null;
		foreach ( $result['data'] as $group ) {
			if ( 'stampy-subscriber' === $group['group_id'] ) {
				$profile_group = $group;
				break;
			}
		}

		$this->assertNotNull( $profile_group, 'Subscriber profile group not found.' );
		$this->assertSame( 'Stampy: Subscriber Profile', $profile_group['group_label'] );

		$found_email = false;
		foreach ( $profile_group['data'] as $item ) {
			if ( 'Email' === $item['name'] ) {
				$this->assertSame( 'alice@example.com', $item['value'] );
				$found_email = true;
				break;
			}
		}
		$this->assertTrue( $found_email, 'Email not found in export.' );
	}

	/**
	 * Export should return subscriber attributes (meta).
	 *
	 * @return void
	 */
	public function test_export_returns_subscriber_meta(): void {
		$sid = $this->create_subscriber( 'bob@example.com' );

		$meta_repo = new SubscriberMetaRepository();
		$meta_repo->set( $sid, 'first_name', 'Bob' );
		$meta_repo->set( $sid, 'last_name', 'Smith' );

		$result = Privacy::export_data( 'bob@example.com' );

		$meta_group = null;
		foreach ( $result['data'] as $group ) {
			if ( 'stampy-subscriber-meta' === $group['group_id'] ) {
				$meta_group = $group;
				break;
			}
		}

		$this->assertNotNull( $meta_group, 'Subscriber meta group not found.' );

		$found_first = false;
		foreach ( $meta_group['data'] as $item ) {
			if ( 'first_name' === $item['name'] ) {
				$this->assertSame( 'Bob', $item['value'] );
				$found_first = true;
				break;
			}
		}
		$this->assertTrue( $found_first, 'first_name not found in meta export.' );
	}

	/**
	 * Export should return list memberships.
	 *
	 * @return void
	 */
	public function test_export_returns_list_memberships(): void {
		$this->create_subscriber( 'carol@example.com' );

		$result = Privacy::export_data( 'carol@example.com' );

		$lists_group = null;
		foreach ( $result['data'] as $group ) {
			if ( 'stampy-lists' === $group['group_id'] ) {
				$lists_group = $group;
				break;
			}
		}

		$this->assertNotNull( $lists_group, 'List memberships group not found.' );
		$this->assertNotEmpty( $lists_group['data'] );

		$this->assertStringContainsString( 'Privacy Test List', $lists_group['data'][0]['name'] );
	}

	/**
	 * Export should return campaign recipient data and clicks.
	 *
	 * @return void
	 */
	public function test_export_returns_campaign_recipients_and_clicks(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'dave@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click here</a></p><!-- /wp:paragraph -->',
			'Test Campaign'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		// Record an open and a click.
		global $wpdb;
		$recipients_table = \Stampy\Schema::table( 'campaign_recipients', $wpdb );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recipient = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $recipients_table WHERE campaign_id = %d", $campaign_id )
		);
		// phpcs:enable
		$this->assertNotNull( $recipient );

		$recipient_id = (int) $recipient->id;
		$recipient_repo = new CampaignRecipientRepository();
		$recipient_repo->mark_opened( $recipient_id );
		$recipient_repo->mark_clicked( $recipient_id );
		$recipient_repo->record_click( $recipient_id, 'https://example.com/page' );

		$result = Privacy::export_data( 'dave@example.com' );

		$recipients_group = null;
		$clicks_group     = null;
		foreach ( $result['data'] as $group ) {
			if ( 'stampy-recipients' === $group['group_id'] ) {
				$recipients_group = $group;
			}
			if ( 'stampy-clicks' === $group['group_id'] ) {
				$clicks_group = $group;
			}
		}

		$this->assertNotNull( $recipients_group, 'Recipients group not found.' );
		$this->assertNotEmpty( $recipients_group['data'] );
		$this->assertStringContainsString( 'Test Campaign', $recipients_group['data'][0]['name'] );

		$this->assertNotNull( $clicks_group, 'Clicks group not found.' );
		$this->assertNotEmpty( $clicks_group['data'] );
		$this->assertStringContainsString( 'https://example.com/page', $clicks_group['data'][0]['value'] );
	}

	/**
	 * Erase should remove all subscriber data.
	 *
	 * @return void
	 */
	public function test_erase_removes_all_subscriber_data(): void {
		$sid = $this->create_subscriber( 'eve@example.com' );

		$meta_repo = new SubscriberMetaRepository();
		$meta_repo->set( $sid, 'first_name', 'Eve' );

		$result = Privacy::erase_data( 'eve@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertTrue( $result['done'] );

		// Verify the subscriber is gone.
		$repo = new SubscriberRepository();
		$this->assertNull( $repo->find_by_email( 'eve@example.com' ) );

		// Verify meta is gone.
		$this->assertSame( array(), $meta_repo->get_all( $sid ) );
	}

	/**
	 * Erase should return done=true with no items when subscriber doesn't exist.
	 *
	 * @return void
	 */
	public function test_erase_for_nonexistent_email(): void {
		$result = Privacy::erase_data( 'nobody@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * Erase should remove campaign recipient and click data too.
	 *
	 * @return void
	 */
	public function test_erase_removes_campaign_data(): void {
		$this->create_subscriber( 'frank@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test Campaign'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		// Verify recipient row exists.
		global $wpdb;
		$recipients_table = \Stampy\Schema::table( 'campaign_recipients', $wpdb );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_before = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $recipients_table WHERE campaign_id = %d", $campaign_id )
		);
		// phpcs:enable
		$this->assertSame( 1, $count_before );

		// Record a click.
		$recipient_repo = new CampaignRecipientRepository();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recipient = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $recipients_table WHERE campaign_id = %d", $campaign_id )
		);
		// phpcs:enable
		$recipient_repo->record_click( (int) $recipient->id, 'https://example.com/page' );

		// Count clicks for this specific recipient before erase.
		$clicks_table = \Stampy\Schema::table( 'campaign_clicks', $wpdb );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clicks_before = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $clicks_table WHERE recipient_id = %d", (int) $recipient->id )
		);
		// phpcs:enable
		$this->assertGreaterThanOrEqual( 1, $clicks_before );

		// Erase.
		Privacy::erase_data( 'frank@example.com' );

		// Verify recipient row is gone.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $recipients_table WHERE campaign_id = %d", $campaign_id )
		);
		// phpcs:enable
		$this->assertSame( 0, $count_after );

		// Verify clicks for this recipient are gone.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$clicks_after = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $clicks_table WHERE recipient_id = %d", (int) $recipient->id )
		);
		// phpcs:enable
		$this->assertSame( 0, $clicks_after );
	}
}
