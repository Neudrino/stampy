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
use Stampy\Campaigns\CampaignPostType;
use Stampy\Tracking\TrackingSettings;
use Stampy\SubmissionLogSettings;

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

// Remove tracking settings.
TrackingSettings::delete();

// Delete all campaign posts.
$stampy_campaigns = get_posts(
	array(
		'post_type'   => CampaignPostType::POST_TYPE,
		'numberposts' => -1,
		'post_status' => 'any',
		'fields'      => 'ids',
		'delete'      => true,
	)
);
foreach ( $stampy_campaigns as $stampy_campaign_id ) {
	wp_delete_post( (int) $stampy_campaign_id, true );
}

// Remove plugin options.
$stampy_options = array(
	'stampy_db_version',
	'stampy_delete_data_on_uninstall',
	'stampy_hmac_secret',
	'stampy_physical_address',
	'stampy_tracking_enabled',
	'stampy_quiz_questions',
	'stampy_turnstile_site_key',
	'stampy_turnstile_secret_key',
	'stampy_friendly_captcha_site_key',
	'stampy_friendly_captcha_secret_key',
	SubmissionLogSettings::ENABLED_OPTION,
);

foreach ( $stampy_options as $stampy_option ) {
	delete_option( $stampy_option );
}

// Clear scheduled events.
wp_clear_scheduled_hook( 'stampy_daily_purge_pending_signups' );

// Unschedule any pending campaign batch actions.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'stampy_process_campaign_batch' );
}
