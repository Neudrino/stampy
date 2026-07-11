<?php
/**
 * Lifecycle hooks for Stampy.
 *
 * Manages activation, deactivation, and plugins_loaded upgrade checks.
 * See PLAN.md §3 "Plugin lifecycle" for the full specification.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

use Stampy\Repositories\PendingSignupRepository;

/**
 * Handles WordPress plugin lifecycle hooks.
 */
class Lifecycle {

	/**
	 * Register activation, deactivation, and plugins_loaded hooks.
	 *
	 * Called from `bootstrap()` during plugin boot.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_activation_hook( PLUGIN_FILE, array( self::class, 'on_activate' ) );
		register_deactivation_hook( PLUGIN_FILE, array( self::class, 'on_deactivate' ) );
		add_action( 'plugins_loaded', array( self::class, 'on_plugins_loaded' ) );
		add_action( 'stampy_daily_purge_pending_signups', array( self::class, 'purge_expired_signups' ) );
	}

	/**
	 * Activation handler.
	 *
	 * - Runs the migration runner (creates/upgrades all tables).
	 * - Seeds default data (fields, consent text).
	 * - Generates the per-site HMAC secret if absent.
	 * - Registers rewrite rules and flushes them once.
	 * - Schedules the daily pending-signups purge.
	 *
	 * All steps are idempotent — safe on re-activation.
	 *
	 * @return void
	 */
	public static function on_activate(): void {
		Installer::install();

		// Ensure the HMAC secret exists (idempotent).
		Security::get_secret();

		// Rewrite rules will be registered in init; flush now so
		// the virtual endpoints resolve without a manual flush.
		flush_rewrite_rules();

		// Schedule the daily pending-signups purge.
		if ( ! wp_next_scheduled( 'stampy_daily_purge_pending_signups' ) ) {
			wp_schedule_event( time(), 'daily', 'stampy_daily_purge_pending_signups' );
		}
	}

	/**
	 * Deactivation handler.
	 *
	 * - Unschedules the daily purge.
	 * - Flushes rewrite rules.
	 * - Does NOT drop any data — destructive deletion happens only via
	 *   `uninstall.php` gated behind the "delete data on uninstall" setting.
	 *
	 * @return void
	 */
	public static function on_deactivate(): void {
		$timestamp = wp_next_scheduled( 'stampy_daily_purge_pending_signups' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'stampy_daily_purge_pending_signups' );
		}

		flush_rewrite_rules();
	}

	/**
	 * Plugins_loaded handler.
	 *
	 * Compares stored db_version to code version and runs the migration
	 * runner if behind. This covers plugin updates that never fire the
	 * activation hook (e.g. via WP-CLI or file replacement).
	 *
	 * @return void
	 */
	public static function on_plugins_loaded(): void {
		$stored = Migrations::get_stored_version();
		$code   = Schema::DB_VERSION;

		if ( $stored < $code ) {
			Installer::install();
		}
	}

	/**
	 * Purge expired pending signups (daily cron callback).
	 *
	 * @return void
	 */
	public static function purge_expired_signups(): void {
		$repo = new PendingSignupRepository();
		$repo->purge_expired();
	}
}
