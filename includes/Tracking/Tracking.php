<?php
/**
 * Tracking: pixel injection + link rewriting.
 *
 * Builds signed tracking URLs (pixel for opens, redirect for clicks),
 * injects the open-tracking pixel into HTML email bodies, and rewrites
 * content links with click-tracking redirects.
 *
 * Excludes from rewriting: `{unsubscribe_url}`, `mailto:`, and in-page
 * anchors (`#...`).
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tracking;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Security;

/**
 * Builds tracking URLs and instruments email HTML for open/click tracking.
 */
final class Tracking {

	/**
	 * Query var for the open-tracking recipient ID.
	 */
	public const OPEN_R_VAR = 'stampy_trk_r';

	/**
	 * Query var for the open-tracking campaign ID.
	 */
	public const OPEN_C_VAR = 'stampy_trk_c';

	/**
	 * Query var for the open-tracking signature.
	 */
	public const OPEN_SIG_VAR = 'stampy_trk_sig';

	/**
	 * Query var for the click-tracking recipient ID.
	 */
	public const CLICK_R_VAR = 'stampy_clk_r';

	/**
	 * Query var for the click-tracking campaign ID.
	 */
	public const CLICK_C_VAR = 'stampy_clk_c';

	/**
	 * Query var for the click-tracking destination URL.
	 */
	public const CLICK_U_VAR = 'stampy_clk_u';

	/**
	 * Query var for the click-tracking signature.
	 */
	public const CLICK_SIG_VAR = 'stampy_clk_sig';

	/**
	 * Build the open-tracking pixel URL for a recipient.
	 *
	 * @param int $recipient_id  Recipient row ID.
	 * @param int $campaign_id   Campaign post ID.
	 * @return string
	 */
	public function build_open_pixel_url( int $recipient_id, int $campaign_id ): string {
		$params = array(
			'r' => $recipient_id,
			'c' => $campaign_id,
		);

		$signature = Security::sign( $params );

		return add_query_arg(
			array(
				self::OPEN_R_VAR   => $recipient_id,
				self::OPEN_C_VAR   => $campaign_id,
				self::OPEN_SIG_VAR => $signature,
			),
			home_url( '/' )
		);
	}

	/**
	 * Build the click-tracking redirect URL for a link.
	 *
	 * @param int    $recipient_id Recipient row ID.
	 * @param int    $campaign_id  Campaign post ID.
	 * @param string $destination  Original destination URL.
	 * @return string
	 */
	public function build_click_url( int $recipient_id, int $campaign_id, string $destination ): string {
		$params = array(
			'r' => $recipient_id,
			'c' => $campaign_id,
			'u' => $destination,
		);

		$signature = Security::sign( $params );

		return add_query_arg(
			array(
				self::CLICK_R_VAR   => $recipient_id,
				self::CLICK_C_VAR   => $campaign_id,
				self::CLICK_U_VAR   => rawurlencode( $destination ),
				self::CLICK_SIG_VAR => $signature,
			),
			home_url( '/' )
		);
	}

	/**
	 * Inject the open-tracking pixel into an HTML email body.
	 *
	 * Appends a 1×1 transparent GIF `<img>` tag just before `</body>`.
	 * If no `</body>` tag is present, appends at the end.
	 *
	 * @param string $html          Email HTML body.
	 * @param int    $recipient_id  Recipient row ID.
	 * @param int    $campaign_id   Campaign post ID.
	 * @return string
	 */
	public function inject_open_pixel( string $html, int $recipient_id, int $campaign_id ): string {
		$pixel_url = $this->build_open_pixel_url( $recipient_id, $campaign_id );

		$pixel = '<img src="' . esc_url( $pixel_url ) . '" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0;" />';

		if ( false !== stripos( $html, '</body>' ) ) {
			return preg_replace( '/<\/body>/i', $pixel . '</body>', $html, 1 ) ?? $html . $pixel;
		}

		return $html . $pixel;
	}

	/**
	 * Rewrite links in HTML email body with click-tracking redirects.
	 *
	 * Excludes:
	 * - `{unsubscribe_url}` merge-tag hrefs (still a placeholder at
	 *   rewrite time; the merge-tag registry replaces it later).
	 * - `mailto:` links.
	 * - In-page anchors (`#...`).
	 * - Links whose href starts with `{` (other merge-tag placeholders).
	 *
	 * @param string $html          Email HTML body.
	 * @param int    $recipient_id  Recipient row ID.
	 * @param int    $campaign_id   Campaign post ID.
	 * @return string
	 */
	public function rewrite_click_links( string $html, int $recipient_id, int $campaign_id ): string {
		$that = $this;

		$rewritten = preg_replace_callback(
			'/(<a\s+[^>]*?href=["\'])([^"\']+)(["\'][^>]*?>)/is',
			static function ( array $m ) use ( $that, $recipient_id, $campaign_id ): string {
				$href = $m[2];

				if ( $that->should_exclude_link( $href ) ) {
					return $m[1] . $href . $m[3];
				}

				return $m[1] . esc_url( $that->build_click_url( $recipient_id, $campaign_id, $href ) ) . $m[3];
			},
			$html
		);

		return $rewritten ?? $html;
	}

	/**
	 * Determine if a link href should be excluded from click tracking.
	 *
	 * @param string $href The href attribute value.
	 * @return bool
	 */
	private function should_exclude_link( string $href ): bool {
		if ( '' === $href ) {
			return true;
		}

		if ( 0 === strpos( $href, '#' ) ) {
			return true;
		}

		$lower = strtolower( $href );
		if ( 0 === strpos( $lower, 'mailto:' ) ) {
			return true;
		}

		if ( 0 === strpos( $lower, 'tel:' ) ) {
			return true;
		}

		if ( 0 === strpos( $href, '{' ) ) {
			return true;
		}

		if ( 0 === strpos( $lower, '%7b' ) ) {
			return true;
		}

		if ( false !== strpos( $href, 'stampy_unsub' ) ) {
			return true;
		}

		if ( false !== strpos( $lower, 'unsubscribe_url' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Verify an open-tracking signature.
	 *
	 * @param int    $recipient_id Recipient row ID.
	 * @param int    $campaign_id  Campaign post ID.
	 * @param string $signature   HMAC signature.
	 * @return bool
	 */
	public static function verify_open_signature( int $recipient_id, int $campaign_id, string $signature ): bool {
		return Security::verify(
			array(
				'r' => $recipient_id,
				'c' => $campaign_id,
			),
			$signature
		);
	}

	/**
	 * Verify a click-tracking signature.
	 *
	 * @param int    $recipient_id Recipient row ID.
	 * @param int    $campaign_id  Campaign post ID.
	 * @param string $destination  Original destination URL.
	 * @param string $signature    HMAC signature.
	 * @return bool
	 */
	public static function verify_click_signature( int $recipient_id, int $campaign_id, string $destination, string $signature ): bool {
		return Security::verify(
			array(
				'r' => $recipient_id,
				'c' => $campaign_id,
				'u' => $destination,
			),
			$signature
		);
	}
}
