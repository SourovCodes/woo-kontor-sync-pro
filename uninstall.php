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
delete_option( 'woo_kontor_sync_job_status' );

/*
 * The two customer emails' own settings, which WooCommerce stores per email id.
 * Unlike the invoices and the Kontor identifiers below, these are this plugin's own
 * preferences about a feature that is going away, and nothing in the ERP or on disk
 * depends on them surviving.
 */
delete_option( 'woocommerce_wksync_customer_invoice_settings' );
delete_option( 'woocommerce_wksync_customer_tracking_settings' );

// Drop any cached stock payload left behind by an interrupted run.
delete_expired_transients();

/*
 * The _wksync_* meta recording each object's Kontor ID is deliberately left in
 * place. Deleting it would orphan the records that already exist in the ERP, and
 * reinstalling would then create duplicates.
 *
 * The downloaded invoices are left alone for the same reason, and a stronger one:
 * they are financial records the shop may be required to keep, and deleting a
 * customer's invoices is not something uninstalling a plugin should do. The option
 * naming their directory therefore survives too — dropping it would generate a new
 * directory on reinstall and strand every file already there.
 */
