<?php
/**
 * Campaign recipients admin page.
 *
 * Shows who received a campaign and, when personalized tracking was
 * used, who opened or clicked it.
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
use Stampy\Tracking\TrackingSettings;

/**
 * Renders the campaign recipients detail view.
 */
final class CampaignRecipientsPage {

	/**
	 * Admin page slug.
	 */
	public const SLUG = 'stampy-campaign-recipients';

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only selection.
		$campaign_id = isset( $_GET['campaign'] ) ? absint( wp_unslash( $_GET['campaign'] ) ) : 0;
		// phpcs:enable

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Campaign Recipients', 'stampy' ) . '</h1>';

		if ( $campaign_id < 1 ) {
			self::render_campaign_chooser();
			echo '</div>';
			return;
		}

		$post = get_post( $campaign_id );
		if ( ! $post instanceof \WP_Post || CampaignPostType::POST_TYPE !== $post->post_type ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Campaign not found.', 'stampy' ) . '</p></div>';
			self::render_campaign_chooser();
			echo '</div>';
			return;
		}

		printf(
			'<p><strong>%s</strong> &mdash; <a href="%s">%s</a></p>',
			esc_html( $post->post_title ),
			esc_url( admin_url( 'edit.php?post_type=' . CampaignPostType::POST_TYPE ) ),
			esc_html__( 'Back to campaigns', 'stampy' )
		);

		self::render_tracking_notice( $campaign_id );
		self::render_search_form( $campaign_id );

		$table = new CampaignRecipientsListTable( $campaign_id );
		$table->prepare_items();
		$table->display();

		echo '</div>';
	}

	/**
	 * Render the tracking-mode notice for the campaign.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	private static function render_tracking_notice( int $campaign_id ): void {
		$mode = CampaignPostType::get_tracking_display_mode( $campaign_id );

		if ( TrackingSettings::MODE_OFF === $mode ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__( 'Tracking was disabled for this send, so no open or click data was recorded.', 'stampy' )
			);
			return;
		}

		if ( TrackingSettings::MODE_ANONYMOUS === $mode ) {
			printf(
				'<div class="notice notice-info inline"><p>%s</p></div>',
				esc_html__( 'This campaign was sent with anonymous tracking. Only aggregate counts are available — the "Opened"/"Clicked" columns stay empty on purpose and the plugin cannot tell who opened or clicked.', 'stampy' )
			);
		}
	}

	/**
	 * Render the per-campaign recipients search form.
	 *
	 * @param int $campaign_id Campaign post ID.
	 * @return void
	 */
	private static function render_search_form( int $campaign_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<input type="hidden" name="campaign" value="<?php echo esc_attr( (string) $campaign_id ); ?>" />
			<p class="search-box">
				<label class="screen-reader-text" for="stampy-recipient-search"><?php esc_html_e( 'Search recipients', 'stampy' ); ?></label>
				<input type="search" id="stampy-recipient-search" name="s" value="<?php echo esc_attr( $search ); ?>" />
				<?php submit_button( __( 'Search', 'stampy' ), '', '', false, array( 'id' => 'stampy-recipient-search-submit' ) ); ?>
			</p>
		</form>
		<?php
	}

	/**
	 * Render a chooser for campaigns with recipient data.
	 *
	 * @return void
	 */
	private static function render_campaign_chooser(): void {
		$campaigns = get_posts(
			array(
				'post_type'   => CampaignPostType::POST_TYPE,
				'numberposts' => 100,
				'post_status' => 'publish',
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);

		if ( empty( $campaigns ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No campaigns found.', 'stampy' ) . '</p></div>';
			return;
		}

		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="stampy-recipients-campaign"><?php esc_html_e( 'Campaign', 'stampy' ); ?></label></th>
					<td>
						<select name="campaign" id="stampy-recipients-campaign">
							<?php foreach ( $campaigns as $campaign ) : ?>
								<option value="<?php echo esc_attr( (string) $campaign->ID ); ?>">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: campaign title, 2: campaign status */
											__( '%1$s (%2$s)', 'stampy' ),
											$campaign->post_title,
											CampaignPostType::get_status( (int) $campaign->ID )
										)
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php submit_button( __( 'View recipients', 'stampy' ), '', '', false ); ?>
					</td>
				</tr>
			</table>
		</form>
		<?php
	}
}
