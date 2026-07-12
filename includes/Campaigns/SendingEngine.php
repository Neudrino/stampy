<?php
/**
 * Sending engine for Stampy campaigns.
 *
 * Orchestrates the full send pipeline:
 * 1. Start: resolve audience, snapshot HTML/text, schedule first batch.
 * 2. Batch: claim recipients, personalize, send via wp_mail, mark sent/failed.
 * 3. Complete: update campaign status, fire action.
 *
 * Uses Action Scheduler for batched self-rescheduling actions. Each
 * batch claims up to `stampy_batch_size` (default 50) recipients,
 * sends them, then schedules the next batch if more remain.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Campaigns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\CampaignRecipientRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Smtp\SmtpSettings;
use Stampy\Tracking\Tracking;
use Stampy\Tracking\TrackingSettings;

/**
 * Core sending engine for campaign delivery.
 */
final class SendingEngine {

	/**
	 * Action Scheduler hook for batch processing.
	 */
	public const BATCH_HOOK = 'stampy_process_campaign_batch';

	/**
	 * Action Scheduler group for campaign actions.
	 */
	public const AS_GROUP = 'stampy_campaigns';

	/**
	 * Meta key for the snapshotted email subject.
	 */
	public const META_SUBJECT_SNAPSHOT = 'stampy_campaign_subject_snapshot';

	/**
	 * Meta key for the snapshotted HTML body.
	 */
	public const META_HTML_SNAPSHOT = 'stampy_campaign_html_snapshot';

	/**
	 * Meta key for the snapshotted plain-text body.
	 */
	public const META_TEXT_SNAPSHOT = 'stampy_campaign_text_snapshot';

	/**
	 * Meta key for the send-start timestamp.
	 */
	public const META_STARTED_AT = 'stampy_campaign_started_at';

	/**
	 * Meta key for the send-completed timestamp.
	 */
	public const META_COMPLETED_AT = 'stampy_campaign_completed_at';

	/**
	 * Recipient repository.
	 *
	 * @var CampaignRecipientRepository
	 */
	private CampaignRecipientRepository $recipients;

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * Email renderer.
	 *
	 * @var EmailRenderer
	 */
	private EmailRenderer $renderer;

	/**
	 * Merge-tag registry.
	 *
	 * @var MergeTagRegistry
	 */
	private MergeTagRegistry $merge_tags;

	/**
	 * Tracking instrumenter.
	 *
	 * @var Tracking
	 */
	private Tracking $tracking;

	/**
	 * Constructor.
	 *
	 * @param CampaignRecipientRepository|null $recipients  Optional.
	 * @param SubscriberRepository|null        $subscribers Optional.
	 * @param EmailRenderer|null               $renderer    Optional.
	 * @param MergeTagRegistry|null            $merge_tags  Optional.
	 * @param Tracking|null                    $tracking    Optional.
	 */
	public function __construct(
		?CampaignRecipientRepository $recipients = null,
		?SubscriberRepository $subscribers = null,
		?EmailRenderer $renderer = null,
		?MergeTagRegistry $merge_tags = null,
		?Tracking $tracking = null
	) {
		$this->recipients  = $recipients ?? new CampaignRecipientRepository();
		$this->subscribers = $subscribers ?? new SubscriberRepository();
		$this->renderer    = $renderer ?? new EmailRenderer();
		$this->merge_tags  = $merge_tags ?? new MergeTagRegistry();
		$this->tracking    = $tracking ?? new Tracking();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::BATCH_HOOK, array( self::class, 'process_batch' ), 10, 1 );
	}

	/**
	 * Start a campaign send.
	 *
	 * Pipeline:
	 * 1. Validate campaign is in 'draft' status.
	 * 2. Resolve audience (queue recipients).
	 * 3. Snapshot HTML and plain-text from the current post_content.
	 * 4. Set status to 'sending', record started_at.
	 * 5. Schedule the first batch via Action Scheduler.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array{success: bool, message: string, recipients?: int}
	 */
	public function start_send( int $campaign_id ): array {
		$status = CampaignPostType::get_status( $campaign_id );

		if ( 'draft' !== $status ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: current campaign status */
					__( 'Campaign cannot be started (current status: %s). Only draft campaigns can be sent.', 'stampy' ),
					$status
				),
			);
		}

		$post = get_post( $campaign_id );
		if ( ! $post instanceof \WP_Post ) {
			return array(
				'success' => false,
				'message' => __( 'Campaign not found.', 'stampy' ),
			);
		}

		$list_ids = CampaignPostType::get_list_ids( $campaign_id );
		if ( empty( $list_ids ) ) {
			return array(
				'success' => false,
				'message' => __( 'No target lists selected. Select at least one list before sending.', 'stampy' ),
			);
		}

		$queued = $this->recipients->queue_audience( $campaign_id, $list_ids );

		if ( 0 === $queued ) {
			return array(
				'success' => false,
				'message' => __( 'No confirmed subscribers found in the selected lists.', 'stampy' ),
			);
		}

		$html    = $this->renderer->render_html( $post );
		$text    = $this->renderer->render_text( $post );
		$subject = CampaignPostType::get_subject( $campaign_id );

		update_post_meta( $campaign_id, self::META_HTML_SNAPSHOT, $html );
		update_post_meta( $campaign_id, self::META_TEXT_SNAPSHOT, $text );
		update_post_meta( $campaign_id, self::META_SUBJECT_SNAPSHOT, $subject );
		update_post_meta( $campaign_id, self::META_STARTED_AT, current_time( 'mysql', true ) );

		CampaignPostType::set_status( $campaign_id, 'sending' );

		do_action( 'stampy_campaign_send_started', $campaign_id, $queued );

		$this->schedule_batch( $campaign_id );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %d: number of recipients */
				__( 'Campaign sending started for %d recipients.', 'stampy' ),
				$queued
			),
			'recipients' => $queued,
		);
	}

	/**
	 * Schedule the next batch via Action Scheduler.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	public function schedule_batch( int $campaign_id ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time(),
			self::BATCH_HOOK,
			array( $campaign_id ),
			self::AS_GROUP
		);
	}

	/**
	 * Process a batch of recipients.
	 *
	 * Hooked to the BATCH_HOOK action. Claims a batch, sends each
	 * email, then schedules the next batch if more recipients remain.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	public static function process_batch( int $campaign_id ): void {
		$engine = new self();
		$engine->do_batch( $campaign_id );
	}

	/**
	 * Claim and send a batch of recipients.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	public function do_batch( int $campaign_id ): void {
		$batch_size = (int) apply_filters( 'stampy_batch_size', 50 );
		$batch_size = max( 1, $batch_size );

		$claimed = $this->recipients->claim_batch( $campaign_id, $batch_size );

		if ( empty( $claimed ) ) {
			$this->maybe_complete_send( $campaign_id );
			return;
		}

		$html    = (string) get_post_meta( $campaign_id, self::META_HTML_SNAPSHOT, true );
		$text    = (string) get_post_meta( $campaign_id, self::META_TEXT_SNAPSHOT, true );
		$subject = (string) get_post_meta( $campaign_id, self::META_SUBJECT_SNAPSHOT, true );

		if ( '' === $subject ) {
			$post    = get_post( $campaign_id );
			$subject = $post instanceof \WP_Post ? $post->post_title : __( '(No subject)', 'stampy' );
		}

		$list_ids        = CampaignPostType::get_list_ids( $campaign_id );
		$primary_list_id = ! empty( $list_ids ) ? (int) $list_ids[0] : 0;

		foreach ( $claimed as $recipient ) {
			$this->send_to_recipient(
				$campaign_id,
				(int) $recipient->id,
				(int) $recipient->subscriber_id,
				$subject,
				$html,
				$text,
				$primary_list_id
			);
		}

		$remaining = $this->recipients->count( $campaign_id, 'queued' );

		if ( $remaining > 0 ) {
			$this->schedule_batch( $campaign_id );
		} else {
			$this->maybe_complete_send( $campaign_id );
		}
	}

	/**
	 * Send a personalized email to a single recipient.
	 *
	 * @param int    $campaign_id   Campaign post ID.
	 * @param int    $recipient_id  Recipient row ID.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $subject       Email subject.
	 * @param string $html          HTML body (snapshot).
	 * @param string $text          Plain-text body (snapshot).
	 * @param int    $list_id       Primary list ID (for unsubscribe headers).
	 * @return void
	 */
	private function send_to_recipient(
		int $campaign_id,
		int $recipient_id,
		int $subscriber_id,
		string $subject,
		string $html,
		string $text,
		int $list_id
	): void {
		$subscriber = $this->subscribers->find( $subscriber_id );

		if ( null === $subscriber ) {
			$this->recipients->mark_failed( $recipient_id );
			return;
		}

		$personalized_html    = $this->merge_tags->replace( $html, $subscriber_id, $campaign_id );
		$personalized_text    = $this->merge_tags->replace( $text, $subscriber_id, $campaign_id );
		$personalized_subject = $this->merge_tags->replace( $subject, $subscriber_id, $campaign_id );

		$tracking_enabled = TrackingSettings::is_tracking_enabled( $campaign_id );

		if ( $tracking_enabled ) {
			$personalized_html = $this->tracking->rewrite_click_links( $personalized_html, $recipient_id, $campaign_id );
			$personalized_html = $this->tracking->inject_open_pixel( $personalized_html, $recipient_id, $campaign_id );
		}

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$unsub_headers = $this->merge_tags->build_unsubscribe_headers( $subscriber_id, $list_id );
		foreach ( $unsub_headers as $header ) {
			$headers[] = $header;
		}

		$headers = apply_filters( 'stampy_campaign_email_headers', $headers, $campaign_id, $subscriber_id );

		$body = $personalized_html;
		$alt  = $personalized_text;

		$body = apply_filters( 'stampy_campaign_email_body', $body, $campaign_id, $subscriber_id );

		$sent = $this->send_email(
			(string) $subscriber->email,
			$personalized_subject,
			$body,
			$headers,
			$alt
		);

		if ( $sent ) {
			$this->recipients->mark_sent( $recipient_id );
			do_action( 'stampy_campaign_email_sent', $campaign_id, $subscriber_id, $recipient_id );
		} else {
			$this->recipients->mark_failed( $recipient_id );
			do_action( 'stampy_campaign_email_failed', $campaign_id, $subscriber_id, $recipient_id );
		}
	}

	/**
	 * Send an email with both HTML and plain-text parts.
	 *
	 * Uses PHPMailer directly (via the `phpmailer_init` action) to set
	 * the AltBody for multipart/alternative.
	 *
	 * @param string        $to      Recipient email.
	 * @param string        $subject Email subject.
	 * @param string        $body    HTML body.
	 * @param array<string> $headers Email headers.
	 * @param string        $alt     Plain-text alternative.
	 * @return bool
	 */
	private function send_email( string $to, string $subject, string $body, array $headers, string $alt ): bool {
		add_action(
			'phpmailer_init',
			$callback = function ( $phpmailer ) use ( $alt ): void {
				// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$phpmailer->AltBody = $alt;
				// phpcs:enable
			}
		);

		try {
			$result = wp_mail( $to, $subject, $body, $headers );
		} finally {
			remove_action( 'phpmailer_init', $callback );
		}

		return $result;
	}

	/**
	 * Check if all recipients are done and complete the campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	private function maybe_complete_send( int $campaign_id ): void {
		$progress = $this->recipients->get_progress( $campaign_id );

		$pending = $progress['queued'] + $progress['sending'];

		if ( $pending > 0 ) {
			return;
		}

		CampaignPostType::set_status( $campaign_id, 'sent' );
		update_post_meta( $campaign_id, self::META_COMPLETED_AT, current_time( 'mysql', true ) );

		do_action( 'stampy_campaign_send_completed', $campaign_id, $progress );
	}

	/**
	 * Cancel a sending campaign.
	 *
	 * Unschedules pending batches and marks remaining recipients as
	 * failed (not sent). Sets campaign status to 'cancelled'.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array{success: bool, message: string}
	 */
	public function cancel_send( int $campaign_id ): array {
		$status = CampaignPostType::get_status( $campaign_id );

		if ( 'sending' !== $status ) {
			return array(
				'success' => false,
				'message' => __( 'Only sending campaigns can be cancelled.', 'stampy' ),
			);
		}

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, array( $campaign_id ), self::AS_GROUP );
		}

		CampaignPostType::set_status( $campaign_id, 'cancelled' );
		update_post_meta( $campaign_id, self::META_COMPLETED_AT, current_time( 'mysql', true ) );

		do_action( 'stampy_campaign_send_cancelled', $campaign_id );

		return array(
			'success' => true,
			'message' => __( 'Campaign send cancelled.', 'stampy' ),
		);
	}

	/**
	 * Get the progress data for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array{total: int, queued: int, sending: int, sent: int, failed: int, status: string}
	 */
	public function get_progress( int $campaign_id ): array {
		$progress           = $this->recipients->get_progress( $campaign_id );
		$progress['status'] = CampaignPostType::get_status( $campaign_id );

		return $progress;
	}

	/**
	 * Run the entire send synchronously (for testing).
	 *
	 * Processes all batches without Action Scheduler by looping
	 * until no queued recipients remain. This is used in integration
	 * tests to test the full send pipeline without AS.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	public function run_synchronous( int $campaign_id ): void {
		do {
			$this->do_batch( $campaign_id );
			$progress = $this->recipients->get_progress( $campaign_id );
		} while ( $progress['queued'] > 0 );
	}
}
