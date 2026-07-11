<?php
/**
 * REST API registration.
 *
 * Registers all REST controllers on the `rest_api_init` hook.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Rest;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Stampy's REST API controllers.
 */
final class RestApi {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register all REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		( new SignupController() )->register_routes();
		( new ConfirmController() )->register_routes();
		( new UnsubscribeController() )->register_routes();
		( new PreferencesController() )->register_routes();
	}
}
