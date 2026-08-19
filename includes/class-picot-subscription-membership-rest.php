<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Custom table queries use current data; exception messages are passed to WordPress error handling.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers come only from Picot_Subscription_Membership_DB::table(); all request values use placeholders.

final class Picot_Subscription_Membership_REST {
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) ); }
	public static function routes() {
		$ns = 'membership/v1';
		register_rest_route(
			$ns,
			'/plans',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'plans' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/account',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'account' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
		register_rest_route(
			$ns,
			'/checkout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'checkout' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'price_id' => array(
						'required'          => true,
						'sanitize_callback' => array( __CLASS__, 'sanitize_positive_integer' ),
						'validate_callback' => array( __CLASS__, 'validate_positive_integer' ),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/portal',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'portal' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
			)
		);
		register_rest_route(
			$ns,
			'/stripe/webhook',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'webhook' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/admin/memberships/(?P<id>\d+)/extend',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'extend' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_membership_periods' );
				},
			)
		);
		register_rest_route(
			$ns,
			'/admin/memberships',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'admin_memberships' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_memberships' );
				},
				'args'                => array(
					'page'     => array(
						'default'           => 1,
						'sanitize_callback' => array( __CLASS__, 'sanitize_positive_integer' ),
						'validate_callback' => array( __CLASS__, 'validate_positive_integer' ),
					),
					'per_page' => array(
						'default'           => 50,
						'sanitize_callback' => array( __CLASS__, 'sanitize_positive_integer' ),
						'validate_callback' => array( __CLASS__, 'validate_positive_integer' ),
					),
					'search'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'status'   => array( 'sanitize_callback' => 'sanitize_key' ),
					'plan_id'  => array(
						'sanitize_callback' => array( __CLASS__, 'sanitize_nonnegative_integer' ),
						'validate_callback' => array( __CLASS__, 'validate_nonnegative_integer' ),
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/admin/memberships/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'admin_membership' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_memberships' );
				},
			)
		);
		register_rest_route(
			$ns,
			'/admin/memberships/(?P<id>\d+)/revoke',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'revoke' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_memberships' );
				},
			)
		);
		register_rest_route(
			$ns,
			'/admin/memberships/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_memberships' );
				},
			)
		);
		register_rest_route(
			$ns,
			'/admin/memberships/(?P<id>\d+)/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'sync' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_memberships' );
				},
			)
		);
	}
	public static function plans() {
		global $wpdb;
		$currency = Picot_Subscription_Membership_Stripe_Gateway::current_currency();
		return rest_ensure_response( $wpdb->get_results( $wpdb->prepare( 'SELECT p.id, p.name, p.slug, p.description, p.sort_order, pr.id AS price_id, pr.billing_interval, pr.interval_count, pr.amount, pr.currency FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' p INNER JOIN ' . Picot_Subscription_Membership_DB::table( 'prices' ) . ' pr ON p.id = pr.plan_id AND pr.active = 1 AND pr.currency = %s WHERE p.active = 1 ORDER BY p.sort_order, p.id', $currency ) ) ); }
	public static function sanitize_nonnegative_integer( $value ) {
		return ( is_int( $value ) || is_string( $value ) ) && ctype_digit( (string) $value ) ? absint( $value ) : 0; }
	public static function validate_nonnegative_integer( $value ) {
		return ( is_int( $value ) || is_string( $value ) ) && ctype_digit( (string) $value ); }
	public static function sanitize_positive_integer( $value ) {
		$value = self::sanitize_nonnegative_integer( $value );
		return $value > 0 ? $value : 0; }
	public static function validate_positive_integer( $value ) {
		return self::validate_nonnegative_integer( $value ) && self::sanitize_nonnegative_integer( $value ) > 0; }
	private static function membership_data( $membership ) {
		global $wpdb;
		$user      = get_userdata( $membership->user_id );
		$plan_name = $membership->plan_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' WHERE id = %d', $membership->plan_id ) ) : null;
		return array(
			'id'                     => (int) $membership->id,
			'user_id'                => (int) $membership->user_id,
			'user_email'             => $user ? $user->user_email : null,
			'display_name'           => $user ? $user->display_name : null,
			'plan_id'                => $membership->plan_id ? (int) $membership->plan_id : null,
			'plan_name'              => $plan_name,
			'price_id'               => $membership->price_id ? (int) $membership->price_id : null,
			'membership_status'      => $membership->membership_status,
			'stripe_status'          => $membership->stripe_status,
			'stripe_period_start'    => $membership->stripe_period_start,
			'stripe_period_end'      => $membership->stripe_period_end,
			'effective_access_until' => $membership->effective_access_until,
			'grace_until'            => $membership->grace_until,
			'cancel_at_period_end'   => (bool) $membership->cancel_at_period_end,
			'access_revoked_at'      => $membership->access_revoked_at,
			'updated_at'             => $membership->updated_at,
		); }
	public static function admin_memberships( WP_REST_Request $request ) {
		global $wpdb;
		$page           = max( 1, self::sanitize_positive_integer( $request->get_param( 'page' ) ) );
		$per_page_value = $request->get_param( 'per_page' );
		$per_page       = min( 100, max( 1, self::sanitize_positive_integer( $per_page_value ? $per_page_value : 50 ) ) );
		$where          = array( '1=1' );
		$params         = array();
		$search         = $request->get_param( 'search' );
		if ( is_scalar( $search ) && '' !== trim( (string) $search ) ) {
			$like    = '%' . $wpdb->esc_like( sanitize_text_field( (string) $search ) ) . '%';
			$where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
			array_push( $params, $like, $like );
		} $status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( in_array( $status, array( 'pending', 'trialing', 'active', 'past_due', 'canceled', 'expired', 'paused', 'revoked' ), true ) ) {
			if ( 'revoked' === $status ) {
				$where[] = 'm.access_revoked_at IS NOT NULL';
			} else {
				$where[]  = 'm.membership_status = %s';
				$params[] = $status;
			}
		} $plan_id = self::sanitize_nonnegative_integer( $request->get_param( 'plan_id' ) );
		if ( $plan_id ) {
			$where[]  = 'm.plan_id = %d';
			$params[] = $plan_id;
		} $from      = ' FROM ' . Picot_Subscription_Membership_DB::table( 'memberships' ) . ' m LEFT JOIN ' . $wpdb->users . ' u ON m.user_id = u.ID';
		$where_sql   = implode( ' AND ', $where );
		$count_sql   = 'SELECT COUNT(*)' . $from . ' WHERE ' . $where_sql;
		$total       = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, ...$params ) : $count_sql );
		$items_sql   = 'SELECT m.*' . $from . ' WHERE ' . $where_sql . ' ORDER BY m.updated_at DESC LIMIT %d OFFSET %d';
		$item_params = array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) );
		$rows        = $wpdb->get_results( $wpdb->prepare( $items_sql, ...$item_params ) );
		return rest_ensure_response(
			array(
				'items'       => array_map( array( __CLASS__, 'membership_data' ), $rows ),
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		); }
	public static function admin_membership( WP_REST_Request $request ) {
		$membership = Picot_Subscription_Membership_Membership::get_by_id( absint( $request['id'] ) );
		return $membership ? rest_ensure_response( self::membership_data( $membership ) ) : new WP_Error( 'membership_not_found', __( '会員情報が見つかりません。', 'picot-subscription-membership' ), array( 'status' => 404 ) ); }
	public static function revoke( WP_REST_Request $request ) {
		global $wpdb;
		$membership = Picot_Subscription_Membership_Membership::get_by_id( absint( $request['id'] ) );
		if ( ! $membership ) {
			return new WP_Error( 'membership_not_found', __( '会員情報が見つかりません。', 'picot-subscription-membership' ), array( 'status' => 404 ) );
		} $wpdb->update(
			Picot_Subscription_Membership_DB::table( 'memberships' ),
			array(
				'access_revoked_at' => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $membership->id )
		);
		Picot_Subscription_Membership_DB::log( 'access_revoked', __( '管理REST APIで利用を停止しました。', 'picot-subscription-membership' ), $membership->id, $membership->user_id );
		return rest_ensure_response( self::membership_data( Picot_Subscription_Membership_Membership::get_by_id( $membership->id ) ) ); }
	public static function restore( WP_REST_Request $request ) {
		global $wpdb;
		$membership = Picot_Subscription_Membership_Membership::get_by_id( absint( $request['id'] ) );
		if ( ! $membership ) {
			return new WP_Error( 'membership_not_found', __( '会員情報が見つかりません。', 'picot-subscription-membership' ), array( 'status' => 404 ) );
		} $wpdb->update(
			Picot_Subscription_Membership_DB::table( 'memberships' ),
			array(
				'access_revoked_at' => null,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $membership->id )
		);
		Picot_Subscription_Membership_DB::log( 'access_reinstated', __( '管理REST APIで利用停止を解除しました。', 'picot-subscription-membership' ), $membership->id, $membership->user_id );
		return rest_ensure_response( self::membership_data( Picot_Subscription_Membership_Membership::get_by_id( $membership->id ) ) ); }
	public static function sync( WP_REST_Request $request ) {
		$membership = Picot_Subscription_Membership_Membership::get_by_id( absint( $request['id'] ) );
		if ( ! $membership ) {
			return new WP_Error( 'membership_not_found', __( '会員情報が見つかりません。', 'picot-subscription-membership' ), array( 'status' => 404 ) );
		} if ( ! $membership->stripe_subscription_id ) {
			return new WP_Error( 'subscription_missing', __( 'Stripe契約情報がありません。', 'picot-subscription-membership' ), array( 'status' => 400 ) );
		} $subscription = Picot_Subscription_Membership_Stripe_Gateway::retrieve_subscription( $membership->stripe_subscription_id );
		if ( is_wp_error( $subscription ) ) {
			return $subscription;
		} $result = Picot_Subscription_Membership_Membership::sync_subscription( $subscription, $membership->user_id );
		return is_wp_error( $result ) ? $result : rest_ensure_response( self::membership_data( $result ) ); }
	public static function account() {
		global $wpdb;
		$m = Picot_Subscription_Membership_Membership::get_for_user( get_current_user_id() );
		if ( ! $m ) {
			return rest_ensure_response(
				array(
					'membership' => null,
					'active'     => false,
				)
			);
		} $plan_name = $m->plan_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' WHERE id = %d', $m->plan_id ) ) : null;
		return rest_ensure_response(
			array(
				'membership' => array(
					'plan_id'                => $m->plan_id ? (int) $m->plan_id : null,
					'plan_name'              => $plan_name,
					'membership_status'      => $m->membership_status,
					'stripe_status'          => $m->stripe_status,
					'stripe_period_end'      => $m->stripe_period_end,
					'effective_access_until' => $m->effective_access_until,
					'grace_until'            => $m->grace_until,
					'cancel_at_period_end'   => (bool) $m->cancel_at_period_end,
					'access_revoked_at'      => $m->access_revoked_at,
				),
				'active'     => Picot_Subscription_Membership_Membership::is_active( get_current_user_id() ),
			)
		); }
	public static function checkout( WP_REST_Request $request ) {
		$session = Picot_Subscription_Membership_Stripe_Gateway::create_checkout( get_current_user_id(), $request['price_id'], add_query_arg( 'membership', 'success', home_url( '/' ) ), add_query_arg( 'membership', 'cancel', home_url( '/' ) ) );
		return is_wp_error( $session ) ? $session : rest_ensure_response( array( 'url' => $session['url'] ) ); }
	public static function portal() {
		$session = Picot_Subscription_Membership_Stripe_Gateway::create_portal( get_current_user_id(), home_url( '/' ) );
		return is_wp_error( $session ) ? $session : rest_ensure_response( array( 'url' => $session['url'] ) ); }
	public static function extend( WP_REST_Request $request ) {
		$raw_days = $request->get_param( 'days' );
		if ( ! is_numeric( $raw_days ) || ! is_finite( (float) $raw_days ) || abs( (float) $raw_days ) > 36500 ) {
			return new WP_Error( 'invalid_days', __( '延長日数は-36500日から36500日の範囲で指定してください。', 'picot-subscription-membership' ), array( 'status' => 400 ) );
		} $days = (float) $raw_days;
		if ( 0.0 === $days ) {
			return new WP_Error( 'invalid_days', __( '延長日数を指定してください。', 'picot-subscription-membership' ), array( 'status' => 400 ) );
		} $raw_reason = $request->get_param( 'reason' );
		$reason       = is_scalar( $raw_reason ) ? sanitize_textarea_field( $raw_reason ) : '';
		$result       = Picot_Subscription_Membership_Membership::adjust( (int) $request['id'], (int) round( $days * DAY_IN_SECONDS ), $reason, get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( array( 'effective_access_until' => $result ) ); }
	public static function webhook( WP_REST_Request $request ) {
		$payload = (string) $request->get_body();
		if ( strlen( $payload ) > 1024 * 1024 ) {
			return new WP_Error( 'payload_too_large', 'Webhook payload is too large.', array( 'status' => 413 ) ); }
		if ( ! Picot_Subscription_Membership_Stripe_Gateway::verify_signature( $payload, $request->get_header( 'stripe-signature' ) ) ) {
			return new WP_Error( 'invalid_signature', 'Invalid Stripe signature.', array( 'status' => 400 ) ); }
		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || ! isset( $event['id'], $event['type'], $event['data']['object'] ) || ! is_string( $event['id'] ) || ! is_string( $event['type'] ) || ! is_array( $event['data']['object'] ) || '' === $event['id'] || '' === $event['type'] ) {
			return new WP_Error( 'invalid_event', 'Invalid Stripe event.', array( 'status' => 400 ) ); }
		global $wpdb;
		$events   = Picot_Subscription_Membership_DB::table( 'webhook_events' );
		$now      = current_time( 'mysql', true );
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . $events . ' (stripe_event_id,event_type,object_id,event_created_at,received_at,status,attempt_count,payload_hash) VALUES (%s,%s,%s,%s,%s,%s,1,%s)', $event['id'], $event['type'], $event['data']['object']['id'] ?? '', ! empty( $event['created'] ) ? gmdate( 'Y-m-d H:i:s', (int) $event['created'] ) : null, $now, 'processing', hash( 'sha256', $payload ) ) );
		if ( false === $inserted ) {
			$message = __( 'Webhookイベントを記録できませんでした。Stripeが配信を再試行します。', 'picot-subscription-membership' );
			Picot_Subscription_Membership_DB::log( 'webhook_database_error', $message );
			return new WP_Error( 'webhook_database_error', $message, array( 'status' => 500 ) );
		}
		if ( ! $inserted ) {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, received_at FROM ' . $events . ' WHERE stripe_event_id = %s', $event['id'] ) );
			if ( ! $existing || 'processed' === $existing->status ) {
				return rest_ensure_response(
					array(
						'received'  => true,
						'duplicate' => true,
					)
				); }
			$stale_processing = 'processing' === $existing->status && strtotime( $existing->received_at . ' UTC' ) < ( time() - 5 * MINUTE_IN_SECONDS );
			if ( 'failed' !== $existing->status && ! $stale_processing ) {
				return rest_ensure_response(
					array(
						'received'  => true,
						'duplicate' => true,
					)
				); }
			$claimed = $wpdb->query( $wpdb->prepare( 'UPDATE ' . $events . ' SET status = %s, attempt_count = attempt_count + 1, received_at = %s, error_message = NULL WHERE id = %d AND status = %s', 'processing', $now, $existing->id, $existing->status ) );
			if ( ! $claimed ) {
				return rest_ensure_response(
					array(
						'received'  => true,
						'duplicate' => true,
					)
				); }
		}
		try {
			self::process_event( $event );
			$wpdb->update(
				$events,
				array(
					'status'       => 'processed',
					'processed_at' => current_time( 'mysql', true ),
				),
				array( 'stripe_event_id' => $event['id'] )
			);
		} catch ( Throwable $e ) {
			$message = sanitize_text_field( $e->getMessage() );
			$wpdb->update(
				$events,
				array(
					'status'        => 'failed',
					'error_message' => $message,
				),
				array( 'stripe_event_id' => $event['id'] )
			);
			Picot_Subscription_Membership_DB::log( 'webhook_error', $message );
			return new WP_Error( 'webhook_processing_failed', 'Webhook processing failed.', array( 'status' => 500 ) ); }
		return rest_ensure_response( array( 'received' => true ) );
	}
	private static function process_event( $event ) {
		$object = $event['data']['object'];
		$type   = $event['type'];
		if ( in_array( $type, array( 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted' ), true ) ) {
			$subscription = ! empty( $object['id'] ) ? Picot_Subscription_Membership_Stripe_Gateway::retrieve_subscription( $object['id'] ) : new WP_Error( 'subscription_missing', __( 'Stripe契約情報がありません。', 'picot-subscription-membership' ) );
			if ( is_wp_error( $subscription ) ) {
				throw new RuntimeException( $subscription->get_error_message() );
			} $user_id = (int) ( $subscription['metadata']['wp_user_id'] ?? $object['metadata']['wp_user_id'] ?? 0 );
			$result    = Picot_Subscription_Membership_Membership::sync_subscription( $subscription, $user_id );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			} return; }
		if ( 'checkout.session.completed' === $type ) {
			if ( ! empty( $object['metadata']['psm_purchase_post_id'] ) ) {
				self::record_article_purchase( $object );
			} else {
				self::ensure_membership_from_checkout( $object );
			} return; }
		if ( 'charge.refunded' === $type && ! empty( $object['refunded'] ) ) {
			self::revoke_refunded_purchase( $object );
			return; }
		if ( in_array( $type, array( 'invoice.paid', 'invoice.payment_failed', 'invoice.payment_action_required' ), true ) ) {
			self::process_invoice( $object, 'invoice.paid' === $type ); }
	}
	private static function ensure_membership_from_checkout( $session ) {
		global $wpdb;
		$user_id = (int) ( $session['client_reference_id'] ?? $session['metadata']['wp_user_id'] ?? 0 );
		if ( ! $user_id ) {
			return;
		} Picot_Subscription_Membership_Stripe_Gateway::clear_pending_subscription_checkout( $user_id );
		$m = Picot_Subscription_Membership_Membership::get_for_user( $user_id );
		if ( ! $m ) {
			$now = current_time( 'mysql', true );
			$wpdb->insert(
				Picot_Subscription_Membership_DB::table( 'memberships' ),
				array(
					'user_id'                => $user_id,
					'stripe_customer_id'     => $session['customer'] ?? null,
					'stripe_subscription_id' => $session['subscription'] ?? null,
					'membership_status'      => 'pending',
					'created_at'             => $now,
					'updated_at'             => $now,
				)
			); } }
	private static function record_article_purchase( $session ) {
		global $wpdb;
		if ( 'paid' !== ( $session['payment_status'] ?? '' ) ) {
			return; }
		$user_id        = (int) ( $session['client_reference_id'] ?? $session['metadata']['wp_user_id'] ?? 0 );
		$post_id        = absint( $session['metadata']['psm_purchase_post_id'] ?? 0 );
		$payment_intent = sanitize_text_field( $session['payment_intent'] ?? '' );
		$session_id     = sanitize_text_field( $session['id'] ?? '' );
		if ( ! $user_id || ! $post_id || ! $payment_intent || ! $session_id || ! get_post( $post_id ) ) {
			throw new RuntimeException( 'Invalid article purchase metadata.' ); }
		Picot_Subscription_Membership_Stripe_Gateway::clear_pending_article_purchase( $user_id, $post_id );
		$created_at = ! empty( $session['created'] ) ? gmdate( 'Y-m-d H:i:s', (int) $session['created'] ) : current_time( 'mysql', true );
		$existing   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'purchases' ) . ' WHERE user_id = %d AND post_id = %d', $user_id, $post_id ) );
		$data       = array(
			'user_id'                    => $user_id,
			'post_id'                    => $post_id,
			'stripe_checkout_session_id' => $session_id,
			'stripe_payment_intent_id'   => $payment_intent,
			'stripe_created_at'          => $created_at,
			'amount'                     => (int) ( $session['amount_total'] ?? 0 ),
			'currency'                   => sanitize_key( $session['currency'] ?? 'jpy' ),
			'status'                     => 'paid',
			'purchased_at'               => current_time( 'mysql', true ),
			'refunded_at'                => null,
			'stripe_refund_id'           => null,
			'created_at'                 => current_time( 'mysql', true ),
		);
		if ( ! $existing ) {
			$wpdb->insert( Picot_Subscription_Membership_DB::table( 'purchases' ), $data );
		} else {
			$existing_created = $existing->stripe_created_at ? strtotime( $existing->stripe_created_at . ' UTC' ) : 0;
			$incoming_created = strtotime( $created_at . ' UTC' );
			if ( 'refunded' === $existing->status && $existing->stripe_payment_intent_id === $payment_intent ) {
				return;
			} if ( $existing_created && $incoming_created && $incoming_created < $existing_created ) {
				return;
			} unset( $data['user_id'], $data['post_id'], $data['created_at'] );
			$wpdb->update( Picot_Subscription_Membership_DB::table( 'purchases' ), $data, array( 'id' => $existing->id ) ); }
		do_action( 'picot_membership_article_purchased', $user_id, $post_id, $session );
	}
	private static function revoke_refunded_purchase( $charge ) {
		global $wpdb;
		$payment_intent = $charge['payment_intent'] ?? '';
		if ( ! $payment_intent ) {
			throw new RuntimeException( 'Refund event does not include a payment intent.' ); }
		$refund_id = $charge['refunds']['data'][0]['id'] ?? '';
		$updated   = $wpdb->update(
			Picot_Subscription_Membership_DB::table( 'purchases' ),
			array(
				'status'           => 'refunded',
				'stripe_refund_id' => $refund_id,
				'refunded_at'      => current_time( 'mysql', true ),
			),
			array(
				'stripe_payment_intent_id' => $payment_intent,
				'status'                   => 'paid',
			)
		);
		if ( 0 === $updated ) {
			$user_id  = absint( $charge['metadata']['wp_user_id'] ?? 0 );
			$post_id  = absint( $charge['metadata']['psm_purchase_post_id'] ?? 0 );
			$existing = $user_id && $post_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . Picot_Subscription_Membership_DB::table( 'purchases' ) . ' WHERE user_id = %d AND post_id = %d', $user_id, $post_id ) ) : null;
			if ( ! $existing && $user_id && $post_id && get_user_by( 'id', $user_id ) && get_post( $post_id ) ) {
				$wpdb->insert(
					Picot_Subscription_Membership_DB::table( 'purchases' ),
					array(
						'user_id'                    => $user_id,
						'post_id'                    => $post_id,
						'stripe_checkout_session_id' => 'refund_' . sanitize_text_field( $charge['id'] ?? $payment_intent ),
						'stripe_payment_intent_id'   => $payment_intent,
						'stripe_created_at'          => ! empty( $charge['created'] ) ? gmdate( 'Y-m-d H:i:s', (int) $charge['created'] ) : current_time( 'mysql', true ),
						'stripe_refund_id'           => $refund_id,
						'amount'                     => 0,
						'currency'                   => sanitize_key( $charge['currency'] ?? 'jpy' ),
						'status'                     => 'refunded',
						'purchased_at'               => current_time( 'mysql', true ),
						'refunded_at'                => current_time( 'mysql', true ),
						'created_at'                 => current_time( 'mysql', true ),
					)
				); }
		}
		if ( false === $updated ) {
			throw new RuntimeException( 'Could not revoke the refunded article purchase.' ); }
		do_action( 'picot_membership_article_purchase_refunded', $payment_intent, $refund_id );
	}
	private static function process_invoice( $invoice, $paid ) {
		global $wpdb;
		$sub_id = $invoice['subscription'] ?? '';
		if ( ! $sub_id ) {
			throw new RuntimeException( 'Invoice does not include a subscription.' ); }
		$m            = Picot_Subscription_Membership_Membership::get_by_subscription( $sub_id );
		$subscription = null;
		$subscription = Picot_Subscription_Membership_Stripe_Gateway::retrieve_subscription( $sub_id );
		if ( is_wp_error( $subscription ) ) {
			throw new RuntimeException( $subscription->get_error_message() ); }
		$m = Picot_Subscription_Membership_Membership::sync_subscription( $subscription, $m ? 0 : (int) ( $subscription['metadata']['wp_user_id'] ?? 0 ) );
		if ( is_wp_error( $m ) ) {
			throw new RuntimeException( $m->get_error_message() ); }
		$can_apply_invoice_status = $m->plan_id && $m->price_id && in_array( $m->membership_status, array( 'active', 'trialing', 'past_due' ), true );
		if ( $paid ) {
			$updates = array(
				'grace_until'     => null,
				'last_invoice_id' => $invoice['id'],
			);
			if ( $can_apply_invoice_status ) {
				$updates['membership_status'] = 'active'; }
			$wpdb->update( Picot_Subscription_Membership_DB::table( 'memberships' ), $updates, array( 'id' => $m->id ) );
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . Picot_Subscription_Membership_DB::table( 'payments' ) . ' (membership_id,user_id,stripe_invoice_id,stripe_payment_intent_id,amount,currency,status,period_start,period_end,paid_at,created_at) VALUES (%d,%d,%s,%s,%d,%s,%s,%s,%s,%s,%s)', $m->id, $m->user_id, $invoice['id'], $invoice['payment_intent'] ?? '', (int) ( $invoice['amount_paid'] ?? 0 ), $invoice['currency'] ?? '', 'paid', ! empty( $invoice['period_start'] ) ? gmdate( 'Y-m-d H:i:s', $invoice['period_start'] ) : null, ! empty( $invoice['period_end'] ) ? gmdate( 'Y-m-d H:i:s', $invoice['period_end'] ) : null, current_time( 'mysql', true ), current_time( 'mysql', true ) ) );
			Picot_Subscription_Membership_Membership::recompute_access_until( $m->id );
			do_action( 'picot_membership_payment_succeeded', $m, $invoice );
			return;
		}
		if ( ! $can_apply_invoice_status || 'past_due' !== $m->membership_status ) {
			Picot_Subscription_Membership_DB::log( 'payment_failure', __( '支払い失敗を受信しましたが、Stripe上の現在状態により会員状態は変更しませんでした。', 'picot-subscription-membership' ), $m->id, $m->user_id );
			do_action( 'picot_membership_payment_failed', $m, $invoice );
			return; }
		$s              = Picot_Subscription_Membership_Stripe_Gateway::settings();
		$grace          = (int) ( $s['grace_days'] ?? 0 );
		$failure_at     = (int) ( $invoice['status_transitions']['finalized_at'] ?? $invoice['created'] ?? 0 );
		$failure_at     = $failure_at > 0 ? $failure_at : time();
		$existing_grace = $m->grace_until ? strtotime( $m->grace_until . ' UTC' ) : 0;
		$grace_until    = $grace ? max( $existing_grace, $failure_at + $grace * DAY_IN_SECONDS ) : 0;
		$until          = $grace_until ? gmdate( 'Y-m-d H:i:s', $grace_until ) : null;
		$wpdb->update(
			Picot_Subscription_Membership_DB::table( 'memberships' ),
			array(
				'membership_status' => 'past_due',
				'grace_until'       => $until,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $m->id )
		);
		Picot_Subscription_Membership_DB::log( 'payment_failure', __( '支払い失敗を受信しました。', 'picot-subscription-membership' ), $m->id, $m->user_id );
		Picot_Subscription_Membership_Membership::recompute_access_until( $m->id );
		do_action( 'picot_membership_payment_failed', $m, $invoice );
	}
}
