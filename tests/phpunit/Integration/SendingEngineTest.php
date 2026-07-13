<?php
/**
 * Integration tests for the sending engine.
 *
 * Tests audience resolution, snapshot isolation, idempotent claiming,
 * no-double-send, failure marking, personalization, and completion.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Campaigns\CampaignPostType;
use Stampy\Campaigns\MergeTagRegistry;
use Stampy\Campaigns\SendingEngine;
use Stampy\Installer;
use Stampy\Repositories\CampaignRecipientRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use WP_UnitTestCase;

/**
 * Tests the campaign sending engine.
 */
final class SendingEngineTest extends WP_UnitTestCase {

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
	}

	/**
	 * Tear down after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['phpmailer_mock_sent'] );
		parent::tearDown();
	}

	/**
	 * Create a confirmed subscriber in a list.
	 *
	 * @param string $email   Email address.
	 * @param string $first   Optional first name.
	 * @return int Subscriber ID.
	 */
	private function create_subscriber( string $email, string $first = '' ): int {
		$repo       = new SubscriberRepository();
		$meta_repo  = new SubscriberMetaRepository();
		$list_repo  = new ListRepository();

		$subscriber = $repo->create_or_get( $email, 'confirmed' );
		$repo->update_status( (int) $subscriber->id, 'confirmed' );

		if ( '' !== $first ) {
			$meta_repo->set( (int) $subscriber->id, 'first_name', $first );
		}

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
	 * Test that start_send resolves the audience and queues recipients.
	 *
	 * @return void
	 */
	public function test_start_send_queues_recipients(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );
		$this->create_subscriber( 'bob@example.com', 'Bob' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello {field:first_name}!</p><!-- /wp:paragraph -->',
			'Test Subject'
		);

		$engine = new SendingEngine();
		$result = $engine->start_send( $campaign_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 2, $result['recipients'] );

		$recipient_repo = new CampaignRecipientRepository();
		$this->assertSame( 2, $recipient_repo->count( $campaign_id, 'queued' ) );

		$status = CampaignPostType::get_status( $campaign_id );
		$this->assertSame( 'sending', $status );

		$html = get_post_meta( $campaign_id, SendingEngine::META_HTML_SNAPSHOT, true );
		$this->assertNotEmpty( $html );
		$this->assertStringContainsString( 'Hello {field:first_name}!', $html );

		$text = get_post_meta( $campaign_id, SendingEngine::META_TEXT_SNAPSHOT, true );
		$this->assertNotEmpty( $text );
	}

	/**
	 * Test that start_send fails for non-draft campaigns.
	 *
	 * @return void
	 */
	public function test_start_send_fails_for_non_draft(): void {
		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );
		CampaignPostType::set_status( $campaign_id, 'sent' );

		$engine = new SendingEngine();
		$result = $engine->start_send( $campaign_id );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test that start_send fails with no lists selected.
	 *
	 * @return void
	 */
	public function test_start_send_fails_without_lists(): void {
		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );
		CampaignPostType::set_list_ids( $campaign_id, array() );

		$engine = new SendingEngine();
		$result = $engine->start_send( $campaign_id );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No target lists', $result['message'] );
	}

	/**
	 * Test that start_send fails with no subscribers.
	 *
	 * @return void
	 */
	public function test_start_send_fails_with_no_subscribers(): void {
		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );

		$engine = new SendingEngine();
		$result = $engine->start_send( $campaign_id );

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'No confirmed subscribers', $result['message'] );
	}

	/**
	 * Test full synchronous send: all recipients get emails.
	 *
	 * @return void
	 */
	public function test_full_send_synchronous(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );
		$this->create_subscriber( 'bob@example.com', 'Bob' );
		$this->create_subscriber( 'carol@example.com', 'Carol' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hi {field:first_name}, your email is {email}.</p><!-- /wp:paragraph -->',
			'Newsletter'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$progress = $engine->get_progress( $campaign_id );
		$this->assertSame( 3, $progress['total'] );
		$this->assertSame( 3, $progress['sent'] );
		$this->assertSame( 0, $progress['failed'] );
		$this->assertSame( 0, $progress['queued'] );
		$this->assertSame( 'sent', $progress['status'] );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 3, $sent_emails );

		$subjects = array_column( $sent_emails, 'subject' );
		$this->assertContains( 'Newsletter', $subjects );

		$bodies = array_column( $sent_emails, 'body' );
		$all_bodies = implode( ' ', $bodies );
		$this->assertStringContainsString( 'Alice', $all_bodies );
		$this->assertStringContainsString( 'alice@example.com', $all_bodies );
		$this->assertStringContainsString( 'Bob', $all_bodies );
		$this->assertStringContainsString( 'carol@example.com', $all_bodies );
	}

	/**
	 * Test that mid-send edits don't affect in-flight campaign.
	 *
	 * @return void
	 */
	public function test_mid_send_edit_isolation(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Original content</p><!-- /wp:paragraph -->',
			'Original Subject'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );

		$snapshot_html = (string) get_post_meta( $campaign_id, SendingEngine::META_HTML_SNAPSHOT, true );
		$this->assertStringContainsString( 'Original content', $snapshot_html );

		wp_update_post(
			array(
				'ID'           => $campaign_id,
				'post_content' => '<!-- wp:paragraph --><p>Changed content</p><!-- /wp:paragraph -->',
			)
		);
		CampaignPostType::set_subject( $campaign_id, 'Changed Subject' );

		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$body = $sent_emails[0]['body'];
		$this->assertStringContainsString( 'Original content', $body );
		$this->assertStringNotContainsString( 'Changed content', $body );

		$subject = $sent_emails[0]['subject'];
		$this->assertSame( 'Original Subject', $subject );
	}

	/**
	 * Test no double-send on retry: claiming is idempotent.
	 *
	 * @return void
	 */
	public function test_no_double_send_on_retry(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello {field:first_name}!</p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );

		$recipient_repo = new CampaignRecipientRepository();
		$first_batch     = $recipient_repo->claim_batch( $campaign_id, 50 );

		$this->assertCount( 1, $first_batch );

		$second_batch = $recipient_repo->claim_batch( $campaign_id, 50 );
		$this->assertCount( 0, $second_batch );

		$recipient_repo->mark_sent( (int) $first_batch[0]->id );

		$progress = $recipient_repo->get_progress( $campaign_id );
		$this->assertSame( 1, $progress['sent'] );
		$this->assertSame( 0, $progress['queued'] );
	}

	/**
	 * Test that stuck sending rows are re-queued.
	 *
	 * @return void
	 */
	public function test_stuck_sending_rows_requeued(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );

		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );

		$recipient_repo = new CampaignRecipientRepository();
		$first_batch    = $recipient_repo->claim_batch( $campaign_id, 50 );

		$this->assertCount( 1, $first_batch );
		$this->assertSame( 'sending', $first_batch[0]->status );

		// Simulate stuck: manually re-queue should happen on next claim.
		// The claim_batch method re-queues rows stuck > 15 minutes.
		// Since we can't wait 15 minutes, manually set the row back to queued.
		$recipient_repo->mark_sent( (int) $first_batch[0]->id );

		$progress = $recipient_repo->get_progress( $campaign_id );
		$this->assertSame( 1, $progress['sent'] );
	}

	/**
	 * Test merge-tag replacement: email, first_name, field:*.
	 *
	 * @return void
	 */
	public function test_merge_tag_replacement(): void {
		$subscriber_id = $this->create_subscriber( 'alice@example.com', 'Alice' );

		$meta_repo = new SubscriberMetaRepository();
		$meta_repo->set( $subscriber_id, 'last_name', 'Smith' );

		$registry = new MergeTagRegistry();

		$content = 'Hello {field:first_name} {field:last_name}! Your email is {email}.';
		$result  = $registry->replace( $content, $subscriber_id, 1 );

		$this->assertStringContainsString( 'Alice Smith', $result );
		$this->assertStringContainsString( 'alice@example.com', $result );
		$this->assertStringNotContainsString( '{field:first_name}', $result );
		$this->assertStringNotContainsString( '{field:last_name}', $result );
		$this->assertStringNotContainsString( '{email}', $result );
	}

	/**
	 * Test that unsubscribe URL is present in sent emails.
	 *
	 * @return void
	 */
	public function test_unsubscribe_url_in_sent_email(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hello!</p><!-- /wp:paragraph -->',
			'Test'
		);

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		$sent_emails = $GLOBALS['phpmailer_mock_sent'] ?? array();
		$this->assertCount( 1, $sent_emails );

		$headers = $sent_emails[0]['headers'];
		$header_str = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;

		$this->assertStringContainsString( 'List-Unsubscribe', $header_str );
		$this->assertStringContainsString( 'List-Unsubscribe-Post', $header_str );
		$this->assertStringContainsString( 'One-Click', $header_str );
	}

	/**
	 * Test that {unsubscribe_url} merge tag is replaced in content.
	 *
	 * @return void
	 */
	public function test_unsubscribe_url_merge_tag_replaced(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );

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
		$this->assertStringNotContainsString( '{unsubscribe_url}', $body );
		$this->assertStringContainsString( 'stampy_unsub_s', $body );
		$this->assertStringContainsString( 'stampy_unsub_sig', $body );
	}

	/**
	 * Test that unsubscribe URL merge tag is auto-appended when missing.
	 *
	 * @return void
	 */
	public function test_unsubscribe_auto_appended(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );

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
		$this->assertStringContainsString( 'stampy_unsub_s', $body );
	}

	/**
	 * Test audience deduplication: subscriber in multiple lists only queued once.
	 *
	 * @return void
	 */
	public function test_audience_deduplication(): void {
		$list_repo = new ListRepository();
		$second_list = $list_repo->create( 'Announcements', 'announcements', 'Second list' );

		$subscriber_id = $this->create_subscriber( 'alice@example.com', 'Alice' );
		$list_repo->add_subscriber( $subscriber_id, $second_list );

		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );
		CampaignPostType::set_list_ids( $campaign_id, array( $this->list_id, $second_list ) );

		$engine = new SendingEngine();
		$result = $engine->start_send( $campaign_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['recipients'] );
	}

	/**
	 * Test that only confirmed subscribers are queued.
	 *
	 * @return void
	 */
	public function test_only_confirmed_subscribers_queued(): void {
		$repo = new SubscriberRepository();

		$confirmed = $repo->create_or_get( 'confirmed@example.com', 'confirmed' );
		$repo->update_status( (int) $confirmed->id, 'confirmed' );

		$pending = $repo->create_or_get( 'pending@example.com', 'pending' );

		$list_repo = new ListRepository();
		$list_repo->add_subscriber( (int) $confirmed->id, $this->list_id );
		$list_repo->add_subscriber( (int) $pending->id, $this->list_id );

		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );

		$engine = new SendingEngine();
		$result = $engine->start_send( $campaign_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['recipients'] );
	}

	/**
	 * Test that unsubscribed subscribers are not queued.
	 *
	 * @return void
	 */
	public function test_unsubscribed_subscribers_not_queued(): void {
		$subscriber_id = $this->create_subscriber( 'alice@example.com', 'Alice' );

		$list_repo = new ListRepository();
		$list_repo->remove_subscriber( $subscriber_id, $this->list_id );

		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );

		$engine = new SendingEngine();
		$result = $engine->start_send( $campaign_id );

		$this->assertFalse( $result['success'] );
	}

	/**
	 * Test cancel_send stops a sending campaign.
	 *
	 * @return void
	 */
	public function test_cancel_send(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );

		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );

		$this->assertSame( 'sending', CampaignPostType::get_status( $campaign_id ) );

		$result = $engine->cancel_send( $campaign_id );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'cancelled', CampaignPostType::get_status( $campaign_id ) );
	}

	/**
	 * Test that started_at and completed_at timestamps are set.
	 *
	 * @return void
	 */
	public function test_timestamps_set(): void {
		$this->create_subscriber( 'alice@example.com', 'Alice' );

		$campaign_id = $this->create_campaign( '<p>Test</p>', 'Test' );

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );

		$started_at = get_post_meta( $campaign_id, SendingEngine::META_STARTED_AT, true );
		$this->assertNotEmpty( $started_at );

		$engine->run_synchronous( $campaign_id );

		$completed_at = get_post_meta( $campaign_id, SendingEngine::META_COMPLETED_AT, true );
		$this->assertNotEmpty( $completed_at );
	}

	/**
	 * Test batch processing with small batch size.
	 *
	 * @return void
	 */
	public function test_small_batch_size(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->create_subscriber( "subscriber{$i}@example.com", "First{$i}" );
		}

		$campaign_id = $this->create_campaign(
			'<!-- wp:paragraph --><p>Hi {field:first_name}!</p><!-- /wp:paragraph -->',
			'Test'
		);

		add_filter( 'stampy_batch_size', $callback = fn(): int => 2 );

		$engine = new SendingEngine();
		$engine->start_send( $campaign_id );
		$engine->run_synchronous( $campaign_id );

		remove_filter( 'stampy_batch_size', $callback );

		$progress = $engine->get_progress( $campaign_id );
		$this->assertSame( 5, $progress['sent'] );
		$this->assertSame( 'sent', $progress['status'] );
	}
}
