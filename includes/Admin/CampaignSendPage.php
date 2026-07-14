<?php
/**
 * Campaign send page — start/cancel send + progress UI.
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
use Stampy\Campaigns\SendingEngine;
use Stampy\Repositories\CampaignRecipientRepository;

/**
 * Handles campaign send actions (start, cancel) and progress display.
 */
final class CampaignSendPage {

	/**
	 * Handle a send-start request (admin-post fallback).
	 *
	 * @return void
	 */
	public static function handle_start_send(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to send campaigns.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
		$nonce   = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'stampy_start_send_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stampy' ) );
		}
		// phpcs:enable

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CampaignPostType::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Campaign not found.', 'stampy' ) );
		}

		$engine = new SendingEngine();
		$result = $engine->start_send( $post_id );

		$redirect_url = add_query_arg(
			array(
				'post'   => $post_id,
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		);

		$redirect_url = add_query_arg(
			array(
				'stampy_send_result'  => $result['success'] ? '1' : '0',
				'stampy_send_message' => rawurlencode( $result['message'] ),
			),
			$redirect_url
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle a send-cancel request (admin-post fallback).
	 *
	 * @return void
	 */
	public static function handle_cancel_send(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to cancel campaigns.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;
		$nonce   = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'stampy_cancel_send_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'stampy' ) );
		}
		// phpcs:enable

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CampaignPostType::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Campaign not found.', 'stampy' ) );
		}

		$engine = new SendingEngine();
		$result = $engine->cancel_send( $post_id );

		$redirect_url = add_query_arg(
			array(
				'post'   => $post_id,
				'action' => 'edit',
			),
			admin_url( 'post.php' )
		);

		$redirect_url = add_query_arg(
			array(
				'stampy_send_result'  => $result['success'] ? '1' : '0',
				'stampy_send_message' => rawurlencode( $result['message'] ),
			),
			$redirect_url
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}

	/**
	 * Handle an AJAX send-start request.
	 *
	 * @return void
	 */
	public static function handle_start_send_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$nonce   = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'stampy_start_send_' . $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
		}
		// phpcs:enable

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CampaignPostType::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => 'Campaign not found' ), 404 );
		}

		$engine = new SendingEngine();
		$result = $engine->start_send( $post_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * Handle an AJAX send-cancel request.
	 *
	 * @return void
	 */
	public static function handle_cancel_send_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		$nonce   = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'stampy_cancel_send_' . $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
		}
		// phpcs:enable

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CampaignPostType::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => 'Campaign not found' ), 404 );
		}

		$engine = new SendingEngine();
		$result = $engine->cancel_send( $post_id );

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		}

		wp_send_json_error( $result, 400 );
	}

	/**
	 * Handle an AJAX progress poll request.
	 *
	 * @return void
	 */
	public static function handle_progress_ajax(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'stampy_progress_' . $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Security check failed' ), 403 );
		}
		// phpcs:enable

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CampaignPostType::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => 'Campaign not found' ), 404 );
		}

		$engine   = new SendingEngine();
		$progress = $engine->get_progress( $post_id );

		$status = CampaignPostType::get_status( $post_id );
		if ( 'sent' === $status ) {
			$recipient_repo         = new CampaignRecipientRepository();
			$stats                  = $recipient_repo->get_stats( $post_id );
			$progress['stats']      = $stats;
			$progress['sent_count'] = (int) $progress['sent'];
		}

		wp_send_json_success( $progress );
	}

	/**
	 * Add a "Send" row action to the campaign list table.
	 *
	 * @param array<string,string> $actions Existing row actions.
	 * @param \WP_Post             $post    Campaign post.
	 * @return array<string,string>
	 */
	public static function add_row_action( array $actions, \WP_Post $post ): array {
		if ( CampaignPostType::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		$status = CampaignPostType::get_status( (int) $post->ID );

		if ( 'draft' === $status ) {
			$send_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=stampy_start_send&post_id=' . $post->ID ),
				'stampy_start_send_' . $post->ID
			);

			$actions['stampy_send'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( $send_url ),
				esc_attr( sprintf( /* translators: %s: campaign title */ __( 'Send &#8220;%s&#8221;', 'stampy' ), $post->post_title ) ),
				esc_html__( 'Send', 'stampy' )
			);
		} elseif ( 'sending' === $status ) {
			$cancel_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=stampy_cancel_send&post_id=' . $post->ID ),
				'stampy_cancel_send_' . $post->ID
			);

			$actions['stampy_cancel'] = sprintf(
				'<a href="%s" style="color:#b32d2e;" aria-label="%s">%s</a>',
				esc_url( $cancel_url ),
				esc_attr( sprintf( /* translators: %s: campaign title */ __( 'Cancel send &#8220;%s&#8221;', 'stampy' ), $post->post_title ) ),
				esc_html__( 'Cancel Send', 'stampy' )
			);
		}

		$copy_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=stampy_copy_campaign&post_id=' . $post->ID ),
			'stampy_copy_campaign_' . $post->ID
		);

		$actions['stampy_copy'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( $copy_url ),
			esc_attr( sprintf( /* translators: %s: campaign title */ __( 'Copy &#8220;%s&#8221;', 'stampy' ), $post->post_title ) ),
			esc_html__( 'Copy', 'stampy' )
		);

		return $actions;
	}
}
