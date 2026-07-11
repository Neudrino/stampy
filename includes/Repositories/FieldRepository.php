<?php
/**
 * Field repository.
 *
 * Manages field definitions in the `fields` table. Fields define the type
 * and validation rules for subscriber attributes stored in `subscriber_meta`.
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
 * Manages field definition records.
 */
class FieldRepository {

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
		return Schema::table( 'fields', $this->wpdb );
	}

	/**
	 * Find a field by ID.
	 *
	 * @param int $id Field ID.
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
	 * Find a field by its key.
	 *
	 * @param string $key Field key (e.g. 'first_name').
	 * @return stdClass|null
	 */
	public function find_by_key( string $key ): ?stdClass {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE field_key = %s", $key ) );
		// phpcs:enable
		return null !== $row ? $row : null;
	}

	/**
	 * Get all field definitions.
	 *
	 * @param bool $admin_only If true, only return fields with show_in_admin=1.
	 * @return array<int, stdClass>
	 */
	public function all( bool $admin_only = false ): array {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $admin_only ) {
			return $wpdb->get_results( "SELECT * FROM $table WHERE show_in_admin = 1 ORDER BY id ASC" );
		}
		return $wpdb->get_results( "SELECT * FROM $table ORDER BY id ASC" );
		// phpcs:enable
	}

	/**
	 * Create a new field definition.
	 *
	 * @param string            $key          Field key (unique).
	 * @param string            $label        Human-readable label.
	 * @param string            $type         Field type (text|textarea|number|date|select|checkbox).
	 * @param array<mixed>|null $options      Optional options (for select/checkbox).
	 * @param bool              $required     Whether the field is required.
	 * @param array<mixed>|null $validation   Optional validation rules.
	 * @param bool              $show_in_admin Whether the field shows in admin.
	 * @return int The new field ID.
	 */
	public function create(
		string $key,
		string $label,
		string $type = 'text',
		?array $options = null,
		bool $required = false,
		?array $validation = null,
		bool $show_in_admin = true
	): int {
		$wpdb = $this->wpdb;
		$wpdb->insert(
			$this->table(),
			array(
				'field_key'     => $key,
				'label'         => $label,
				'type'          => $type,
				'options'       => null !== $options ? wp_json_encode( $options ) : null,
				'required'      => $required ? 1 : 0,
				'validation'    => null !== $validation ? wp_json_encode( $validation ) : null,
				'show_in_admin' => $show_in_admin ? 1 : 0,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Delete a field definition.
	 *
	 * Note: this does NOT delete existing subscriber_meta rows for this
	 * field_key — they remain as orphaned data until the subscriber is
	 * deleted or GDPR-erased.
	 *
	 * @param int $id Field ID.
	 * @return bool True on success.
	 */
	public function delete( int $id ): bool {
		$wpdb = $this->wpdb;
		return (bool) $wpdb->delete(
			$this->table(),
			array( 'id' => $id ),
			array( '%d' )
		);
	}
}
