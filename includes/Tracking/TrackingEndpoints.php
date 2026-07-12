<?php
/**
 * Tracking endpoints: open pixel + click redirect.
 *
 * Registers query vars and rewrite rules for the open-tracking pixel
 * and click-tracking redirect. Handles requests by verifying the HMAC
 * signature, recording the event, and serving the response (1×1 GIF
 * for opens, 302 redirect for clicks).
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tracking;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\CampaignRecipientRepository;

/**
 * Registers and handles tracking endpoints.
 */
final class TrackingEndpoints {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( self::class, 'handle_requests' ), 0 );
	}

	/**
	 * Add tracking query vars to the allowed list.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 * @return array<int, string>
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = Tracking::OPEN_R_VAR;
		$vars[] = Tracking::OPEN_C_VAR;
		$vars[] = Tracking::OPEN_SIG_VAR;
		$vars[] = Tracking::CLICK_R_VAR;
		$vars[] = Tracking::CLICK_C_VAR;
		$vars[] = Tracking::CLICK_U_VAR;
		$vars[] = Tracking::CLICK_SIG_VAR;
		return $vars;
	}

	/**
	 * Register rewrite rules for tracking endpoints.
	 *
	 * Uses query-var-based rules so they work without pretty permalinks.
	 *
	 * @return void
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^stampy/open/([0-9]+)/([0-9]+)/([a-f0-9]+)/?$',
			'index.php?' . Tracking::OPEN_R_VAR . '=$matches[1]&' . Tracking::OPEN_C_VAR . '=$matches[2]&' . Tracking::OPEN_SIG_VAR . '=$matches[3]',
			'top'
		);

		add_rewrite_rule(
			'^stampy/click/([0-9]+)/([0-9]+)/([a-f0-9]+)/?$',
			'index.php?' . Tracking::CLICK_R_VAR . '=$matches[1]&' . Tracking::CLICK_C_VAR . '=$matches[2]&' . Tracking::CLICK_SIG_VAR . '=$matches[3]',
			'top'
		);
	}

	/**
	 * Handle tracking requests (open pixel or click redirect).
	 *
	 * @return void
	 */
	public static function handle_requests(): void {
		$open_r = get_query_var( Tracking::OPEN_R_VAR );
		if ( '' !== $open_r ) {
			self::handle_open();
			return;
		}

		$click_r = get_query_var( Tracking::CLICK_R_VAR );
		if ( '' !== $click_r ) {
			self::handle_click();
			return;
		}
	}

	/**
	 * Handle an open-tracking pixel request.
	 *
	 * Verifies the signature, marks the recipient as opened, and serves
	 * a 1×1 transparent GIF.
	 *
	 * @return void
	 */
	private static function handle_open(): void {
		$recipient_id = (int) get_query_var( Tracking::OPEN_R_VAR );
		$campaign_id  = (int) get_query_var( Tracking::OPEN_C_VAR );
		$signature    = (string) get_query_var( Tracking::OPEN_SIG_VAR );

		if ( ! Tracking::verify_open_signature( $recipient_id, $campaign_id, $signature ) ) {
			http_response_code( 404 );
			exit;
		}

		$processed = self::process_open( $recipient_id, $campaign_id );

		if ( ! $processed ) {
			http_response_code( 404 );
			exit;
		}

		self::serve_pixel();
	}

	/**
	 * Process an open-tracking event.
	 *
	 * Marks the recipient as opened and fires the action hook.
	 * Separated from handle_open() for testability (no exit).
	 *
	 * @param int $recipient_id Recipient row ID.
	 * @param int $campaign_id  Campaign post ID.
	 * @return bool True if the open was recorded.
	 */
	public static function process_open( int $recipient_id, int $campaign_id ): bool {
		$recipient = ( new CampaignRecipientRepository() )->find( $recipient_id );

		if ( null === $recipient ) {
			return false;
		}

		$repo = new CampaignRecipientRepository();
		$repo->mark_opened( $recipient_id );

		do_action( 'stampy_campaign_email_opened', $campaign_id, $recipient_id );

		return true;
	}

	/**
	 * Handle a click-tracking redirect request.
	 *
	 * Verifies the signature, records the click, and 302-redirects to
	 * the original destination URL.
	 *
	 * @return void
	 */
	private static function handle_click(): void {
		$recipient_id = (int) get_query_var( Tracking::CLICK_R_VAR );
		$campaign_id  = (int) get_query_var( Tracking::CLICK_C_VAR );
		$sig          = (string) get_query_var( Tracking::CLICK_SIG_VAR );
		$dest_raw     = (string) get_query_var( Tracking::CLICK_U_VAR );

		$destination = '' !== $dest_raw ? rawurldecode( $dest_raw ) : '';

		if ( '' === $destination ) {
			http_response_code( 404 );
			exit;
		}

		if ( ! Tracking::verify_click_signature( $recipient_id, $campaign_id, $destination, $sig ) ) {
			http_response_code( 404 );
			exit;
		}

		$destination = self::process_click( $recipient_id, $campaign_id, $destination );

		if ( false === $destination ) {
			http_response_code( 404 );
			exit;
		}

		wp_safe_redirect( $destination, 302 );
		exit;
	}

	/**
	 * Process a click-tracking event.
	 *
	 * Marks the recipient as clicked, records the click, and fires the
	 * action hook. Separated from handle_click() for testability (no exit).
	 *
	 * @param int    $recipient_id Recipient row ID.
	 * @param int    $campaign_id  Campaign post ID.
	 * @param string $destination  Original destination URL.
	 * @return string|false Destination URL on success, false on failure.
	 */
	public static function process_click( int $recipient_id, int $campaign_id, string $destination ): string|false {
		$recipient = ( new CampaignRecipientRepository() )->find( $recipient_id );

		if ( null === $recipient ) {
			return false;
		}

		$repo = new CampaignRecipientRepository();
		$repo->mark_clicked( $recipient_id );
		$repo->record_click( $recipient_id, $destination );

		do_action( 'stampy_campaign_link_clicked', $campaign_id, $recipient_id, $destination );

		return $destination;
	}

	/**
	 * Serve a 1×1 transparent GIF and exit.
	 *
	 * @return void
	 */
	private static function serve_pixel(): void {
		// phpcs:ignore WordPress.PHP.NoRemovableServe.SendHeaders -- legitimate binary output.
		nocache_headers();
		header( 'Content-Type: image/gif' );
		header( 'Content-Length: 43' );

		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- benign: serving a fixed 1x1 transparent GIF.
		echo base64_decode( 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary image data.
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		exit;
	}
}
