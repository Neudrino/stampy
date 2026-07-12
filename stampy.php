<?php
/**
 * Plugin Name:       Stampy
 * Plugin URI:        https://github.com/Neudrino/stampy
 * Description:       Mailing-list plugin with double opt-in signup, subscriber/list management, a block-editor newsletter composer, SMTP delivery, and open/click tracking.
 * Version:           0.0.1
 * Requires at least: 7.0
 * Requires PHP:      8.3
 * Author:            Neudrino
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       stampy
 * Domain Path:       /languages
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
 * Absolute path to the plugin's main file.
 */
const PLUGIN_FILE = __FILE__;

/**
 * Plugin version. Frozen at 0.0.1 during development.
 */
const VERSION = '0.0.1';

/**
 * Composer autoloader. Present once `composer install` has run.
 */
$stampy_autoload = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $stampy_autoload ) ) {
	require_once $stampy_autoload;
}

/**
 * Bootstraps the plugin.
 *
 * Registers lifecycle hooks, rewrite rules, REST controllers, and
 * WP-CLI commands.
 */
function bootstrap(): void {
	load_action_scheduler();

	load_plugin_textdomain( 'stampy', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );

	Lifecycle::register();
	Rewrites::register();
	Rest\RestApi::register();
	Admin\AdminMenu::register();
	Smtp\SmtpTransport::register();
	Campaigns\CampaignPostType::register();
	Campaigns\SendingEngine::register();
	Tracking\TrackingEndpoints::register();
	Privacy::register();
	add_action( 'init', array( SignupBlock::class, 'register' ) );

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		\WP_CLI::add_command( 'stampy', Cli::class );
	}
}

/**
 * Load the bundled Action Scheduler library.
 *
 * Must be called before the 'plugins_loaded' hook fires so that AS
 * can register its own initialization hooks.
 *
 * @return void
 */
function load_action_scheduler(): void {
	$as_file = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';
	if ( is_readable( $as_file ) ) {
		require_once $as_file;
	}
}

bootstrap();
