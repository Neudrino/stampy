<?php
/**
 * Pending signup repository.
 *
 * Manages staged signups in the `pending_signups` table. Each row holds a
 * single-use confirmation token and the staged payload (attributes, target
 * list IDs, consent_version, form_id). The UNIQUE(subscriber_id, form_id)
 * constraint ensures a repeat signup of the same form refreshes the row.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Repositories;

use Stampy\Schema;
use stdClass;
use wpdb;

/**
 * Manages pending signup records.
 */
class PendingSignupRepository {

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
		return Schema::table( 'pending_signups', $this->wpdb );
	}

	/**
	 * Find a pending signup by ID.
	 *
	 * @param int $id Pending signup ID.
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
	 * Find a pending signup by token hash.
	 *
	 * @param string $token_hash SHA-256 hex digest of the confirmation token.
	 * @return stdClass|null
	 */
	public function find_by_token( string $token_hash ): ?stdClass {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE token_hash = %s", $token_hash ) );
		// phpcs:enable
		return null !== $row ? $row : null;
	}

	/**
	 * Create or refresh a pending signup.
	 *
	 * If a pending signup already exists for (subscriber_id, form_id), it is
	 * refreshed: new payload, fresh token, expires_at reset to +7 days.
	 * Otherwise a new row is created.
	 *
	 * @param int          $subscriber_id Subscriber ID.
	 * @param string       $token_hash    SHA-256 hex digest of the confirmation token.
	 * @param array<mixed> $payload       Staged payload (attributes, list_ids, consent_version, form_id).
	 * @param int|null     $form_id       Form ID (null for built-in block schema).
	 * @return int The pending signup ID.
	 */
	public function create_or_refresh( int $subscriber_id, string $token_hash, array $payload, ?int $form_id = null ): int {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );
		$exp   = gmdate( 'Y-m-d H:i:s', time() + ( 7 * DAY_IN_SECONDS ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $form_id ) {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM $table WHERE subscriber_id = %d AND form_id IS NULL",
					$subscriber_id
				)
			);
		} else {
			$existing = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM $table WHERE subscriber_id = %d AND form_id = %d",
					$subscriber_id,
					$form_id
				)
			);
		}
		// phpcs:enable

		$json_payload = wp_json_encode( $payload );

		if ( null !== $existing ) {
			$wpdb->update(
				$table,
				array(
					'token_hash' => $token_hash,
					'payload'    => $json_payload,
					'created_at' => $now,
					'expires_at' => $exp,
				),
				array( 'id' => $existing->id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return (int) $existing->id;
		}

		$wpdb->insert(
			$table,
			array(
				'subscriber_id' => $subscriber_id,
				'form_id'       => $form_id,
				'token_hash'    => $token_hash,
				'payload'       => $json_payload,
				'created_at'    => $now,
				'expires_at'    => $exp,
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a pending signup (after confirmation or expiry).
	 *
	 * @param int $id Pending signup ID.
	 * @return void
	 */
	public function delete( int $id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id = %d", $id ) );
		// phpcs:enable
	}

	/**
	 * Purge expired pending signups.
	 *
	 * @return int Number of rows deleted.
	 */
	public function purge_expired(): int {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		$now   = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires_at < %s", $now ) );
		// phpcs:enable
	}
}
