<?php
/**
 * Campaign copy page — duplicate a campaign as a new draft.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Campaigns\CampaignPostType;
use Stampy\Tracking\TrackingSettings;

/**
 * Handles campaign duplication via admin-post.
 */
final class CampaignCopyPage {

	/**
	 * Handle a copy-campaign request.
	 *
	 * Creates a new draft campaign post with the same content, subject,
	 * target list IDs, and tracking override as the source. Does NOT copy
	 * internal sending meta (snapshots, started_at, completed_at).
	 *
	 * @return void
	 */
	public static function handle_copy_campaign(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to copy campaigns.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
		$nonce   = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'stampy_copy_campaign_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stampy' ) );
		}
		// phpcs:enable

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CampaignPostType::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Campaign not found.', 'stampy' ) );
		}

		$new_post_id = wp_insert_post(
			array(
				'post_type'    => CampaignPostType::POST_TYPE,
				'post_status'  => 'draft',
				'post_title'   => sprintf(
					/* translators: %s: campaign title */
					__( '%s (Copy)', 'stampy' ),
					$post->post_title
				),
				'post_content' => $post->post_content,
				'post_author'  => get_current_user_id(),
			),
			true
		);

		if ( $new_post_id instanceof \WP_Error || 0 === $new_post_id ) {
			wp_die( esc_html__( 'Failed to copy campaign.', 'stampy' ) );
		}

		CampaignPostType::set_subject( $new_post_id, CampaignPostType::get_subject( $post_id ) );
		CampaignPostType::set_list_ids( $new_post_id, CampaignPostType::get_list_ids( $post_id ) );
		CampaignPostType::set_status( $new_post_id, 'draft' );

		$tracking_override = get_post_meta( $post_id, TrackingSettings::META_OVERRIDE, true );
		if ( '' !== $tracking_override ) {
			update_post_meta( $new_post_id, TrackingSettings::META_OVERRIDE, $tracking_override );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'post'   => $new_post_id,
					'action' => 'edit',
				),
				admin_url( 'post.php' )
			)
		);
		exit;
	}
}
