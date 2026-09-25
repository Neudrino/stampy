<?php
/**
 * Campaign recipients list table for WP admin.
 *
 * Shows who received a campaign and (when personalized tracking was
 * used) who opened or clicked it.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\CampaignRecipientRepository;
use stdClass;
use WP_List_Table;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * List table for browsing a campaign's recipients.
 */
class CampaignRecipientsListTable extends WP_List_Table {

	/**
	 * Campaign post ID.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Recipient repository.
	 *
	 * @var CampaignRecipientRepository
	 */
	private CampaignRecipientRepository $repo;

	/**
	 * Constructor.
	 *
	 * @param int $campaign_id Campaign post ID.
	 */
	public function __construct( int $campaign_id ) {
		parent::__construct(
			array(
				'singular' => 'campaign-recipient',
				'plural'   => 'campaign-recipients',
				'ajax'     => false,
			)
		);

		$this->campaign_id = $campaign_id;
		$this->repo        = new CampaignRecipientRepository();
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
			'sent_at'    => __( 'Sent', 'stampy' ),
			'opened_at'  => __( 'Opened', 'stampy' ),
			'clicked_at' => __( 'Clicked', 'stampy' ),
			'clicks'     => __( 'Clicks', 'stampy' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * Sorting is not supported (stable newest-first id order) — return
	 * an empty set so no inactive sort links are rendered.
	 *
	 * @return array<string, array<int, bool|string>>
	 */
	protected function get_sortable_columns(): array {
		return array();
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
			'email',
		);

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only search.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable

		$per_page    = 20;
		$paged       = $this->get_pagenum();
		$total_items = $this->repo->count_recipients( $this->campaign_id, $search );

		$this->items = $this->repo->get_recipients(
			$this->campaign_id,
			array(
				'per_page' => $per_page,
				'page'     => $paged,
				'search'   => $search,
			)
		);

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
	 * @param stdClass $item Recipient row.
	 * @return string
	 */
	public function column_email( $item ): string {
		return esc_html( (string) $item->email );
	}

	/**
	 * Render the status column.
	 *
	 * @param stdClass $item Recipient row.
	 * @return string
	 */
	public function column_status( $item ): string {
		$status = (string) $item->status;
		$colors = array(
			'sent'    => '#00a32a',
			'failed'  => '#b32d2e',
			'sending' => '#0071a1',
			'queued'  => '#50575e',
		);
		$color  = $colors[ $status ] ?? '#50575e';

		return sprintf(
			'<span style="color:%s;">%s</span>',
			esc_attr( $color ),
			esc_html( ucfirst( $status ) )
		);
	}

	/**
	 * Render the sent timestamp column.
	 *
	 * @param stdClass $item Recipient row.
	 * @return string
	 */
	public function column_sent_at( $item ): string {
		return esc_html( $this->format_datetime( $item->sent_at ?? null ) );
	}

	/**
	 * Render the opened timestamp column.
	 *
	 * @param stdClass $item Recipient row.
	 * @return string
	 */
	public function column_opened_at( $item ): string {
		return esc_html( $this->format_datetime( $item->opened_at ?? null ) );
	}

	/**
	 * Render the clicked timestamp column.
	 *
	 * @param stdClass $item Recipient row.
	 * @return string
	 */
	public function column_clicked_at( $item ): string {
		return esc_html( $this->format_datetime( $item->clicked_at ?? null ) );
	}

	/**
	 * Render the clicks column (count + clicked URLs).
	 *
	 * @param stdClass $item Recipient row.
	 * @return string
	 */
	public function column_clicks( $item ): string {
		$count = (int) ( $item->click_count ?? 0 );

		if ( $count < 1 ) {
			return esc_html__( '—', 'stampy' );
		}

		$urls = (string) ( $item->urls ?? '' );

		return esc_html( (string) $count ) . '<br><small>' . esc_html( wp_trim_words( $urls, 12, '…' ) ) . '</small>';
	}

	/**
	 * Render default columns.
	 *
	 * @param stdClass $item        Recipient row.
	 * @param string   $column_name Column name.
	 * @return string
	 */
	public function column_default( $item, $column_name ): string {
		return esc_html( (string) ( $item->$column_name ?? '—' ) );
	}

	/**
	 * Format a DATETIME value for display.
	 *
	 * @param mixed $value Stored datetime (or null).
	 * @return string Localized datetime, or an em dash when empty.
	 */
	private function format_datetime( mixed $value ): string {
		if ( ! is_string( $value ) || '' === $value ) {
			return '—';
		}

		$timestamp = strtotime( $value . ' UTC' );
		if ( false === $timestamp ) {
			return $value;
		}

		return (string) wp_date( 'Y-m-d H:i', $timestamp );
	}
}
