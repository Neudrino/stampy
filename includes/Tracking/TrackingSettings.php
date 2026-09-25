<?php
/**
 * Tracking settings storage.
 *
 * Global toggle for open/click tracking (default OFF) plus per-campaign
 * override meta. The override allows 'on' (force enable), 'off' (force
 * disable), or '' (inherit global default).
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Tracking;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages tracking settings (global option + per-campaign override).
 */
final class TrackingSettings {

	/**
	 * Option key for the global tracking toggle.
	 */
	public const OPTION_KEY = 'stampy_tracking_enabled';

	/**
	 * Option key for the anonymous-tracking toggle.
	 *
	 * Only effective while tracking is enabled. Default: anonymous
	 * tracking ON ('1').
	 */
	public const OPTION_ANONYMOUS = 'stampy_tracking_anonymous';

	/**
	 * Meta key for the per-campaign tracking override.
	 */
	public const META_OVERRIDE = 'stampy_campaign_tracking';

	/**
	 * Meta key for the tracking mode actually used when the campaign was
	 * sent (snapshot written by the SendingEngine at send start).
	 *
	 * Values: 'off', 'anonymous', 'personalized', or '' (never sent).
	 */
	public const META_SENT_MODE = 'stampy_campaign_tracking_mode';

	/**
	 * Tracking-by-mode constants.
	 */
	public const MODE_OFF          = 'off';
	public const MODE_ANONYMOUS    = 'anonymous';
	public const MODE_PERSONALIZED = 'personalized';

	/**
	 * Get the global tracking-enabled setting.
	 *
	 * @return bool True if tracking is globally enabled.
	 */
	public static function is_globally_enabled(): bool {
		return '1' === get_option( self::OPTION_KEY, '0' );
	}

	/**
	 * Set the global tracking-enabled setting.
	 *
	 * @param bool $enabled Whether tracking is enabled.
	 * @return void
	 */
	public static function set_globally_enabled( bool $enabled ): void {
		update_option( self::OPTION_KEY, $enabled ? '1' : '0', false );
	}

	/**
	 * Delete the global tracking setting.
	 *
	 * @return void
	 */
	public static function delete(): void {
		delete_option( self::OPTION_KEY );
	}

	/**
	 * Get the anonymous-tracking setting.
	 *
	 * When anonymous tracking is active (the default), tracking records
	 * only aggregate counts (how many recipients opened or clicked) but
	 * never identifies the individual recipients. Only effective while
	 * tracking is enabled — if tracking is off, nothing is tracked
	 * regardless of this setting.
	 *
	 * @return bool True if tracking is anonymous (default).
	 */
	public static function is_anonymous(): bool {
		return '1' === get_option( self::OPTION_ANONYMOUS, '1' );
	}

	/**
	 * Set the anonymous-tracking setting.
	 *
	 * @param bool $anonymous Whether tracking should be anonymous.
	 * @return void
	 */
	public static function set_anonymous( bool $anonymous ): void {
		update_option( self::OPTION_ANONYMOUS, $anonymous ? '1' : '0', false );
	}

	/**
	 * Delete the anonymous-tracking setting.
	 *
	 * @return void
	 */
	public static function delete_anonymous(): void {
		delete_option( self::OPTION_ANONYMOUS );
	}

	/**
	 * Get the per-campaign tracking override.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return string '' (inherit), 'on', or 'off'.
	 */
	public static function get_campaign_override( int $campaign_id ): string {
		$val = get_post_meta( $campaign_id, self::META_OVERRIDE, true );
		if ( ! is_string( $val ) ) {
			return '';
		}
		return $val;
	}

	/**
	 * Set the per-campaign tracking override.
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $override    '' (inherit), 'on', or 'off'.
	 * @return bool
	 */
	public static function set_campaign_override( int $campaign_id, string $override ): bool {
		if ( ! in_array( $override, array( '', 'on', 'off' ), true ) ) {
			return false;
		}
		return (bool) update_post_meta( $campaign_id, self::META_OVERRIDE, $override );
	}

	/**
	 * Determine if tracking is enabled for a specific campaign.
	 *
	 * Per-campaign override takes precedence; otherwise the global default
	 * applies.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return bool
	 */
	public static function is_tracking_enabled( int $campaign_id ): bool {
		$override = self::get_campaign_override( $campaign_id );

		if ( 'on' === $override ) {
			return true;
		}

		if ( 'off' === $override ) {
			return false;
		}

		return self::is_globally_enabled();
	}

	/**
	 * Resolve the effective tracking mode for a campaign right now.
	 *
	 * Resolves global toggle + per-campaign override + anonymous setting
	 * into one of the tracking-mode constants. Use this for new sends;
	 * for campaigns that have already been sent, the snapshot meta
	 * (META_SENT_MODE) records the mode actually used.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return string One of MODE_OFF, MODE_ANONYMOUS, MODE_PERSONALIZED.
	 */
	public static function resolve_current_mode( int $campaign_id ): string {
		if ( ! self::is_tracking_enabled( $campaign_id ) ) {
			return self::MODE_OFF;
		}

		return self::is_anonymous() ? self::MODE_ANONYMOUS : self::MODE_PERSONALIZED;
	}

	/**
	 * Get the tracking mode that was in effect when the campaign was sent.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return string One of MODE_OFF, MODE_ANONYMOUS, MODE_PERSONALIZED,
	 *                or '' if the campaign was never sent.
	 */
	public static function get_campaign_sent_mode( int $campaign_id ): string {
		$val = get_post_meta( $campaign_id, self::META_SENT_MODE, true );
		if ( ! is_string( $val ) || ! in_array( $val, array( self::MODE_OFF, self::MODE_ANONYMOUS, self::MODE_PERSONALIZED ), true ) ) {
			return '';
		}
		return $val;
	}

	/**
	 * Store the tracking mode used for a send (snapshot at send start).
	 *
	 * @param int    $campaign_id Campaign post ID.
	 * @param string $mode        One of MODE_OFF, MODE_ANONYMOUS, MODE_PERSONALIZED.
	 * @return bool
	 */
	public static function set_campaign_sent_mode( int $campaign_id, string $mode ): bool {
		if ( ! in_array( $mode, array( self::MODE_OFF, self::MODE_ANONYMOUS, self::MODE_PERSONALIZED ), true ) ) {
			return false;
		}
		return (bool) update_post_meta( $campaign_id, self::META_SENT_MODE, $mode );
	}
}
