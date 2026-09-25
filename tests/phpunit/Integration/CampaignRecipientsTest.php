<?php
/**
 * Integration tests for the campaign recipients view and the send-time
 * tracking-mode snapshot.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Admin\CampaignRecipientsPage;
use Stampy\Admin\CampaignSendPage;
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
 * Tests recipients listing + tracking mode snapshot.
 */
final class CampaignRecipientsTest extends WP_UnitTestCase {

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

		TrackingSettings::set_anonymous( true );
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
		unset( $GLOBALS['phpmailer_mock_sent'], $_GET, $_POST, $_REQUEST );
		parent::tearDown();

		// Purge the custom tables after the rollback (see AGENTS.md:
		// custom tables survive between test runs and post IDs restart low).
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
		$repo      = new SubscriberRepository();
		$list_repo = new ListRepository();

		$subscriber = $repo->create_or_get( $email, 'confirmed' );
		$repo->update_status( (int) $subscriber->id, 'confirmed' );
		$list_repo->add_subscriber( (int) $subscriber->id, $this->list_id );

		return (int) $subscriber->id;
	}

	/**
	 * Create a campaign.
	 *
	 * @param string $content Post content.
	 * @param string $subject Subject.
	 * @param string $status  Campaign status.
	 * @return int Campaign post ID.
	 */
	private function create_campaign( string $content, string $subject, string $status = 'draft' ): int {
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
		CampaignPostType::set_status( $campaign_id, $status );

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
	 * Get recipient row IDs for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return int[]
	 */
	private function get_recipient_ids( int $campaign_id ): array {
		$repo = new CampaignRecipientRepository();
		$ids  = array();
		foreach ( $repo->get_recipients( $campaign_id ) as $row ) {
			$ids[] = (int) $row->id;
		}
		return $ids;
	}

	/**
	 * resolve_current_mode returns off when tracking is disabled.
	 *
	 * @return void
	 */
	public function test_resolve_current_mode_off_when_disabled(): void {
		TrackingSettings::set_globally_enabled( false );

		$this->assertSame( TrackingSettings::MODE_OFF, TrackingSettings::resolve_current_mode( 123 ) );
	}

	/**
	 * resolve_current_mode returns anonymous/personalized accordingly.
	 *
	 * @return void
	 */
	public function test_resolve_current_mode_reflects_anonymous_setting(): void {
		TrackingSettings::set_anonymous( true );
		$this->assertSame( TrackingSettings::MODE_ANONYMOUS, TrackingSettings::resolve_current_mode( 123 ) );

		TrackingSettings::set_anonymous( false );
		$this->assertSame( TrackingSettings::MODE_PERSONALIZED, TrackingSettings::resolve_current_mode( 123 ) );
	}

	/**
	 * start_send snapshots the tracking mode for each mode.
	 *
	 * @return void
	 */
	public function test_start_send_snapshots_tracking_mode(): void {
		$cases = array(
			'off'          => array( false, true, TrackingSettings::MODE_OFF ),
			'anonymous'    => array( true, true, TrackingSettings::MODE_ANONYMOUS ),
			'personalized' => array( true, false, TrackingSettings::MODE_PERSONALIZED ),
		);

		foreach ( $cases as $label => $case ) {
			list( $enabled, $anonymous, $expected ) = $case;

			TrackingSettings::set_globally_enabled( $enabled );
			TrackingSettings::set_anonymous( $anonymous );

			$this->create_subscriber( 'mode-' . $label . '@stampy.local' );
			$campaign_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', 'Mode ' . $label );

			$this->send_campaign( $campaign_id );

			$this->assertSame( $expected, TrackingSettings::get_campaign_sent_mode( $campaign_id ) );
		}
	}

	/**
	 * The list column shows the send-time mode, not the current setting.
	 *
	 * @return void
	 */
	public function test_display_mode_uses_snapshot_after_setting_change(): void {
		TrackingSettings::set_globally_enabled( true );
		TrackingSettings::set_anonymous( false );

		$this->create_subscriber( 'snapshot@stampy.local' );
		$campaign_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', 'Snapshot' );

		$this->send_campaign( $campaign_id );
		$this->assertSame( TrackingSettings::MODE_PERSONALIZED, CampaignPostType::get_tracking_display_mode( $campaign_id ) );

		// Change the global configuration afterwards.
		TrackingSettings::set_globally_enabled( false );
		$this->assertSame( TrackingSettings::MODE_OFF, TrackingSettings::resolve_current_mode( $campaign_id ) );

		// The already-sent campaign still reports its send-time mode.
		$this->assertSame( TrackingSettings::MODE_PERSONALIZED, CampaignPostType::get_tracking_display_mode( $campaign_id ) );

		// A draft reflects the current configuration.
		$draft_id = $this->create_campaign( '<!-- wp:paragraph --><p>Draft</p><!-- /wp:paragraph -->', 'Draft' );
		$this->assertSame( TrackingSettings::MODE_OFF, CampaignPostType::get_tracking_display_mode( $draft_id ) );
	}

	/**
	 * Recipients view lists emails and aggregates clicks.
	 *
	 * @return void
	 */
	public function test_recipients_listing_includes_email_and_clicks(): void {
		TrackingSettings::set_anonymous( false );

		$this->create_subscriber( 'alice@stampy.local' );
		$this->create_subscriber( 'bob@stampy.local' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p><a href="https://example.com/page">Click</a></p><!-- /wp:paragraph -->',
			'Recipients'
		);

		$this->send_campaign( $campaign_id );

		$recipient_ids = $this->get_recipient_ids( $campaign_id );
		$this->assertCount( 2, $recipient_ids );

		TrackingEndpoints::process_open( $recipient_ids[0], $campaign_id );
		TrackingEndpoints::process_click( $recipient_ids[0], $campaign_id, 'https://example.com/page' );

		$repo = new CampaignRecipientRepository();
		$rows = $repo->get_recipients( $campaign_id );

		$this->assertCount( 2, $rows );

		$by_email = array();
		foreach ( $rows as $row ) {
			$by_email[ (string) $row->email ] = $row;
		}

		$this->assertArrayHasKey( 'alice@stampy.local', $by_email );
		$this->assertSame( 'sent', (string) $by_email['alice@stampy.local']->status );
		$this->assertNotNull( $by_email['alice@stampy.local']->opened_at );
		$this->assertNotNull( $by_email['alice@stampy.local']->clicked_at );
		$this->assertSame( 1, (int) $by_email['alice@stampy.local']->click_count );
		$this->assertStringContainsString( 'https://example.com/page', (string) $by_email['alice@stampy.local']->urls );

		// Bob did nothing.
		$this->assertNull( $by_email['bob@stampy.local']->opened_at );
		$this->assertSame( 0, (int) $by_email['bob@stampy.local']->click_count );
	}

	/**
	 * Search and count filter by email.
	 *
	 * @return void
	 */
	public function test_recipients_search_filters_by_email(): void {
		$this->create_subscriber( 'alice@stampy.local' );
		$this->create_subscriber( 'bob@stampy.local' );

		$campaign_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', 'Search' );
		$this->send_campaign( $campaign_id );

		$repo = new CampaignRecipientRepository();

		$this->assertSame( 2, $repo->count_recipients( $campaign_id ) );
		$this->assertSame( 1, $repo->count_recipients( $campaign_id, 'alice' ) );

		$rows = $repo->get_recipients( $campaign_id, array( 'search' => 'alice' ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'alice@stampy.local', (string) $rows[0]->email );
	}

	/**
	 * Anonymous campaigns keep recipient rows identity-free.
	 *
	 * @return void
	 */
	public function test_anonymous_recipients_have_no_identity(): void {
		TrackingSettings::set_anonymous( true );

		$this->create_subscriber( 'anon@stampy.local' );
		$campaign_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', 'Anon recipients' );

		$this->send_campaign( $campaign_id );

		$recipient_ids = $this->get_recipient_ids( $campaign_id );
		TrackingEndpoints::process_open_anonymous(
			$campaign_id,
			Tracking::subject_hash( $recipient_ids[0], $campaign_id )
		);

		$repo = new CampaignRecipientRepository();
		$rows = $repo->get_recipients( $campaign_id );

		$this->assertCount( 1, $rows );
		$this->assertNull( $rows[0]->opened_at );
		$this->assertSame( 0, (int) $rows[0]->click_count );

		// Aggregate count is still visible.
		$this->assertSame( 1, $repo->get_stats( $campaign_id )['opens'] );
	}

	/**
	 * The recipients row action appears for sent campaigns only.
	 *
	 * @return void
	 */
	public function test_recipients_row_action(): void {
		$draft_id = $this->create_campaign( '<!-- wp:paragraph --><p>Draft</p><!-- /wp:paragraph -->', 'Draft' );
		$draft    = get_post( $draft_id );
		$actions  = CampaignSendPage::add_row_action( array(), $draft );
		$this->assertArrayNotHasKey( 'stampy_recipients', $actions );

		$this->create_subscriber( 'sent@stampy.local' );
		$sent_id = $this->create_campaign( '<!-- wp:paragraph --><p>Sent</p><!-- /wp:paragraph -->', 'Sent' );
		$this->send_campaign( $sent_id );

		$sent    = get_post( $sent_id );
		$actions = CampaignSendPage::add_row_action( array(), $sent );
		$this->assertArrayHasKey( 'stampy_recipients', $actions );
		$this->assertStringContainsString( CampaignRecipientsPage::SLUG, $actions['stampy_recipients'] );
	}

	/**
	 * The admin page renders the recipient email for a sent campaign.
	 *
	 * @return void
	 */
	public function test_recipients_page_renders(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->create_subscriber( 'page@stampy.local' );
		$campaign_id = $this->create_campaign( '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->', 'Page' );
		$this->send_campaign( $campaign_id );

		$_GET     = array(
			'page'     => CampaignRecipientsPage::SLUG,
			'campaign' => (string) $campaign_id,
		);
		$_REQUEST = $_GET;

		ob_start();
		CampaignRecipientsPage::render();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'page@stampy.local', $output );
		$this->assertStringContainsString( 'Campaign Recipients', $output );
	}
}
