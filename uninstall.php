<?php
/**
 * Uninstall handler for Stampy.
 *
 * Runs only when the plugin is deleted through the WordPress admin. All
 * destructive data removal is gated behind the "delete data on uninstall"
 * setting (added in a later phase). Phase 0 ships an inert, safe stub.
 *
 * @package Stampy
 */

declare( strict_types=1 );

// Exit if not called by WordPress during uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Phase 0: no data exists yet and no setting is stored, so there is nothing
// to remove. Later phases implement gated deletion of tables/options here.
