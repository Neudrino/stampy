<?php
/**
 * Integration tests for open/click tracking.
 *
 * Tests pixel injection, link rewriting, toggle behavior (global +
 * per-campaign override), endpoint tamper-rejection, and stats recording.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Campaigns\CampaignPostType;
use Stampy\Campaigns\SendingEngine;
use Stampy\Installer;
use Stampy\Repositories\CampaignRecipientRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Tracking\Tracking;
use Stampy\Tracking\TrackingEndpoints;
use Stampy\Tracking\TrackingSettings;
use WP_UnitTestCase;

/**
 * Tests open/click tracking.
 */
final class TrackingTest extends WP_UnitTestCase {

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

		$list_repo     = new ListRepository();
		$this->list_id = $list_repo->create( 'Newsletter', 'newsletter', 'Test list' );

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
	}

	/**
	 * Create a confirmed subscriber in a list.
	 *
	 * @param string $email Email address.
	 * @return int Subscriber ID.
	 */
	private function create_subscriber( string $email ): int {
		$repo       = new SubscriberRepository();
		$list_repo  = new ListRepository();

		$subscriber = $repo->create_or_get( $email, 'confirmed' );
		$repo->update_status( (int) $subscriber->id, 'confirmed' );
		$list_repo->add_subscriber( (int) $subscriber->id, $this->list_id );

		return (int) $subscriber->id;
	}

	/**
	 * Create a campaign post with given content.
	 *
	 * @param string $content Post content (block HTML).
	 * @param string $subject  Email subject.
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
	 * Test that no pixel is injected when tracking is disabled.
	 *
	 * @return void
	 */
	public function test_no_pixel_when_tracking_disabled(): void {
		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringNotContainsString( 'stampy_trk_r', $body );
		$this->assertStringNotContainsString( 'stampy_trk_sig', $body );
	}

	/**
	 * Test that the open-tracking pixel is injected when tracking is enabled.
	 *
	 * @return void
	 */
	public function test_pixel_injected_when_tracking_enabled(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringContainsString( 'stampy_trk_r', $body );
		$this->assertStringContainsString( 'stampy_trk_sig', $body );
		$this->assertStringContainsString( 'width="1"', $body );
		$this->assertStringContainsString( 'height="1"', $body );
	}

	/**
	 * Test that content links are rewritten with click-tracking when enabled.
	 *
	 * @return void
	 */
	public function test_links_rewritten_when_tracking_enabled(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click here</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringContainsString( 'stampy_clk_r', $body );
		$this->assertStringContainsString( 'stampy_clk_sig', $body );
		$this->assertStringNotContainsString( 'href="https://example.com/page"', $body );
	}

	/**
	 * Test that links are NOT rewritten when tracking is disabled.
	 *
	 * @return void
	 */
	public function test_links_not_rewritten_when_tracking_disabled(): void {
		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click here</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringContainsString( 'href="https://example.com/page"', $body );
		$this->assertStringNotContainsString( 'stampy_clk_r', $body );
	}

	/**
	 * Test per-campaign override: force on overrides global off.
	 *
	 * @return void
	 */
	public function test_per_campaign_override_on(): void {
		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test'
		);

		TrackingSettings::set_campaign_override( $campaign_id, 'on' );

		$this->assertTrue( TrackingSettings::is_tracking_enabled( $campaign_id ) );

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringContainsString( 'stampy_trk_r', $body );
	}

	/**
	 * Test per-campaign override: force off overrides global on.
	 *
	 * @return void
	 */
	public function test_per_campaign_override_off(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test'
		);

		TrackingSettings::set_campaign_override( $campaign_id, 'off' );

		$this->assertFalse( TrackingSettings::is_tracking_enabled( $campaign_id ) );

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringNotContainsString( 'stampy_trk_r', $body );
	}

	/**
	 * Test that mailto: links are not rewritten.
	 *
	 * @return void
	 */
	public function test_mailto_links_not_rewritten(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="mailto:info@example.com">Email us</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringContainsString( 'href="mailto:info@example.com"', $body );
		$this->assertStringNotContainsString( 'stampy_clk_r', $body );
	}

	/**
	 * Test that in-page anchors are not rewritten.
	 *
	 * @return void
	 */
	public function test_anchor_links_not_rewritten(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="#section1">Jump to section</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringContainsString( 'href="#section1"', $body );
		$this->assertStringNotContainsString( 'stampy_clk_r', $body );
	}

	/**
	 * Test that {unsubscribe_url} links are not rewritten with click tracking.
	 *
	 * @return void
	 */
	public function test_unsubscribe_links_not_rewritten(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="{unsubscribe_url}">Unsubscribe</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringContainsString( 'stampy_unsub_s', $body );
		$this->assertStringNotContainsString( 'stampy_clk_r', $body );
	}

	/**
	 * Test open endpoint records the open.
	 *
	 * @return void
	 */
	public function test_open_endpoint_records_open(): void {
		TrackingSettings::set_globally_enabled( true );

		$subscriber_id = $this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$recipient_repo = new CampaignRecipientRepository();
		$progress       = $recipient_repo->get_progress( $campaign_id );
		$this->assertSame( 1, $progress['sent'] );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$this->assertCount( 1, $recipients );
		$recipient_id = (int) $recipients[0]->id;

		$tracking = new Tracking();
		$pixel_url = $tracking->build_open_pixel_url( $recipient_id, $campaign_id );

		$stats_before = $recipient_repo->get_stats( $campaign_id );
		$this->assertSame( 0, $stats_before['opens'] );

		$this->process_open_from_url( $pixel_url );

		$stats_after = $recipient_repo->get_stats( $campaign_id );
		$this->assertSame( 1, $stats_after['opens'] );

		$recipient = $recipient_repo->find( $recipient_id );
		$this->assertNotNull( $recipient->opened_at );
	}

	/**
	 * Test click endpoint records the click and redirects.
	 *
	 * @return void
	 */
	public function test_click_endpoint_records_click_and_redirects(): void {
		TrackingSettings::set_globally_enabled( true );

		$subscriber_id = $this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click here</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$this->assertCount( 1, $recipients );
		$recipient_id = (int) $recipients[0]->id;

		$tracking  = new Tracking();
		$click_url = $tracking->build_click_url( $recipient_id, $campaign_id, 'https://example.com/page' );

		$recipient_repo = new CampaignRecipientRepository();
		$stats          = $recipient_repo->get_stats( $campaign_id );
		$this->assertSame( 0, $stats['clicks'] );

		$this->process_click_from_url( $click_url );

		$stats_after = $recipient_repo->get_stats( $campaign_id );
		$this->assertSame( 1, $stats_after['clicks'] );
		$this->assertSame( 1, $stats_after['total_clicks'] );

		$recipient = $recipient_repo->find( $recipient_id );
		$this->assertNotNull( $recipient->clicked_at );
	}

	/**
	 * Test that a tampered open signature is rejected.
	 *
	 * @return void
	 */
	public function test_tampered_open_signature_rejected(): void {
		TrackingSettings::set_globally_enabled( true );

		$subscriber_id = $this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$recipient_id = (int) $recipients[0]->id;

		$tracking = new Tracking();
		$pixel_url = $tracking->build_open_pixel_url( $recipient_id, $campaign_id );

		$tampered_url = str_replace(
			'stampy_trk_sig=',
			'stampy_trk_sig=deadbeef',
			$pixel_url
		);

		$this->process_open_from_url( $tampered_url );

		$recipient_repo = new CampaignRecipientRepository();
		$stats          = $recipient_repo->get_stats( $campaign_id );
		$this->assertSame( 0, $stats['opens'] );
	}

	/**
	 * Test that a tampered click signature is rejected.
	 *
	 * @return void
	 */
	public function test_tampered_click_signature_rejected(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click here</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$recipient_id = (int) $recipients[0]->id;

		$tracking  = new Tracking();
		$click_url = $tracking->build_click_url( $recipient_id, $campaign_id, 'https://example.com/page' );

		$tampered_url = str_replace(
			'stampy_clk_sig=',
			'stampy_clk_sig=deadbeef',
			$click_url
		);

		$this->process_click_from_url( $tampered_url );

		$recipient_repo = new CampaignRecipientRepository();
		$stats          = $recipient_repo->get_stats( $campaign_id );
		$this->assertSame( 0, $stats['clicks'] );
		$this->assertSame( 0, $stats['total_clicks'] );
	}

	/**
	 * Test that clicking a swapped destination URL is rejected.
	 *
	 * @return void
	 */
	public function test_click_swapped_destination_rejected(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click here</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$recipient_id = (int) $recipients[0]->id;

		$tracking = new Tracking();
		$original_url = $tracking->build_click_url( $recipient_id, $campaign_id, 'https://example.com/page' );

		// Use the signature from original_url but change the destination parameter.
		$query = array();
		parse_str( (string) wp_parse_url( $original_url, PHP_URL_QUERY ), $query );

		// Build a URL with the original signature but a different destination.
		$tampered_url = add_query_arg(
			array(
				Tracking::CLICK_R_VAR   => $recipient_id,
				Tracking::CLICK_C_VAR   => $campaign_id,
				Tracking::CLICK_U_VAR   => rawurlencode( 'https://evil.com/page' ),
				Tracking::CLICK_SIG_VAR => $query[ Tracking::CLICK_SIG_VAR ],
			),
			home_url( '/' )
		);

		$this->process_click_from_url( $tampered_url );

		$recipient_repo = new CampaignRecipientRepository();
		$stats          = $recipient_repo->get_stats( $campaign_id );
		$this->assertSame( 0, $stats['clicks'] );
	}

	/**
	 * Test mark_opened is idempotent (only first call sets opened_at).
	 *
	 * @return void
	 */
	public function test_mark_opened_is_idempotent(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$recipient_id = (int) $recipients[0]->id;

		$tracking = new Tracking();
		$pixel_url = $tracking->build_open_pixel_url( $recipient_id, $campaign_id );

		$this->process_open_from_url( $pixel_url );
		$this->process_open_from_url( $pixel_url );

		$recipient_repo = new CampaignRecipientRepository();
		$stats          = $recipient_repo->get_stats( $campaign_id );
		$this->assertSame( 1, $stats['opens'] );
	}

	/**
	 * Test get_click_summary returns per-URL click counts.
	 *
	 * @return void
	 */
	public function test_click_summary_per_url(): void {
		TrackingSettings::set_globally_enabled( true );

		$this->create_subscriber( 'alice@example.com' );
		$this->create_subscriber( 'bob@example.com' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page1">Link 1</a> <a href="https://example.com/page2">Link 2</a></p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$this->assertCount( 2, $recipients );

		$tracking = new Tracking();

		$click1 = $tracking->build_click_url( (int) $recipients[0]->id, $campaign_id, 'https://example.com/page1' );
		$click2 = $tracking->build_click_url( (int) $recipients[1]->id, $campaign_id, 'https://example.com/page1' );
		$click3 = $tracking->build_click_url( (int) $recipients[0]->id, $campaign_id, 'https://example.com/page2' );

		$this->process_click_from_url( $click1 );
		$this->process_click_from_url( $click2 );
		$this->process_click_from_url( $click3 );

		$recipient_repo = new CampaignRecipientRepository();
		$summary        = $recipient_repo->get_click_summary( $campaign_id );

		$this->assertCount( 2, $summary );

		$first = $summary[0];
		$this->assertSame( 'https://example.com/page1', $first->url );
		$this->assertSame( 2, (int) $first->cnt );

		$second = $summary[1];
		$this->assertSame( 'https://example.com/page2', $second->url );
		$this->assertSame( 1, (int) $second->cnt );
	}

	/**
	 * Get all recipient rows for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<int, \stdClass>
	 */
	private function get_recipients_for_campaign( int $campaign_id ): array {
		global $wpdb;
		$table = \Stampy\Schema::table( 'campaign_recipients', $wpdb );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE campaign_id = %d", $campaign_id )
		);
		// phpcs:enable

		return null !== $results ? $results : array();
	}

	/**
	 * Process an open-tracking request from a pixel URL.
	 *
	 * Parses the URL, verifies the signature, and records the open —
	 * without calling exit (which would terminate the test).
	 *
	 * @param string $pixel_url Full pixel URL.
	 * @return void
	 */
	private function process_open_from_url( string $pixel_url ): void {
		$query = array();
		parse_str( (string) wp_parse_url( $pixel_url, PHP_URL_QUERY ), $query );

		$recipient_id = isset( $query[ Tracking::OPEN_R_VAR ] ) ? (int) $query[ Tracking::OPEN_R_VAR ] : 0;
		$campaign_id  = isset( $query[ Tracking::OPEN_C_VAR ] ) ? (int) $query[ Tracking::OPEN_C_VAR ] : 0;
		$signature    = isset( $query[ Tracking::OPEN_SIG_VAR ] ) ? (string) $query[ Tracking::OPEN_SIG_VAR ] : '';

		if ( ! Tracking::verify_open_signature( $recipient_id, $campaign_id, $signature ) ) {
			return;
		}

		\Stampy\Tracking\TrackingEndpoints::process_open( $recipient_id, $campaign_id );
	}

	/**
	 * Process a click-tracking request from a click URL.
	 *
	 * Parses the URL, verifies the signature, and records the click —
	 * without calling wp_safe_redirect/exit.
	 *
	 * @param string $click_url Full click URL.
	 * @return void
	 */
	private function process_click_from_url( string $click_url ): void {
		$query = array();
		parse_str( (string) wp_parse_url( $click_url, PHP_URL_QUERY ), $query );

		$recipient_id = isset( $query[ Tracking::CLICK_R_VAR ] ) ? (int) $query[ Tracking::CLICK_R_VAR ] : 0;
		$campaign_id  = isset( $query[ Tracking::CLICK_C_VAR ] ) ? (int) $query[ Tracking::CLICK_C_VAR ] : 0;
		$sig          = isset( $query[ Tracking::CLICK_SIG_VAR ] ) ? (string) $query[ Tracking::CLICK_SIG_VAR ] : '';
		$dest_raw     = isset( $query[ Tracking::CLICK_U_VAR ] ) ? (string) $query[ Tracking::CLICK_U_VAR ] : '';
		$destination  = '' !== $dest_raw ? rawurldecode( $dest_raw ) : '';

		if ( '' === $destination ) {
			return;
		}

		if ( ! Tracking::verify_click_signature( $recipient_id, $campaign_id, $destination, $sig ) ) {
			return;
		}

		\Stampy\Tracking\TrackingEndpoints::process_click( $recipient_id, $campaign_id, $destination );
	}
}
