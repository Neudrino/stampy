<?php
/**
 * Database schema definitions for Stampy.
 *
 * Defines all custom table schemas and provides the central `install()` method
 * called by the migration runner on activation and upgrade.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use wpdb;

/**
 * Manages the creation and upgrade of Stampy's custom database tables.
 *
 * Each table follows the conventions in PLAN.md §3:
 * - Charset/collation from `$wpdb->get_charset_collate()`.
 * - `id`/FK columns `BIGINT UNSIGNED`.
 * - `email` `VARCHAR(255)` with UNIQUE index.
 * - `field_key`/`slug` `VARCHAR(191)` with UNIQUE index.
 * - `status` columns `VARCHAR(20)` (validated in PHP, not SQL ENUM).
 * - Timestamps `DATETIME` (nullable where appropriate), UTC.
 * - Boolean flags `TINYINT(1)`.
 * - JSON payloads `LONGTEXT` (not native JSON — dbDelta compatibility).
 */
class Schema {

	/**
	 * Current schema version.
	 *
	 * Increment when a table definition changes. The migration runner compares
	 * this against the stored `stampy_db_version` option.
	 */
	public const DB_VERSION = 1;

	/**
	 * Get the list of all Stampy table names (prefixed).
	 *
	 * @param wpdb $wpdb Optional wpdb instance (defaults to global).
	 * @return array<string> Map of short-name => fully-qualified table name.
	 */
	public static function table_names( ?wpdb $wpdb = null ): array {
		$wpdb   = $wpdb ?? $GLOBALS['wpdb'];
		$prefix = $wpdb->prefix . 'stampy_';
		$short  = array(
			'subscribers',
			'fields',
			'subscriber_meta',
			'consent_texts',
			'pending_signups',
			'lists',
			'subscriber_lists',
			'campaign_recipients',
			'campaign_clicks',
		);
		$full   = array();
		foreach ( $short as $name ) {
			$full[ $name ] = $prefix . $name;
		}
		return $full;
	}

	/**
	 * Get a single fully-qualified table name by short key.
	 *
	 * @param string    $short Short name (e.g. 'subscribers').
	 * @param wpdb|null $wpdb  Optional wpdb instance.
	 * @return string Fully-qualified table name.
	 */
	public static function table( string $short, ?wpdb $wpdb = null ): string {
		$tables = self::table_names( $wpdb );
		return $tables[ $short ];
	}

	/**
	 * Create or upgrade all Stampy tables.
	 *
	 * Uses `dbDelta()` for idempotent schema management. Safe to call on
	 * every activation and on every `plugins_loaded` upgrade check.
	 *
	 * @param wpdb|null $wpdb Optional wpdb instance.
	 * @return void
	 */
	public static function install( ?wpdb $wpdb = null ): void {
		$wpdb    = $wpdb ?? $GLOBALS['wpdb'];
		$charset = $wpdb->get_charset_collate();
		$tables  = self::table_names( $wpdb );
		$biguint = 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT';
		$sql     = array();

		// phpcs:disable WordPress.DB.PreparedSQL -- dbDelta does not use prepared statements.

		// subscribers.
		$sql[] = "CREATE TABLE {$tables['subscribers']} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(255) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			created_at DATETIME NOT NULL,
			confirmed_at DATETIME DEFAULT NULL,
			unsubscribed_at DATETIME DEFAULT NULL,
			consent_version INT UNSIGNED NOT NULL DEFAULT 1,
			unsub_token_hash CHAR(64) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status (status)
		) {$charset};";

		// fields.
		$sql[] = "CREATE TABLE {$tables['fields']} (
			id {$biguint},
			field_key VARCHAR(191) NOT NULL,
			label VARCHAR(255) NOT NULL,
			type VARCHAR(50) NOT NULL DEFAULT 'text',
			options LONGTEXT,
			required TINYINT(1) NOT NULL DEFAULT 0,
			validation LONGTEXT,
			show_in_admin TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY field_key (field_key)
		) {$charset};";

		// subscriber_meta.
		$sql[] = "CREATE TABLE {$tables['subscriber_meta']} (
			id {$biguint},
			subscriber_id BIGINT UNSIGNED NOT NULL,
			field_key VARCHAR(191) NOT NULL,
			value LONGTEXT,
			PRIMARY KEY  (id),
			UNIQUE KEY subscriber_field (subscriber_id, field_key),
			KEY field_key (field_key)
		) {$charset};";

		// consent_texts.
		$sql[] = "CREATE TABLE {$tables['consent_texts']} (
			version INT UNSIGNED NOT NULL,
			text LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (version)
		) {$charset};";

		// pending_signups.
		$sql[] = "CREATE TABLE {$tables['pending_signups']} (
			id {$biguint},
			subscriber_id BIGINT UNSIGNED NOT NULL,
			form_id BIGINT UNSIGNED DEFAULT NULL,
			token_hash CHAR(64) NOT NULL,
			payload LONGTEXT,
			created_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			UNIQUE KEY subscriber_form (subscriber_id, form_id),
			KEY expires_at (expires_at)
		) {$charset};";

		// lists.
		$sql[] = "CREATE TABLE {$tables['lists']} (
			id {$biguint},
			name VARCHAR(255) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			description LONGTEXT,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};";

		// subscriber_lists.
		$sql[] = "CREATE TABLE {$tables['subscriber_lists']} (
			subscriber_id BIGINT UNSIGNED NOT NULL,
			list_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'subscribed',
			subscribed_at DATETIME DEFAULT NULL,
			unsubscribed_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (subscriber_id, list_id)
		) {$charset};";

		// campaign_recipients.
		$sql[] = "CREATE TABLE {$tables['campaign_recipients']} (
			id {$biguint},
			campaign_id BIGINT UNSIGNED NOT NULL,
			subscriber_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'queued',
			sent_at DATETIME DEFAULT NULL,
			opened_at DATETIME DEFAULT NULL,
			clicked_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY campaign_subscriber (campaign_id, subscriber_id),
			KEY campaign_status (campaign_id, status)
		) {$charset};";

		// campaign_clicks.
		$sql[] = "CREATE TABLE {$tables['campaign_clicks']} (
			id {$biguint},
			recipient_id BIGINT UNSIGNED NOT NULL,
			url VARCHAR(2083) NOT NULL,
			clicked_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY recipient_id (recipient_id)
		) {$charset};";

		// phpcs:enable WordPress.DB.PreparedSQL

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Drop all Stampy tables.
	 *
	 * Called only from `uninstall.php` when the "delete data on uninstall"
	 * setting is enabled.
	 *
	 * @param wpdb|null $wpdb Optional wpdb instance.
	 * @return void
	 */
	public static function uninstall( ?wpdb $wpdb = null ): void {
		$wpdb   = $wpdb ?? $GLOBALS['wpdb'];
		$tables = self::table_names( $wpdb );
		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoTruncation, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS $table" );
		}
	}
}
