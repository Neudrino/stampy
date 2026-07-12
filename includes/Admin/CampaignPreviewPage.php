<?php
/**
 * Campaign preview page — renders email HTML or plain text.
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
use Stampy\Campaigns\EmailRenderer;

/**
 * Handles campaign preview requests.
 */
final class CampaignPreviewPage {

	/**
	 * Handle a preview request — outputs email HTML or plain text.
	 *
	 * @return void
	 */
	public static function handle_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview campaigns.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;
		$format  = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'html';
		// phpcs:enable

		if ( $post_id <= 0 ) {
			wp_die( esc_html__( 'Invalid campaign ID.', 'stampy' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || CampaignPostType::POST_TYPE !== $post->post_type ) {
			wp_die( esc_html__( 'Campaign not found.', 'stampy' ) );
		}

		$renderer = new EmailRenderer();

		if ( 'text' === $format ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo esc_html( $renderer->render_text( $post ) );
			exit;
		}

		header( 'Content-Type: text/html; charset=utf-8' );
		echo $renderer->render_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
