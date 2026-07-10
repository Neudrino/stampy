<?php
/**
 * Subscriber meta repository.
 *
 * Manages attribute values in the `subscriber_meta` EAV table. Each
 * subscriber can have one value per field_key. Values are stored as
 * LONGTEXT; multi-value answers are JSON-encoded.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Repositories;

use Stampy\Schema;
use stdClass;
use wpdb;

/**
 * Manages subscriber attribute records.
 */
class SubscriberMetaRepository {

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
		return Schema::table( 'subscriber_meta', $this->wpdb );
	}

	/**
	 * Get a single meta value for a subscriber + field key.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $field_key     Field key.
	 * @return string|null The raw value or null if not set.
	 */
	public function get( int $subscriber_id, string $field_key ): ?string {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM $table WHERE subscriber_id = %d AND field_key = %s",
				$subscriber_id,
				$field_key
			)
		);
		// phpcs:enable
		return null !== $value ? $value : null;
	}

	/**
	 * Get all meta values for a subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return array<string, string> Map of field_key => value.
	 */
	public function get_all( int $subscriber_id ): array {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT field_key, value FROM $table WHERE subscriber_id = %d",
				$subscriber_id
			)
		);
		// phpcs:enable

		$result = array();
		foreach ( null !== $rows ? $rows : array() as $row ) {
			$result[ $row->field_key ] = $row->value;
		}
		return $result;
	}

	/**
	 * Set a meta value for a subscriber + field key (upsert).
	 *
	 * Uses UNIQUE(subscriber_id, field_key) to ensure one value per key.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $field_key     Field key.
	 * @param string $value         The value to store.
	 * @return bool True on success.
	 */
	public function set( int $subscriber_id, string $field_key, string $value ): bool {
		$wpdb  = $this->wpdb;
		$table = $this->table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $table WHERE subscriber_id = %d AND field_key = %s",
				$subscriber_id,
				$field_key
			)
		);
		// phpcs:enable

		if ( null !== $existing ) {
			return (bool) $wpdb->update(
				$table,
				array( 'value' => $value ),
				array(
					'subscriber_id' => $subscriber_id,
					'field_key'     => $field_key,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}

		return (bool) $wpdb->insert(
			$table,
			array(
				'subscriber_id' => $subscriber_id,
				'field_key'     => $field_key,
				'value'         => $value,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Apply multiple meta values using the merge policy:
	 * new non-empty values overwrite; empty values never erase existing data.
	 *
	 * @param int                   $subscriber_id Subscriber ID.
	 * @param array<string, string> $attributes   Map of field_key => value.
	 * @return void
	 */
	public function apply_merge( int $subscriber_id, array $attributes ): void {
		foreach ( $attributes as $key => $value ) {
			if ( '' !== $value && null !== $value ) {
				$this->set( $subscriber_id, $key, $value );
			}
		}
	}

	/**
	 * Delete all meta for a subscriber.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return void
	 */
	public function delete_all( int $subscriber_id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE subscriber_id = %d", $subscriber_id ) );
		// phpcs:enable
	}
}
