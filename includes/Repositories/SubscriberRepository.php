<?php
/**
 * Subscriber repository.
 *
 * Provides CRUD operations for the `subscribers` table. Email is the sole
 * identity — a signup for an existing email upserts into the existing row,
 * never a second row.
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
 * Manages subscriber records.
 */
class SubscriberRepository {

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
		return Schema::table( 'subscribers', $this->wpdb );
	}

	/**
	 * Normalize an email address (lowercase + trim).
	 *
	 * @param string $email Raw email.
	 * @return string Normalized email.
	 */
	private function normalize_email( string $email ): string {
		return strtolower( trim( $email ) );
	}

	/**
	 * Find a subscriber by ID.
	 *
	 * @param int $id Subscriber ID.
	 * @return stdClass|null Subscriber row or null if not found.
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
	 * Find a subscriber by email.
	 *
	 * @param string $email Email address (will be normalized).
	 * @return stdClass|null Subscriber row or null if not found.
	 */
	public function find_by_email( string $email ): ?stdClass {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		$email = $this->normalize_email( $email );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE email = %s", $email ) );
		// phpcs:enable
		return null !== $row ? $row : null;
	}

	/**
	 * Create a new subscriber or return the existing one if the email is
	 * already registered (upsert by email).
	 *
	 * @param string $email           Email address.
	 * @param string $status          Subscriber status (pending|confirmed|unsubscribed).
	 * @param int    $consent_version Consent-text version the subscriber accepted.
	 * @return stdClass The subscriber row.
	 *
	 * @throws \RuntimeException When the subscriber could not be created.
	 */
	public function create_or_get( string $email, string $status = 'pending', int $consent_version = 1 ): stdClass {
		$email = $this->normalize_email( $email );

		$existing = $this->find_by_email( $email );
		if ( null !== $existing ) {
			return $existing;
		}

		$wpdb = $this->wpdb;
		$now  = current_time( 'mysql', true );
		$wpdb->insert(
			$this->table(),
			array(
				'email'           => $email,
				'status'          => $status,
				'created_at'      => $now,
				'consent_version' => $consent_version,
			),
			array( '%s', '%s', '%s', '%d' )
		);

		$subscriber = $this->find( (int) $wpdb->insert_id );
		if ( null === $subscriber ) {
			throw new \RuntimeException( 'Failed to create subscriber.' );
		}
		return $subscriber;
	}

	/**
	 * Update a subscriber's status.
	 *
	 * @param int    $id     Subscriber ID.
	 * @param string $status New status (pending|confirmed|unsubscribed).
	 * @return bool True on success.
	 */
	public function update_status( int $id, string $status ): bool {
		$wpdb   = $this->wpdb;
		$data   = array( 'status' => $status );
		$format = array( '%s' );

		if ( 'confirmed' === $status ) {
			$data['confirmed_at'] = current_time( 'mysql', true );
			$format[]             = '%s';
		} elseif ( 'unsubscribed' === $status ) {
			$data['unsubscribed_at'] = current_time( 'mysql', true );
			$format[]                = '%s';
		}

		return (bool) $wpdb->update(
			$this->table(),
			$data,
			array( 'id' => $id ),
			$format,
			array( '%d' )
		);
	}

	/**
	 * Set the unsubscribe token hash for a subscriber.
	 *
	 * @param int    $id         Subscriber ID.
	 * @param string $token_hash SHA-256 hex digest of the unsubscribe token.
	 * @return bool True on success.
	 */
	public function set_unsub_token_hash( int $id, string $token_hash ): bool {
		$wpdb = $this->wpdb;
		return (bool) $wpdb->update(
			$this->table(),
			array( 'unsub_token_hash' => $token_hash ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Update the consent version for a subscriber.
	 *
	 * @param int $id      Subscriber ID.
	 * @param int $version Consent-text version.
	 * @return bool True on success.
	 */
	public function set_consent_version( int $id, int $version ): bool {
		$wpdb = $this->wpdb;
		return (bool) $wpdb->update(
			$this->table(),
			array( 'consent_version' => $version ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Delete a subscriber and their orphan data (meta, list memberships,
	 * pending signups, campaign recipients, clicks).
	 *
	 * Runs in a transaction where the storage engine allows.
	 *
	 * @param int $id Subscriber ID.
	 * @return void
	 *
	 * @throws \Throwable On database error (transaction is rolled back).
	 */
	public function delete( int $id ): void {
		$wpdb             = $this->wpdb;
		$meta_table       = Schema::table( 'subscriber_meta', $wpdb );
		$lists_table      = Schema::table( 'subscriber_lists', $wpdb );
		$pending_table    = Schema::table( 'pending_signups', $wpdb );
		$recipients_table = Schema::table( 'campaign_recipients', $wpdb );
		$clicks_table     = Schema::table( 'campaign_clicks', $wpdb );
		$log_table        = Schema::table( 'submission_log', $wpdb );
		$subscribers      = $this->table();

		$wpdb->query( 'START TRANSACTION' );

		try {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE c FROM $clicks_table c INNER JOIN $recipients_table r ON c.recipient_id = r.id WHERE r.subscriber_id = %d", $id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $recipients_table WHERE subscriber_id = %d", $id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $pending_table WHERE subscriber_id = %d", $id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $lists_table WHERE subscriber_id = %d", $id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $meta_table WHERE subscriber_id = %d", $id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $log_table WHERE subscriber_id = %d", $id ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $subscribers WHERE id = %d", $id ) );
			// phpcs:enable

			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
	}

	/**
	 * Get all subscribers with optional filtering, search, and pagination.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Query arguments.
	 *     @type string $status   Filter by status (pending|confirmed|unsubscribed).
	 *     @type string $search   Search term for email (LIKE).
	 *     @type int    $per_page Number of rows per page. Default 20.
	 *     @type int    $page     Page number (1-based). Default 1.
	 *     @type string $orderby  Column to order by. Default 'created_at'.
	 *     @type string $order    ASC or DESC. Default 'DESC'.
	 * }
	 * @return array<int, stdClass> Subscriber rows.
	 */
	public function get_all( array $args = array() ): array {
		$wpdb    = $this->wpdb;
		$table   = $this->table();
		$status  = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$search  = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$list_id = isset( $args['list_id'] ) ? (int) $args['list_id'] : 0;
		$perpage = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page    = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset  = ( $page - 1 ) * $perpage;

		$allowed_orderby = array( 'id', 'email', 'status', 'created_at', 'confirmed_at' );
		$orderby         = in_array( $args['orderby'] ?? 'created_at', $allowed_orderby, true ) ? ( $args['orderby'] ?? 'created_at' ) : 'created_at';
		$order           = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC';

		$join   = '';
		$where  = '1=1';
		$params = array();

		if ( $list_id > 0 ) {
			$junction = Schema::table( 'subscriber_lists', $wpdb );
			$join    .= " INNER JOIN $junction sl ON sl.subscriber_id = s.id";
			$where   .= ' AND sl.list_id = %d AND sl.status = %s';
			$params[] = $list_id;
			$params[] = 'subscribed';
			// Use alias to avoid ambiguity.
			$table = "$table s";
		}

		if ( '' !== $status && in_array( $status, array( 'pending', 'confirmed', 'unsubscribed' ), true ) ) {
			$col      = 0 === strpos( $table, $table ) && '' !== $join ? 's.status' : 'status';
			$where   .= " AND $col = %s";
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$col      = '' !== $join ? 's.email' : 'email';
			$where   .= " AND $col LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		$order_col = '' !== $join ? "s.$orderby" : $orderby;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( count( $params ) > 0 ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $table $join WHERE $where ORDER BY $order_col $order LIMIT %d OFFSET %d",
					array_merge( $params, array( $perpage, $offset ) )
				)
			);
		} else {
			$results = $wpdb->get_results(
				"SELECT * FROM $table $join WHERE $where ORDER BY $order_col $order LIMIT $perpage OFFSET $offset"
			);
		}
		// phpcs:enable

		return null !== $results ? $results : array();
	}

	/**
	 * Count subscribers with optional status and search filters.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Query arguments.
	 *     @type string $status Filter by status.
	 *     @type string $search Search term for email (LIKE).
	 * }
	 * @return int
	 */
	public function count_filtered( array $args = array() ): int {
		$wpdb    = $this->wpdb;
		$table   = $this->table();
		$status  = isset( $args['status'] ) ? sanitize_key( $args['status'] ) : '';
		$search  = isset( $args['search'] ) ? sanitize_text_field( $args['search'] ) : '';
		$list_id = isset( $args['list_id'] ) ? (int) $args['list_id'] : 0;

		$join   = '';
		$where  = '1=1';
		$params = array();

		if ( $list_id > 0 ) {
			$junction = Schema::table( 'subscriber_lists', $wpdb );
			$join    .= " INNER JOIN $junction sl ON sl.subscriber_id = s.id";
			$where   .= ' AND sl.list_id = %d AND sl.status = %s';
			$params[] = $list_id;
			$params[] = 'subscribed';
			$table    = "$table s";
		}

		if ( '' !== $status && in_array( $status, array( 'pending', 'confirmed', 'unsubscribed' ), true ) ) {
			$col      = '' !== $join ? 's.status' : 'status';
			$where   .= " AND $col = %s";
			$params[] = $status;
		}

		if ( '' !== $search ) {
			$col      = '' !== $join ? 's.email' : 'email';
			$where   .= " AND $col LIKE %s";
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		if ( count( $params ) > 0 ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table $join WHERE $where", $params ) );
		} else {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $join WHERE $where" );
		}
		// phpcs:enable

		return $count;
	}

	/**
	 * Count subscribers by status.
	 *
	 * @param string $status Optional status filter.
	 * @return int
	 */
	public function count( string $status = '' ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		if ( '' !== $status ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", $status ) );
			// phpcs:enable
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		// phpcs:enable
	}
}
