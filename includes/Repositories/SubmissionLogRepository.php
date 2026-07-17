<?php
/**
 * Submission log repository.
 *
 * Provides CRUD for the submission_log table — audit trail of every
 * successful signup submission. Entries are tied to subscribers via
 * subscriber_id and auto-deleted when the subscriber is deleted.
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
 * Manages submission log records.
 */
class SubmissionLogRepository {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private wpdb $wpdb;

	/**
	 * Constructor.
	 *
	 * @param wpdb|null $wpdb Optional.
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
		return Schema::table( 'submission_log', $this->wpdb );
	}

	/**
	 * Log a submission.
	 *
	 * @param int                 $subscriber_id   Subscriber ID.
	 * @param string              $email           Submitter email.
	 * @param array<string,mixed> $form_data       Submitted field values.
	 * @param array<int>          $list_ids        Target list IDs.
	 * @param int|null            $form_id         Form ID (if any).
	 * @param int                 $consent_version Consent-text version.
	 * @param string              $consent_text    Consent text wording at time of submission.
	 * @param string              $status          Submission status (pending|confirmed).
	 * @return int The new log entry ID, or 0 on failure.
	 */
	public function log(
		int $subscriber_id,
		string $email,
		array $form_data,
		array $list_ids,
		?int $form_id,
		int $consent_version,
		string $consent_text,
		string $status = 'pending'
	): int {
		$encoded_data = wp_json_encode( $form_data );
		$encoded_data = false !== $encoded_data ? $encoded_data : '{}';

		$list_ids_str = implode( ',', $list_ids );

		// Suppress DB errors during insert so a failure doesn't corrupt
		// the REST response JSON output (WP_DEBUG prints error HTML).
		$prev_show_errors = $this->wpdb->show_errors( false );

		$this->wpdb->insert(
			$this->table(),
			array(
				'subscriber_id'   => $subscriber_id,
				'email'           => $email,
				'form_data'       => $encoded_data,
				'list_ids'        => $list_ids_str,
				'form_id'         => $form_id,
				'consent_version' => $consent_version,
				'consent_text'    => $consent_text,
				'status'          => $status,
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		$this->wpdb->show_errors( $prev_show_errors );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * Find a log entry by ID.
	 *
	 * @param int $id Log entry ID.
	 * @return stdClass|null
	 */
	public function find( int $id ): ?stdClass {
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
		// phpcs:enable
		return null !== $row ? $row : null;
	}

	/**
	 * Find all log entries by subscriber ID.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return array<int, stdClass>
	 */
	public function find_by_subscriber( int $subscriber_id ): array {
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM $table WHERE subscriber_id = %d ORDER BY created_at DESC", $subscriber_id )
		);
		// phpcs:enable
		return null !== $results ? $results : array();
	}

	/**
	 * Find all log entries by email address.
	 *
	 * @param string $email Email address.
	 * @return array<int, stdClass>
	 */
	public function find_by_email( string $email ): array {
		$table = $this->table();
		$email = strtolower( trim( $email ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$results = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM $table WHERE email = %s ORDER BY created_at DESC", $email )
		);
		// phpcs:enable
		return null !== $results ? $results : array();
	}

	/**
	 * Get paginated log entries with optional email search.
	 *
	 * @param int    $per_page Entries per page.
	 * @param int    $page     Page number (1-based).
	 * @param string $search   Optional email search term.
	 * @return array<int, stdClass>
	 */
	public function get_all( int $per_page = 20, int $page = 1, string $search = '' ): array {
		$table   = $this->table();
		$perpage = max( 1, $per_page );
		$offset  = ( max( 1, $page ) - 1 ) * $perpage;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $search ) {
			$results = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM $table WHERE email LIKE %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
					'%' . $this->wpdb->esc_like( $search ) . '%',
					$perpage,
					$offset
				)
			);
		} else {
			$results = $this->wpdb->get_results(
				$this->wpdb->prepare(
					"SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
					$perpage,
					$offset
				)
			);
		}
		// phpcs:enable

		return null !== $results ? $results : array();
	}

	/**
	 * Count total log entries (with optional search).
	 *
	 * @param string $search Optional email search term.
	 * @return int
	 */
	public function count( string $search = '' ): int {
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $search ) {
			$count = $this->wpdb->get_var(
				$this->wpdb->prepare(
					"SELECT COUNT(*) FROM $table WHERE email LIKE %s",
					'%' . $this->wpdb->esc_like( $search ) . '%'
				)
			);
		} else {
			$count = $this->wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		}
		// phpcs:enable

		return null !== $count ? (int) $count : 0;
	}

	/**
	 * Delete all log entries for a given subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return int Number of entries deleted.
	 */
	public function delete_by_subscriber( int $subscriber_id ): int {
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM $table WHERE subscriber_id = %d", $subscriber_id ) );
		// phpcs:enable
		return false !== $deleted ? (int) $deleted : 0;
	}

	/**
	 * Delete all log entries for a given email.
	 *
	 * @param string $email Email address.
	 * @return int Number of entries deleted.
	 */
	public function delete_by_email( string $email ): int {
		$table = $this->table();
		$email = strtolower( trim( $email ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$deleted = $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM $table WHERE email = %s", $email ) );
		// phpcs:enable
		return false !== $deleted ? (int) $deleted : 0;
	}
}
