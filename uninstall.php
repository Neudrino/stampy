<?php
/**
 * Uninstall handler for Stampy.
 *
 * Runs only when the plugin is deleted through the WordPress admin. By
 * default, all plugin data (tables, options, scheduled events) is removed.
 * The admin settings UI to opt out of deletion ships in Phase 10.
 *
 * @package Stampy
 */

declare( strict_types=1 );

use Stampy\Schema;
use Stampy\Smtp\SmtpSettings;

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// The plugin main file is NOT loaded during uninstall, so the Composer
// autoloader must be required manually.
$stampy_autoload = __DIR__ . '/vendor/autoload.php';
if ( ! is_readable( $stampy_autoload ) ) {
	exit;
}

require_once $stampy_autoload;

// By default, all data is removed on uninstall. The admin settings UI
// (Phase 10) can set this option to '0' to preserve data.
$stampy_delete_data = get_option( 'stampy_delete_data_on_uninstall', '1' );

if ( '1' !== $stampy_delete_data ) {
	exit;
}

// Drop all custom tables.
Schema::uninstall();

// Remove SMTP settings.
SmtpSettings::delete_all();

// Remove plugin options.
$stampy_options = array(
	'stampy_db_version',
	'stampy_delete_data_on_uninstall',
	'stampy_hmac_secret',
);

foreach ( $stampy_options as $stampy_option ) {
	delete_option( $stampy_option );
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'stampy_daily_purge_pending_signups' );
