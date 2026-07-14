<?php
/**
 * Submission log list table for WP admin.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\SubmissionLogRepository;
use stdClass;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for browsing submission log entries.
 */
class SubmissionLogListTable extends WP_List_Table {

	/**
	 * Submission log repository.
	 *
	 * @var SubmissionLogRepository
	 */
	private $repo;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'log-entry',
				'plural'   => 'log-entries',
				'ajax'     => false,
			)
		);

		$this->repo = new SubmissionLogRepository();
	}

	/**
	 * Get column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'email'      => __( 'Email', 'stampy' ),
			'status'     => __( 'Status', 'stampy' ),
			'form_data'  => __( 'Form Data', 'stampy' ),
			'lists'      => __( 'Lists', 'stampy' ),
			'consent'    => __( 'Consent', 'stampy' ),
			'created_at' => __( 'Date', 'stampy' ),
		);
	}

	/**
	 * Get sortable columns.
	 *
	 * @return array<string, array<int, bool|string>>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'email'      => array( 'email', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
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
			'created_at',
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		$per_page    = 20;
		$paged       = $this->get_pagenum();
		$total_items = $this->repo->count( $search );

		$this->items = $this->repo->get_all( $per_page, $paged, $search );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $total_items / $per_page ) ),
			)
		);
	}

	/**
	 * Render the email column.
	 *
	 * @param stdClass $item Log entry row.
	 * @return string
	 */
	public function column_email( $item ): string {
		return esc_html( $item->email );
	}

	/**
	 * Render the status column.
	 *
	 * @param stdClass $item Log entry row.
	 * @return string
	 */
	public function column_status( $item ): string {
		$status = esc_html( ucfirst( $item->status ) );
		$class  = 'pending' === $item->status ? 'status-pending' : 'status-confirmed';
		return sprintf( '<span class="stampy-status %s">%s</span>', esc_attr( $class ), $status );
	}

	/**
	 * Render the form_data column.
	 *
	 * @param stdClass $item Log entry row.
	 * @return string
	 */
	public function column_form_data( $item ): string {
		$data = json_decode( (string) ( $item->form_data ?? '{}' ), true );
		if ( ! is_array( $data ) || count( $data ) === 0 ) {
			return esc_html__( '—', 'stampy' );
		}

		$pairs = array();
		foreach ( $data as $key => $value ) {
			$pairs[] = esc_html( $key ) . ': ' . esc_html( is_array( $value ) ? wp_json_encode( $value ) : (string) $value );
		}

		return implode( '<br>', $pairs );
	}

	/**
	 * Render the lists column.
	 *
	 * @param stdClass $item Log entry row.
	 * @return string
	 */
	public function column_lists( $item ): string {
		$list_ids = (string) ( $item->list_ids ?? '' );
		if ( '' === $list_ids ) {
			return esc_html__( '—', 'stampy' );
		}
		return esc_html( $list_ids );
	}

	/**
	 * Render the consent column.
	 *
	 * @param stdClass $item Log entry row.
	 * @return string
	 */
	public function column_consent( $item ): string {
		$version = (int) $item->consent_version;
		$text    = (string) ( $item->consent_text ?? '' );

		if ( '' === $text ) {
			return sprintf( 'v%d', $version );
		}

		$excerpt = wp_trim_words( $text, 10, '…' );
		return sprintf(
			'<span title="%s">v%d: %s</span>',
			esc_attr( $text ),
			$version,
			esc_html( $excerpt )
		);
	}

	/**
	 * Render the created_at column.
	 *
	 * @param stdClass $item Log entry row.
	 * @return string
	 */
	public function column_created_at( $item ): string {
		return esc_html( (string) $item->created_at );
	}

	/**
	 * Render default columns.
	 *
	 * @param stdClass $item        Log entry row.
	 * @param string   $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item->$column_name ?? '—' ) );
	}

	/**
	 * Extra controls for the navigation area (search box).
	 *
	 * @param string $which Top or bottom.
	 * @return void
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
	}
}
