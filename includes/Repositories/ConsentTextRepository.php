<?php
/**
 * Consent text repository.
 *
 * Manages the append-only `consent_texts` registry. Each version maps to
 * the exact wording a subscriber accepted. Versions are never deleted or
 * modified — only new versions are inserted.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Repositories;

use Stampy\Schema;
use stdClass;
use wpdb;

/**
 * Manages consent text records.
 */
class ConsentTextRepository {

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
		return Schema::table( 'consent_texts', $this->wpdb );
	}

	/**
	 * Get the consent text for a specific version.
	 *
	 * @param int $version Version number.
	 * @return stdClass|null
	 */
	public function find( int $version ): ?stdClass {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE version = %d", $version ) );
		// phpcs:enable
		return null !== $row ? $row : null;
	}

	/**
	 * Get the latest consent text version.
	 *
	 * @return stdClass|null The latest version row, or null if none.
	 */
	public function latest(): ?stdClass {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM $table ORDER BY version DESC LIMIT 1" );
		// phpcs:enable
		return null !== $row ? $row : null;
	}

	/**
	 * Get all consent text versions.
	 *
	 * @return array<int, stdClass>
	 */
	public function all(): array {
		$wpdb  = $this->wpdb;
		$table = $this->table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM $table ORDER BY version DESC" );
		// phpcs:enable
	}

	/**
	 * Create a new consent text version.
	 *
	 * The version number is auto-incremented (latest + 1).
	 *
	 * @param string $text The consent text.
	 * @return int The new version number.
	 */
	public function create( string $text ): int {
		$wpdb    = $this->wpdb;
		$latest  = $this->latest();
		$version = null !== $latest ? (int) $latest->version + 1 : 1;

		$wpdb->insert(
			$this->table(),
			array(
				'version'    => $version,
				'text'       => $text,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s' )
		);

		return $version;
	}
}
