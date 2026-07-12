<?php
/**
 * Merge-tag registry for campaign personalization.
 *
 * Replaces merge tags ({email}, {unsubscribe_url}, {first_name},
 * {field:*}) at send time on a per-recipient basis. The registry is
 * extensible via the `stampy_merge_tags` filter.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Campaigns;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use Stampy\Security;

/**
 * Registry for merge tags available during campaign sending.
 */
final class MergeTagRegistry {

	/**
	 * Meta repository for fetching subscriber attributes.
	 *
	 * @var SubscriberMetaRepository
	 */
	private SubscriberMetaRepository $meta;

	/**
	 * Subscriber repository for fetching subscriber data.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * Constructor.
	 *
	 * @param SubscriberMetaRepository|null $meta        Optional.
	 * @param SubscriberRepository|null     $subscribers Optional.
	 */
	public function __construct(
		?SubscriberMetaRepository $meta = null,
		?SubscriberRepository $subscribers = null
	) {
		$this->meta        = $meta ?? new SubscriberMetaRepository();
		$this->subscribers = $subscribers ?? new SubscriberRepository();
	}

	/**
	 * Replace all merge tags in a string for a given subscriber.
	 *
	 * @param string $content      Content with merge tags.
	 * @param int    $subscriber_id Subscriber ID.
	 * @param int    $campaign_id   Campaign ID (used for unsubscribe URL).
	 * @return string Content with merge tags replaced.
	 */
	public function replace( string $content, int $subscriber_id, int $campaign_id ): string {
		$subscriber = $this->subscribers->find( $subscriber_id );
		if ( null === $subscriber ) {
			return $content;
		}

		$meta   = $this->meta->get_all( $subscriber_id );
		$values = $this->build_tag_values( $subscriber, $meta );

		$values = apply_filters( 'stampy_merge_tags', $values, $subscriber_id, $campaign_id );

		foreach ( $values as $tag => $value ) {
			$content = str_replace( '{' . $tag . '}', $value, $content );
		}

		return $content;
	}

	/**
	 * Build the map of tag name => replacement value.
	 *
	 * @param \stdClass             $subscriber  Subscriber row.
	 * @param array<string, string> $meta        Subscriber meta (field_key => value).
	 * @return array<string, string> Tag name => value.
	 */
	private function build_tag_values( \stdClass $subscriber, array $meta ): array {
		$values = array(
			'email'           => (string) $subscriber->email,
			'unsubscribe_url' => $this->build_unsubscribe_url( (int) $subscriber->id ),
			'first_name'      => (string) ( $meta['first_name'] ?? '' ),
			'last_name'       => (string) ( $meta['last_name'] ?? '' ),
		);

		foreach ( $meta as $key => $value ) {
			$values[ 'field:' . $key ] = (string) $value;
		}

		return $values;
	}

	/**
	 * Build the one-click unsubscribe URL for a subscriber.
	 *
	 * Uses HMAC-only authentication (no subscriber token) because the
	 * raw subscriber token is not available at send time — only its
	 * hash is stored. The HMAC signature, keyed by the per-site secret,
	 * is sufficient for authentication and tamper prevention.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return string
	 */
	public function build_unsubscribe_url( int $subscriber_id ): string {
		$params = array(
			's' => $subscriber_id,
		);

		$signature = Security::sign( $params );

		return add_query_arg(
			array(
				'stampy_unsub_s'   => $subscriber_id,
				'stampy_unsub_sig' => $signature,
			),
			home_url( '/' )
		);
	}

	/**
	 * Build the one-click unsubscribe URL targeting a specific list.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $list_id       List ID.
	 * @return string
	 */
	public function build_list_unsubscribe_url( int $subscriber_id, int $list_id ): string {
		$params = array(
			's'    => $subscriber_id,
			'list' => $list_id,
		);

		$signature = Security::sign( $params );

		return add_query_arg(
			array(
				'stampy_unsub_s'   => $subscriber_id,
				'stampy_unsub_l'   => $list_id,
				'stampy_unsub_sig' => $signature,
			),
			home_url( '/' )
		);
	}

	/**
	 * Build the RFC 8058 List-Unsubscribe headers for a recipient.
	 *
	 * Returns both the List-Unsubscribe and List-Unsubscribe-Post headers.
	 * The URL targets the specific list the campaign was sent to.
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @param int $list_id       List ID.
	 * @return array<int, string> Header strings.
	 */
	public function build_unsubscribe_headers( int $subscriber_id, int $list_id ): array {
		$url = $this->build_list_unsubscribe_url( $subscriber_id, $list_id );

		return array(
			'List-Unsubscribe: <' . $url . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		);
	}
}
