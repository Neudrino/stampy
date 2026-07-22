<?php
/**
 * Lists list table for WP admin.
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
use stdClass;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for browsing mailing lists.
 */
class ListsListTable extends WP_List_Table {

	/**
	 * List repository.
	 *
	 * @var ListRepository
	 */
	private $lists;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'list',
				'plural'   => 'lists',
				'ajax'     => false,
			)
		);

		$this->lists = new ListRepository();
	}

	/**
	 * Get column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'cb'          => '<input type="checkbox" />',
			'name'        => __( 'Name', 'stampy' ),
			'slug'        => __( 'Slug', 'stampy' ),
			'description' => __( 'Description', 'stampy' ),
			'subscribers' => __( 'Subscribers', 'stampy' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array<int, bool|string>>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'name' => array( 'name', false ),
			'slug' => array( 'slug', false ),
		);
	}

	/**
	 * Get bulk actions.
	 *
	 * @return array<string, string>
	 */
	protected function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'stampy' ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->process_bulk_action();

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			'name',
		);

		$all = $this->lists->all_with_counts();

		$per_page    = 20;
		$total_items = count( $all );
		$paged       = $this->get_pagenum();
		$offset      = ( $paged - 1 ) * $per_page;

		$this->items = array_slice( $all, $offset, $per_page );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $total_items / $per_page ) ),
			)
		);
	}

	/**
	 * Render the checkbox column.
	 *
	 * @param stdClass $item List row.
	 * @return string
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="list[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Render the name column with row actions.
	 *
	 * @param stdClass $item List row.
	 * @return string
	 */
	public function column_name( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'    => 'stampy-lists',
				'action'  => 'edit',
				'list_id' => (int) $item->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'    => 'stampy-lists',
					'action'  => 'delete',
					'list_id' => (int) $item->id,
				),
				admin_url( 'admin.php' )
			),
			'stampy_delete_list_' . $item->id,
			'stampy_list_nonce'
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'stampy' ) ),
			'delete' => sprintf( '<a href="%s">%s</a>', esc_url( $delete_url ), esc_html__( 'Delete', 'stampy' ) ),
		);

		return sprintf(
			'<strong><a href="%s" class="row-title">%s</a></strong> %s',
			esc_url( $edit_url ),
			esc_html( $item->name ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Render default columns.
	 *
	 * @param stdClass $item        List row.
	 * @param string   $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'slug':
				return esc_html( $item->slug );
			case 'description':
				return esc_html( $item->description ?? '—' );
			case 'subscribers':
				return esc_html( (string) ( $item->subscriber_count ?? 0 ) );
			default:
				return esc_html__( '—', 'stampy' );
		}
	}

	/**
	 * Process bulk and individual delete actions.
	 *
	 * @return void
	 */
	private function process_bulk_action(): void {
		$action = $this->current_action();
		if ( ! $action || 'delete' !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$repo = new ListRepository();
		$ids  = array();

		if ( isset( $_GET['list_id'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$id = (int) $_GET['list_id'];
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$nonce = isset( $_GET['stampy_list_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['stampy_list_nonce'] ) ) : '';
			if ( $id > 0 && wp_verify_nonce( $nonce, 'stampy_delete_list_' . $id ) ) {
				$ids = array( $id );
			}
		} elseif ( isset( $_POST['list'] ) ) {
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			check_admin_referer( 'bulk-' . $this->_args['plural'] );
			$ids = array_map( 'intval', (array) wp_unslash( $_POST['list'] ) );
			// phpcs:enable
		}

		foreach ( $ids as $id ) {
			$repo->delete( $id );
		}
	}
}
