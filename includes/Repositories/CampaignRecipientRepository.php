<?php
/**
 * Campaign recipient repository.
 *
 * Manages the `campaign_recipients` table: audience resolution at send
 * start, idempotent claiming for batch processing, and progress counts.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Repositories;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Schema;
use stdClass;
use wpdb;

/**
 * Manages campaign recipient records.
 */
class CampaignRecipientRepository {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $wpdb Optional wpdb instance.
	 */
	public function __construct( ?wpdb $wpdb = null ) {
		$this->wpdb = $wpdb ?? $GLOBALS['wpdb'];
	}

	/**
	 * Get the fully-qualified table name.
	 *
	 * @return string
	 */
	private function table(): string {
		return Schema::table( 'campaign_recipients', $this->wpdb );
	}

	/**
	 * Resolve the audience for a campaign and insert recipient rows.
	 *
	 * Selects all confirmed subscribers who are subscribed to at least
	 * one of the campaign's target lists, deduplicated by subscriber_id.
	 * Each row starts with status 'queued'.
	 *
	 * @param int   $campaign_id Campaign post ID.
	 * @param int[] $list_ids   Target list IDs.
	 * @return int Number of recipients queued.
	 */
	public function queue_audience( int $campaign_id, array $list_ids ): int {
		if ( empty( $list_ids ) ) {
			return 0;
		}

		$wpdb        = $this->wpdb;
		$table       = $this->table();
		$subscribers = Schema::table( 'subscribers', $wpdb );
		$junction    = Schema::table( 'subscriber_lists', $wpdb );

		$list_placeholders = implode( ',', array_fill( 0, count( $list_ids ), '%d' ) );
		$list_args         = array_map( 'intval', $list_ids );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT s.id, s.email
				FROM $subscribers s
				INNER JOIN $junction sl ON s.id = sl.subscriber_id
				WHERE s.status = 'confirmed'
					AND sl.status = 'subscribed'
					AND sl.list_id IN ($list_placeholders)",
				$list_args
			)
		);
		// phpcs:enable

		if ( null === $results || empty( $results ) ) {
			return 0;
		}

		$now      = current_time( 'mysql', true );
		$inserted = 0;

		foreach ( $results as $row ) {
			$wpdb->insert(
				$table,
				array(
					'campaign_id'   => $campaign_id,
					'subscriber_id' => (int) $row->id,
					'status'        => 'queued',
				),
				array( '%d', '%d', '%s' )
			);

			if ( false !== $wpdb->insert_id ) {
				++$inserted;
			}
		}

		return $inserted;
	}

	/**
	 * Claim a batch of recipients for sending.
	 *
	 * Uses a conditional UPDATE to atomically claim rows:
	 * `SET status='sending' WHERE id=? AND status='queued'`.
	 * If 0 rows are affected, the row was already claimed by another
	 * worker (or a retry) — guaranteeing no double-send.
	 *
	 * Also re-queues rows stuck in 'sending' longer than the timeout
	 * (based on claimed_at timestamp).
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @param int $batch_size  Max recipients per batch.
	 * @return array<int, stdClass> Claimed recipient rows.
	 */
	public function claim_batch( int $campaign_id, int $batch_size ): array {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		$stuck_timeout = (int) apply_filters( 'stampy_stuck_send_timeout', 15 * MINUTE_IN_SECONDS );
		$stuck_before  = gmdate( 'Y-m-d H:i:s', time() - $stuck_timeout );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		// Re-queue rows stuck in 'sending' longer than the timeout.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET status = 'queued', claimed_at = NULL WHERE campaign_id = %d AND status = 'sending' AND claimed_at < %s",
				$campaign_id,
				$stuck_before
			)
		);

		// Get the next batch of queued recipient IDs.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE campaign_id = %d AND status = 'queued' ORDER BY id ASC LIMIT %d",
				$campaign_id,
				$batch_size
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		$now = current_time( 'mysql', true );

		// Claim each row individually via conditional UPDATE.
		$claimed = array();
		foreach ( $ids as $id ) {
			$affected = $wpdb->query(
				$wpdb->prepare(
					"UPDATE $table SET status = 'sending', claimed_at = %s WHERE id = %d AND status = 'queued'",
					$now,
					(int) $id
				)
			);

			if ( 0 !== (int) $affected ) {
				$row = $wpdb->get_row(
					$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id )
				);
				if ( null !== $row ) {
					$claimed[] = $row;
				}
			}
		}

		// phpcs:enable

		return $claimed;
	}

	/**
	 * Mark a recipient as sent.
	 *
	 * @param int $recipient_id Recipient row ID.
	 * @return void
	 */
	public function mark_sent( int $recipient_id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );

		$wpdb->update(
			$table,
			array(
				'status'  => 'sent',
				'sent_at' => $now,
			),
			array( 'id' => $recipient_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Mark a recipient as failed.
	 *
	 * @param int $recipient_id Recipient row ID.
	 * @return void
	 */
	public function mark_failed( int $recipient_id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		$wpdb->update(
			$table,
			array( 'status' => 'failed' ),
			array( 'id' => $recipient_id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Count recipients by status for a campaign.
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $status      Optional status filter.
	 * @return int
	 */
	public function count( int $campaign_id, string $status = '' ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $status ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $table WHERE campaign_id = %d AND status = %s",
					$campaign_id,
					$status
				)
			);
		}
		// phpcs:enable

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE campaign_id = %d",
				$campaign_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Get progress counts for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array{total: int, queued: int, sending: int, sent: int, failed: int}
	 */
	public function get_progress( int $campaign_id ): array {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) as cnt FROM $table WHERE campaign_id = %d GROUP BY status",
				$campaign_id
			)
		);
		// phpcs:enable

		$counts = array(
			'total'   => 0,
			'queued'  => 0,
			'sending' => 0,
			'sent'    => 0,
			'failed'  => 0,
		);

		foreach ( null !== $results ? $results : array() as $row ) {
			if ( isset( $counts[ $row->status ] ) ) {
				$counts[ $row->status ] = (int) $row->cnt;
			}
			$counts['total'] += (int) $row->cnt;
		}

		return $counts;
	}

	/**
	 * Delete all recipients for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	public function delete_all_for_campaign( int $campaign_id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM $table WHERE campaign_id = %d", $campaign_id )
		);
		// phpcs:enable
	}

	/**
	 * Find a recipient by ID.
	 *
	 * @param int $id Recipient row ID.
	 * @return stdClass|null
	 */
	public function find( int $id ): ?stdClass {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
		// phpcs:enable

		return null !== $row ? $row : null;
	}

	/**
	 * Mark a recipient as opened (open-tracking pixel hit).
	 *
	 * Idempotent: only sets opened_at on the first call (if NULL).
	 *
	 * @param int $recipient_id Recipient row ID.
	 * @return void
	 */
	public function mark_opened( int $recipient_id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET opened_at = %s WHERE id = %d AND opened_at IS NULL",
				$now,
				$recipient_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Mark a recipient as clicked (click-tracking redirect hit).
	 *
	 * Idempotent: only sets clicked_at on the first call (if NULL).
	 *
	 * @param int $recipient_id Recipient row ID.
	 * @return void
	 */
	public function mark_clicked( int $recipient_id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $table SET clicked_at = %s WHERE id = %d AND clicked_at IS NULL",
				$now,
				$recipient_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Record a click event in the campaign_clicks table.
	 *
	 * @param int    $recipient_id Recipient row ID.
	 * @param string $url          Clicked URL.
	 * @return void
	 */
	public function record_click( int $recipient_id, string $url ): void {
		$wpdb  = $this->wpdb;
		$table = Schema::table( 'campaign_clicks', $wpdb );
		$now   = current_time( 'mysql', true );

		$wpdb->insert(
			$table,
			array(
				'recipient_id' => $recipient_id,
				'url'          => $url,
				'clicked_at'   => $now,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Get tracking stats for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array{opens: int, clicks: int, total_clicks: int}
	 */
	public function get_stats( int $campaign_id ): array {
		$wpdb         = $this->wpdb;
		$table        = $this->table();
		$clicks_table = Schema::table( 'campaign_clicks', $wpdb );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$opens = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE campaign_id = %d AND opened_at IS NOT NULL",
				$campaign_id
			)
		);

		$clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE campaign_id = %d AND clicked_at IS NOT NULL",
				$campaign_id
			)
		);
		// phpcs:enable

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total_clicks = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $clicks_table c INNER JOIN $table r ON c.recipient_id = r.id WHERE r.campaign_id = %d",
				$campaign_id
			)
		);
		// phpcs:enable

		return array(
			'opens'        => $opens,
			'clicks'       => $clicks,
			'total_clicks' => $total_clicks,
		);
	}

	/**
	 * Get per-URL click counts for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<int, stdClass> Rows with `url` and `cnt`.
	 */
	public function get_click_summary( int $campaign_id ): array {
		$wpdb         = $this->wpdb;
		$table        = $this->table();
		$clicks_table = Schema::table( 'campaign_clicks', $wpdb );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.url, COUNT(*) as cnt
				FROM $clicks_table c
				INNER JOIN $table r ON c.recipient_id = r.id
				WHERE r.campaign_id = %d
				GROUP BY c.url
				ORDER BY cnt DESC",
				$campaign_id
			)
		);
		// phpcs:enable

		return null !== $results ? $results : array();
	}
}
