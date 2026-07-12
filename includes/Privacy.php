<?php
/**
 * Privacy (GDPR) handlers for Stampy.
 *
 * Registers personal-data exporters and erasers for the WordPress
 * privacy tools (Tools → Export Personal Data / Erase Personal Data).
 *
 * Covered data:
 * - Subscriber row (email, status, timestamps, consent version)
 * - Subscriber meta (all attribute values generically)
 * - Pending signups (token hashes, payload fields)
 * - List memberships
 * - Campaign recipients (sent/opened/clicked timestamps)
 * - Campaign clicks (clicked URLs)
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\SubscriberRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\PendingSignupRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\CampaignRecipientRepository;

/**
 * Registers privacy exporters and erasers with WordPress core.
 */
final class Privacy {

	/**
	 * Register the privacy hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'register_eraser' ) );
	}

	/**
	 * Register the Stampy data exporter.
	 *
	 * @param array<int, array<string, mixed>> $exporters Existing exporters.
	 * @return array<int, array<string, mixed>>
	 */
	public static function register_exporter( array $exporters ): array {
		$exporters[] = array(
			'exporter_friendly_name' => __( 'Stampy Subscriber Data', 'stampy' ),
			'callback'               => array( self::class, 'export_data' ),
		);
		return $exporters;
	}

	/**
	 * Register the Stampy data eraser.
	 *
	 * @param array<int, array<string, mixed>> $erasers Existing erasers.
	 * @return array<int, array<string, mixed>>
	 */
	public static function register_eraser( array $erasers ): array {
		$erasers[] = array(
			'eraser_friendly_name' => __( 'Stampy Subscriber Data', 'stampy' ),
			'callback'             => array( self::class, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * Find a subscriber by email address.
	 *
	 * @param string $email Email address to look up.
	 * @return \stdClass|null Subscriber row or null.
	 */
	private static function find_subscriber( string $email ): ?\stdClass {
		$repo = new SubscriberRepository();
		return $repo->find_by_email( $email );
	}

	/**
	 * Export all Stampy data for a given email address.
	 *
	 * @param string $email_address Email address being exported.
	 * @param int    $page          Pagination page (1-based). Required by
	 *                              the WP privacy exporter API but unused —
	 *                              all data for a subscriber fits in one page.
	 * @return array{data: array<int, array{group_id: string, group_label: string, item_id: string, data: array<int, array{name: string, value: string}>}>, done: bool}
	 */
	public static function export_data( string $email_address, int $page = 1 ): array {
		$subscriber = self::find_subscriber( $email_address );

		if ( null === $subscriber ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		// All data fits in a single page; no pagination needed.
		unset( $page );

		$sid    = (int) $subscriber->id;
		$groups = array();

		// Group 1: Subscriber profile.
		$profile_data = array(
			array(
				'name'  => __( 'Email', 'stampy' ),
				'value' => $subscriber->email,
			),
			array(
				'name'  => __( 'Status', 'stampy' ),
				'value' => $subscriber->status,
			),
			array(
				'name'  => __( 'Subscribed', 'stampy' ),
				'value' => (string) $subscriber->created_at,
			),
			array(
				'name'  => __( 'Confirmed', 'stampy' ),
				'value' => (string) ( $subscriber->confirmed_at ?? '' ),
			),
			array(
				'name'  => __( 'Unsubscribed', 'stampy' ),
				'value' => (string) ( $subscriber->unsubscribed_at ?? '' ),
			),
			array(
				'name'  => __( 'Consent Version', 'stampy' ),
				'value' => (string) $subscriber->consent_version,
			),
		);

		$groups[] = array(
			'group_id'    => 'stampy-subscriber',
			'group_label' => __( 'Stampy: Subscriber Profile', 'stampy' ),
			'item_id'     => 'stampy-subscriber-' . $sid,
			'data'        => $profile_data,
		);

		// Group 2: Subscriber attributes (meta).
		$meta_repo = new SubscriberMetaRepository();
		$meta      = $meta_repo->get_all( $sid );

		if ( count( $meta ) > 0 ) {
			$meta_data = array();
			foreach ( $meta as $key => $value ) {
				$meta_data[] = array(
					'name'  => $key,
					'value' => $value,
				);
			}
			$groups[] = array(
				'group_id'    => 'stampy-subscriber-meta',
				'group_label' => __( 'Stampy: Subscriber Attributes', 'stampy' ),
				'item_id'     => 'stampy-subscriber-meta-' . $sid,
				'data'        => $meta_data,
			);
		}

		// Group 3: List memberships.
		$list_repo = new ListRepository();
		$lists     = $list_repo->get_subscriber_lists( $sid );

		if ( count( $lists ) > 0 ) {
			$lists_data = array();
			foreach ( $lists as $list ) {
				$lists_data[] = array(
					'name'  => (string) $list->name,
					'value' => sprintf(
						/* translators: 1: list status, 2: subscribed date, 3: unsubscribed date */
						__( 'Status: %1$s, Subscribed: %2$s, Unsubscribed: %3$s', 'stampy' ),
						(string) $list->status,
						(string) ( $list->subscribed_at ?? '' ),
						(string) ( $list->unsubscribed_at ?? '' )
					),
				);
			}
			$groups[] = array(
				'group_id'    => 'stampy-lists',
				'group_label' => __( 'Stampy: List Memberships', 'stampy' ),
				'item_id'     => 'stampy-lists-' . $sid,
				'data'        => $lists_data,
			);
		}

		// Group 4: Pending signups.
		$pending_repo  = new PendingSignupRepository();
		$wpdb          = $GLOBALS['wpdb'];
		$pending_table = Schema::table( 'pending_signups', $wpdb );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pendings = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $pending_table WHERE subscriber_id = %d",
				$sid
			)
		);
		// phpcs:enable

		if ( count( $pendings ) > 0 ) {
			$pending_data = array();
			foreach ( $pendings as $pending ) {
				$payload = json_decode( (string) $pending->payload, true );
				$fields  = is_array( $payload ) && isset( $payload['fields'] ) && is_array( $payload['fields'] )
					? $payload['fields']
					: array();

				$row = array(
					array(
						'name'  => __( 'Created', 'stampy' ),
						'value' => (string) $pending->created_at,
					),
					array(
						'name'  => __( 'Expires', 'stampy' ),
						'value' => (string) $pending->expires_at,
					),
				);

				foreach ( $fields as $field_key => $field_value ) {
					$row[] = array(
						'name'  => __( 'Field: ', 'stampy' ) . $field_key,
						'value' => is_array( $field_value ) ? (string) ( false !== wp_json_encode( $field_value ) ? wp_json_encode( $field_value ) : '' ) : (string) $field_value,
					);
				}

				$pending_data = array_merge( $pending_data, $row );
			}
			$groups[] = array(
				'group_id'    => 'stampy-pending',
				'group_label' => __( 'Stampy: Pending Signups', 'stampy' ),
				'item_id'     => 'stampy-pending-' . $sid,
				'data'        => $pending_data,
			);
		}

		// Group 5: Campaign recipients (sent/opened/clicked timestamps).
		$recipients_table = Schema::table( 'campaign_recipients', $wpdb );
		$clicks_table     = Schema::table( 'campaign_clicks', $wpdb );
		$posts_table      = $wpdb->posts;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$recipients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT r.id, r.campaign_id, r.status, r.sent_at, r.opened_at, r.clicked_at,
				        p.post_title AS campaign_title
				 FROM $recipients_table r
				 LEFT JOIN $posts_table p ON p.ID = r.campaign_id
				 WHERE r.subscriber_id = %d
				 ORDER BY r.sent_at DESC",
				$sid
			)
		);
		// phpcs:enable

		if ( count( $recipients ) > 0 ) {
			$recipient_data = array();
			foreach ( $recipients as $recipient ) {
				$recipient_data[] = array(
					'name'  => (string) ( $recipient->campaign_title ?? '#' . $recipient->campaign_id ),
					'value' => sprintf(
						/* translators: 1: status, 2: sent date, 3: opened date, 4: clicked date */
						__( 'Status: %1$s, Sent: %2$s, Opened: %3$s, Clicked: %4$s', 'stampy' ),
						(string) $recipient->status,
						(string) ( $recipient->sent_at ?? '' ),
						(string) ( $recipient->opened_at ?? '' ),
						(string) ( $recipient->clicked_at ?? '' )
					),
				);
			}
			$groups[] = array(
				'group_id'    => 'stampy-recipients',
				'group_label' => __( 'Stampy: Campaign Recipients', 'stampy' ),
				'item_id'     => 'stampy-recipients-' . $sid,
				'data'        => $recipient_data,
			);

			// Group 6: Campaign clicks (individual click events).
			$recipient_ids = array_map( 'intval', array_column( $recipients, 'id' ) );
			if ( count( $recipient_ids ) > 0 ) {
				$ids_csv = implode( ',', array_map( 'intval', $recipient_ids ) );
				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$clicks = $wpdb->get_results(
					"SELECT c.url, c.clicked_at, p.post_title AS campaign_title
					 FROM $clicks_table c
					 INNER JOIN $recipients_table r ON r.id = c.recipient_id
					 LEFT JOIN $posts_table p ON p.ID = r.campaign_id
					 WHERE c.recipient_id IN ($ids_csv)
					 ORDER BY c.clicked_at DESC"
				);
				// phpcs:enable

				if ( count( $clicks ) > 0 ) {
					$clicks_data = array();
					foreach ( $clicks as $click ) {
						$clicks_data[] = array(
							'name'  => (string) ( $click->campaign_title ?? '' ),
							'value' => sprintf(
								/* translators: 1: clicked URL, 2: clicked date */
								__( 'Clicked URL: %1$s, Date: %2$s', 'stampy' ),
								(string) $click->url,
								(string) $click->clicked_at
							),
						);
					}
					$groups[] = array(
						'group_id'    => 'stampy-clicks',
						'group_label' => __( 'Stampy: Campaign Clicks', 'stampy' ),
						'item_id'     => 'stampy-clicks-' . $sid,
						'data'        => $clicks_data,
					);
				}
			}
		}

		return array(
			'data' => $groups,
			'done' => true,
		);
	}

	/**
	 * Erase all Stampy data for a given email address.
	 *
	 * Uses SubscriberRepository::delete() which cascades across all
	 * related tables (meta, lists, pending signups, recipients, clicks).
	 *
	 * @param string $email_address Email address being erased.
	 * @return array{items_removed: bool, items_retained: bool, messages: array<int, string>, done: bool}
	 */
	public static function erase_data( string $email_address ): array {
		$subscriber = self::find_subscriber( $email_address );

		if ( null === $subscriber ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(
					__( 'No Stampy subscriber data found for this email address.', 'stampy' ),
				),
				'done'           => true,
			);
		}

		$repo = new SubscriberRepository();
		$repo->delete( (int) $subscriber->id );

		return array(
			'items_removed'  => true,
			'items_retained' => false,
			'messages'       => array(
				__( 'Stampy subscriber data erased.', 'stampy' ),
			),
			'done'           => true,
		);
	}
}
