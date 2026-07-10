<?php
/**
 * Mailing list repository.
 *
 * Manages list definitions in the `lists` table and subscriber-list
 * memberships in the `subscriber_lists` junction table.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Repositories;

use Stampy\Schema;
use stdClass;
use wpdb;

/**
 * Manages mailing list records and memberships.
 */
class ListRepository {

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
	 * Get the lists table name.
	 *
	 * @return string
	 */
	private function lists_table(): string {
		return Schema::table( 'lists', $this->wpdb );
	}

	/**
	 * Get the subscriber_lists junction table name.
	 *
	 * @return string
	 */
	private function junction_table(): string {
		return Schema::table( 'subscriber_lists', $this->wpdb );
	}

	/**
	 * Find a list by ID.
	 *
	 * @param int $id List ID.
	 * @return stdClass|null
	 */
	public function find( int $id ): ?stdClass {
		$wpdb  = $this->wpdb;
		$table = $this->lists_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
		// phpcs:enable
		return null !== $row ? $row : null;
	}

	/**
	 * Find a list by slug.
	 *
	 * @param string $slug List slug.
	 * @return stdClass|null
	 */
	public function find_by_slug( string $slug ): ?stdClass {
		$wpdb  = $this->wpdb;
		$table = $this->lists_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE slug = %s", $slug ) );
		// phpcs:enable
		return null !== $row ? $row : null;
	}

	/**
	 * Get all lists.
	 *
	 * @return array<int, stdClass>
	 */
	public function all(): array {
		$wpdb  = $this->wpdb;
		$table = $this->lists_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $table ORDER BY name ASC" );
		// phpcs:enable
	}

	/**
	 * Create a new list.
	 *
	 * @param string $name        List name.
	 * @param string $slug        List slug (unique).
	 * @param string $description Optional description.
	 * @return int The new list ID.
	 */
	public function create( string $name, string $slug, string $description = '' ): int {
		$wpdb = $this->wpdb;
		$wpdb->insert(
			$this->lists_table(),
			array(
				'name'        => $name,
				'slug'        => $slug,
				'description' => $description,
			),
			array( '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Add a subscriber to a list (upsert).
	 *
	 * If the junction row already exists as `unsubscribed`, flips it back
	 * to `subscribed` and refreshes `subscribed_at`. No duplicate row.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $list_id       List ID.
	 * @return void
	 */
	public function add_subscriber( int $subscriber_id, int $list_id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->junction_table();
		$now   = current_time( 'mysql', true );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM $table WHERE subscriber_id = %d AND list_id = %d",
				$subscriber_id,
				$list_id
			)
		);
		// phpcs:enable

		if ( null === $existing ) {
			$wpdb->insert(
				$table,
				array(
					'subscriber_id' => $subscriber_id,
					'list_id'       => $list_id,
					'status'        => 'subscribed',
					'subscribed_at' => $now,
				),
				array( '%d', '%d', '%s', '%s' )
			);
		} elseif ( 'subscribed' !== $existing->status ) {
			$wpdb->update(
				$table,
				array(
					'status'          => 'subscribed',
					'subscribed_at'   => $now,
					'unsubscribed_at' => null,
				),
				array(
					'subscriber_id' => $subscriber_id,
					'list_id'       => $list_id,
				),
				array( '%s', '%s', '%s' ),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Unsubscribe a subscriber from a specific list.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $list_id       List ID.
	 * @return void
	 */
	public function remove_subscriber( int $subscriber_id, int $list_id ): void {
		$wpdb  = $this->wpdb;
		$table = $this->junction_table();
		$now   = current_time( 'mysql', true );

		$wpdb->update(
			$table,
			array(
				'status'          => 'unsubscribed',
				'unsubscribed_at' => $now,
			),
			array(
				'subscriber_id' => $subscriber_id,
				'list_id'       => $list_id,
			),
			array( '%s', '%s' ),
			array( '%d', '%d' )
		);
	}

	/**
	 * Get all lists a subscriber is a member of.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return array<int, stdClass>
	 */
	public function get_subscriber_lists( int $subscriber_id ): array {
		$wpdb     = $this->wpdb;
		$lists    = $this->lists_table();
		$junction = $this->junction_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.*, sl.status, sl.subscribed_at, sl.unsubscribed_at FROM $lists l INNER JOIN $junction sl ON l.id = sl.list_id WHERE sl.subscriber_id = %d ORDER BY l.name ASC",
				$subscriber_id
			)
		);
		// phpcs:enable
	}

	/**
	 * Get all subscribers for a list (by membership status).
	 *
	 * @param int    $list_id List ID.
	 * @param string $status  Optional status filter (subscribed|unsubscribed).
	 * @return array<int, stdClass>
	 */
	public function get_list_subscribers( int $list_id, string $status = 'subscribed' ): array {
		$wpdb        = $this->wpdb;
		$junction    = $this->junction_table();
		$subscribers = Schema::table( 'subscribers', $wpdb );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.* FROM $subscribers s INNER JOIN $junction sl ON s.id = sl.subscriber_id WHERE sl.list_id = %d AND sl.status = %s ORDER BY s.email ASC",
				$list_id,
				$status
			)
		);
		// phpcs:enable
	}
}
