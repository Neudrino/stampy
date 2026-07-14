<?php
/**
 * Settings admin page — SMTP configuration + test send.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

use Stampy\Smtp\SmtpSettings;
use Stampy\Smtp\SmtpTransport;
use Stampy\SpamGuards\QuizGuard;
use Stampy\SpamGuards\FriendlyCaptchaGuard;
use Stampy\SpamGuards\TurnstileGuard;
use Stampy\SubmissionLogSettings;
use Stampy\Tracking\TrackingSettings;

/**
 * Renders the SMTP settings page and handles form submissions.
 */
final class SettingsPage {

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stampy' ) );
		}

		$settings = SmtpSettings::get();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only GET param.
		$updated  = isset( $_GET['updated'] ) ? (string) $_GET['updated'] : '';
		$test_err = isset( $_GET['test_error'] ) ? sanitize_text_field( wp_unslash( $_GET['test_error'] ) ) : '';
		// phpcs:enable

		$has_password = '' !== $settings['password'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Stampy Settings', 'stampy' ); ?></h1>

			<?php if ( '1' === $updated ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Settings saved.', 'stampy' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( '' !== $test_err ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $test_err ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'SMTP Configuration', 'stampy' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="stampy_save_smtp_settings" />
				<?php wp_nonce_field( 'stampy_save_smtp_settings', 'stampy_smtp_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smtp_host"><?php esc_html_e( 'SMTP Host', 'stampy' ); ?></label></th>
						<td><input type="text" name="smtp_host" id="smtp_host" class="regular-text" value="<?php echo esc_attr( $settings['host'] ); ?>" placeholder="smtp.example.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_port"><?php esc_html_e( 'SMTP Port', 'stampy' ); ?></label></th>
						<td><input type="number" name="smtp_port" id="smtp_port" class="small-text" value="<?php echo esc_attr( (string) $settings['port'] ); ?>" min="1" max="65535" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_encryption"><?php esc_html_e( 'Encryption', 'stampy' ); ?></label></th>
						<td>
							<select name="smtp_encryption" id="smtp_encryption">
								<option value="none" <?php selected( $settings['encryption'], 'none' ); ?>><?php esc_html_e( 'None', 'stampy' ); ?></option>
								<option value="ssl" <?php selected( $settings['encryption'], 'ssl' ); ?>><?php esc_html_e( 'SSL', 'stampy' ); ?></option>
								<option value="tls" <?php selected( $settings['encryption'], 'tls' ); ?>><?php esc_html_e( 'TLS', 'stampy' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Authentication', 'stampy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="smtp_auth" id="smtp_auth" value="1" <?php checked( $settings['auth'] ); ?> />
								<?php esc_html_e( 'Use SMTP authentication', 'stampy' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_username"><?php esc_html_e( 'Username', 'stampy' ); ?></label></th>
						<td><input type="text" name="smtp_username" id="smtp_username" class="regular-text" value="<?php echo esc_attr( $settings['username'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_password"><?php esc_html_e( 'Password', 'stampy' ); ?></label></th>
						<td>
							<input type="password" name="smtp_password" id="smtp_password" class="regular-text" placeholder="<?php echo $has_password ? esc_attr__( '•••• stored (leave blank to keep)', 'stampy' ) : ''; ?>" autocomplete="new-password" />
							<?php if ( $has_password ) : ?>
								<p class="description"><?php esc_html_e( 'A password is already stored. Leave blank to keep the existing one.', 'stampy' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'From Address', 'stampy' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="smtp_from_email"><?php esc_html_e( 'From Email', 'stampy' ); ?></label></th>
						<td>
							<input type="email" name="smtp_from_email" id="smtp_from_email" class="regular-text" value="<?php echo esc_attr( $settings['from_email'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Defaults to the site admin email.', 'stampy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="smtp_from_name"><?php esc_html_e( 'From Name', 'stampy' ); ?></label></th>
						<td>
							<input type="text" name="smtp_from_name" id="smtp_from_name" class="regular-text" value="<?php echo esc_attr( $settings['from_name'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'blogname' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Defaults to the site name.', 'stampy' ); ?></p>
						</td>
					</tr>
				</table>

			<h2><?php esc_html_e( 'Open & Click Tracking', 'stampy' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Tracking', 'stampy' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="tracking_enabled" id="tracking_enabled" value="1" <?php checked( TrackingSettings::is_globally_enabled() ); ?> />
							<?php esc_html_e( 'Track opens and clicks in campaign emails', 'stampy' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, a tracking pixel and click-redirect links are added to campaign emails. Individual campaigns can override this setting. Disabled by default for privacy.', 'stampy' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Anti-Spam Quiz', 'stampy' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="quiz_questions"><?php esc_html_e( 'Quiz Questions', 'stampy' ); ?></label></th>
				<td>
					<textarea name="quiz_questions" id="quiz_questions" rows="5" class="large-text" placeholder="<?php esc_attr_e( 'What is 3 + 4?||7', 'stampy' ); ?>"><?php echo esc_textarea( (string) get_option( 'stampy_quiz_questions', '' ) ); ?></textarea>
					<p class="description"><?php esc_html_e( 'One question per line, in the format: question||answer. When configured, a random question is shown in the signup form. Leave empty to disable the quiz challenge.', 'stampy' ); ?></p>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Cloudflare Turnstile', 'stampy' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="turnstile_site_key"><?php esc_html_e( 'Site Key', 'stampy' ); ?></label></th>
				<td><input type="text" name="turnstile_site_key" id="turnstile_site_key" class="regular-text" value="<?php echo esc_attr( TurnstileGuard::get_site_key() ); ?>" placeholder="0x4AAAAAAA..." /></td>
			</tr>
			<tr>
				<th scope="row"><label for="turnstile_secret_key"><?php esc_html_e( 'Secret Key', 'stampy' ); ?></label></th>
				<td><input type="password" name="turnstile_secret_key" id="turnstile_secret_key" class="regular-text" value="<?php echo esc_attr( get_option( 'stampy_turnstile_secret_key', '' ) ); ?>" placeholder="0x4AAAAAAA..." autocomplete="new-password" />
				<p class="description"><?php esc_html_e( 'Get your keys at cloudflare.com/products/turnstile. Leave empty to disable.', 'stampy' ); ?></p></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Friendly Captcha', 'stampy' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="friendly_captcha_site_key"><?php esc_html_e( 'Site Key', 'stampy' ); ?></label></th>
				<td><input type="text" name="friendly_captcha_site_key" id="friendly_captcha_site_key" class="regular-text" value="<?php echo esc_attr( FriendlyCaptchaGuard::get_site_key() ); ?>" placeholder="FC..." /></td>
			</tr>
			<tr>
				<th scope="row"><label for="friendly_captcha_secret_key"><?php esc_html_e( 'Secret Key', 'stampy' ); ?></label></th>
				<td><input type="password" name="friendly_captcha_secret_key" id="friendly_captcha_secret_key" class="regular-text" value="<?php echo esc_attr( get_option( 'stampy_friendly_captcha_secret_key', '' ) ); ?>" placeholder="..." autocomplete="new-password" />
				<p class="description"><?php esc_html_e( 'Get your keys at friendlycaptcha.com. Leave empty to disable.', 'stampy' ); ?></p></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Compliance', 'stampy' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="physical_address"><?php esc_html_e( 'Physical Address', 'stampy' ); ?></label></th>
					<td>
						<textarea name="physical_address" id="physical_address" rows="3" class="large-text"><?php echo esc_textarea( (string) get_option( 'stampy_physical_address', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Your postal address, included in the footer of every campaign email (CAN-SPAM / GDPR requirement).', 'stampy' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Data on Uninstall', 'stampy' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="delete_data_on_uninstall" id="delete_data_on_uninstall" value="1" <?php checked( get_option( 'stampy_delete_data_on_uninstall', '1' ), '1' ); ?> />
							<?php esc_html_e( 'Remove all Stampy data when the plugin is deleted', 'stampy' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, deleting the plugin removes all subscribers, lists, campaigns, and settings. Deactivating the plugin preserves data regardless of this setting.', 'stampy' ); ?></p>
					</td>
				</tr>
			</table>

		<h2><?php esc_html_e( 'Submission Log', 'stampy' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Enable Submission Log', 'stampy' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="submission_log_enabled" id="submission_log_enabled" value="1" <?php checked( SubmissionLogSettings::is_enabled(), true ); ?> />
							<?php esc_html_e( 'Log every successful signup submission (email, fields, consent text, timestamp)', 'stampy' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, an audit trail of every signup submission is kept and automatically deleted when the subscriber is removed. Enabled by default.', 'stampy' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Save Settings', 'stampy' ) ); ?>

			</form>

			<?php if ( SmtpSettings::is_configured() ) : ?>
				<h2><?php esc_html_e( 'Send Test Email', 'stampy' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="stampy_send_test_email" />
					<?php wp_nonce_field( 'stampy_send_test_email', 'stampy_test_nonce' ); ?>

					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="test_recipient"><?php esc_html_e( 'Recipient', 'stampy' ); ?></label></th>
							<td><input type="email" name="test_recipient" id="test_recipient" class="regular-text" required /></td>
						</tr>
					</table>

					<?php submit_button( __( 'Send Test Email', 'stampy' ), 'primary', 'stampy-send-test' ); ?>
				</form>
			<?php else : ?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'Configure and save SMTP settings before sending a test email.', 'stampy' ); ?></p>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Handle the save settings form submission.
	 *
	 * @return void
	 */
	public static function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'stampy_save_smtp_settings', 'stampy_smtp_nonce' );

		$input = array(
			'host'       => isset( $_POST['smtp_host'] ) ? wp_unslash( $_POST['smtp_host'] ) : '',
			'port'       => isset( $_POST['smtp_port'] ) ? (int) $_POST['smtp_port'] : 587,
			'encryption' => isset( $_POST['smtp_encryption'] ) ? wp_unslash( $_POST['smtp_encryption'] ) : 'tls',
			'auth'       => isset( $_POST['smtp_auth'] ),
			'username'   => isset( $_POST['smtp_username'] ) ? wp_unslash( $_POST['smtp_username'] ) : '',
			'password'   => isset( $_POST['smtp_password'] ) ? (string) wp_unslash( $_POST['smtp_password'] ) : '',
			'from_email' => isset( $_POST['smtp_from_email'] ) ? wp_unslash( $_POST['smtp_from_email'] ) : '',
			'from_name'  => isset( $_POST['smtp_from_name'] ) ? wp_unslash( $_POST['smtp_from_name'] ) : '',
		);
		// phpcs:enable

		SmtpSettings::save( $input );

		$tracking_enabled = isset( $_POST['tracking_enabled'] );
		// phpcs:enable

		TrackingSettings::set_globally_enabled( $tracking_enabled );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$physical_address = isset( $_POST['physical_address'] ) ? sanitize_textarea_field( wp_unslash( $_POST['physical_address'] ) ) : '';
		// phpcs:enable
		update_option( 'stampy_physical_address', $physical_address, true );

		$delete_on_uninstall = isset( $_POST['delete_data_on_uninstall'] ) ? '1' : '0';
		update_option( 'stampy_delete_data_on_uninstall', $delete_on_uninstall, true );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$quiz_questions = isset( $_POST['quiz_questions'] ) ? sanitize_textarea_field( wp_unslash( $_POST['quiz_questions'] ) ) : '';
		// phpcs:enable
		update_option( 'stampy_quiz_questions', $quiz_questions, true );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$turnstile_site_key   = isset( $_POST['turnstile_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_site_key'] ) ) : '';
		$turnstile_secret_key = isset( $_POST['turnstile_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['turnstile_secret_key'] ) ) : '';
		$fc_site_key          = isset( $_POST['friendly_captcha_site_key'] ) ? sanitize_text_field( wp_unslash( $_POST['friendly_captcha_site_key'] ) ) : '';
		$fc_secret_key        = isset( $_POST['friendly_captcha_secret_key'] ) ? sanitize_text_field( wp_unslash( $_POST['friendly_captcha_secret_key'] ) ) : '';
		// phpcs:enable
		update_option( 'stampy_turnstile_site_key', $turnstile_site_key, true );
		update_option( 'stampy_turnstile_secret_key', $turnstile_secret_key, true );
		update_option( 'stampy_friendly_captcha_site_key', $fc_site_key, true );
		update_option( 'stampy_friendly_captcha_secret_key', $fc_secret_key, true );

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$sub_log_enabled = isset( $_POST['submission_log_enabled'] ) ? '1' : '0';
		// phpcs:enable
		update_option( SubmissionLogSettings::ENABLED_OPTION, $sub_log_enabled, true );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'stampy-settings',
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle the test email form submission.
	 *
	 * @return void
	 */
	public static function handle_send_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		check_admin_referer( 'stampy_send_test_email', 'stampy_test_nonce' );

		$to = isset( $_POST['test_recipient'] ) ? sanitize_email( wp_unslash( $_POST['test_recipient'] ) ) : '';
		// phpcs:enable

		if ( '' === $to ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'stampy-settings',
						'test_error' => rawurlencode( __( 'Invalid recipient email address.', 'stampy' ) ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$sent = SmtpTransport::send_test( $to );

		if ( $sent ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'stampy-settings',
						'updated' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => 'stampy-settings',
					'test_error' => rawurlencode( __( 'Test email failed to send. Check your SMTP settings.', 'stampy' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
