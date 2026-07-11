<?php
/**
 * REST confirm controller.
 *
 * Handles GET /stampy/v1/confirm — promotes pending signups to confirmed.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Rest;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\SignupService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for the confirm endpoint.
 */
final class ConfirmController {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'stampy/v1';

	/**
	 * Route name.
	 *
	 * @var string
	 */
	public const ROUTE = 'confirm';

	/**
	 * Signup service.
	 *
	 * @var SignupService
	 */
	private SignupService $service;

	/**
	 * Constructor.
	 *
	 * @param SignupService|null $service Optional.
	 */
	public function __construct( ?SignupService $service = null ) {
		$this->service = $service ?? new SignupService();
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_confirm' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle the confirm request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_confirm( WP_REST_Request $request ): WP_REST_Response {
		$token  = (string) $request->get_param( 'token' );
		$result = $this->service->confirm( $token );

		$status = $result['success'] ? 200 : 400;

		return new WP_REST_Response( $result, $status );
	}
}
