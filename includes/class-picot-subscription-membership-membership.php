<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queries use plugin-owned, prefixed tables and return current subscription state.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers come only from Picot_Subscription_Membership_DB::table(); all query values use placeholders.

final class Picot_Subscription_Membership_Membership {
	public static function get_for_user( $user_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'memberships' ) . ' WHERE user_id = %d', $user_id ) ); }
	public static function get_by_id( $membership_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'memberships' ) . ' WHERE id = %d', $membership_id ) ); }
	public static function get_by_subscription( $subscription_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'memberships' ) . ' WHERE stripe_subscription_id = %s', $subscription_id ) ); }
	public static function is_active( $user_id ) {
		$m = self::get_for_user( $user_id );
		if ( ! $m || ! empty( $m->access_revoked_at ) ) {
			return false; }
		$now = time();
		if ( 'past_due' === $m->membership_status ) {
			return ! empty( $m->grace_until ) && $now <= strtotime( $m->grace_until . ' UTC' ); }
		return in_array( $m->membership_status, array( 'active', 'trialing', 'canceled' ), true ) && $m->effective_access_until && $now <= strtotime( $m->effective_access_until . ' UTC' );
	}
	public static function recompute_access_until( $membership_id ) {
		global $wpdb;
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'memberships' ) . ' WHERE id = %d', $membership_id ) );
		if ( ! $m ) {
			return false; }
		$base = $m->stripe_period_end ? strtotime( $m->stripe_period_end . ' UTC' ) : 0;
		if ( ! $base ) {
			$anchor         = ! empty( $m->manual_extension_anchor ) ? strtotime( $m->manual_extension_anchor . ' UTC' ) : 0;
			$existing_until = $m->effective_access_until ? strtotime( $m->effective_access_until . ' UTC' ) : 0;
			$base           = $anchor ? $anchor : ( $existing_until ? $existing_until - (int) $m->manual_extension_seconds : time() );
		}
		$grace = $m->grace_until ? strtotime( $m->grace_until . ' UTC' ) : 0;
		$until = max( $base + (int) $m->manual_extension_seconds, $grace );
		$value = $until ? gmdate( 'Y-m-d H:i:s', $until ) : null;
		$value = apply_filters( 'picot_membership_effective_access_until', $value, $m );
		$wpdb->update(
			Picot_Subscription_Membership_DB::table( 'memberships' ),
			array(
				'effective_access_until' => $value,
				'updated_at'             => current_time( 'mysql', true ),
			),
			array( 'id' => $membership_id )
		);
		return $value;
	}
	public static function adjust( $membership_id, $seconds, $reason, $admin_user_id = 0, $type = 'extend' ) {
		global $wpdb;
		$type = sanitize_key( $type );
		if ( 'extend' === $type && (int) $seconds < 0 ) {
			$type = 'reduce';
		} if ( ! in_array( $type, array( 'extend', 'reduce', 'revoke', 'restore' ), true ) ) {
			$type = (int) $seconds < 0 ? 'reduce' : 'extend'; }
		$m = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'memberships' ) . ' WHERE id = %d', $membership_id ) );
		if ( ! $m ) {
			return new WP_Error( 'membership_not_found', __( '会員情報が見つかりません。', 'picot-subscription-membership' ) ); }
		$now = current_time( 'mysql', true );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Picot_Subscription_Membership_DB::table( 'memberships' ) . ' SET manual_extension_seconds = manual_extension_seconds + %d, manual_extension_anchor = CASE WHEN stripe_period_end IS NULL AND manual_extension_anchor IS NULL THEN %s ELSE manual_extension_anchor END, updated_at = %s WHERE id = %d', (int) $seconds, $now, $now, $membership_id ) );
		$wpdb->insert(
			Picot_Subscription_Membership_DB::table( 'adjustments' ),
			array(
				'membership_id' => $membership_id,
				'user_id'       => $m->user_id,
				'type'          => $type,
				'delta_seconds' => (int) $seconds,
				'reason'        => sanitize_textarea_field( $reason ),
				'admin_user_id' => (int) $admin_user_id,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		Picot_Subscription_Membership_DB::log( 'period_adjustment', sprintf( '%s: %d seconds. %s', $type, (int) $seconds, sanitize_textarea_field( $reason ) ), $membership_id, $m->user_id );
		$until = self::recompute_access_until( $membership_id );
		do_action( 'picot_membership_extended', $m, $seconds, $reason );
		return $until;
	}
	public static function sync_subscription( $subscription, $user_id = 0, $event_created_at = null ) {
		global $wpdb;
		$sub_id = is_array( $subscription ) ? $subscription['id'] : $subscription->id;
		$m      = self::get_by_subscription( $sub_id );
		if ( ! $m && $user_id ) {
			$m = self::get_for_user( $user_id ); }
		if ( ! $m && $user_id && get_user_by( 'id', $user_id ) ) {
			$now = current_time( 'mysql', true );
			$wpdb->insert(
				Picot_Subscription_Membership_DB::table( 'memberships' ),
				array(
					'user_id'           => $user_id,
					'membership_status' => 'pending',
					'created_at'        => $now,
					'updated_at'        => $now,
				)
			);
			$m = self::get_for_user( $user_id );
		}
		if ( ! $m ) {
			return new WP_Error( 'membership_not_found', __( '紐付く会員が見つかりません。', 'picot-subscription-membership' ) ); }
		$event_timestamp      = $event_created_at ? strtotime( $event_created_at . ' UTC' ) : 0;
		$last_event_timestamp = ! empty( $m->last_stripe_event_created_at ) ? strtotime( $m->last_stripe_event_created_at . ' UTC' ) : 0;
		if ( $event_timestamp && $last_event_timestamp && $event_timestamp < $last_event_timestamp ) {
			return $m; }
		$data              = is_array( $subscription ) ? $subscription : (array) $subscription;
		$items             = $data['items']['data'] ?? array();
		$price             = $items[0]['price'] ?? array();
		$price_id          = is_array( $price ) ? ( $price['id'] ?? '' ) : $price->id;
		$price_row         = $price_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'prices' ) . ' WHERE stripe_price_id = %s', $price_id ) ) : null;
		$status            = sanitize_key( $data['status'] ?? 'pending' );
		$period_start_raw  = $data['current_period_start'] ?? ( $items[0]['current_period_start'] ?? null );
		$period_end_raw    = $data['current_period_end'] ?? ( $items[0]['current_period_end'] ?? null );
		$period_start      = $period_start_raw ? gmdate( 'Y-m-d H:i:s', (int) $period_start_raw ) : $m->stripe_period_start;
		$period_end        = $period_end_raw ? gmdate( 'Y-m-d H:i:s', (int) $period_end_raw ) : $m->stripe_period_end;
		$membership_status = in_array( $status, array( 'active', 'trialing', 'past_due', 'paused' ), true ) ? $status : ( 'canceled' === $status ? 'canceled' : ( in_array( $status, array( 'incomplete_expired', 'unpaid' ), true ) ? 'expired' : 'pending' ) );
		if ( ! $price_row && ! in_array( $status, array( 'incomplete_expired', 'unpaid' ), true ) ) {
			$membership_status = 'pending';
			do_action( 'picot_membership_unknown_stripe_price', $m, $price_id, $subscription ); }
		$wpdb->update(
			Picot_Subscription_Membership_DB::table( 'memberships' ),
			array(
				'plan_id'                      => $price_row ? $price_row->plan_id : null,
				'price_id'                     => $price_row ? $price_row->id : null,
				'stripe_customer_id'           => is_string( $data['customer'] ?? null ) ? $data['customer'] : $m->stripe_customer_id,
				'stripe_subscription_id'       => $sub_id,
				'stripe_status'                => $status,
				'membership_status'            => $membership_status,
				'stripe_period_start'          => $period_start,
				'stripe_period_end'            => $period_end,
				'cancel_at_period_end'         => ! empty( $data['cancel_at_period_end'] ) ? 1 : 0,
				'canceled_at'                  => 'canceled' === $status ? current_time( 'mysql', true ) : null,
				'last_stripe_event_created_at' => $event_timestamp ? gmdate( 'Y-m-d H:i:s', $event_timestamp ) : $m->last_stripe_event_created_at,
				'updated_at'                   => current_time( 'mysql', true ),
			),
			array( 'id' => $m->id )
		);
		self::recompute_access_until( $m->id );
		$result = self::get_for_user( $m->user_id );
		if ( $result && in_array( $result->membership_status, array( 'active', 'trialing' ), true ) && ! in_array( $m->membership_status, array( 'active', 'trialing' ), true ) ) {
			do_action( 'picot_membership_activated', $result, $subscription ); }
		if ( $result && 'canceled' === $result->membership_status && 'canceled' !== $m->membership_status ) {
			do_action( 'picot_membership_canceled', $result, $subscription ); }
		return $result;
	}
	public static function run_daily_sync() {
		global $wpdb;
		$table                = Picot_Subscription_Membership_DB::table( 'memberships' );
		$memberships          = $wpdb->get_results( "SELECT * FROM $table WHERE access_revoked_at IS NULL AND stripe_subscription_id IS NOT NULL AND stripe_subscription_id <> '' ORDER BY updated_at ASC LIMIT 50" );
		$consecutive_failures = 0;
		$summary              = array(
			'synced'        => 0,
			'errors'        => 0,
			'expired'       => 0,
			'stopped_early' => false,
		);
		foreach ( $memberships as $membership ) {
			$subscription = Picot_Subscription_Membership_Stripe_Gateway::retrieve_subscription( $membership->stripe_subscription_id );
			if ( is_wp_error( $subscription ) ) {
				++$summary['errors'];
				Picot_Subscription_Membership_DB::log( 'stripe_sync_error', $subscription->get_error_message(), $membership->id, $membership->user_id );
				do_action( 'picot_membership_sync_error', $membership, $subscription );
				if ( ++$consecutive_failures >= 3 ) {
					$summary['stopped_early'] = true;
					break;
				} continue; }
			$consecutive_failures = 0;
			$result               = self::sync_subscription( $subscription, (int) $membership->user_id );
			if ( is_wp_error( $result ) ) {
				++$summary['errors'];
				Picot_Subscription_Membership_DB::log( 'stripe_sync_error', $result->get_error_message(), $membership->id, $membership->user_id );
				do_action( 'picot_membership_sync_error', $membership, $result );
				continue;
			} ++$summary['synced'];
		}
		$ids = $wpdb->get_col( "SELECT id FROM $table WHERE access_revoked_at IS NULL AND effective_access_until IS NOT NULL AND effective_access_until < UTC_TIMESTAMP() AND membership_status <> 'expired'" );
		foreach ( $ids as $id ) {
			$wpdb->update(
				$table,
				array(
					'membership_status' => 'expired',
					'updated_at'        => current_time( 'mysql', true ),
				),
				array( 'id' => $id )
			);
			++$summary['expired'];
			do_action( 'picot_membership_expired', $id ); }
		return $summary;
	}
}
