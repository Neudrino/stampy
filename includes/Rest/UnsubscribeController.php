<?php
/**
 * REST unsubscribe controller.
 *
 * Handles one-click unsubscribe (RFC 8058) and per-list unsubscribe
 * via REST API.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Rest;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Security;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for unsubscribe endpoints.
 */
final class UnsubscribeController {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'stampy/v1';

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * List repository.
	 *
	 * @var ListRepository
	 */
	private ListRepository $lists;

	/**
	 * Constructor.
	 *
	 * @param SubscriberRepository|null $subscribers Optional.
	 * @param ListRepository|null       $lists       Optional.
	 */
	public function __construct(
		?SubscriberRepository $subscribers = null,
		?ListRepository $lists = null
	) {
		$this->subscribers = $subscribers ?? new SubscriberRepository();
		$this->lists       = $lists ?? new ListRepository();
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'unsubscribe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_one_click' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					's'    => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'list' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					't'    => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sig'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'unsubscribe-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_global' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					's'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					't'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'sig' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle one-click unsubscribe (RFC 8058 List-Unsubscribe-Post).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_one_click( WP_REST_Request $request ): WP_REST_Response {
		$subscriber_id = (int) $request->get_param( 's' );
		$list_id       = (int) $request->get_param( 'list' );
		$token         = (string) $request->get_param( 't' );
		$sig           = (string) $request->get_param( 'sig' );

		if ( ! $this->verify_unsub_signature( $subscriber_id, $list_id, $token, $sig ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid unsubscribe link.', 'stampy' ),
				),
				403
			);
		}

		$subscriber = $this->subscribers->find( $subscriber_id );
		if ( null === $subscriber ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Subscriber not found.', 'stampy' ),
				),
				404
			);
		}

		if ( ! Security::verify_token( $token, (string) $subscriber->unsub_token_hash ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid unsubscribe token.', 'stampy' ),
				),
				403
			);
		}

		$this->lists->remove_subscriber( $subscriber_id, $list_id );

		do_action( 'stampy_subscriber_unsubscribed', $subscriber_id, $list_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'You have been unsubscribed from this list.', 'stampy' ),
			),
			200
		);
	}

	/**
	 * Handle global unsubscribe (all lists).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function handle_global( WP_REST_Request $request ): WP_REST_Response {
		$subscriber_id = (int) $request->get_param( 's' );
		$token         = (string) $request->get_param( 't' );
		$sig           = (string) $request->get_param( 'sig' );

		if ( ! $this->verify_global_signature( $subscriber_id, $token, $sig ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid unsubscribe link.', 'stampy' ),
				),
				403
			);
		}

		$subscriber = $this->subscribers->find( $subscriber_id );
		if ( null === $subscriber ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Subscriber not found.', 'stampy' ),
				),
				404
			);
		}

		if ( ! Security::verify_token( $token, (string) $subscriber->unsub_token_hash ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid unsubscribe token.', 'stampy' ),
				),
				403
			);
		}

		$this->subscribers->update_status( $subscriber_id, 'unsubscribed' );

		$member_lists = $this->lists->get_subscriber_lists( $subscriber_id );
		foreach ( $member_lists as $list ) {
			$this->lists->remove_subscriber( $subscriber_id, (int) $list->id );
		}

		do_action( 'stampy_subscriber_unsubscribed_all', $subscriber_id );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'You have been unsubscribed from all lists.', 'stampy' ),
			),
			200
		);
	}

	/**
	 * Verify the HMAC signature for a per-list unsubscribe URL.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $list_id       List ID.
	 * @param string $token         Raw token.
	 * @param string $sig           Signature.
	 * @return bool
	 */
	private function verify_unsub_signature( int $subscriber_id, int $list_id, string $token, string $sig ): bool {
		return Security::verify(
			array(
				's'    => $subscriber_id,
				'list' => $list_id,
				't'    => $token,
			),
			$sig
		);
	}

	/**
	 * Verify the HMAC signature for a global unsubscribe URL.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $token         Raw token.
	 * @param string $sig           Signature.
	 * @return bool
	 */
	private function verify_global_signature( int $subscriber_id, string $token, string $sig ): bool {
		return Security::verify(
			array(
				's' => $subscriber_id,
				't' => $token,
			),
			$sig
		);
	}
}
