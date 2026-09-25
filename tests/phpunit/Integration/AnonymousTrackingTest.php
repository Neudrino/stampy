<?php
/**
 * Integration tests for anonymous open/click tracking.
 *
 * Anonymous tracking records HOW MANY recipients opened a campaign
 * email or clicked a link, but never WHO. The anonymous setting only
 * applies while tracking is enabled; with tracking disabled nothing is
 * tracked regardless of the setting. Default: anonymous ON.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Admin\SettingsPage;
use Stampy\Campaigns\CampaignPostType;
use Stampy\Campaigns\SendingEngine;
use Stampy\Installer;
use Stampy\Repositories\CampaignRecipientRepository;
use Stampy\Repositories\CampaignTrackingEventRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Tracking\Tracking;
use Stampy\Tracking\TrackingEndpoints;
use Stampy\Tracking\TrackingSettings;
use WP_UnitTestCase;

/**
 * Tests anonymous tracking.
 */
final class AnonymousTrackingTest extends WP_UnitTestCase {

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

		$list_repo     = new ListRepository();
		$this->list_id = $list_repo->create( 'Newsletter', 'newsletter', 'Test list' );

		unset( $GLOBALS['phpmailer_mock_sent'] );

		// Anonymous is the default — tests opt out explicitly.
		TrackingSettings::delete_anonymous();
		TrackingSettings::set_globally_enabled( true );
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		TrackingSettings::set_globally_enabled( false );
		TrackingSettings::set_anonymous( true );
		unset( $GLOBALS['phpmailer_mock_sent'] );
		parent::tearDown();

		// The custom tables survive between test runs (dbDelta commits the
		// test transaction), while WP core tables are reset — so newly
		// assigned campaign post IDs can collide with stale recipient rows
		// from an earlier run. Purge after the rollback to keep runs
		// idempotent.
		global $wpdb;
		foreach ( array( 'campaign_recipients', 'campaign_clicks', 'campaign_tracking_events' ) as $short ) {
			$table = \Stampy\Schema::table( $short, $wpdb );
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "DELETE FROM $table" );
		}
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
	 * Send a campaign synchronously.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	private function send_campaign( int $campaign_id ): void {
		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );
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

		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE campaign_id = %d", $campaign_id )
		);

		return null !== $results ? $results : array();
	}

	/**
	 * Count anonymous event rows for a campaign.
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $kind        Event kind ('open', 'click', or '' for all).
	 * @return int
	 */
	private function count_events( int $campaign_id, string $kind = '' ): int {
		global $wpdb;
		$table = \Stampy\Schema::table( 'campaign_tracking_events', $wpdb );

		if ( '' !== $kind ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE campaign_id = %d AND kind = %s", $campaign_id, $kind )
			);
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE campaign_id = %d", $campaign_id )
		);
	}

	/**
	 * Process an open-tracking request from a pixel URL.
	 *
	 * Auto-detects anonymous vs personalized URLs, verifies the
	 * signature, and records the open — without calling exit.
	 *
	 * @param string $pixel_url Full pixel URL.
	 * @return void
	 */
	private function open_from_url( string $pixel_url ): void {
		$query = array();
		parse_str( (string) wp_parse_url( $pixel_url, PHP_URL_QUERY ), $query );

		$campaign_id = isset( $query[ Tracking::OPEN_C_VAR ] ) ? (int) $query[ Tracking::OPEN_C_VAR ] : 0;
		$signature   = isset( $query[ Tracking::OPEN_SIG_VAR ] ) ? (string) $query[ Tracking::OPEN_SIG_VAR ] : '';

		if ( ! empty( $query[ Tracking::OPEN_H_VAR ] ) ) {
			$subject_hash = (string) $query[ Tracking::OPEN_H_VAR ];
			if ( Tracking::verify_open_anonymous_signature( $campaign_id, $subject_hash, $signature ) ) {
				TrackingEndpoints::process_open_anonymous( $campaign_id, $subject_hash );
			}
			return;
		}

		$recipient_id = isset( $query[ Tracking::OPEN_R_VAR ] ) ? (int) $query[ Tracking::OPEN_R_VAR ] : 0;
		if ( Tracking::verify_open_signature( $recipient_id, $campaign_id, $signature ) ) {
			TrackingEndpoints::process_open( $recipient_id, $campaign_id );
		}
	}

	/**
	 * Process a click-tracking request from a click URL.
	 *
	 * Auto-detects anonymous vs personalized URLs, verifies the
	 * signature, and records the click — without redirect/exit.
	 *
	 * @param string $click_url Full click URL.
	 * @return void
	 */
	private function click_from_url( string $click_url ): void {
		$query = array();
		parse_str( (string) wp_parse_url( $click_url, PHP_URL_QUERY ), $query );

		$campaign_id = isset( $query[ Tracking::CLICK_C_VAR ] ) ? (int) $query[ Tracking::CLICK_C_VAR ] : 0;
		$sig         = isset( $query[ Tracking::CLICK_SIG_VAR ] ) ? (string) $query[ Tracking::CLICK_SIG_VAR ] : '';
		$dest_raw    = isset( $query[ Tracking::CLICK_U_VAR ] ) ? (string) $query[ Tracking::CLICK_U_VAR ] : '';
		$destination = '' !== $dest_raw ? rawurldecode( $dest_raw ) : '';

		if ( '' === $destination ) {
			return;
		}

		if ( ! empty( $query[ Tracking::CLICK_H_VAR ] ) ) {
			$subject_hash = (string) $query[ Tracking::CLICK_H_VAR ];
			if ( Tracking::verify_click_anonymous_signature( $campaign_id, $subject_hash, $destination, $sig ) ) {
				TrackingEndpoints::process_click_anonymous( $campaign_id, $subject_hash, $destination );
			}
			return;
		}

		$recipient_id = isset( $query[ Tracking::CLICK_R_VAR ] ) ? (int) $query[ Tracking::CLICK_R_VAR ] : 0;
		if ( Tracking::verify_click_signature( $recipient_id, $campaign_id, $destination, $sig ) ) {
			TrackingEndpoints::process_click( $recipient_id, $campaign_id, $destination );
		}
	}

	/**
	 * Extract the first anonymous pixel URL from an email body.
	 *
	 * @param string $body Email HTML body.
	 * @return string Pixel URL (entities decoded).
	 */
	private function extract_pixel_url( string $body ): string {
		$this->assertSame( 1, preg_match( '/src="(http[^"]*stampy_trk_h=[^"]+)"/i', $body, $m ) );
		return str_replace( array( '&amp;', '&#038;' ), '&', $m[1] );
	}

	/**
	 * Extract the first anonymous click URL from an email body.
	 *
	 * @param string $body Email HTML body.
	 * @return string Click URL (entities decoded).
	 */
	private function extract_click_url( string $body ): string {
		$this->assertSame( 1, preg_match( '/href="(http[^"]*stampy_clk_h=[^"]+)"/i', $body, $m ) );
		return str_replace( array( '&amp;', '&#038;' ), '&', $m[1] );
	}

	/**
	 * Anonymous tracking is the default.
	 *
	 * @return void
	 */
	public function test_anonymous_tracking_is_default(): void {
		TrackingSettings::delete_anonymous();

		$this->assertTrue( TrackingSettings::is_anonymous() );
	}

	/**
	 * A fresh install (no options at all) tracks nothing.
	 *
	 * This guards the product default: tracking must be off out of the
	 * box, regardless of the anonymous-tracking default.
	 *
	 * @return void
	 */
	public function test_fresh_install_tracks_nothing(): void {
		TrackingSettings::delete();
		TrackingSettings::delete_anonymous();

		$this->create_subscriber( 'fresh@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click</a></p><!-- /wp:paragraph -->',
			'Fresh install'
		);

		$this->assertFalse( TrackingSettings::is_globally_enabled() );
		$this->assertFalse( TrackingSettings::is_tracking_enabled( $campaign_id ) );

		$this->send_campaign( $campaign_id );

		$body = $GLOBALS['phpmailer_mock_sent'][0]['body'];
		$this->assertStringContainsString( 'href="https://example.com/page"', $body );
		$this->assertStringNotContainsString( 'stampy_trk', $body );
		$this->assertStringNotContainsString( 'stampy_clk', $body );

		$repo = new CampaignRecipientRepository();
		$this->assertSame( 0, $repo->get_stats( $campaign_id )['opens'] );
		$this->assertSame( 0, $repo->get_stats( $campaign_id )['clicks'] );
		$this->assertSame( 0, $this->count_events( $campaign_id ) );
	}

	/**
	 * Tracking disabled means no tracking, regardless of anonymous setting.
	 *
	 * @return void
	 */
	public function test_tracking_disabled_means_no_tracking_regardless_of_anonymous_setting(): void {
		foreach ( array( true, false ) as $anonymous ) {
			TrackingSettings::set_globally_enabled( false );
			TrackingSettings::set_anonymous( $anonymous );

			$email = 'anon-disabled-' . ( $anonymous ? 'on' : 'off' ) . '@stampy.local';
			$this->create_subscriber( $email );

			$campaign_id = $this->create_campaign(
				'<!-- wp:paragraph --><p><a href="https://example.com/page">Click</a></p><!-- /wp:paragraph -->',
				'No tracking ' . ( $anonymous ? 'anon' : 'personal' )
			);

			$this->assertFalse( TrackingSettings::is_tracking_enabled( $campaign_id ) );

			$this->send_campaign( $campaign_id );

			$body = $GLOBALS['phpmailer_mock_sent'][0]['body'];
			$this->assertStringNotContainsString( 'stampy_trk_r', $body );
			$this->assertStringNotContainsString( 'stampy_trk_h', $body );
			$this->assertStringNotContainsString( 'stampy_clk_r', $body );
			$this->assertStringNotContainsString( 'stampy_clk_h', $body );
			$this->assertStringContainsString( 'href="https://example.com/page"', $body );

			// Nothing recorded anywhere.
			$repo = new CampaignRecipientRepository();
			$this->assertSame( 0, $repo->get_stats( $campaign_id )['opens'] );
			$this->assertSame( 0, $repo->get_stats( $campaign_id )['clicks'] );
			$this->assertSame( 0, $this->count_events( $campaign_id ) );
		}
	}

	/**
	 * Anonymous emails carry no recipient IDs in their tracking URLs.
	 *
	 * @return void
	 */
	public function test_anonymous_email_contains_no_recipient_ids(): void {
		$this->create_subscriber( 'alice@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click</a></p><!-- /wp:paragraph -->',
			'Anon body'
		);

		$this->send_campaign( $campaign_id );

		$body = $GLOBALS['phpmailer_mock_sent'][0]['body'];

		$this->assertTrue( TrackingSettings::is_anonymous() );
		$this->assertStringContainsString( 'stampy_trk_h', $body );
		$this->assertStringContainsString( 'stampy_clk_h', $body );
		$this->assertStringNotContainsString( 'stampy_trk_r', $body );
		$this->assertStringNotContainsString( 'stampy_clk_r', $body );
	}

	/**
	 * Anonymous open counts, but records no per-recipient identity.
	 *
	 * @return void
	 */
	public function test_anonymous_open_counts_without_recording_identity(): void {
		$this->create_subscriber( 'alice@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Anon open'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$this->assertCount( 1, $recipients );

		// The pixel URL embedded in the email works end-to-end.
		$pixel_url = $this->extract_pixel_url( $GLOBALS['phpmailer_mock_sent'][0]['body'] );
		$this->open_from_url( $pixel_url );

		$repo = new CampaignRecipientRepository();

		// Count is visible…
		$this->assertSame( 1, $repo->get_stats( $campaign_id )['opens'] );
		// …but the recipient row reveals nothing.
		$recipient = $repo->find( (int) $recipients[0]->id );
		$this->assertNull( $recipient->opened_at );
		$this->assertNull( $recipient->clicked_at );

		// One aggregate event row, keyed by an opaque 64-hex-char hash.
		$this->assertSame( 1, $this->count_events( $campaign_id, 'open' ) );
		$expected_hash = Tracking::subject_hash( (int) $recipients[0]->id, $campaign_id );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $expected_hash );
	}

	/**
	 * Repeated anonymous opens by the same recipient count once.
	 *
	 * @return void
	 */
	public function test_anonymous_open_is_deduplicated(): void {
		$this->create_subscriber( 'alice@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Anon dedup'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );

		$tracking  = new Tracking();
		$pixel_url = $tracking->build_open_pixel_url( (int) $recipients[0]->id, $campaign_id );

		$this->open_from_url( $pixel_url );
		$this->open_from_url( $pixel_url );
		$this->open_from_url( $pixel_url );

		$repo = new CampaignRecipientRepository();
		$this->assertSame( 1, $repo->get_stats( $campaign_id )['opens'] );
		$this->assertSame( 1, $this->count_events( $campaign_id, 'open' ) );
	}

	/**
	 * Opens from two different recipients count as two — still anonymous.
	 *
	 * @return void
	 */
	public function test_anonymous_opens_count_multiple_subscribers(): void {
		$this->create_subscriber( 'alice@stampy.local' );
		$this->create_subscriber( 'bob@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Anon two opens'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$this->assertCount( 2, $recipients );

		$tracking = new Tracking();

		// Subject hashes must differ per recipient (opaque, not comparable
		// across campaigns either).
		$hash_a = Tracking::subject_hash( (int) $recipients[0]->id, $campaign_id );
		$hash_b = Tracking::subject_hash( (int) $recipients[1]->id, $campaign_id );
		$this->assertNotSame( $hash_a, $hash_b );

		foreach ( $recipients as $recipient ) {
			$this->open_from_url(
				$tracking->build_open_pixel_url( (int) $recipient->id, $campaign_id )
			);
		}

		$repo = new CampaignRecipientRepository();
		$this->assertSame( 2, $repo->get_stats( $campaign_id )['opens'] );

		// Still impossible to tell who opened.
		foreach ( $recipients as $recipient ) {
			$row = $repo->find( (int) $recipient->id );
			$this->assertNull( $row->opened_at );
		}
	}

	/**
	 * Anonymous clicks count, keep per-URL summary, record no identity.
	 *
	 * @return void
	 */
	public function test_anonymous_click_counts_and_url_summary(): void {
		$this->create_subscriber( 'alice@stampy.local' );
		$this->create_subscriber( 'bob@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page1">Link 1</a> <a href="https://example.com/page2">Link 2</a></p><!-- /wp:paragraph -->',
			'Anon clicks'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$this->assertCount( 2, $recipients );

		$tracking = new Tracking();

		// Both recipients click page1; recipient 1 also clicks page2.
		$click1 = $tracking->build_click_url( (int) $recipients[0]->id, $campaign_id, 'https://example.com/page1' );
		$click2 = $tracking->build_click_url( (int) $recipients[1]->id, $campaign_id, 'https://example.com/page1' );
		$click3 = $tracking->build_click_url( (int) $recipients[0]->id, $campaign_id, 'https://example.com/page2' );

		$this->click_from_url( $click1 );
		$this->click_from_url( $click2 );
		$this->click_from_url( $click3 );

		$repo  = new CampaignRecipientRepository();
		$stats = $repo->get_stats( $campaign_id );

		// Two unique clickers, three click events.
		$this->assertSame( 2, $stats['clicks'] );
		$this->assertSame( 3, $stats['total_clicks'] );

		// Per-URL summary still works.
		$summary = $repo->get_click_summary( $campaign_id );
		$this->assertCount( 2, $summary );
		$this->assertSame( 'https://example.com/page1', $summary[0]->url );
		$this->assertSame( 2, (int) $summary[0]->cnt );
		$this->assertSame( 'https://example.com/page2', $summary[1]->url );
		$this->assertSame( 1, (int) $summary[1]->cnt );

		// No per-recipient identity recorded.
		foreach ( $recipients as $recipient ) {
			$row = $repo->find( (int) $recipient->id );
			$this->assertNull( $row->clicked_at );
		}
	}

	/**
	 * Repeated anonymous clicks on the same URL by the same person dedup.
	 *
	 * @return void
	 */
	public function test_anonymous_click_dedup_per_person_and_url(): void {
		$this->create_subscriber( 'alice@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page1">Link 1</a></p><!-- /wp:paragraph -->',
			'Anon click dedup'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );

		$tracking = new Tracking();
		$click    = $tracking->build_click_url( (int) $recipients[0]->id, $campaign_id, 'https://example.com/page1' );

		$this->click_from_url( $click );
		$this->click_from_url( $click );

		$repo  = new CampaignRecipientRepository();
		$stats = $repo->get_stats( $campaign_id );

		$this->assertSame( 1, $stats['clicks'] );
		$this->assertSame( 1, $stats['total_clicks'] );
	}

	/**
	 * A tampered anonymous open signature is rejected.
	 *
	 * @return void
	 */
	public function test_anonymous_tampered_open_rejected(): void {
		$this->create_subscriber( 'alice@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Anon tamper open'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );

		$tracking  = new Tracking();
		$pixel_url = $tracking->build_open_pixel_url( (int) $recipients[0]->id, $campaign_id );

		$tampered = str_replace( 'stampy_trk_sig=', 'stampy_trk_sig=deadbeef', $pixel_url );
		$this->assertNotSame( $tampered, $pixel_url );

		$this->open_from_url( $tampered );

		$repo = new CampaignRecipientRepository();
		$this->assertSame( 0, $repo->get_stats( $campaign_id )['opens'] );
		$this->assertSame( 0, $this->count_events( $campaign_id ) );
	}

	/**
	 * A tampered anonymous click signature is rejected.
	 *
	 * @return void
	 */
	public function test_anonymous_tampered_click_rejected(): void {
		$this->create_subscriber( 'alice@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click</a></p><!-- /wp:paragraph -->',
			'Anon tamper click'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );

		$tracking = new Tracking();
		$click    = $tracking->build_click_url( (int) $recipients[0]->id, $campaign_id, 'https://example.com/page' );

		$tampered = str_replace( 'stampy_clk_sig=', 'stampy_clk_sig=deadbeef', $click );

		$this->click_from_url( $tampered );

		$repo = new CampaignRecipientRepository();
		$this->assertSame( 0, $repo->get_stats( $campaign_id )['clicks'] );
		$this->assertSame( 0, $this->count_events( $campaign_id ) );
	}

	/**
	 * A swapped anonymous click destination is rejected.
	 *
	 * @return void
	 */
	public function test_anonymous_swapped_destination_rejected(): void {
		$this->create_subscriber( 'alice@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click</a></p><!-- /wp:paragraph -->',
			'Anon swapped dest'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );

		$tracking = new Tracking();
		$original = $tracking->build_click_url( (int) $recipients[0]->id, $campaign_id, 'https://example.com/page' );

		$query = array();
		parse_str( (string) wp_parse_url( $original, PHP_URL_QUERY ), $query );

		$tampered = add_query_arg(
			array(
				Tracking::CLICK_C_VAR   => (int) $query[ Tracking::CLICK_C_VAR ],
				Tracking::CLICK_H_VAR   => (string) $query[ Tracking::CLICK_H_VAR ],
				Tracking::CLICK_U_VAR   => rawurlencode( 'https://evil.com/page' ),
				Tracking::CLICK_SIG_VAR => (string) $query[ Tracking::CLICK_SIG_VAR ],
			),
			home_url( '/' )
		);

		$this->click_from_url( $tampered );

		$repo = new CampaignRecipientRepository();
		$this->assertSame( 0, $repo->get_stats( $campaign_id )['clicks'] );
		$this->assertSame( 0, $this->count_events( $campaign_id ) );
	}

	/**
	 * Non-anonymous (personalized) tracking still records per-recipient identity.
	 *
	 * @return void
	 */
	public function test_personalized_mode_still_records_identity(): void {
		TrackingSettings::set_anonymous( false );

		$this->create_subscriber( 'alice@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click</a></p><!-- /wp:paragraph -->',
			'Personal mode'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$recipient_id = (int) $recipients[0]->id;

		$body = $GLOBALS['phpmailer_mock_sent'][0]['body'];
		$this->assertStringContainsString( 'stampy_trk_r', $body );
		$this->assertStringContainsString( 'stampy_clk_r', $body );

		$tracking = new Tracking();

		$this->open_from_url( $tracking->build_open_pixel_url( $recipient_id, $campaign_id ) );
		$this->click_from_url( $tracking->build_click_url( $recipient_id, $campaign_id, 'https://example.com/page' ) );

		$repo      = new CampaignRecipientRepository();
		$recipient = $repo->find( $recipient_id );

		$this->assertNotNull( $recipient->opened_at );
		$this->assertNotNull( $recipient->clicked_at );

		// No anonymous events recorded.
		$this->assertSame( 0, $this->count_events( $campaign_id ) );
	}

	/**
	 * Stats merge anonymous and personalized events (mode switched mid-way).
	 *
	 * @return void
	 */
	public function test_stats_merge_anonymous_and_personalized(): void {
		$this->create_subscriber( 'alice@stampy.local' );
		$this->create_subscriber( 'bob@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Merge modes'
		);

		$this->send_campaign( $campaign_id );

		$recipients = $this->get_recipients_for_campaign( $campaign_id );
		$tracking   = new Tracking();

		// Alice opens anonymously…
		$this->open_from_url(
			$tracking->build_open_pixel_url( (int) $recipients[0]->id, $campaign_id )
		);

		// …then personalized mode is switched on and Bob opens.
		TrackingSettings::set_anonymous( false );
		$this->open_from_url(
			$tracking->build_open_pixel_url( (int) $recipients[1]->id, $campaign_id )
		);

		$repo = new CampaignRecipientRepository();
		$this->assertSame( 2, $repo->get_stats( $campaign_id )['opens'] );
	}

	/**
	 * The settings-page save handler persists the anonymous option.
	 *
	 * @return void
	 */
	public function test_settings_save_persists_anonymous_option(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 1 );

		// Unchecked → personalized tracking.
		$_POST = array(
			'action'            => 'stampy_save_smtp_settings',
			'stampy_smtp_nonce'  => wp_create_nonce( 'stampy_save_smtp_settings' ),
			'tracking_enabled'   => '1',
		);
		$_REQUEST = $_POST;

		try {
			SettingsPage::handle_save_settings();
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted.
		}

		$this->assertTrue( TrackingSettings::is_globally_enabled() );
		$this->assertFalse( TrackingSettings::is_anonymous() );

		// Checked → anonymous tracking.
		$_POST['tracking_anonymous'] = '1';
		$_REQUEST                    = $_POST;

		try {
			SettingsPage::handle_save_settings();
		} catch ( \RuntimeException $e ) {
			// Redirect intercepted.
		}

		$this->assertTrue( TrackingSettings::is_anonymous() );

		unset( $_POST, $_REQUEST );
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ), 1 );
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
}
