<?php
/**
 * REST import/export controller.
 *
 * Handles GET /stampy/v1/export (CSV/JSON export) and
 * POST /stampy/v1/import (JSON import).
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Rest;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\ImportExportService;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for import/export endpoints.
 */
final class ImportExportController {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'stampy/v1';

	/**
	 * Export route.
	 *
	 * @var string
	 */
	public const EXPORT_ROUTE = 'export';

	/**
	 * Import route.
	 *
	 * @var string
	 */
	public const IMPORT_ROUTE = 'import';

	/**
	 * Import/export service.
	 *
	 * @var ImportExportService
	 */
	private ImportExportService $service;

	/**
	 * Constructor.
	 *
	 * @param ImportExportService|null $service Optional.
	 */
	public function __construct( ?ImportExportService $service = null ) {
		$this->service = $service ?? new ImportExportService();
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::EXPORT_ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_export' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'format'  => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'csv',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'list_id' => array(
						'required' => false,
						'type'     => 'integer',
						'default'  => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::IMPORT_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_import' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'rows'      => array(
						'required' => true,
						'type'     => 'array',
					),
					'list_name' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Check that the current user can manage options.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Handle the export request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_export( WP_REST_Request $request ): WP_REST_Response {
		$format  = strtolower( (string) $request->get_param( 'format' ) );
		$list_id = (int) $request->get_param( 'list_id' );

		if ( 'json' === $format ) {
			$content = $this->service->export_json( $list_id );
			return new WP_REST_Response(
				array(
					'format'  => 'json',
					'data'    => json_decode( $content, true ),
					'columns' => $this->service->export_columns(),
				),
				200
			);
		}

		$content = $this->service->export_csv( $list_id );
		return new WP_REST_Response(
			array(
				'format'  => 'csv',
				'data'    => $content,
				'columns' => $this->service->export_columns(),
			),
			200
		);
	}

	/**
	 * Handle the import request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_import( WP_REST_Request $request ): WP_REST_Response {
		$rows      = $request->get_param( 'rows' );
		$list_name = (string) $request->get_param( 'list_name' );

		if ( ! is_array( $rows ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid rows data.', 'stampy' ),
				),
				400
			);
		}

		$result = $this->service->import( $rows, $list_name );

		return new WP_REST_Response( $result, 200 );
	}
}
