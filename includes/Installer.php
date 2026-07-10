<?php
/**
 * Installer for Stampy.
 *
 * Handles seeding of default data after schema creation: the pre-seeded
 * `first_name`/`last_name` field definitions and consent-text version 1.
 * All operations are idempotent — safe to call on every activation.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

use wpdb;

/**
 * Seeds default data on activation.
 */
class Installer {

	/**
	 * Run the full installation: create tables + seed defaults.
	 *
	 * Called on activation and when the stored db_version is behind.
	 * After running migrations, seeds any missing default data.
	 *
	 * @return void
	 */
	public static function install(): void {
		Migrations::run();
		self::seed_default_fields();
		self::seed_default_consent_text();
	}

	/**
	 * Seed the pre-seeded field definitions (first_name, last_name).
	 *
	 * Idempotent: only inserts if the field doesn't already exist.
	 *
	 * @param wpdb|null $wpdb Optional wpdb instance.
	 * @return void
	 */
	public static function seed_default_fields( ?wpdb $wpdb = null ): void {
		$wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$table = Schema::table( 'fields', $wpdb );
		$now   = current_time( 'mysql', true );

		$defaults = array(
			array(
				'field_key' => 'first_name',
				'label'     => __( 'First Name', 'stampy' ),
				'type'      => 'text',
				'show'      => 1,
			),
			array(
				'field_key' => 'last_name',
				'label'     => __( 'Last Name', 'stampy' ),
				'type'      => 'text',
				'show'      => 1,
			),
		);

		foreach ( $defaults as $field ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$existing = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM $table WHERE field_key = %s", $field['field_key'] )
			);
			// phpcs:enable

			if ( null === $existing ) {
				$wpdb->insert(
					$table,
					array(
						'field_key'     => $field['field_key'],
						'label'         => $field['label'],
						'type'          => $field['type'],
						'required'      => 0,
						'show_in_admin' => $field['show'],
						'created_at'    => $now,
					),
					array( '%s', '%s', '%s', '%d', '%d', '%s' )
				);
			}
		}
	}

	/**
	 * Seed consent-text version 1 if it doesn't exist.
	 *
	 * Idempotent: only inserts if version 1 is absent.
	 *
	 * @param wpdb|null $wpdb Optional wpdb instance.
	 * @return void
	 */
	public static function seed_default_consent_text( ?wpdb $wpdb = null ): void {
		$wpdb  = $wpdb ?? $GLOBALS['wpdb'];
		$table = Schema::table( 'consent_texts', $wpdb );
		$now   = current_time( 'mysql', true );

		$existing = $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoSelect
			$wpdb->prepare( "SELECT version FROM $table WHERE version = %d", 1 )
		);

		if ( null === $existing ) {
			$text = __( 'I agree to receive marketing emails from this website. I can unsubscribe at any time.', 'stampy' );

			$wpdb->insert(
				$table,
				array(
					'version'    => 1,
					'text'       => $text,
					'created_at' => $now,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}
}
