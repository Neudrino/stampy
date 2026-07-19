<?php
/**
 * Integration tests for Schema creation and activation idempotency.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tests\Integration;

use Stampy\Installer;
use Stampy\Schema;
use WP_UnitTestCase;

/**
 * Tests schema creation, activation idempotency, and default data seeding.
 */
class SchemaTest extends WP_UnitTestCase {

	/**
	 * Set up before each test: ensure tables exist.
	 *
	 * @return void
	 */
	public function setUp(): void {
		parent::setUp();
		Installer::install();
	}

	/**
	 * All 9 tables should be created on activation.
	 *
	 * @return void
	 */
	public function test_all_tables_exist(): void {
		global $wpdb;

		$tables = Schema::table_names( $wpdb );

		foreach ( $tables as $short => $full ) {
			// phpcs:ignore WordPress.DB
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			$this->assertSame( $full, $exists, "Table $short ($full) should exist." );
		}
	}

	/**
	 * Activation should be idempotent — running install() twice should not
	 * error or duplicate data.
	 *
	 * @return void
	 */
	public function test_activation_is_idempotent(): void {
		// Run install a second time.
		Installer::install();

		// Tables should still exist.
		global $wpdb;
		$tables = Schema::table_names( $wpdb );

		foreach ( $tables as $full ) {
			// phpcs:ignore WordPress.DB
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) );
			$this->assertSame( $full, $exists );
		}

		// Default fields should not be duplicated.
		$fields_table = Schema::table( 'fields' );
		// phpcs:ignore WordPress.DB
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $fields_table WHERE field_key IN ('first_name', 'last_name')" );
		$this->assertSame( 2, $count, 'Default fields should not be duplicated.' );

		// Consent text v1 should not be duplicated.
		$consent_table = Schema::table( 'consent_texts' );
		// phpcs:ignore WordPress.DB
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $consent_table WHERE version = 1" );
		$this->assertSame( 1, $count, 'Consent text v1 should not be duplicated.' );
	}

	/**
	 * Default fields (first_name, last_name) should be seeded.
	 *
	 * @return void
	 */
	public function test_default_fields_are_seeded(): void {
		global $wpdb;
		$table = Schema::table( 'fields' );

		// phpcs:ignore WordPress.DB
		$first = $wpdb->get_row( "SELECT * FROM $table WHERE field_key = 'first_name'" );
		$this->assertNotNull( $first );
		$this->assertSame( 'First Name', $first->label );
		$this->assertSame( 'text', $first->type );

		// phpcs:ignore WordPress.DB
		$last = $wpdb->get_row( "SELECT * FROM $table WHERE field_key = 'last_name'" );
		$this->assertNotNull( $last );
		$this->assertSame( 'Last Name', $last->label );
		$this->assertSame( 'text', $last->type );
	}

	/**
	 * Consent text version 1 should be seeded.
	 *
	 * @return void
	 */
	public function test_default_consent_text_is_seeded(): void {
		global $wpdb;
		$table = Schema::table( 'consent_texts' );

		// phpcs:ignore WordPress.DB
		$row = $wpdb->get_row( "SELECT * FROM $table WHERE version = 1" );
		$this->assertNotNull( $row );
		$this->assertNotEmpty( $row->text );
	}

	/**
	 * Table names should be prefixed with the WP table prefix.
	 *
	 * @return void
	 */
	public function test_table_names_are_prefixed(): void {
		global $wpdb;
		$tables = Schema::table_names( $wpdb );

		foreach ( $tables as $short => $full ) {
			$this->assertStringContainsString( $wpdb->prefix . 'stampy_', $full, "Table $short should be prefixed." );
		}
	}

	/**
	 * subscribers table should have a UNIQUE index on email.
	 *
	 * @return void
	 */
	public function test_subscribers_email_is_unique(): void {
		global $wpdb;
		$table = Schema::table( 'subscribers' );

		// phpcs:ignore WordPress.DB
		$indexes = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'email'" );
		$this->assertNotEmpty( $indexes, 'subscribers.email should have a UNIQUE index.' );

		// Verify it's actually unique (not just a key).
		$unique = (int) $indexes[0]->Non_unique === 0;
		$this->assertTrue( $unique, 'subscribers.email index should be UNIQUE.' );
	}

	/**
	 * pending_signups table should have UNIQUE(subscriber_id, form_id).
	 *
	 * @return void
	 */
	public function test_pending_signups_subscriber_form_unique(): void {
		global $wpdb;
		$table = Schema::table( 'pending_signups' );

		// phpcs:ignore WordPress.DB
		$indexes = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'subscriber_form'" );
		$this->assertNotEmpty( $indexes, 'pending_signups should have a subscriber_form index.' );

		$unique = (int) $indexes[0]->Non_unique === 0;
		$this->assertTrue( $unique, 'subscriber_form index should be UNIQUE.' );
	}

	/**
	 * subscriber_meta should have UNIQUE(subscriber_id, field_key).
	 *
	 * @return void
	 */
	public function test_subscriber_meta_unique_constraint(): void {
		global $wpdb;
		$table = Schema::table( 'subscriber_meta' );

		// phpcs:ignore WordPress.DB
		$indexes = $wpdb->get_results( "SHOW INDEX FROM $table WHERE Key_name = 'subscriber_field'" );
		$this->assertNotEmpty( $indexes, 'subscriber_meta should have a subscriber_field index.' );

		$unique = (int) $indexes[0]->Non_unique === 0;
		$this->assertTrue( $unique, 'subscriber_field index should be UNIQUE.' );
	}
}
