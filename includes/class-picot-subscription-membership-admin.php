<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queries use plugin-owned, prefixed tables and current membership data must not be cached.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers come only from Picot_Subscription_Membership_DB::table() or $wpdb core table properties; all query values use placeholders.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.EscapeOutput.OutputNotEscaped -- Request handlers verify their dedicated nonces before making state changes; core nonce markup is safe HTML.
final class Picot_Subscription_Membership_Admin {
	public static function init() {
		foreach ( array( 'id', '_wpnonce' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ! is_scalar( $_POST[ $key ] ) ) {
				unset( $_POST[ $key ] );
			} if ( isset( $_GET[ $key ] ) && ! is_scalar( $_GET[ $key ] ) ) {
				unset( $_GET[ $key ] );
			} if ( isset( $_REQUEST[ $key ] ) && ! is_scalar( $_REQUEST[ $key ] ) ) {
				unset( $_REQUEST[ $key ] );
			}
		} foreach ( array( 'psm_plan_notice', 'psm_plan_error', 'psm_notice', 'psm_notice_type' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && ! is_scalar( $_GET[ $key ] ) ) {
				unset( $_GET[ $key ] );
			}
		} add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_psm_save_plan', array( __CLASS__, 'save_plan' ) );
		add_action( 'admin_post_psm_adjust', array( __CLASS__, 'adjust' ) );
		add_action( 'admin_post_psm_sync_member', array( __CLASS__, 'sync_member' ) );
		add_action( 'admin_post_psm_sync_all', array( __CLASS__, 'sync_all' ) );
		add_action( 'admin_post_psm_toggle_access_revocation', array( __CLASS__, 'toggle_access_revocation' ) );
		add_action( 'admin_post_psm_toggle_plan', array( __CLASS__, 'toggle_plan' ) ); }
	public static function menu() {
		add_menu_page( 'Membership', 'Membership', 'manage_memberships', 'psm', array( __CLASS__, 'dashboard' ), 'dashicons-groups', 58 );
		add_submenu_page( 'psm', __( '会員', 'picot-subscription-membership' ), __( '会員', 'picot-subscription-membership' ), 'manage_memberships', 'psm-members', array( __CLASS__, 'members' ) );
		add_submenu_page( 'psm', __( 'プラン', 'picot-subscription-membership' ), __( 'プラン', 'picot-subscription-membership' ), 'manage_membership_plans', 'psm-plans', array( __CLASS__, 'plans' ) );
		add_submenu_page( 'psm', __( '決済履歴', 'picot-subscription-membership' ), __( '決済履歴', 'picot-subscription-membership' ), 'view_membership_payments', 'psm-payments', array( __CLASS__, 'payments' ) );
		add_submenu_page( 'psm', __( '期間変更履歴', 'picot-subscription-membership' ), __( '期間変更履歴', 'picot-subscription-membership' ), 'manage_membership_periods', 'psm-adjustments', array( __CLASS__, 'adjustments' ) );
		add_submenu_page( 'psm', __( 'Stripe同期', 'picot-subscription-membership' ), __( 'Stripe同期', 'picot-subscription-membership' ), 'manage_memberships', 'psm-sync', array( __CLASS__, 'sync' ) );
		add_submenu_page( 'psm', __( 'ログ', 'picot-subscription-membership' ), __( 'ログ', 'picot-subscription-membership' ), 'manage_memberships', 'psm-logs', array( __CLASS__, 'logs' ) );
		add_submenu_page( 'psm', __( 'Webhookログ', 'picot-subscription-membership' ), __( 'Webhookログ', 'picot-subscription-membership' ), 'manage_membership_webhooks', 'psm-webhooks', array( __CLASS__, 'webhooks' ) );
		add_submenu_page( null, __( '会員詳細', 'picot-subscription-membership' ), __( '会員詳細', 'picot-subscription-membership' ), 'manage_memberships', 'psm-member-detail', array( __CLASS__, 'member_detail' ) ); }
	private static function table( $title, $headers, $rows ) {
		$allowed           = wp_kses_allowed_html( 'post' );
		$allowed['form']   = array(
			'method' => true,
			'action' => true,
			'style'  => true,
		);
		$allowed['input']  = array(
			'type'        => true,
			'name'        => true,
			'value'       => true,
			'class'       => true,
			'placeholder' => true,
			'step'        => true,
			'min'         => true,
		);
		$allowed['button'] = array(
			'type'  => true,
			'class' => true,
		);
		if ( $title ) {
			echo '<div class="wrap"><h1>' . esc_html( self::translated_label( $title ) ) . '</h1>';
		} echo '<table class="widefat striped"><thead><tr>';
		foreach ( $headers as $h ) {
			echo '<th>' . esc_html( self::translated_label( $h ) ) . '</th>';
		} echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( $row as $value ) {
				echo '<td>' . wp_kses( $value, $allowed ) . '</td>';
			} echo '</tr>';
		} echo '</tbody></table>';
		if ( $title ) {
			echo '</div>'; } }
	private static function translated_label( $text ) {
		return Picot_Subscription_Membership_I18n::translate( $text, $text, 'picot-subscription-membership' );
	}
	private static function value_or_dash( $value ) {
		return '' !== (string) $value ? $value : '—';
	}
	public static function dashboard() {
		if ( ! current_user_can( 'manage_memberships' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		global $wpdb;
		$t           = Picot_Subscription_Membership_DB::table( 'memberships' );
		$users       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
		$total       = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t" );
		$paid        = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE plan_id IS NOT NULL AND access_revoked_at IS NULL AND ((membership_status IN ('active','trialing','canceled') AND effective_access_until >= UTC_TIMESTAMP()) OR (membership_status = 'past_due' AND grace_until >= UTC_TIMESTAMP()))" );
		$active      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE membership_status = 'active' AND access_revoked_at IS NULL" );
		$past_due    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE membership_status = 'past_due' AND access_revoked_at IS NULL" );
		$expired     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE membership_status = 'expired'" );
		$cancelling  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE cancel_at_period_end = 1 AND membership_status IN ('active','trialing','past_due','canceled')" );
		$new_members = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)" );
		$plans       = $wpdb->get_results( 'SELECT p.name, COUNT(m.id) AS members FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' p LEFT JOIN ' . $t . ' m ON m.plan_id=p.id AND m.access_revoked_at IS NULL GROUP BY p.id ORDER BY p.sort_order,p.id' );
		echo '<div class="wrap"><h1>Membership</h1><div class="notice notice-info"><p>Stripe Webhook URL: <code>' . esc_html( rest_url( 'membership/v1/stripe/webhook' ) ) . '</code></p></div><table class="widefat striped"><tbody><tr><th>' . esc_html__( '総WordPress会員', 'picot-subscription-membership' ) . '</th><td>' . esc_html( number_format_i18n( $users ) ) . '</td><th>' . esc_html__( '無料会員', 'picot-subscription-membership' ) . '</th><td>' . esc_html( number_format_i18n( max( 0, $users - $paid ) ) ) . '</td><th>' . esc_html__( '有料会員', 'picot-subscription-membership' ) . '</th><td>' . esc_html( number_format_i18n( $paid ) ) . '</td></tr><tr><th>Active</th><td>' . esc_html( number_format_i18n( $active ) ) . '</td><th>' . esc_html__( '解約予約', 'picot-subscription-membership' ) . '</th><td>' . esc_html( number_format_i18n( $cancelling ) ) . '</td><th>Past Due</th><td>' . esc_html( number_format_i18n( $past_due ) ) . '</td></tr><tr><th>Expired</th><td>' . esc_html( number_format_i18n( $expired ) ) . '</td><th>' . esc_html__( '直近30日新規', 'picot-subscription-membership' ) . '</th><td>' . esc_html( number_format_i18n( $new_members ) ) . '</td><th>' . esc_html__( 'Membership登録', 'picot-subscription-membership' ) . '</th><td>' . esc_html( number_format_i18n( $total ) ) . '</td></tr></tbody></table><h2>' . esc_html__( 'プラン別会員数', 'picot-subscription-membership' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'プラン', 'picot-subscription-membership' ) . '</th><th>' . esc_html__( '会員数', 'picot-subscription-membership' ) . '</th></tr></thead><tbody>';
		foreach ( $plans as $plan ) {
			echo '<tr><td>' . esc_html( $plan->name ) . '</td><td>' . esc_html( number_format_i18n( $plan->members ) ) . '</td></tr>';
		} echo '</tbody></table></div>';
	}
	public static function members() {
		if ( ! current_user_can( 'manage_memberships' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		global $wpdb;
		$raw_search    = $_GET['psm_member_search'] ?? '';
		$raw_status    = $_GET['psm_member_status'] ?? '';
		$raw_plan      = $_GET['psm_member_plan'] ?? 0;
		$search        = is_scalar( $raw_search ) ? sanitize_text_field( wp_unslash( $raw_search ) ) : '';
		$status_filter = is_scalar( $raw_status ) ? sanitize_key( wp_unslash( $raw_status ) ) : '';
		$plan_filter   = is_scalar( $raw_plan ) ? absint( $raw_plan ) : 0;
		$statuses      = array( 'pending', 'trialing', 'active', 'past_due', 'canceled', 'expired', 'paused', 'revoked' );
		$where         = array( '1=1' );
		$params        = array();
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s)';
			array_push( $params, $like, $like ); }
		if ( in_array( $status_filter, $statuses, true ) ) {
			if ( 'revoked' === $status_filter ) {
				$where[] = 'm.access_revoked_at IS NOT NULL';
			} else {
				$where[]  = 'm.membership_status = %s';
				$params[] = $status_filter; }
		}
		if ( $plan_filter ) {
			$where[]  = 'm.plan_id = %d';
			$params[] = $plan_filter; }
		$sql   = 'SELECT m.*, u.display_name, u.user_email, p.name AS plan_name FROM ' . Picot_Subscription_Membership_DB::table( 'memberships' ) . ' m LEFT JOIN ' . $wpdb->users . ' u ON m.user_id = u.ID LEFT JOIN ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' p ON m.plan_id = p.id WHERE ' . implode( ' AND ', $where ) . ' ORDER BY m.updated_at DESC LIMIT 200';
		$rows  = $wpdb->get_results( $params ? $wpdb->prepare( $sql, ...$params ) : $sql );
		$plans = $wpdb->get_results( 'SELECT id, name FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' ORDER BY sort_order, name' );
		echo '<div class="wrap"><h1>' . esc_html__( '会員', 'picot-subscription-membership' ) . '</h1><form method="get" style="margin:1em 0"><input type="hidden" name="page" value="psm-members"><label>' . esc_html__( '検索', 'picot-subscription-membership' ) . ' <input name="psm_member_search" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( '名前・メール', 'picot-subscription-membership' ) . '"></label> <label>' . esc_html__( '状態', 'picot-subscription-membership' ) . ' <select name="psm_member_status"><option value="">' . esc_html__( 'すべて', 'picot-subscription-membership' ) . '</option>';
		foreach ( $statuses as $status ) {
			echo '<option value="' . esc_attr( $status ) . '" ' . selected( $status_filter, $status, false ) . '>' . esc_html( $status ) . '</option>';
		} echo '</select></label> <label>' . esc_html__( 'プラン', 'picot-subscription-membership' ) . ' <select name="psm_member_plan"><option value="0">' . esc_html__( 'すべて', 'picot-subscription-membership' ) . '</option>';
		foreach ( $plans as $plan ) {
			echo '<option value="' . esc_attr( $plan->id ) . '" ' . selected( $plan_filter, $plan->id, false ) . '>' . esc_html( $plan->name ) . '</option>';
		} echo '</select></label> <button class="button">' . esc_html__( '絞り込む', 'picot-subscription-membership' ) . '</button></form>';
		$out = array();
		foreach ( $rows as $r ) {
			$adjust = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_adjust"><input type="hidden" name="id" value="' . esc_attr( $r->id ) . '"><input type="number" name="days" value="7" step="1" class="small-text"> ' . esc_html__( '日', 'picot-subscription-membership' ) . ' <input name="reason" placeholder="' . esc_attr__( '理由', 'picot-subscription-membership' ) . '"> ' . wp_nonce_field( 'psm_adjust', '_wpnonce', false, false ) . '<button class="button">' . esc_html__( '延長', 'picot-subscription-membership' ) . '</button></form>';
			$detail = '<a class="button" href="' . esc_url(
				add_query_arg(
					array(
						'page' => 'psm-member-detail',
						'id'   => $r->id,
					),
					admin_url( 'admin.php' )
				)
			) . '">' . esc_html__( '詳細', 'picot-subscription-membership' ) . '</a>';
			$status = $r->access_revoked_at ? 'revoked' : $r->membership_status;
			$out[]  = array( esc_html( $r->display_name ), esc_html( $r->user_email ), esc_html( self::value_or_dash( $r->plan_name ) ), esc_html( $status ), esc_html( self::value_or_dash( $r->stripe_status ) ), esc_html( self::value_or_dash( $r->stripe_customer_id ) ), esc_html( self::value_or_dash( $r->stripe_period_end ) ), esc_html( $r->cancel_at_period_end ? __( 'あり', 'picot-subscription-membership' ) : '—' ), esc_html( self::value_or_dash( $r->effective_access_until ) ), $detail . ' ' . $adjust );
		} self::table( '', array( 'ユーザー', 'メール', 'プラン', '状態', 'Stripe', 'Stripe Customer', '次回更新', '解約予約', '利用可能期限', '操作' ), $out );
		echo '</div>';
	}
	public static function plans() {
		if ( ! current_user_can( 'manage_membership_plans' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		global $wpdb;
		$plans = $wpdb->get_results( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' ORDER BY sort_order,id' );
		echo '<div class="wrap"><h1>' . esc_html__( 'プラン', 'picot-subscription-membership' ) . '</h1>';
		if ( isset( $_GET['psm_plan_notice'], $_GET['psm_plan_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( $_GET['psm_plan_notice'] ?? $_GET['psm_plan_error'] ) );
			echo '<div class="notice notice-' . esc_attr( isset( $_GET['psm_plan_error'] ) ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>'; }
		echo '<h2>' . esc_html__( 'プラン・料金を追加', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( '同じプラン名を入力すると、既存プランへ月額・年額などの料金を追加します。', 'picot-subscription-membership' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_save_plan">';
		wp_nonce_field( 'psm_save_plan' );
		echo '<table class="form-table"><tr><th>' . esc_html__( '名称', 'picot-subscription-membership' ) . '</th><td><input required name="name" class="regular-text"></td></tr><tr><th>' . esc_html__( '説明', 'picot-subscription-membership' ) . '</th><td><textarea name="description" class="large-text"></textarea></td></tr><tr><th>Stripe Product ID</th><td><input name="product" class="regular-text"></td></tr><tr><th>' . esc_html__( '表示順', 'picot-subscription-membership' ) . '</th><td><input type="number" name="sort_order" class="small-text" placeholder="0"> <span class="description">' . esc_html__( '小さい値ほど先に表示されます。既存プランへの料金追加時は空欄のままにすると変更しません。', 'picot-subscription-membership' ) . '</span></td></tr><tr><th>Stripe Price ID</th><td><input required name="price" class="regular-text"></td></tr><tr><th>' . esc_html__( '料金', 'picot-subscription-membership' ) . '</th><td><input type="number" min="0.01" step="0.01" required name="amount"> <select name="currency">';
		foreach ( Picot_Subscription_Membership_Stripe_Gateway::supported_currencies() as $currency ) {
			echo '<option value="' . esc_attr( $currency ) . '" ' . selected( Picot_Subscription_Membership_Stripe_Gateway::current_currency(), $currency, false ) . '>' . esc_html( Picot_Subscription_Membership_Stripe_Gateway::currency_label( $currency ) ) . '</option>'; }
			echo '</select> <select name="interval"><option value="month">' . esc_html__( '月額', 'picot-subscription-membership' ) . '</option><option value="year">' . esc_html__( '年額', 'picot-subscription-membership' ) . '</option></select><br><span class="description">' . esc_html__( '販売通貨の単位で入力してください。小数を使わない通貨（JPYなど）とISK・UGXは整数、その他は小数第2位まで入力できます。Stripe側にも同じ通貨のPrice IDが必要です。', 'picot-subscription-membership' ) . '</span></td></tr></table><button class="button button-primary">' . esc_html__( '保存', 'picot-subscription-membership' ) . '</button></form><h2>' . esc_html__( '登録済みプラン', 'picot-subscription-membership' ) . '</h2>';
		$rows = array();
		foreach ( $plans as $plan ) {
			$prices     = $wpdb->get_results( $wpdb->prepare( 'SELECT billing_interval, amount, currency, stripe_price_id FROM ' . Picot_Subscription_Membership_DB::table( 'prices' ) . ' WHERE plan_id = %d ORDER BY amount', $plan->id ) );
			$price_text = implode( '<br>', array_map( fn( $price ) => esc_html( ( 'year' === $price->billing_interval ? __( '年額', 'picot-subscription-membership' ) : __( '月額', 'picot-subscription-membership' ) ) . ': ' . Picot_Subscription_Membership_Stripe_Gateway::display_amount( $price->amount, $price->currency ) . ' (' . $price->stripe_price_id . ')' ), $prices ) );
			$action     = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_toggle_plan"><input type="hidden" name="id" value="' . esc_attr( $plan->id ) . '"><input type="hidden" name="active" value="' . esc_attr( $plan->active ? 0 : 1 ) . '">' . wp_nonce_field( 'psm_toggle_plan_' . $plan->id, '_wpnonce', true, false ) . '<button class="button">' . esc_html( $plan->active ? __( '無効化', 'picot-subscription-membership' ) : __( '有効化', 'picot-subscription-membership' ) ) . '</button></form>';
			$rows[]     = array( esc_html( $plan->name ), esc_html( $plan->slug ), esc_html( $plan->stripe_product_id ), self::value_or_dash( $price_text ), esc_html( $plan->active ? __( '有効', 'picot-subscription-membership' ) : __( '無効', 'picot-subscription-membership' ) ), $action );
		}
		self::table( '', array( '名称', 'スラッグ', 'Stripe Product', '料金', '状態', '操作' ), $rows );
	}
	public static function save_plan() {
		if ( ! current_user_can( 'manage_membership_plans' ) || ! check_admin_referer( 'psm_save_plan' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		global $wpdb;
		$now             = current_time( 'mysql', true );
		$raw_name        = $_POST['name'] ?? '';
		$raw_interval    = $_POST['interval'] ?? '';
		$raw_price       = $_POST['price'] ?? '';
		$raw_description = $_POST['description'] ?? '';
		$raw_product     = $_POST['product'] ?? '';
		$raw_sort_order  = $_POST['sort_order'] ?? '';
		$raw_amount      = $_POST['amount'] ?? 0;
		$raw_currency    = $_POST['currency'] ?? 'jpy';
		$name            = is_scalar( $raw_name ) ? sanitize_text_field( wp_unslash( $raw_name ) ) : '';
		$slug            = sanitize_title( $name );
		$interval        = is_scalar( $raw_interval ) ? sanitize_key( wp_unslash( $raw_interval ) ) : '';
		$price_id        = is_scalar( $raw_price ) ? sanitize_text_field( wp_unslash( $raw_price ) ) : '';
		$currency        = is_scalar( $raw_currency ) && in_array( strtolower( (string) $raw_currency ), Picot_Subscription_Membership_Stripe_Gateway::supported_currencies(), true ) ? strtolower( (string) $raw_currency ) : 'jpy';
		$amount          = Picot_Subscription_Membership_Stripe_Gateway::normalize_amount( is_scalar( $raw_amount ) ? wp_unslash( $raw_amount ) : '', $currency );
		$description     = is_scalar( $raw_description ) ? wp_kses_post( wp_unslash( $raw_description ) ) : '';
		$product_id      = is_scalar( $raw_product ) ? sanitize_text_field( wp_unslash( $raw_product ) ) : '';
		$sort_order      = is_scalar( $raw_sort_order ) && '' !== $raw_sort_order ? (int) wp_unslash( $raw_sort_order ) : null;
		if ( ! $name || ! $price_id || ! Picot_Subscription_Membership_Stripe_Gateway::is_valid_charge_amount( $amount, $currency ) ) {
			wp_safe_redirect( add_query_arg( 'psm_plan_error', rawurlencode( __( 'プラン名、Stripe Price ID、1以上の料金を入力してください。', 'picot-subscription-membership' ) ), admin_url( 'admin.php?page=psm-plans' ) ) );
			exit; }
		$plan = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' WHERE slug = %s', $slug ) );
		if ( $plan ) {
			$plan_id = (int) $plan->id;
			$wpdb->update(
				Picot_Subscription_Membership_DB::table( 'plans' ),
				array(
					'description'       => '' !== $description ? $description : $plan->description,
					'stripe_product_id' => '' !== $product_id ? $product_id : $plan->stripe_product_id,
					'sort_order'        => null === $sort_order ? $plan->sort_order : $sort_order,
					'updated_at'        => $now,
				),
				array( 'id' => $plan_id )
			);
		} else {
			$wpdb->insert(
				Picot_Subscription_Membership_DB::table( 'plans' ),
				array(
					'name'              => $name,
					'slug'              => $slug,
					'description'       => $description,
					'stripe_product_id' => $product_id,
					'active'            => 1,
					'sort_order'        => $sort_order ?? 0,
					'created_at'        => $now,
					'updated_at'        => $now,
				)
			);
			$plan_id = (int) $wpdb->insert_id;
		}
		$existing_price = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Picot_Subscription_Membership_DB::table( 'prices' ) . ' WHERE stripe_price_id = %s', $price_id ) );
		if ( $plan_id && ! $existing_price ) {
			$wpdb->insert(
				Picot_Subscription_Membership_DB::table( 'prices' ),
				array(
					'plan_id'          => $plan_id,
					'billing_interval' => in_array( $interval, array( 'month', 'year' ), true ) ? $interval : 'month',
					'stripe_price_id'  => $price_id,
					'amount'           => $amount,
					'currency'         => $currency,
					'active'           => 1,
					'created_at'       => $now,
					'updated_at'       => $now,
				)
			);
		}
		wp_safe_redirect( add_query_arg( 'psm_plan_notice', rawurlencode( $existing_price ? __( 'このStripe Price IDはすでに登録されています。', 'picot-subscription-membership' ) : __( 'プラン料金を保存しました。', 'picot-subscription-membership' ) ), admin_url( 'admin.php?page=psm-plans' ) ) );
		exit;
	}
	public static function toggle_plan() {
		if ( ! current_user_can( 'manage_membership_plans' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} $id = absint( $_POST['id'] ?? 0 );
		if ( ! check_admin_referer( 'psm_toggle_plan_' . $id ) ) {
			wp_die( esc_html__( '不正なリクエストです。', 'picot-subscription-membership' ) );
		} global $wpdb;
		$wpdb->update(
			Picot_Subscription_Membership_DB::table( 'plans' ),
			array(
				'active'     => ! empty( $_POST['active'] ) ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
		wp_safe_redirect( add_query_arg( 'psm_plan_notice', rawurlencode( __( 'プラン状態を更新しました。', 'picot-subscription-membership' ) ), admin_url( 'admin.php?page=psm-plans' ) ) );
		exit; }
	private static function admin_notice() {
		if ( empty( $_GET['psm_notice'] ) ) {
			return;
		} $type = isset( $_GET['psm_notice_type'] ) && 'error' === $_GET['psm_notice_type'] ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['psm_notice'] ) ) ) . '</p></div>'; }
	private static function detail_url( $membership_id, $message = '', $type = 'success' ) {
		$args = array(
			'page' => 'psm-member-detail',
			'id'   => absint( $membership_id ),
		);
		if ( '' !== $message ) {
			$args['psm_notice']      = rawurlencode( $message );
			$args['psm_notice_type'] = $type;
		} return add_query_arg( $args, admin_url( 'admin.php' ) ); }
	public static function member_detail() {
		if ( ! current_user_can( 'manage_memberships' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		$membership_id = absint( $_GET['id'] ?? 0 );
		$m             = Picot_Subscription_Membership_Membership::get_by_id( $membership_id );
		if ( ! $m ) {
			wp_die( esc_html__( '会員情報が見つかりません。', 'picot-subscription-membership' ) ); }
		global $wpdb;
		$user = get_userdata( $m->user_id );
		$plan = $m->plan_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' WHERE id = %d', $m->plan_id ) ) : '';
		echo '<div class="wrap"><h1>' . esc_html__( '会員詳細', 'picot-subscription-membership' ) . '</h1>';
		self::admin_notice();
		echo '<table class="widefat striped"><tbody>';
		foreach ( array(
			__( 'WordPress User', 'picot-subscription-membership' ) => $user ? $user->display_name . ' (' . $user->user_email . ')' : '—',
			__( '現在プラン', 'picot-subscription-membership' ) => self::value_or_dash( $plan ),
			__( 'Membership Status', 'picot-subscription-membership' ) => $m->access_revoked_at ? 'revoked' : $m->membership_status,
			__( 'Stripe Status', 'picot-subscription-membership' ) => self::value_or_dash( $m->stripe_status ),
			__( 'Stripe Customer ID', 'picot-subscription-membership' ) => self::value_or_dash( $m->stripe_customer_id ),
			__( 'Stripe Subscription ID', 'picot-subscription-membership' ) => self::value_or_dash( $m->stripe_subscription_id ),
			__( 'Stripe有効期限', 'picot-subscription-membership' ) => self::value_or_dash( $m->stripe_period_end ),
			__( '手動延長', 'picot-subscription-membership' )  => number_format_i18n( (int) round( $m->manual_extension_seconds / DAY_IN_SECONDS ) ) . __( '日', 'picot-subscription-membership' ),
			__( '実利用期限', 'picot-subscription-membership' ) => self::value_or_dash( $m->effective_access_until ),
		) as $label => $value ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><code>' . esc_html( $value ) . '</code></td></tr>'; }
		echo '</tbody></table><h2>' . esc_html__( '操作', 'picot-subscription-membership' ) . '</h2>';
		if ( $m->stripe_subscription_id ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'psm_sync_member_' . $m->id, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="psm_sync_member"><input type="hidden" name="id" value="' . esc_attr( $m->id ) . '"><button class="button">' . esc_html__( 'Stripeと同期', 'picot-subscription-membership' ) . '</button></form> '; }
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'psm_toggle_access_revocation_' . $m->id, '_wpnonce', true, false ) . '<input type="hidden" name="action" value="psm_toggle_access_revocation"><input type="hidden" name="id" value="' . esc_attr( $m->id ) . '"><input type="hidden" name="revoke" value="' . esc_attr( $m->access_revoked_at ? 0 : 1 ) . '"><button class="button">' . esc_html( $m->access_revoked_at ? __( '利用停止を解除', 'picot-subscription-membership' ) : __( '利用停止', 'picot-subscription-membership' ) ) . '</button></form>';
		$payments    = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'payments' ) . ' WHERE membership_id = %d ORDER BY created_at DESC LIMIT 100', $m->id ) );
		$adjustments = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*,u.display_name FROM ' . Picot_Subscription_Membership_DB::table( 'adjustments' ) . ' a LEFT JOIN ' . $wpdb->users . ' u ON a.admin_user_id=u.ID WHERE a.membership_id = %d ORDER BY a.created_at DESC LIMIT 100', $m->id ) );
		$events      = $m->stripe_subscription_id ? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'webhook_events' ) . ' WHERE object_id = %s ORDER BY received_at DESC LIMIT 100', $m->stripe_subscription_id ) ) : array();
		echo '<h2>' . esc_html__( '決済履歴', 'picot-subscription-membership' ) . '</h2>';
		self::table( '', array( 'Invoice', '金額', '状態', '決済日時' ), array_map( fn( $row ) => array( esc_html( $row->stripe_invoice_id ), esc_html( number_format_i18n( $row->amount ) . ' ' . $row->currency ), esc_html( $row->status ), esc_html( $row->paid_at ) ), $payments ) );
		echo '<h2>' . esc_html__( '期間変更履歴', 'picot-subscription-membership' ) . '</h2>';
		self::table( '', array( '種別', '変更日数', '理由', '操作者', '日時' ), array_map( fn( $row ) => array( esc_html( $row->type ), esc_html( number_format_i18n( $row->delta_seconds / DAY_IN_SECONDS, 2 ) ), esc_html( $row->reason ), esc_html( self::value_or_dash( $row->display_name ) ), esc_html( $row->created_at ) ), $adjustments ) );
		echo '<h2>' . esc_html__( 'Webhook履歴', 'picot-subscription-membership' ) . '</h2>';
		self::table( '', array( 'Event ID', '種別', '状態', '受信日時' ), array_map( fn( $row ) => array( esc_html( $row->stripe_event_id ), esc_html( $row->event_type ), esc_html( $row->status ), esc_html( $row->received_at ) ), $events ) );
		echo '</div>';
	}
	public static function adjustments() {
		if ( ! current_user_can( 'manage_membership_periods' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} global $wpdb;
		$rows = $wpdb->get_results( 'SELECT a.*, u.user_email, au.display_name AS admin_name FROM ' . Picot_Subscription_Membership_DB::table( 'adjustments' ) . ' a LEFT JOIN ' . $wpdb->users . ' u ON a.user_id=u.ID LEFT JOIN ' . $wpdb->users . ' au ON a.admin_user_id=au.ID ORDER BY a.created_at DESC LIMIT 200' );
		self::table( '期間変更履歴', array( '会員', '種別', '変更日数', '理由', '操作者', '日時' ), array_map( fn( $row ) => array( esc_html( $row->user_email ), esc_html( $row->type ), esc_html( number_format_i18n( $row->delta_seconds / DAY_IN_SECONDS, 2 ) ), esc_html( $row->reason ), esc_html( self::value_or_dash( $row->admin_name ) ), esc_html( $row->created_at ) ), $rows ) ); }
	public static function sync() {
		if ( ! current_user_can( 'manage_memberships' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} echo '<div class="wrap"><h1>' . esc_html__( 'Stripe同期', 'picot-subscription-membership' ) . '</h1>';
		self::admin_notice();
		echo '<p>' . esc_html__( 'Stripe上の現在の契約状態を取得して、会員情報を同期します。最大50件を処理します。個別同期は会員詳細から実行できます。', 'picot-subscription-membership' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_sync_all">' . wp_nonce_field( 'psm_sync_all', '_wpnonce', true, false ) . '<button class="button button-primary">' . esc_html__( 'Stripeと同期', 'picot-subscription-membership' ) . '</button></form></div>'; }
	public static function sync_member() {
		if ( ! current_user_can( 'manage_memberships' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} $id = absint( $_POST['id'] ?? 0 );
		if ( ! check_admin_referer( 'psm_sync_member_' . $id ) ) {
			wp_die( esc_html__( '不正なリクエストです。', 'picot-subscription-membership' ) );
		} $m          = Picot_Subscription_Membership_Membership::get_by_id( $id );
		$subscription = $m && $m->stripe_subscription_id ? Picot_Subscription_Membership_Stripe_Gateway::retrieve_subscription( $m->stripe_subscription_id ) : new WP_Error( 'subscription_missing', __( 'Stripe契約情報がありません。', 'picot-subscription-membership' ) );
		$result       = is_wp_error( $subscription ) ? $subscription : Picot_Subscription_Membership_Membership::sync_subscription( $subscription, $m->user_id );
		$message      = is_wp_error( $result ) ? $result->get_error_message() : __( 'Stripeと同期しました。', 'picot-subscription-membership' );
		wp_safe_redirect( self::detail_url( $id, $message, is_wp_error( $result ) ? 'error' : 'success' ) );
		exit; }
	public static function sync_all() {
		if ( ! current_user_can( 'manage_memberships' ) || ! check_admin_referer( 'psm_sync_all' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} $summary = Picot_Subscription_Membership_Membership::run_daily_sync();
		/* translators: 1: number of synchronized memberships, 2: number of failures, 3: number of expired memberships updated. */
		$message = sprintf( __( 'Stripe同期を実行しました。同期: %1$d件、失敗: %2$d件、期限切れ更新: %3$d件。', 'picot-subscription-membership' ), $summary['synced'], $summary['errors'], $summary['expired'] );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => 'psm-sync',
					'psm_notice'      => rawurlencode( $message ),
					'psm_notice_type' => $summary['errors'] ? 'error' : 'success',
				),
				admin_url( 'admin.php' )
			)
		);
		exit; }
	public static function toggle_access_revocation() {
		if ( ! current_user_can( 'manage_memberships' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} $id = absint( $_POST['id'] ?? 0 );
		if ( ! check_admin_referer( 'psm_toggle_access_revocation_' . $id ) ) {
			wp_die( esc_html__( '不正なリクエストです。', 'picot-subscription-membership' ) );
		} $m = Picot_Subscription_Membership_Membership::get_by_id( $id );
		if ( ! $m ) {
			wp_die( esc_html__( '会員情報が見つかりません。', 'picot-subscription-membership' ) );
		} $revoke = ! empty( $_POST['revoke'] );
		global $wpdb;
		$wpdb->update(
			Picot_Subscription_Membership_DB::table( 'memberships' ),
			array(
				'access_revoked_at' => $revoke ? current_time( 'mysql', true ) : null,
				'updated_at'        => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
		Picot_Subscription_Membership_DB::log( $revoke ? 'access_revoked' : 'access_reinstated', $revoke ? __( '管理者が利用を停止しました。', 'picot-subscription-membership' ) : __( '管理者が利用停止を解除しました。', 'picot-subscription-membership' ), $id, $m->user_id );
		wp_safe_redirect( self::detail_url( $id, $revoke ? __( '利用を停止しました。', 'picot-subscription-membership' ) : __( '利用停止を解除しました。', 'picot-subscription-membership' ) ) );
		exit; }
	public static function logs() {
		if ( ! current_user_can( 'manage_memberships' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} global $wpdb;
		$rows = $wpdb->get_results( 'SELECT l.*,u.user_email FROM ' . Picot_Subscription_Membership_DB::table( 'logs' ) . ' l LEFT JOIN ' . $wpdb->users . ' u ON l.user_id=u.ID ORDER BY l.created_at DESC LIMIT 300' );
		self::table( 'ログ', array( '種別', '会員', 'メッセージ', '日時' ), array_map( fn( $row ) => array( esc_html( $row->log_type ), esc_html( self::value_or_dash( $row->user_email ) ), esc_html( $row->message ), esc_html( $row->created_at ) ), $rows ) ); }
	public static function payments() {
		if ( ! current_user_can( 'view_membership_payments' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} global $wpdb;
		$rows = $wpdb->get_results( 'SELECT p.*,u.user_email FROM ' . Picot_Subscription_Membership_DB::table( 'payments' ) . ' p LEFT JOIN ' . $wpdb->users . ' u ON p.user_id=u.ID ORDER BY p.created_at DESC LIMIT 200' );
		self::table( '決済履歴', array( 'メール', 'Invoice', '金額', '状態', '決済日時' ), array_map( fn( $r ) => array( esc_html( $r->user_email ), esc_html( $r->stripe_invoice_id ), esc_html( number_format_i18n( $r->amount ) . ' ' . $r->currency ), esc_html( $r->status ), esc_html( $r->paid_at ) ), $rows ) ); }
	public static function webhooks() {
		if ( ! current_user_can( 'manage_membership_webhooks' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . Picot_Subscription_Membership_DB::table( 'webhook_events' ) . ' ORDER BY received_at DESC LIMIT 200' );
		self::table( 'Webhookログ', array( 'Event ID', '種別', '状態', '受信日時', 'エラー' ), array_map( fn( $r ) => array( esc_html( $r->stripe_event_id ), esc_html( $r->event_type ), esc_html( $r->status ), esc_html( $r->received_at ), esc_html( $r->error_message ) ), $rows ) ); }
	public static function adjust() {
		if ( ! current_user_can( 'manage_membership_periods' ) || ! check_admin_referer( 'psm_adjust' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) );
		} $raw_days = $_POST['days'] ?? '';
		$raw_reason = $_POST['reason'] ?? '';
		$days       = (float) ( is_scalar( $raw_days ) ? sanitize_text_field( wp_unslash( $raw_days ) ) : 0 );
		if ( ! is_finite( $days ) || 0.0 === $days || abs( $days ) > 36500 ) {
			wp_safe_redirect( add_query_arg( 'psm_notice', rawurlencode( __( '延長日数は-36500日から36500日の範囲で指定してください。', 'picot-subscription-membership' ) ), admin_url( 'admin.php?page=psm-members' ) ) );
			exit;
		} Picot_Subscription_Membership_Membership::adjust( absint( $_POST['id'] ?? 0 ), (int) round( $days * DAY_IN_SECONDS ), is_scalar( $raw_reason ) ? sanitize_textarea_field( wp_unslash( $raw_reason ) ) : '', get_current_user_id() );
		wp_safe_redirect( admin_url( 'admin.php?page=psm-members' ) );
		exit; }
}
