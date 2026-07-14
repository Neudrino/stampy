<?php
/**
 * Submission log admin page.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the submission log admin page.
 */
final class SubmissionLogPage {

	/**
	 * Render the submission log page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stampy' ) );
		}

		$table = new SubmissionLogListTable();
		$table->prepare_items();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Submission Log', 'stampy' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="stampy-submission-log" />
				<?php $table->search_box( __( 'Search by email', 'stampy' ), 'submission-log' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}
}
