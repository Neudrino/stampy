<?php
/**
 * Admin menu registration for Stampy.
 *
 * Registers the top-level "Stampy" menu with Subscribers and Lists sub-pages.
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
 * Registers the Stampy admin menu and pages.
 */
final class AdminMenu {

	/**
	 * Register the admin menu on the admin_menu hook.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_post_stampy_save_subscriber', array( SubscribersPage::class, 'handle_save' ) );
		add_action( 'admin_post_stampy_save_list', array( ListsPage::class, 'handle_save' ) );
		add_action( 'admin_post_stampy_save_field', array( FieldsPage::class, 'handle_save' ) );
		add_action( 'admin_post_stampy_save_smtp_settings', array( SettingsPage::class, 'handle_save_settings' ) );
		add_action( 'admin_post_stampy_send_test_email', array( SettingsPage::class, 'handle_send_test' ) );
		add_action( 'admin_post_stampy_campaign_preview', array( CampaignPreviewPage::class, 'handle_preview' ) );
		add_action( 'admin_post_stampy_start_send', array( CampaignSendPage::class, 'handle_start_send' ) );
		add_action( 'admin_post_stampy_cancel_send', array( CampaignSendPage::class, 'handle_cancel_send' ) );
		add_action( 'admin_post_stampy_copy_campaign', array( CampaignCopyPage::class, 'handle_copy_campaign' ) );
		add_action( 'wp_ajax_stampy_campaign_progress', array( CampaignSendPage::class, 'handle_progress_ajax' ) );
		add_action( 'wp_ajax_stampy_start_send', array( CampaignSendPage::class, 'handle_start_send_ajax' ) );
		add_action( 'wp_ajax_stampy_cancel_send', array( CampaignSendPage::class, 'handle_cancel_send_ajax' ) );
		add_filter( 'post_row_actions', array( CampaignSendPage::class, 'add_row_action' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'show_send_result_notice' ) );
	}

	/**
	 * Add the top-level menu and sub-pages.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		$hook = add_menu_page(
			__( 'Stampy Subscribers', 'stampy' ),
			__( 'Stampy', 'stampy' ),
			'manage_options',
			'stampy-subscribers',
			array( SubscribersPage::class, 'render' ),
			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding SVG icon as data URI.
			'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><text x="12" y="18" font-size="18" text-anchor="middle">&#x1F992;</text></svg>' ),
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			26
		);

		add_submenu_page(
			'stampy-subscribers',
			__( 'Subscribers', 'stampy' ),
			__( 'Subscribers', 'stampy' ),
			'manage_options',
			'stampy-subscribers',
			array( SubscribersPage::class, 'render' )
		);

		add_submenu_page(
			'stampy-subscribers',
			__( 'Lists', 'stampy' ),
			__( 'Lists', 'stampy' ),
			'manage_options',
			'stampy-lists',
			array( ListsPage::class, 'render' )
		);

		$fields_hook = add_submenu_page(
			'stampy-subscribers',
			__( 'Fields', 'stampy' ),
			__( 'Fields', 'stampy' ),
			'manage_options',
			'stampy-fields',
			array( FieldsPage::class, 'render' )
		);

		if ( $fields_hook ) {
			add_action( 'load-' . $fields_hook, array( FieldsPage::class, 'handle_delete_action' ) );
		}

		add_submenu_page(
			'stampy-subscribers',
			__( 'Settings', 'stampy' ),
			__( 'Settings', 'stampy' ),
			'manage_options',
			'stampy-settings',
			array( SettingsPage::class, 'render' )
		);

		add_submenu_page(
			'stampy-subscribers',
			__( 'Submission Log', 'stampy' ),
			__( 'Submission Log', 'stampy' ),
			'manage_options',
			'stampy-submission-log',
			array( SubmissionLogPage::class, 'render' )
		);

		$ie_hook = add_submenu_page(
			'stampy-subscribers',
			__( 'Import / Export', 'stampy' ),
			__( 'Import / Export', 'stampy' ),
			'manage_options',
			'stampy-import-export',
			array( ImportExportPage::class, 'render' )
		);

		add_action( 'admin_enqueue_scripts', array( ImportExportPage::class, 'enqueue_assets' ) );

		add_action( 'load-' . $hook, array( SubscribersPage::class, 'setup_screen' ) );
		add_action( 'load-' . $hook, array( SubscribersListTable::class, 'handle_bulk_action' ) );
	}

	/**
	 * Show the send result notice after redirect.
	 *
	 * @return void
	 */
	public static function show_send_result_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$result  = isset( $_GET['stampy_send_result'] ) ? sanitize_text_field( wp_unslash( $_GET['stampy_send_result'] ) ) : '';
		$message = isset( $_GET['stampy_send_message'] ) ? sanitize_text_field( wp_unslash( $_GET['stampy_send_message'] ) ) : '';
		// phpcs:enable

		if ( '' === $result || '' === $message ) {
			return;
		}

		$success = '1' === $result;
		$class   = $success ? 'notice-success' : 'notice-error';

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( rawurldecode( $message ) )
		);
	}
}
