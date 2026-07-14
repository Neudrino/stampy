<?php
/**
 * Import/Export service for subscriber data portability.
 *
 * @package Stampy
 */

declare( strict_types=1 );

namespace Stampy;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Stampy\Repositories\FieldRepository;
use Stampy\Repositories\ListRepository;
use Stampy\Repositories\SubscriberMetaRepository;
use Stampy\Repositories\SubscriberRepository;

/**
 * Handles CSV/JSON export and JSON import of subscriber data.
 */
final class ImportExportService {

	/**
	 * Subscriber repository.
	 *
	 * @var SubscriberRepository
	 */
	private SubscriberRepository $subscribers;

	/**
	 * Subscriber meta repository.
	 *
	 * @var SubscriberMetaRepository
	 */
	private SubscriberMetaRepository $meta;

	/**
	 * List repository.
	 *
	 * @var ListRepository
	 */
	private ListRepository $lists;

	/**
	 * Field repository.
	 *
	 * @var FieldRepository
	 */
	private FieldRepository $fields;

	/**
	 * Constructor.
	 *
	 * @param SubscriberRepository|null     $subscribers Optional.
	 * @param SubscriberMetaRepository|null $meta       Optional.
	 * @param ListRepository|null           $lists      Optional.
	 * @param FieldRepository|null          $fields     Optional.
	 */
	public function __construct(
		?SubscriberRepository $subscribers = null,
		?SubscriberMetaRepository $meta = null,
		?ListRepository $lists = null,
		?FieldRepository $fields = null
	) {
		$this->subscribers = $subscribers ?? new SubscriberRepository();
		$this->meta        = $meta ?? new SubscriberMetaRepository();
		$this->lists       = $lists ?? new ListRepository();
		$this->fields      = $fields ?? new FieldRepository();
	}

	/**
	 * Export all subscribers as CSV.
	 *
	 * @param int $list_id Optional list ID to filter by.
	 * @return string CSV content.
	 */
	public function export_csv( int $list_id = 0 ): string {
		$rows      = $this->gather_export_data( $list_id );
		$columns   = $this->export_columns();
		$delimiter = ',';

		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- writing to php://temp stream, not a filesystem file.
		$output = fopen( 'php://temp', 'r+' );
		// phpcs:enable
		if ( false === $output ) {
			return '';
		}

		fputcsv( $output, $columns, $delimiter );

		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $columns as $col ) {
				$line[] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
			}
			fputcsv( $output, $line, $delimiter );
		}

		rewind( $output );
		$content = stream_get_contents( $output );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing php://temp stream.
		fclose( $output );
		// phpcs:enable

		return false !== $content ? $content : '';
	}

	/**
	 * Export all subscribers as JSON.
	 *
	 * @param int $list_id Optional list ID to filter by.
	 * @return string JSON content.
	 */
	public function export_json( int $list_id = 0 ): string {
		$rows    = $this->gather_export_data( $list_id );
		$encoded = wp_json_encode( array_values( $rows ) );
		return false !== $encoded ? $encoded : '[]';
	}

	/**
	 * Get the ordered column headers for export.
	 *
	 * Email is always first, followed by subscriber properties,
	 * then custom field keys.
	 *
	 * @return array<int, string>
	 */
	public function export_columns(): array {
		$columns = array( 'email', 'status', 'created_at', 'confirmed_at', 'unsubscribed_at', 'consent_version' );

		$fields = $this->fields->all();
		foreach ( $fields as $field ) {
			$columns[] = $field->field_key;
		}

		return $columns;
	}

	/**
	 * Gather all subscriber data for export (properties + custom fields).
	 *
	 * @param int $list_id Optional list ID. If > 0, export only subscribers in that list.
	 * @return array<int, array<string, mixed>> Array of associative arrays.
	 */
	private function gather_export_data( int $list_id = 0 ): array {
		if ( $list_id > 0 ) {
			$subscriber_rows = $this->lists->get_list_subscribers( $list_id );
		} else {
			$subscriber_rows = $this->subscribers->get_all(
				array(
					'per_page' => 100000,
					'orderby'  => 'email',
					'order'    => 'ASC',
				)
			);
		}

		$result = array();
		foreach ( $subscriber_rows as $sub ) {
			$row = array(
				'email'           => $sub->email,
				'status'          => $sub->status,
				'created_at'      => $sub->created_at,
				'confirmed_at'    => $sub->confirmed_at ?? '',
				'unsubscribed_at' => $sub->unsubscribed_at ?? '',
				'consent_version' => $sub->consent_version ?? 1,
			);

			$meta = $this->meta->get_all( (int) $sub->id );
			foreach ( $meta as $key => $value ) {
				$row[ $key ] = $value;
			}

			$result[] = $row;
		}

		return $result;
	}

	/**
	 * Import subscribers from parsed row data.
	 *
	 * Creates a new list, then upserts each subscriber by email, applies
	 * field values via merge policy, and adds them to the list.
	 * Subscribers are imported as 'confirmed'.
	 *
	 * @param array<int, array<string, mixed>> $rows      Parsed rows (each an associative array).
	 * @param string                           $list_name Name for the new list.
	 * @return array{
	 *     success: bool,
	 *     list_id: int,
	 *     imported: int,
	 *     skipped: int,
	 *     errors: array<int, string>
	 * }
	 */
	public function import( array $rows, string $list_name ): array {
		$slug = sanitize_title( $list_name );
		if ( '' === $slug ) {
			$slug = 'import-' . time();
		}

		$list_id = $this->lists->create( $list_name, $slug );

		$imported = 0;
		$skipped  = 0;
		$errors   = array();
		$idx      = 0;

		foreach ( $rows as $row ) {
			++$idx;
			$email = isset( $row['email'] ) ? trim( (string) $row['email'] ) : '';

			if ( '' === $email || ! is_email( $email ) ) {
				++$skipped;
				$errors[] = sprintf(
					/* translators: 1: row number, 2: email or empty */
					__( 'Row %1$d: invalid email "%2$s" — skipped.', 'stampy' ),
					$idx,
					$email
				);
				continue;
			}

			$subscriber = $this->subscribers->create_or_get( $email, 'confirmed', 1 );

			if ( 'confirmed' !== $subscriber->status ) {
				$this->subscribers->update_status( (int) $subscriber->id, 'confirmed' );
			}

			$attributes = array();
			foreach ( $row as $key => $value ) {
				if ( in_array( $key, array( 'email', 'status', 'created_at', 'confirmed_at', 'unsubscribed_at', 'consent_version' ), true ) ) {
					continue;
				}
				$attributes[ sanitize_key( (string) $key ) ] = is_string( $value ) ? $value : (string) $value;
			}

			if ( count( $attributes ) > 0 ) {
				$this->meta->apply_merge( (int) $subscriber->id, $attributes );
			}

			$this->lists->add_subscriber( (int) $subscriber->id, $list_id );
			++$imported;
		}

		return array(
			'success'  => true,
			'list_id'  => $list_id,
			'imported' => $imported,
			'skipped'  => $skipped,
			'errors'   => $errors,
		);
	}
}
