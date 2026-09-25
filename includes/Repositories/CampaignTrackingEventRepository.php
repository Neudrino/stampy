<?php
/**
 * Campaign tracking event repository (anonymous mode).
 *
 * Manages the `campaign_tracking_events` table. Rows record aggregate
 * tracking events (opens/clicks) keyed by an irreversible subject hash
 * instead of a recipient ID — the site can still count how many people
 * opened a campaign or clicked a link, but who did it cannot be
 * determined from the stored data. `INSERT IGNORE` + a unique key on
 * (campaign, kind, subject, url) makes one row per event type and
 * subject, giving unique-person counts.
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
 * Manages anonymous campaign tracking events.
 */
class CampaignTrackingEventRepository {

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
		return Schema::table( 'campaign_tracking_events', $this->wpdb );
	}

	/**
	 * Record an anonymous open event.
	 *
	 * Idempotent per subject: a UNIQUE key on
	 * (campaign, kind, subject, url) plus `INSERT IGNORE` means a
	 * repeated open by the same recipient is skipped.
	 *
	 * @param int    $campaign_id  Campaign post ID.
	 * @param string $subject_hash Keyed HMAC subject hash.
	 * @return void
	 */
	public function record_open( int $campaign_id, string $subject_hash ): void {
		$this->record( $campaign_id, 'open', $subject_hash, '', null );
	}

	/**
	 * Record an anonymous click event.
	 *
	 * Unique per subject and URL: repeated clicks on the same URL by
	 * the same recipient are skipped; different URLs are stored
	 * separately (enabling per-URL click counts).
	 *
	 * @param int    $campaign_id  Campaign post ID.
	 * @param string $subject_hash Keyed HMAC subject hash.
	 * @param string $url          Clicked URL.
	 * @return void
	 */
	public function record_click( int $campaign_id, string $subject_hash, string $url ): void {
		$this->record( $campaign_id, 'click', $subject_hash, self::url_hash( $url ), $url );
	}

	/**
	 * Insert an event row, ignoring duplicates.
	 *
	 * @param int         $campaign_id  Campaign post ID.
	 * @param string      $kind         Event kind ('open' or 'click').
	 * @param string      $subject_hash Keyed HMAC subject hash.
	 * @param string      $url_hash     SHA-256 of the clicked URL (or '' for opens).
	 * @param string|null $url          Clicked URL (or null for opens).
	 * @return void
	 */
	private function record( int $campaign_id, string $kind, string $subject_hash, string $url_hash, ?string $url ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO $table (campaign_id, kind, subject_hash, url_hash, url, created_at)
				VALUES (%d, %s, %s, %s, %s, %s)",
				$campaign_id,
				$kind,
				$subject_hash,
				$url_hash,
				$url,
				$now
			)
		);
		// phpcs:enable
	}

	/**
	 * Count unique opens for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return int
	 */
	public function count_opens( int $campaign_id ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE campaign_id = %d AND kind = 'open'",
				$campaign_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Count unique clickers for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return int
	 */
	public function count_unique_clickers( int $campaign_id ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT subject_hash) FROM $table WHERE campaign_id = %d AND kind = 'click'",
				$campaign_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Count all recorded click events for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return int
	 */
	public function count_clicks( int $campaign_id ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE campaign_id = %d AND kind = 'click'",
				$campaign_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Get per-URL unique-clicker counts for a campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return array<int, stdClass> Rows with `url` and `cnt`.
	 */
	public function click_summary( int $campaign_id ): array {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT url, COUNT(*) as cnt
				FROM $table
				WHERE campaign_id = %d AND kind = 'click' AND url IS NOT NULL
				GROUP BY url
				ORDER BY cnt DESC",
				$campaign_id
			)
		);
		// phpcs:enable

		return null !== $results ? $results : array();
	}

	/**
	 * Hash a URL for the dedup key.
	 *
	 * @param string $url The clicked URL.
	 * @return string 64-character hex hash.
	 */
	private static function url_hash( string $url ): string {
		return hash( 'sha256', $url );
	}
}
