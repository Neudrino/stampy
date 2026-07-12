<?php
/**
 * Subscribers admin page — list table + detail view.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\FieldRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use WP_List_Table;

/**
 * Renders the subscribers admin page with list table and detail editing.
 */
final class SubscribersPage {

	/**
	 * Set up the screen (set per-page option, etc.).
	 *
	 * @return void
	 */
	public static function setup_screen(): void {
		$option = 'stampy_subscribers_per_page';
		$args   = array(
			'label'   => __( 'Subscribers per page', 'stampy' ),
			'default' => 20,
			'option'  => $option,
		);
		add_screen_option( 'per_page', $args );
	}

	/**
	 * Render the subscribers page (list or detail).
	 *
	 * @return void
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only GET params for page routing.
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		$id     = isset( $_GET['subscriber_id'] ) ? (int) $_GET['subscriber_id'] : 0;
		// phpcs:enable

		if ( 'edit' === $action && $id > 0 ) {
			self::render_detail( $id );
		} else {
			self::render_list();
		}
	}

	/**
	 * Render the subscribers list table.
	 *
	 * @return void
	 */
	private static function render_list(): void {
		$table = new SubscribersListTable();
		$table->prepare_items();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display-only GET params for search/filter.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		// phpcs:enable

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$bulk_done = isset( $_GET['stampy_bulk_done'] ) ? sanitize_text_field( wp_unslash( $_GET['stampy_bulk_done'] ) ) : '';
		$bulk_msg  = isset( $_GET['stampy_bulk_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['stampy_bulk_msg'] ) ) : '';
		// phpcs:enable
		?>
		<div class="wrap">
			<?php if ( '1' === $bulk_done && '' !== $bulk_msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $bulk_msg ); ?></p></div>
			<?php endif; ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'stampy' ); ?></h1>
		<form method="post">
			<input type="hidden" name="page" value="stampy-subscribers" />
			<?php $table->search_box( __( 'Search subscribers', 'stampy' ), 'subscriber' ); ?>
			<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render the subscriber detail/edit view.
	 *
	 * @param int $id Subscriber ID.
	 * @return void
	 */
	private static function render_detail( int $id ): void {
		$subscribers_repo = new SubscriberRepository();
		$meta_repo        = new SubscriberMetaRepository();
		$fields_repo      = new FieldRepository();
		$lists_repo       = new ListRepository();

		$subscriber = $subscribers_repo->find( $id );
		if ( null === $subscriber ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Subscriber not found', 'stampy' ) . '</h1></div>';
			return;
		}

		$meta        = $meta_repo->get_all( $id );
		$fields      = $fields_repo->all( true );
		$all_lists   = $lists_repo->all();
		$my_lists    = $lists_repo->get_subscriber_lists( $id );
		$my_list_map = array();
		foreach ( $my_lists as $ml ) {
			$my_list_map[ (int) $ml->id ] = $ml->status;
		}
		?>
		<div class="wrap">
			<h1>
				<?php esc_html_e( 'Edit Subscriber', 'stampy' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=stampy-subscribers' ) ); ?>" class="page-title-action">
					<?php esc_html_e( 'Back', 'stampy' ); ?>
				</a>
			</h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="stampy_save_subscriber" />
				<input type="hidden" name="subscriber_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<?php wp_nonce_field( 'stampy_save_subscriber_' . $id, 'stampy_nonce' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="email"><?php esc_html_e( 'Email', 'stampy' ); ?></label></th>
						<td><input type="text" value="<?php echo esc_attr( $subscriber->email ); ?>" disabled /></td>
					</tr>
					<tr>
						<th scope="row"><label for="status"><?php esc_html_e( 'Status', 'stampy' ); ?></label></th>
						<td>
							<select name="status" id="status">
								<?php
								$statuses = array( 'pending', 'confirmed', 'unsubscribed' );
								foreach ( $statuses as $s ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $s ),
										selected( $subscriber->status, $s, false ),
										esc_html( ucfirst( $s ) )
									);
								}
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Created', 'stampy' ); ?></th>
						<td><?php echo esc_html( $subscriber->created_at ); ?></td>
					</tr>
					<?php if ( ! empty( $subscriber->confirmed_at ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Confirmed', 'stampy' ); ?></th>
							<td><?php echo esc_html( $subscriber->confirmed_at ); ?></td>
						</tr>
					<?php endif; ?>
				</table>

				<?php if ( count( $fields ) > 0 ) : ?>
					<h2><?php esc_html_e( 'Attributes (read-only)', 'stampy' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $fields as $field ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $field->label ); ?></th>
								<td><?php echo esc_html( $meta[ $field->field_key ] ?? '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>

				<h2><?php esc_html_e( 'List Memberships', 'stampy' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php foreach ( $all_lists as $list ) : ?>
						<?php $membership_status = $my_list_map[ (int) $list->id ] ?? ''; ?>
						<tr>
							<th scope="row"><?php echo esc_html( $list->name ); ?></th>
							<td>
								<label>
									<input
										type="checkbox"
										name="list_ids[]"
										value="<?php echo esc_attr( (string) $list->id ); ?>"
										<?php checked( 'subscribed' === $membership_status ); ?>
									/>
									<?php esc_html_e( 'Subscribed', 'stampy' ); ?>
								</label>
								<?php if ( 'unsubscribed' === $membership_status ) : ?>
									<span class="description"><?php esc_html_e( '(currently unsubscribed)', 'stampy' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button( __( 'Save', 'stampy' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle the save form submission.
	 *
	 * @return void
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'stampy' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		// Nonce is verified below via check_admin_referer.
		$id = isset( $_POST['subscriber_id'] ) ? (int) $_POST['subscriber_id'] : 0;
		if ( $id <= 0 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=stampy-subscribers' ) );
			exit;
		}

		check_admin_referer( 'stampy_save_subscriber_' . $id, 'stampy_nonce' );

		$status   = isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : 'pending';
		$list_ids = isset( $_POST['list_ids'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['list_ids'] ) ) : array();
		// phpcs:enable

		if ( ! in_array( $status, array( 'pending', 'confirmed', 'unsubscribed' ), true ) ) {
			$status = 'pending';
		}

		$subscribers_repo = new SubscriberRepository();
		$lists_repo       = new ListRepository();

		$subscribers_repo->update_status( $id, $status );

		$all_lists = $lists_repo->all();
		$my_lists  = $lists_repo->get_subscriber_lists( $id );
		$my_map    = array();
		foreach ( $my_lists as $ml ) {
			$my_map[ (int) $ml->id ] = $ml->status;
		}

		foreach ( $all_lists as $list ) {
			$list_id     = (int) $list->id;
			$should_sub  = in_array( $list_id, $list_ids, true );
			$current_sts = $my_map[ $list_id ] ?? '';

			if ( $should_sub && 'subscribed' !== $current_sts ) {
				$lists_repo->add_subscriber( $id, $list_id );
			} elseif ( ! $should_sub && 'subscribed' === $current_sts ) {
				$lists_repo->remove_subscriber( $id, $list_id );
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => 'stampy-subscribers',
					'action'        => 'edit',
					'subscriber_id' => $id,
					'updated'       => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
