<?php
/**
 * REST preferences controller.
 *
 * Handles GET /stampy/v1/preferences — returns subscriber's list memberships.
 * Handles POST /stampy/v1/preferences — updates list memberships.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Rest;

use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Security;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST controller for the preference page.
 */
final class PreferencesController {

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
	 * Subscriber meta repository.
	 *
	 * @var SubscriberMetaRepository
	 */
	private SubscriberMetaRepository $meta;

	/**
	 * Constructor.
	 *
	 * @param SubscriberRepository|null     $subscribers Optional.
	 * @param ListRepository|null           $lists       Optional.
	 * @param SubscriberMetaRepository|null $meta       Optional.
	 */
	public function __construct(
		?SubscriberRepository $subscribers = null,
		?ListRepository $lists = null,
		?SubscriberMetaRepository $meta = null
	) {
		$this->subscribers = $subscribers ?? new SubscriberRepository();
		$this->lists       = $lists ?? new ListRepository();
		$this->meta        = $meta ?? new SubscriberMetaRepository();
	}

	/**
	 * Register the REST routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'preferences',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'get_preferences' ),
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
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'update_preferences' ),
					'permission_callback' => '__return_true',
					'args'                => array(
						's'       => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						't'       => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'sig'     => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'lists'   => array(
							'required' => false,
							'type'     => 'array',
							'default'  => array(),
						),
						'opt_out' => array(
							'required' => false,
							'type'     => 'boolean',
							'default'  => false,
						),
					),
				),
			)
		);
	}

	/**
	 * Get subscriber preferences (list memberships + attributes).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_preferences( WP_REST_Request $request ): WP_REST_Response {
		$subscriber_id = (int) $request->get_param( 's' );
		$token         = (string) $request->get_param( 't' );
		$sig           = (string) $request->get_param( 'sig' );

		if ( ! $this->verify_signature( $subscriber_id, $token, $sig ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid preference link.', 'stampy' ),
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
					'message' => __( 'Invalid token.', 'stampy' ),
				),
				403
			);
		}

		$member_lists = $this->lists->get_subscriber_lists( $subscriber_id );
		$all_lists    = $this->lists->all();

		$lists_data = array();
		foreach ( $all_lists as $list ) {
			$membership = null;
			foreach ( $member_lists as $ml ) {
				if ( (int) $ml->id === (int) $list->id ) {
					$membership = $ml;
					break;
				}
			}

			$lists_data[] = array(
				'id'         => (int) $list->id,
				'name'       => $list->name,
				'slug'       => $list->slug,
				'status'     => null !== $membership ? $membership->status : 'none',
				'subscribed' => null !== $membership && 'subscribed' === $membership->status,
			);
		}

		$meta = $this->meta->get_all( $subscriber_id );

		return new WP_REST_Response(
			array(
				'success'    => true,
				'email'      => $subscriber->email,
				'status'     => $subscriber->status,
				'lists'      => $lists_data,
				'attributes' => $meta,
			),
			200
		);
	}

	/**
	 * Update subscriber preferences (toggle lists, global opt-out).
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function update_preferences( WP_REST_Request $request ): WP_REST_Response {
		$subscriber_id = (int) $request->get_param( 's' );
		$token         = (string) $request->get_param( 't' );
		$sig           = (string) $request->get_param( 'sig' );

		if ( ! $this->verify_signature( $subscriber_id, $token, $sig ) ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'message' => __( 'Invalid preference link.', 'stampy' ),
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
					'message' => __( 'Invalid token.', 'stampy' ),
				),
				403
			);
		}

		$opt_out = (bool) $request->get_param( 'opt_out' );

		if ( $opt_out ) {
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

		$requested_list_ids = $request->get_param( 'lists' );
		if ( ! is_array( $requested_list_ids ) ) {
			$requested_list_ids = array();
		}
		$requested_list_ids = array_map( 'intval', $requested_list_ids );

		$all_lists = $this->lists->all();
		foreach ( $all_lists as $list ) {
			$list_id = (int) $list->id;

			if ( in_array( $list_id, $requested_list_ids, true ) ) {
				$this->lists->add_subscriber( $subscriber_id, $list_id );
			} else {
				$this->lists->remove_subscriber( $subscriber_id, $list_id );
			}
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Your preferences have been updated.', 'stampy' ),
			),
			200
		);
	}

	/**
	 * Verify the HMAC signature for a preferences URL.
	 *
	 * @param int    $subscriber_id Subscriber ID.
	 * @param string $token         Raw token.
	 * @param string $sig           Signature.
	 * @return bool
	 */
	private function verify_signature( int $subscriber_id, string $token, string $sig ): bool {
		return Security::verify(
			array(
				's' => $subscriber_id,
				't' => $token,
			),
			$sig
		);
	}
}
