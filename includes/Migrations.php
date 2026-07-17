<?php
/**
 * Migration runner for Stampy.
 *
 * Manages database schema versioning. Compares the stored `stampy_db_version`
 * option against `Schema::DB_VERSION` and runs upgrades when behind. Supports
 * jumps from any older version (not just the previous one).
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs database migrations for the Stampy plugin.
 */
class Migrations {

	/**
	 * Option key for the stored database version.
	 */
	public const DB_VERSION_OPTION = 'stampy_db_version';

	/**
	 * Run pending migrations.
	 *
	 * Compares the stored version against the code version and runs the
	 * appropriate migration steps. Safe to call on every `plugins_loaded` —
	 * does nothing when versions match.
	 *
	 * @return int The version now stored in the database.
	 */
	public static function run(): int {
		$stored = self::get_stored_version();
		$code   = Schema::DB_VERSION;

		if ( $stored >= $code ) {
			return $stored;
		}

		// Run migrations for each version between stored+1 and code.
		for ( $v = $stored + 1; $v <= $code; $v++ ) {
			$method = 'migrate_to_' . $v;
			if ( method_exists( self::class, $method ) ) {
				self::$method();
			}
		}

		// Update the stored version.
		update_option( self::DB_VERSION_OPTION, $code, false );

		return $code;
	}

	/**
	 * Get the stored database version.
	 *
	 * @return int The stored version (0 if never installed).
	 */
	public static function get_stored_version(): int {
		$stored = get_option( self::DB_VERSION_OPTION, 0 );
		return (int) $stored;
	}

	/**
	 * Migration to version 1: initial schema creation.
	 *
	 * Creates all custom tables via `Schema::install()`. This is the
	 * first and only migration — future versions add methods like
	 * `migrate_to_2()` for incremental changes.
	 */
	protected static function migrate_to_1(): void {
		Schema::install();
	}

	/**
	 * Migration to version 3: add submission_log table.
	 *
	 * Schema::install() is idempotent via dbDelta(), so it safely
	 * creates the new table without touching existing ones.
	 */
	protected static function migrate_to_3(): void {
		Schema::install();
	}

	/**
	 * Migration to version 4: add subscriber_id column, remove ip_address.
	 *
	 * DbDelta() handles adding the new column. The ip_address column
	 * is dropped explicitly since dbDelta does not remove columns.
	 */
	protected static function migrate_to_4(): void {
		Schema::install();

		$wpdb  = $GLOBALS['wpdb'];
		$table = Schema::table( 'submission_log', $wpdb );

		// Check if subscriber_id column exists, then add it explicitly.
		// dbDelta() does not reliably add columns in the middle of a table
		// when the column ordering differs from the existing structure.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$column = $wpdb->get_results( "SHOW COLUMNS FROM $table LIKE 'subscriber_id'" );
		if ( ! is_array( $column ) || count( $column ) === 0 ) {
			$wpdb->query( "ALTER TABLE $table ADD COLUMN subscriber_id BIGINT UNSIGNED NOT NULL AFTER id" );
			$wpdb->query( "ALTER TABLE $table ADD INDEX subscriber_id (subscriber_id)" );
		}

		// Check if ip_address column exists, then drop it.
		$column = $wpdb->get_results( "SHOW COLUMNS FROM $table LIKE 'ip_address'" );
		if ( is_array( $column ) && count( $column ) > 0 ) {
			$wpdb->query( "ALTER TABLE $table DROP COLUMN ip_address" );
		}
		// phpcs:enable
	}
}
