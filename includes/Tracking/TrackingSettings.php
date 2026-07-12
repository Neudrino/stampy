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
	 * Meta key for the per-campaign tracking override.
	 */
	public const META_OVERRIDE = 'stampy_campaign_tracking';

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
}
