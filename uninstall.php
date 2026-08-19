<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Uninstall iterates a fixed list of this plugin's own table suffixes.
if ( ! get_option( 'psm_settings', array() ) || empty( get_option( 'psm_settings', array() )['delete_data_on_uninstall'] ) ) {
	return; }
global $wpdb;
foreach ( array( 'plans', 'prices', 'memberships', 'payments', 'purchases', 'adjustments', 'webhook_events', 'logs' ) as $picot_subscription_membership_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Uninstall intentionally drops only this plugin's fixed, prefixed table names.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'membership_' . $picot_subscription_membership_table );
}
delete_option( 'psm_settings' );
delete_option( 'psm_db_version' );
