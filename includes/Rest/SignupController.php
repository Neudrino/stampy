<?php
/**
 * REST signup controller.
 *
 * Handles POST /stampy/v1/signup — creates pending signups and sends
 * confirmation emails.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Rest;

use Stampy\SignupService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for the signup endpoint.
 */
final class SignupController {

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
	public const ROUTE = 'signup';

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
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_signup' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email'         => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'fields'        => array(
						'required'          => false,
						'type'              => 'object',
						'default'           => array(),
						'sanitize_callback' => array( $this, 'sanitize_fields' ),
					),
					'consent'       => array(
						'required' => true,
						'type'     => 'boolean',
						'default'  => false,
					),
					'form_id'       => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					),
					'list_ids'      => array(
						'required'          => true,
						'type'              => 'array',
						'items'             => array( 'type' => 'integer' ),
						'sanitize_callback' => array( $this, 'sanitize_list_ids' ),
					),
					'website_check' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Sanitize the fields object.
	 *
	 * @param mixed $value Raw value.
	 * @return array<string, string>
	 */
	public function sanitize_fields( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$result = array();
		foreach ( $value as $key => $val ) {
			$key            = sanitize_key( (string) $key );
			$result[ $key ] = is_string( $val ) ? sanitize_text_field( $val ) : '';
		}
		return $result;
	}

	/**
	 * Sanitize list IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return array<int>
	 */
	public function sanitize_list_ids( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $value ), fn( $id ) => $id > 0 ) );
	}

	/**
	 * Handle the signup request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_signup( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->signup(
			array(
				'email'         => (string) $request->get_param( 'email' ),
				'fields'        => $request->get_param( 'fields' ),
				'consent'       => (bool) $request->get_param( 'consent' ),
				'form_id'       => null !== $request->get_param( 'form_id' ) ? (int) $request->get_param( 'form_id' ) : null,
				'list_ids'      => $request->get_param( 'list_ids' ),
				'website_check' => (string) $request->get_param( 'website_check' ),
			)
		);

		$status = $result['success'] ? 200 : 400;

		return new WP_REST_Response( $result, $status );
	}
}
