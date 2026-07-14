<?php
/**
 * Subscribers list table for WP admin.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;
use stdClass;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for browsing subscribers.
 */
class SubscribersListTable extends WP_List_Table {

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private $subscribers;

	/**
	 * Subscriber meta repository.
	 *
	 * @var SubscriberMetaRepository
	 */
	private $meta;

	/**
	 * List repository.
	 *
	 * @var ListRepository
	 */
	private $lists;

	/**
	 * Cached meta for the current page (subscriber_id => [field_key => value]).
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $page_meta = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);

		$this->subscribers = new SubscriberRepository();
		$this->meta        = new SubscriberMetaRepository();
		$this->lists       = new ListRepository();
	}

	/**
	 * Get column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'           => '<input type="checkbox" />',
			'email'        => __( 'Email', 'stampy' ),
			'first_name'   => __( 'First Name', 'stampy' ),
			'last_name'    => __( 'Last Name', 'stampy' ),
			'status'       => __( 'Status', 'stampy' ),
			'lists'        => __( 'Lists', 'stampy' ),
			'created_at'   => __( 'Created', 'stampy' ),
			'confirmed_at' => __( 'Confirmed', 'stampy' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array<int, bool|string>>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'email'        => array( 'email', false ),
			'status'       => array( 'status', false ),
			'created_at'   => array( 'created_at', true ),
			'confirmed_at' => array( 'confirmed_at', false ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete'           => __( 'Delete', 'stampy' ),
			'set_pending'      => __( 'Set Pending', 'stampy' ),
			'set_confirmed'    => __( 'Set Confirmed', 'stampy' ),
			'set_unsubscribed' => __( 'Set Unsubscribed', 'stampy' ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$per_page = (int) get_user_option( 'stampy_subscribers_per_page' );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}

		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$status  = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$list_id = isset( $_GET['list_id'] ) ? (int) $_GET['list_id'] : 0;

		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'desc';

		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		// Handle bulk actions.
		$this->process_bulk_action();
		// phpcs:enable

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			'email',
		);

		$args = array(
			'per_page' => $per_page,
			'page'     => $paged,
			'search'   => $search,
			'status'   => $status,
			'list_id'  => $list_id,
			'orderby'  => $orderby,
			'order'    => strtoupper( $order ),
		);

		$this->items = $this->subscribers->get_all( $args );

		$this->page_meta = array();
		foreach ( $this->items as $row ) {
			$this->page_meta[ (int) $row->id ] = $this->meta->get_all( (int) $row->id );
		}

		$total = $this->subscribers->count_filtered(
			array(
				'status'  => $status,
				'search'  => $search,
				'list_id' => $list_id,
			)
		);

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
			)
		);
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param stdClass $item Subscriber row.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="subscriber[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Render the email column with row actions.
	 *
	 * @param stdClass $item Subscriber row.
	 * @return string
	 */
	public function column_email( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'          => 'stampy-subscribers',
				'action'        => 'edit',
				'subscriber_id' => (int) $item->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'          => 'stampy-subscribers',
					'action'        => 'delete',
					'subscriber_id' => (int) $item->id,
				),
				admin_url( 'admin.php' )
			),
			'stampy_delete_subscriber_' . $item->id,
			'stampy_nonce'
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'stampy' ) ),
			'delete' => sprintf( '<a href="%s">%s</a>', esc_url( $delete_url ), esc_html__( 'Delete', 'stampy' ) ),
		);

		return sprintf(
			'<strong><a href="%s" class="row-title">%s</a></strong> %s',
			esc_url( $edit_url ),
			esc_html( $item->email ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Render the first name column.
	 *
	 * @param stdClass $item Subscriber row.
	 * @return string
	 */
	public function column_first_name( $item ): string {
		$value = $this->page_meta[ (int) $item->id ]['first_name'] ?? '';
		return '' !== $value ? esc_html( $value ) : esc_html__( '—', 'stampy' );
	}

	/**
	 * Render the last name column.
	 *
	 * @param stdClass $item Subscriber row.
	 * @return string
	 */
	public function column_last_name( $item ): string {
		$value = $this->page_meta[ (int) $item->id ]['last_name'] ?? '';
		return '' !== $value ? esc_html( $value ) : esc_html__( '—', 'stampy' );
	}

	/**
	 * Render the status column.
	 *
	 * @param stdClass $item Subscriber row.
	 * @return string
	 */
	public function column_status( $item ): string {
		$status = esc_html( ucfirst( $item->status ) );
		$class  = 'pending' === $item->status ? 'status-pending' : ( 'confirmed' === $item->status ? 'status-confirmed' : 'status-unsubscribed' );
		return sprintf( '<span class="stampy-status %s">%s</span>', esc_attr( $class ), $status );
	}

	/**
	 * Render the lists column.
	 *
	 * @param stdClass $item Subscriber row.
	 * @return string
	 */
	public function column_lists( $item ): string {
		$my_lists = $this->lists->get_subscriber_lists( (int) $item->id );
		if ( count( $my_lists ) === 0 ) {
			return esc_html__( '—', 'stampy' );
		}

		$names = array();
		foreach ( $my_lists as $ml ) {
			if ( 'subscribed' === $ml->status ) {
				$names[] = esc_html( $ml->name );
			}
		}

		return count( $names ) > 0 ? implode( ', ', $names ) : esc_html__( '—', 'stampy' );
	}

	/**
	 * Render default columns.
	 *
	 * @param stdClass $item        Subscriber row.
	 * @param string   $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		if ( ! isset( $item->{$column_name} ) ) {
			return esc_html__( '—', 'stampy' );
		}

		$value = $item->{$column_name};
		return '' === $value ? esc_html__( '—', 'stampy' ) : esc_html( $value );
	}

	/**
	 * Extra controls for the navigation area (status filter).
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current   = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
		$list_id   = isset( $_GET['list_id'] ) ? (int) $_GET['list_id'] : 0;
		$all_lists = $this->lists->all();
		?>
		<div class="alignleft actions">
			<label for="filter-by-status" class="screen-reader-text"><?php esc_html_e( 'Filter by status', 'stampy' ); ?></label>
			<select name="status" id="filter-by-status">
				<option value="" <?php selected( $current, '' ); ?>><?php esc_html_e( 'All statuses', 'stampy' ); ?></option>
				<option value="pending" <?php selected( $current, 'pending' ); ?>><?php esc_html_e( 'Pending', 'stampy' ); ?></option>
				<option value="confirmed" <?php selected( $current, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'stampy' ); ?></option>
				<option value="unsubscribed" <?php selected( $current, 'unsubscribed' ); ?>><?php esc_html_e( 'Unsubscribed', 'stampy' ); ?></option>
			</select>
			<label for="filter-by-list" class="screen-reader-text"><?php esc_html_e( 'Filter by list', 'stampy' ); ?></label>
			<select name="list_id" id="filter-by-list">
				<option value="0"<?php selected( $list_id, 0 ); ?>><?php esc_html_e( 'All lists', 'stampy' ); ?></option>
				<?php foreach ( $all_lists as $list ) : ?>
					<option value="<?php echo esc_attr( (string) $list->id ); ?>"<?php selected( $list_id, (int) $list->id ); ?>><?php echo esc_html( $list->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'stampy' ), '', 'filter_action', false ); ?>
		</div>
		<?php
		// phpcs:enable
	}

	/**
	 * Handle bulk actions on the load-{page-hook} action.
	 *
	 * This runs before the admin header is output, so redirects work.
	 *
	 * @return void
	 */
	public static function handle_bulk_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$action = isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] ? sanitize_key( $_REQUEST['action'] ) : '';
		if ( '' === $action ) {
			$action = isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] ? sanitize_key( $_REQUEST['action2'] ) : '';
		}

		if ( '' === $action ) {
			return;
		}

		$valid_actions = array( 'delete', 'set_pending', 'set_confirmed', 'set_unsubscribed' );
		if ( ! in_array( $action, $valid_actions, true ) ) {
			return;
		}

		if ( ! isset( $_POST['subscriber'] ) ) {
			return;
		}

		check_admin_referer( 'bulk-subscribers' );
		$ids = array_map( 'intval', (array) wp_unslash( $_POST['subscriber'] ) );
		// phpcs:enable

		if ( count( $ids ) === 0 ) {
			return;
		}

		$subscribers_repo = new SubscriberRepository();
		$status_map       = array(
			'set_pending'      => 'pending',
			'set_confirmed'    => 'confirmed',
			'set_unsubscribed' => 'unsubscribed',
		);
		$count            = 0;

		foreach ( $ids as $id ) {
			if ( 'delete' === $action ) {
				$subscribers_repo->delete( $id );
				++$count;
			} elseif ( array_key_exists( $action, $status_map ) ) {
				$subscribers_repo->update_status( $id, $status_map[ $action ] );
				++$count;
			}
		}

		$labels = array(
			'delete'           => __( 'deleted', 'stampy' ),
			'set_pending'      => __( 'set pending', 'stampy' ),
			'set_confirmed'    => __( 'set confirmed', 'stampy' ),
			'set_unsubscribed' => __( 'set unsubscribed', 'stampy' ),
		);

		$message = sprintf(
			/* translators: 1: number of subscribers, 2: action label */
			_n( '%1$d subscriber %2$s.', '%1$d subscribers %2$s.', $count, 'stampy' ),
			$count,
			$labels[ $action ] ?? __( 'updated', 'stampy' )
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'stampy-subscribers',
					'stampy_bulk_done' => '1',
					'stampy_bulk_msg'  => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Process single-row delete action (via GET link).
	 *
	 * @return void
	 */
	private function process_bulk_action(): void {
		$action = $this->current_action();
		if ( ! $action ) {
			return;
		}

		if ( 'delete' !== $action || ! isset( $_GET['subscriber_id'] ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$id    = (int) $_GET['subscriber_id'];
		$nonce = isset( $_GET['stampy_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['stampy_nonce'] ) ) : '';
		// phpcs:enable

		if ( $id <= 0 || ! wp_verify_nonce( $nonce, 'stampy_delete_subscriber_' . $id ) ) {
			return;
		}

		$subscribers_repo = new SubscriberRepository();
		$subscribers_repo->delete( $id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => 'stampy-subscribers',
					'stampy_bulk_done' => '1',
					'stampy_bulk_msg'  => __( 'Subscriber deleted.', 'stampy' ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
