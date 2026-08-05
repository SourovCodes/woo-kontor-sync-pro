<?php
/**
 * Uninstall routine.
 *
 * Runs when the plugin is deleted from the Plugins screen. WordPress loads this
 * file directly, so nothing from the plugin's own bootstrap is available here.
 *
 * @package WooKontorSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Cancel any queued or recurring sync work before its handlers disappear.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'woo-kontor-sync' );
}

delete_option( 'woo_kontor_sync_settings' );
delete_option( 'woo_kontor_sync_version' );

/*
 * The _wksync_* meta recording each object's Kontor ID is deliberately left in
 * place. Deleting it would orphan the records that already exist in the ERP, and
 * reinstalling would then create duplicates.
 */
