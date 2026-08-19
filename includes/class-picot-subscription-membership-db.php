<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema and queries are limited to plugin-owned, prefixed tables.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Schema identifiers are limited to fixed plugin-owned table names.

final class Picot_Subscription_Membership_DB {
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'membership_' . sanitize_key( $name ); }
	public static function log( $type, $message, $membership_id = 0, $user_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			self::table( 'logs' ),
			array(
				'log_type'      => sanitize_key( $type ),
				'membership_id' => absint( $membership_id ) > 0 ? absint( $membership_id ) : null,
				'user_id'       => absint( $user_id ) > 0 ? absint( $user_id ) : null,
				'message'       => substr( sanitize_textarea_field( wp_strip_all_tags( $message ) ), 0, 1000 ),
				'created_at'    => current_time( 'mysql', true ),
			)
		); }
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sql     = array(
			'CREATE TABLE ' . self::table( 'plans' ) . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nname varchar(191) NOT NULL,\nslug varchar(191) NOT NULL,\ndescription text NULL,\nstripe_product_id varchar(191) NULL,\nactive tinyint(1) NOT NULL DEFAULT 1,\nsort_order int NOT NULL DEFAULT 0,\ncreated_at datetime NOT NULL,\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY slug (slug)\n) $charset;",
			'CREATE TABLE ' . self::table( 'prices' ) . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nplan_id bigint unsigned NOT NULL,\nbilling_interval varchar(20) NOT NULL,\ninterval_count int NOT NULL DEFAULT 1,\nstripe_price_id varchar(191) NOT NULL,\namount bigint NOT NULL DEFAULT 0,\ncurrency varchar(10) NOT NULL DEFAULT 'jpy',\nactive tinyint(1) NOT NULL DEFAULT 1,\ncreated_at datetime NOT NULL,\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY stripe_price_id (stripe_price_id),\nKEY plan_id (plan_id)\n) $charset;",
			'CREATE TABLE ' . self::table( 'memberships' ) . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint unsigned NOT NULL,\nplan_id bigint unsigned NULL,\nprice_id bigint unsigned NULL,\nstripe_customer_id varchar(191) NULL,\nstripe_subscription_id varchar(191) NULL,\nmembership_status varchar(30) NOT NULL DEFAULT 'pending',\nstripe_status varchar(30) NULL,\nstripe_period_start datetime NULL,\nstripe_period_end datetime NULL,\nmanual_extension_seconds bigint NOT NULL DEFAULT 0,\nmanual_extension_anchor datetime NULL,\ngrace_until datetime NULL,\neffective_access_until datetime NULL,\ncancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,\ncanceled_at datetime NULL,\naccess_revoked_at datetime NULL,\nlast_invoice_id varchar(191) NULL,\nlast_stripe_event_created_at datetime NULL,\ncreated_at datetime NOT NULL,\nupdated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY user_id (user_id),\nUNIQUE KEY stripe_subscription_id (stripe_subscription_id),\nKEY access_until (effective_access_until)\n) $charset;",
			'CREATE TABLE ' . self::table( 'payments' ) . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nmembership_id bigint unsigned NOT NULL,\nuser_id bigint unsigned NOT NULL,\nstripe_invoice_id varchar(191) NOT NULL,\nstripe_payment_intent_id varchar(191) NULL,\namount bigint NOT NULL DEFAULT 0,\ncurrency varchar(10) NULL,\nstatus varchar(30) NOT NULL,\nperiod_start datetime NULL,\nperiod_end datetime NULL,\npaid_at datetime NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY stripe_invoice_id (stripe_invoice_id),\nKEY membership_id (membership_id)\n) $charset;",
			'CREATE TABLE ' . self::table( 'purchases' ) . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nuser_id bigint unsigned NOT NULL,\npost_id bigint unsigned NOT NULL,\nstripe_checkout_session_id varchar(191) NOT NULL,\nstripe_payment_intent_id varchar(191) NULL,\nstripe_created_at datetime NULL,\nstripe_refund_id varchar(191) NULL,\namount bigint NOT NULL DEFAULT 0,\ncurrency varchar(10) NOT NULL DEFAULT 'jpy',\nstatus varchar(30) NOT NULL DEFAULT 'paid',\npurchased_at datetime NOT NULL,\nrefunded_at datetime NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY stripe_checkout_session_id (stripe_checkout_session_id),\nUNIQUE KEY user_post (user_id,post_id),\nKEY post_id (post_id),\nKEY stripe_payment_intent_id (stripe_payment_intent_id)\n) $charset;",
			'CREATE TABLE ' . self::table( 'adjustments' ) . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nmembership_id bigint unsigned NOT NULL,\nuser_id bigint unsigned NOT NULL,\ntype varchar(30) NOT NULL,\ndelta_seconds bigint NOT NULL DEFAULT 0,\nreason text NULL,\nadmin_user_id bigint unsigned NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY membership_id (membership_id)\n) $charset;",
			'CREATE TABLE ' . self::table( 'webhook_events' ) . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nstripe_event_id varchar(191) NOT NULL,\nevent_type varchar(100) NOT NULL,\nobject_id varchar(191) NULL,\nevent_created_at datetime NULL,\nreceived_at datetime NOT NULL,\nstatus varchar(30) NOT NULL,\nattempt_count int NOT NULL DEFAULT 1,\npayload_hash char(64) NOT NULL,\nerror_message text NULL,\nprocessed_at datetime NULL,\nPRIMARY KEY  (id),\nUNIQUE KEY stripe_event_id (stripe_event_id),\nKEY event_type (event_type)\n) $charset;",
			'CREATE TABLE ' . self::table( 'logs' ) . " (\nid bigint unsigned NOT NULL AUTO_INCREMENT,\nlog_type varchar(50) NOT NULL,\nmembership_id bigint unsigned NULL,\nuser_id bigint unsigned NULL,\nmessage text NOT NULL,\ncreated_at datetime NOT NULL,\nPRIMARY KEY  (id),\nKEY log_type (log_type),\nKEY membership_id (membership_id),\nKEY created_at (created_at)\n) $charset;",
		);
		foreach ( $sql as $query ) {
			dbDelta( $query ); }
		$purchases          = self::table( 'purchases' );
		$memberships        = self::table( 'memberships' );
		$membership_columns = $wpdb->get_col( "SHOW COLUMNS FROM $memberships", 0 );
		if ( ! in_array( 'manual_extension_anchor', $membership_columns, true ) ) {
			$wpdb->query( "ALTER TABLE $memberships ADD COLUMN manual_extension_anchor datetime NULL AFTER manual_extension_seconds" ); }
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM $purchases", 0 );
		if ( ! in_array( 'stripe_refund_id', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE $purchases ADD COLUMN stripe_refund_id varchar(191) NULL AFTER stripe_payment_intent_id" ); }
		if ( ! in_array( 'stripe_created_at', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE $purchases ADD COLUMN stripe_created_at datetime NULL AFTER stripe_payment_intent_id" ); }
		if ( ! in_array( 'refunded_at', $columns, true ) ) {
			$wpdb->query( "ALTER TABLE $purchases ADD COLUMN refunded_at datetime NULL AFTER purchased_at" ); }
		$indexes = $wpdb->get_col( "SHOW INDEX FROM $purchases", 2 );
		if ( ! in_array( 'stripe_payment_intent_id', $indexes, true ) ) {
			$wpdb->query( "ALTER TABLE $purchases ADD KEY stripe_payment_intent_id (stripe_payment_intent_id)" ); }
		update_option( 'psm_db_version', PICOT_SUBSCRIPTION_MEMBERSHIP_VERSION );
	}
}
