<?php
/**
 * Rewrite rules for virtual endpoints.
 *
 * Registers virtual URL endpoints for the confirmation landing page
 * and the preference page. These are plugin-controlled, token-authenticated
 * URLs that don't depend on user-created pages.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages rewrite rules for virtual Stampy pages.
 */
final class Rewrites {

	/**
	 * Query var for the confirmation token.
	 */
	public const CONFIRM_VAR = 'stampy_confirm';

	/**
	 * Query var for the preferences page subscriber ID.
	 */
	public const PREF_S_VAR = 'stampy_pref_s';

	/**
	 * Query var for the preferences page token.
	 */
	public const PREF_T_VAR = 'stampy_pref_t';

	/**
	 * Query var for the preferences page signature.
	 */
	public const PREF_SIG_VAR = 'stampy_pref_sig';

	/**
	 * Query var for the unsubscribe page subscriber ID.
	 */
	public const UNSUB_S_VAR = 'stampy_unsub_s';

	/**
	 * Query var for the unsubscribe page list ID.
	 */
	public const UNSUB_L_VAR = 'stampy_unsub_l';

	/**
	 * Query var for the unsubscribe page token.
	 */
	public const UNSUB_T_VAR = 'stampy_unsub_t';

	/**
	 * Query var for the unsubscribe page signature.
	 */
	public const UNSUB_SIG_VAR = 'stampy_unsub_sig';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'query_vars', array( self::class, 'add_query_vars' ) );
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( self::class, 'handle_virtual_pages' ) );
	}

	/**
	 * Add Stampy query vars to the allowed list.
	 *
	 * @param array<int, string> $vars Existing query vars.
	 * @return array<int, string>
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = self::CONFIRM_VAR;
		$vars[] = self::PREF_S_VAR;
		$vars[] = self::PREF_T_VAR;
		$vars[] = self::PREF_SIG_VAR;
		$vars[] = self::UNSUB_S_VAR;
		$vars[] = self::UNSUB_L_VAR;
		$vars[] = self::UNSUB_T_VAR;
		$vars[] = self::UNSUB_SIG_VAR;
		return $vars;
	}

	/**
	 * Register rewrite rules for virtual endpoints.
	 *
	 * Uses simple query-var-based rules so they work without pretty
	 * permalinks (important for wp-env tests instance).
	 *
	 * @return void
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^stampy/confirm/([a-f0-9]+)/?$',
			'index.php?' . self::CONFIRM_VAR . '=$matches[1]',
			'top'
		);

		add_rewrite_rule(
			'^stampy/preferences/([0-9]+)/([a-f0-9]+)/([a-f0-9]+)/?$',
			'index.php?' . self::PREF_S_VAR . '=$matches[1]&' . self::PREF_T_VAR . '=$matches[2]&' . self::PREF_SIG_VAR . '=$matches[3]',
			'top'
		);

		add_rewrite_rule(
			'^stampy/unsubscribe/([0-9]+)/([0-9]+)/([a-f0-9]+)/([a-f0-9]+)/?$',
			'index.php?' . self::UNSUB_S_VAR . '=$matches[1]&' . self::UNSUB_L_VAR . '=$matches[2]&' . self::UNSUB_T_VAR . '=$matches[3]&' . self::UNSUB_SIG_VAR . '=$matches[4]',
			'top'
		);
	}

	/**
	 * Handle virtual page requests.
	 *
	 * If a Stampy query var is present, render the appropriate virtual
	 * page and exit (preventing the default 404/template loader).
	 *
	 * @return void
	 */
	public static function handle_virtual_pages(): void {
		$confirm_token = get_query_var( self::CONFIRM_VAR );
		if ( '' !== $confirm_token ) {
			self::render_confirm_page( $confirm_token );
			return;
		}

		$pref_s = get_query_var( self::PREF_S_VAR );
		if ( '' !== $pref_s ) {
			self::render_preferences_page();
			return;
		}

		$unsub_s = get_query_var( self::UNSUB_S_VAR );
		if ( '' !== $unsub_s ) {
			self::render_unsubscribe_page();
			return;
		}
	}

	/**
	 * Render the confirmation landing page.
	 *
	 * Calls the SignupService to confirm the token, then shows a
	 * success or error message.
	 *
	 * @param string $token Raw confirmation token.
	 * @return void
	 */
	private static function render_confirm_page( string $token ): void {
		$service = new SignupService();
		$result  = $service->confirm( $token );

		$title = $result['success']
			? __( 'Subscription Confirmed', 'stampy' )
			: __( 'Confirmation Failed', 'stampy' );

		$message = $result['message'];

		if ( $result['success'] && isset( $result['preferences_url'] ) ) {
			$prefs_link = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $result['preferences_url'] ),
				esc_html( __( 'Manage your preferences', 'stampy' ) )
			);
			self::render_html_page( $title, $message . '</p><p>' . $prefs_link, false );
			return;
		}

		self::render_html_page( $title, $message );
	}

	/**
	 * Render the preferences page.
	 *
	 * Handles both GET (show the form) and POST (process updates, then
	 * re-render the page with a status message).
	 *
	 * @return void
	 */
	private static function render_preferences_page(): void {
		$subscriber_id = (int) get_query_var( self::PREF_S_VAR );
		$token         = (string) get_query_var( self::PREF_T_VAR );
		$sig           = (string) get_query_var( self::PREF_SIG_VAR );

		if ( ! Security::verify(
			array(
				's' => $subscriber_id,
				't' => $token,
			),
			$sig
		) ) {
			self::render_html_page(
				__( 'Invalid Link', 'stampy' ),
				__( 'The preference link is invalid or has expired.', 'stampy' )
			);
			return;
		}

		$subscriber_repo = new Repositories\SubscriberRepository();
		$subscriber      = $subscriber_repo->find( $subscriber_id );

		if ( null === $subscriber ) {
			self::render_html_page(
				__( 'Not Found', 'stampy' ),
				__( 'Subscriber not found.', 'stampy' )
			);
			return;
		}

		if ( ! Security::verify_token( $token, (string) $subscriber->unsub_token_hash ) ) {
			self::render_html_page(
				__( 'Invalid Token', 'stampy' ),
				__( 'The token in this link is invalid.', 'stampy' )
			);
			return;
		}

		// Handle POST: process preference updates.
		$notice = '';
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			$notice = self::handle_preferences_post( $subscriber_id );
		}

		$list_repo    = new Repositories\ListRepository();
		$member_lists = $list_repo->get_subscriber_lists( $subscriber_id );
		$all_lists    = $list_repo->all();

		$lists_html = '';
		foreach ( $all_lists as $list ) {
			$is_subscribed = false;
			foreach ( $member_lists as $ml ) {
				if ( (int) $ml->id === (int) $list->id && 'subscribed' === $ml->status ) {
					$is_subscribed = true;
					break;
				}
			}

			$checked     = $is_subscribed ? 'checked' : '';
			$lists_html .= sprintf(
				'<li><label><input type="checkbox" name="lists[]" value="%d" %s> %s</label></li>',
				(int) $list->id,
				$checked,
				esc_html( $list->name )
			);
		}

		$notice_html = '' !== $notice
			? sprintf( '<div class="stampy-notice">%s</div>', $notice )
			: '';

		$current_url = add_query_arg( array() );

		$html = sprintf(
			'<h1>%s</h1>
			%s
			<p>%s: <strong>%s</strong></p>
			<form method="post" action="%s" id="stampy-prefs-form">
				<ul>%s</ul>
				<p><button type="submit">%s</button></p>
				<hr>
				<p><button type="submit" name="opt_out" value="1" onclick="return confirm(\'%s\')">%s</button></p>
			</form>',
			esc_html( __( 'Manage Your Preferences', 'stampy' ) ),
			$notice_html,
			esc_html( __( 'Email', 'stampy' ) ),
			esc_html( $subscriber->email ),
			esc_url( $current_url ),
			$lists_html,
			esc_html( __( 'Save Preferences', 'stampy' ) ),
			esc_js( __( 'Are you sure you want to unsubscribe from all lists?', 'stampy' ) ),
			esc_html( __( 'Unsubscribe from all lists', 'stampy' ) )
		);

		self::render_html_page( __( 'Manage Your Preferences', 'stampy' ), $html, false );
	}

	/**
	 * Process a POST from the preferences form.
	 *
	 * Updates list memberships or handles global opt-out. Returns an
	 * HTML notice string (success or error message).
	 *
	 * @param int $subscriber_id Subscriber ID.
	 * @return string HTML notice (empty if nothing to show).
	 */
	private static function handle_preferences_post( int $subscriber_id ): string {
		$subscriber_repo = new Repositories\SubscriberRepository();
		$list_repo       = new Repositories\ListRepository();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		// No WordPress nonce needed: this form is only reachable via a
		// token-authenticated, HMAC-signed URL (verified in the caller).
		// The subscriber_id is from the rewrite query var, not user input.
		$opt_out = isset( $_POST['opt_out'] );

		if ( $opt_out ) {
			$subscriber_repo->update_status( $subscriber_id, 'unsubscribed' );

			$member_lists = $list_repo->get_subscriber_lists( $subscriber_id );
			foreach ( $member_lists as $list ) {
				$list_repo->remove_subscriber( $subscriber_id, (int) $list->id );
			}

			do_action( 'stampy_subscriber_unsubscribed_all', $subscriber_id );

			return sprintf(
				'<p class="stampy-success">%s</p>',
				esc_html( __( 'You have been unsubscribed from all lists.', 'stampy' ) )
			);
		}

		$raw_lists = $_POST['lists'] ?? array();
		if ( ! is_array( $raw_lists ) ) {
			$raw_lists = array();
		}
		$requested = array_map( 'intval', $raw_lists );

		$all_lists = $list_repo->all();
		foreach ( $all_lists as $list ) {
			$list_id = (int) $list->id;

			if ( in_array( $list_id, $requested, true ) ) {
				$list_repo->add_subscriber( $subscriber_id, $list_id );
			} else {
				$list_repo->remove_subscriber( $subscriber_id, $list_id );
			}
		}

		return sprintf(
			'<p class="stampy-success">%s</p>',
			esc_html( __( 'Your preferences have been updated.', 'stampy' ) )
		);
		// phpcs:enable
	}

	/**
	 * Render the one-click unsubscribe landing page.
	 *
	 * @return void
	 */
	private static function render_unsubscribe_page(): void {
		$subscriber_id = (int) get_query_var( self::UNSUB_S_VAR );
		$list_id       = (int) get_query_var( self::UNSUB_L_VAR );
		$token         = (string) get_query_var( self::UNSUB_T_VAR );
		$sig           = (string) get_query_var( self::UNSUB_SIG_VAR );

		if ( ! Security::verify(
			array(
				's'    => $subscriber_id,
				'list' => $list_id,
				't'    => $token,
			),
			$sig
		) ) {
			self::render_html_page(
				__( 'Invalid Link', 'stampy' ),
				__( 'The unsubscribe link is invalid or has expired.', 'stampy' )
			);
			return;
		}

		$subscriber_repo = new Repositories\SubscriberRepository();
		$subscriber      = $subscriber_repo->find( $subscriber_id );

		if ( null === $subscriber ) {
			self::render_html_page(
				__( 'Not Found', 'stampy' ),
				__( 'Subscriber not found.', 'stampy' )
			);
			return;
		}

		if ( ! Security::verify_token( $token, (string) $subscriber->unsub_token_hash ) ) {
			self::render_html_page(
				__( 'Invalid Token', 'stampy' ),
				__( 'The token in this link is invalid.', 'stampy' )
			);
			return;
		}

		$list_repo = new Repositories\ListRepository();
		$list_repo->remove_subscriber( $subscriber_id, $list_id );

		do_action( 'stampy_subscriber_unsubscribed', $subscriber_id, $list_id );

		self::render_html_page(
			__( 'Unsubscribed', 'stampy' ),
			__( 'You have been unsubscribed from this list.', 'stampy' )
		);
	}

	/**
	 * Render a full HTML page and exit.
	 *
	 * @param string $title   Page title.
	 * @param string $body    HTML body content (already escaped).
	 * @param bool   $escape  Whether to escape the body (default true).
	 * @return void
	 */
	private static function render_html_page( string $title, string $body, bool $escape = true ): void {
		$body_html = $escape ? wpautop( esc_html( $body ) ) : $body;

		printf(
			'<!DOCTYPE html>
<html %s>
<head>
	<meta charset="%s">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>%s</title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 600px; margin: 40px auto; padding: 0 20px; color: #333; }
		h1 { color: #2271b1; }
		.stampy-notice { padding: 12px 16px; border-radius: 4px; margin: 16px 0; background: #e7f3ff; border: 1px solid #2271b1; }
		.stampy-notice .stampy-success { color: #00a32a; }
	</style>
</head>
<body>
	%s
</body>
</html>',
			esc_attr( get_language_attributes() ),
			esc_attr( get_bloginfo( 'charset' ) ),
			esc_html( $title ),
			$body_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);

		exit;
	}
}
