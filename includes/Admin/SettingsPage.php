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
