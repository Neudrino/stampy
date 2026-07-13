<?php
/**
 * Fields list table for WP admin.
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
use stdClass;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for browsing field definitions.
 */
class FieldsListTable extends WP_List_Table {

	/**
	 * Field repository.
	 *
	 * @var FieldRepository
	 */
	private $fields;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'field',
				'plural'   => 'fields',
				'ajax'     => false,
			)
		);

		$this->fields = new FieldRepository();
	}

	/**
	 * Get column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'label'         => __( 'Label', 'stampy' ),
			'field_key'     => __( 'Key', 'stampy' ),
			'type'          => __( 'Type', 'stampy' ),
			'required'      => __( 'Required', 'stampy' ),
			'show_in_admin' => __( 'Admin', 'stampy' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array<int, bool|string>>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'label'     => array( 'label', false ),
			'field_key' => array( 'field_key', false ),
			'type'      => array( 'type', false ),
		);
	}

	/**
	 * Prepare items for display.
	 *
	 * @return void
	 */
	public function prepare_items(): void {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
			'label',
		);

		$all = $this->fields->all();

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
	 * Render the label column with row actions.
	 *
	 * @param stdClass $item Field row.
	 * @return string
	 */
	public function column_label( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'     => 'stampy-fields',
				'action'   => 'edit',
				'field_id' => (int) $item->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'               => 'stampy-fields',
					'action'             => 'delete',
					'field_id'           => (int) $item->id,
					'stampy_field_nonce' => '',
				),
				admin_url( 'admin.php' )
			),
			'stampy_delete_field_' . $item->id,
			'stampy_field_nonce'
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'stampy' ) ),
			'delete' => sprintf( '<a href="%s">%s</a>', esc_url( $delete_url ), esc_html__( 'Delete', 'stampy' ) ),
		);

		return sprintf(
			'<strong><a href="%s" class="row-title">%s</a></strong> %s',
			esc_url( $edit_url ),
			esc_html( $item->label ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * Render default columns.
	 *
	 * @param stdClass $item        Field row.
	 * @param string   $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'field_key':
				return esc_html( $item->field_key );
			case 'type':
				return esc_html( ucfirst( $item->type ) );
			case 'required':
				return '1' === (string) $item->required ? '<span class="dashicons dashicons-yes"></span>' : '—';
			case 'show_in_admin':
				return '1' === (string) $item->show_in_admin ? '<span class="dashicons dashicons-yes"></span>' : '—';
			default:
				return esc_html__( '—', 'stampy' );
		}
	}
}
