<?php
/**
 * Submission log settings storage.
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
 * Manages submission log settings (enable/disable).
 *
 * The submission log is enabled by default. Entries are retained
 * indefinitely and auto-deleted when the subscriber is deleted.
 */
final class SubmissionLogSettings {

	/**
	 * Option key for the enabled flag.
	 */
	public const ENABLED_OPTION = 'stampy_submission_log_enabled';

	/**
	 * Check if the submission log is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return '1' === get_option( self::ENABLED_OPTION, '1' );
	}
}
