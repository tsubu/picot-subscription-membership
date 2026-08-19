<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queries use plugin-owned, prefixed tables and return current membership data.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers come only from Picot_Subscription_Membership_DB::table(); all query values use placeholders.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- State-changing checkout and registration handlers verify their dedicated nonces.
final class Picot_Subscription_Membership_Shortcodes {
	public static function init() {
		foreach ( array( '_wpnonce', 'psm_checkout_price', 'psm_email', 'psm_display_name', 'psm_password', 'psm_website', 'psm_terms', 'psm_privacy', 'psm_account_profile', 'psm_account_password', 'psm_account_display_name', 'psm_account_email', 'psm_account_current_password', 'psm_account_new_password', 'psm_account_confirm_password' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ! is_scalar( $_POST[ $key ] ) ) {
				unset( $_POST[ $key ] ); }
		}
		foreach ( array( 'psm_checkout_error', 'psm_portal_error', 'psm_register_error', 'psm_account_notice', 'psm_account_notice_type' ) as $key ) {
			if ( isset( $_GET[ $key ] ) && ! is_scalar( $_GET[ $key ] ) ) {
				unset( $_GET[ $key ] ); }
		}
		if ( isset( $_SERVER['REMOTE_ADDR'] ) && ! is_scalar( $_SERVER['REMOTE_ADDR'] ) ) {
			unset( $_SERVER['REMOTE_ADDR'] ); }
		add_shortcode( 'membership_content', array( __CLASS__, 'content' ) );
		add_shortcode( 'membership_account', array( __CLASS__, 'account' ) );
		add_shortcode( 'membership_plans', array( __CLASS__, 'plans' ) );
		add_shortcode( 'membership_login', array( __CLASS__, 'login' ) );
		add_shortcode( 'membership_register', array( __CLASS__, 'register' ) );
	}

	/** Return a public policy or contact-page link, or an empty string when unavailable. */
	private static function public_page_link( $setting_key, $label ) {
		$settings = Picot_Subscription_Membership_Stripe_Gateway::settings();
		$page_id  = absint( $settings[ $setting_key ] ?? 0 );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return ''; }
		$url = get_permalink( $page_id );
		return $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>' : '';
	}

	/** Return the policy and support links displayed before a membership purchase. */
	public static function policy_links() {
		$links = array_filter(
			array(
				self::public_page_link( 'terms_page_id', __( '利用規約', 'picot-subscription-membership' ) ),
				self::public_page_link( 'privacy_page_id', __( 'プライバシーポリシー', 'picot-subscription-membership' ) ),
				self::public_page_link( 'subscription_policy_page_id', __( '解約・返金ポリシー', 'picot-subscription-membership' ) ),
				self::public_page_link( 'contact_page_id', __( 'お問い合わせ', 'picot-subscription-membership' ) ),
			)
		);
		if ( ! $links ) {
			return ''; }
		return '<aside class="psm-policy-links"><h3>' . esc_html__( 'お申し込み前にご確認ください', 'picot-subscription-membership' ) . '</h3><ul><li>' . implode( '</li><li>', $links ) . '</li></ul></aside>';
	}
	public static function clear_canceled_subscription_checkout() {
		$value = $_GET['membership'] ?? '';
		if ( is_user_logged_in() && is_scalar( $value ) && 'cancel' === sanitize_key( wp_unslash( $value ) ) ) {
			Picot_Subscription_Membership_Stripe_Gateway::clear_pending_subscription_checkout( get_current_user_id() ); } }
	public static function content( $atts, $content = '' ) {
		$a     = shortcode_atts(
			array(
				'plans' => '',
				'mode'  => 'paid',
			),
			$atts
		);
		$plans = array_filter( array_map( 'trim', explode( ',', $a['plans'] ) ) );
		return Picot_Subscription_Membership_Content::user_can_access( 0, 0, $plans, $a['mode'] ) ? do_shortcode( $content ) : Picot_Subscription_Membership_Content::restricted_message(); }
	public static function account() {
		if ( ! is_user_logged_in() ) {
			return self::login(); }
		$user       = wp_get_current_user();
		$membership = Picot_Subscription_Membership_Membership::get_for_user( $user->ID );
		$notice     = self::account_notice();
		$html       = '<div class="psm-account"><h2>' . esc_html__( 'マイページ', 'picot-subscription-membership' ) . '</h2>' . $notice;
		$html      .= '<section class="psm-account-profile"><h3>' . esc_html__( 'アカウント情報', 'picot-subscription-membership' ) . '</h3><form method="post"><p><label>' . esc_html__( '表示名', 'picot-subscription-membership' ) . '<input required type="text" name="psm_account_display_name" value="' . esc_attr( $user->display_name ) . '" autocomplete="name"></label></p><p><label>' . esc_html__( 'メールアドレス', 'picot-subscription-membership' ) . '<input required type="email" name="psm_account_email" value="' . esc_attr( $user->user_email ) . '" autocomplete="email"></label></p><input type="hidden" name="psm_account_profile" value="1">' . wp_nonce_field( 'psm_account_profile', '_wpnonce', true, false ) . '<button class="wp-element-button psm-account__button" type="submit">' . esc_html__( 'アカウント情報を保存', 'picot-subscription-membership' ) . '</button></form></section>';
		$html      .= '<section class="psm-account-password"><h3>' . esc_html__( 'パスワードを変更', 'picot-subscription-membership' ) . '</h3><form method="post"><p><label>' . esc_html__( '現在のパスワード', 'picot-subscription-membership' ) . '<input required type="password" name="psm_account_current_password" autocomplete="current-password"></label></p><p><label>' . esc_html__( '新しいパスワード', 'picot-subscription-membership' ) . '<input required type="password" name="psm_account_new_password" minlength="8" autocomplete="new-password"></label></p><p><label>' . esc_html__( '新しいパスワード（確認）', 'picot-subscription-membership' ) . '<input required type="password" name="psm_account_confirm_password" minlength="8" autocomplete="new-password"></label></p><input type="hidden" name="psm_account_password" value="1">' . wp_nonce_field( 'psm_account_password', '_wpnonce', true, false ) . '<button class="wp-element-button psm-account__button" type="submit">' . esc_html__( 'パスワードを更新', 'picot-subscription-membership' ) . '</button></form></section>';
		if ( $membership ) {
			global $wpdb;
			$plan_name = $membership->plan_id ? $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' WHERE id = %d', $membership->plan_id ) ) : '';
			$status    = $membership->cancel_at_period_end ? __( '解約予約済み', 'picot-subscription-membership' ) : $membership->membership_status;
			$plan_name = '' !== $plan_name ? $plan_name : '—';
			$renewal   = $membership->cancel_at_period_end ? '—' : $membership->stripe_period_end;
			$renewal   = '' !== $renewal ? $renewal : '—';
			$access    = '' !== $membership->effective_access_until ? $membership->effective_access_until : '—';
			$html     .= '<section id="psm-membership-management" class="psm-account-membership"><h3>' . esc_html__( '会員情報', 'picot-subscription-membership' ) . '</h3><p><strong>' . esc_html__( '現在のプラン', 'picot-subscription-membership' ) . '</strong> ' . esc_html( $plan_name ) . '<br><strong>' . esc_html__( '契約状態', 'picot-subscription-membership' ) . '</strong> ' . esc_html( $status ) . '<br><strong>' . esc_html__( '次回更新', 'picot-subscription-membership' ) . '</strong> ' . esc_html( $renewal ) . '<br><strong>' . esc_html__( '利用可能期限', 'picot-subscription-membership' ) . '</strong> ' . esc_html( $access ) . '<br><strong>' . esc_html__( '運営特典延長', 'picot-subscription-membership' ) . '</strong> ' . esc_html( number_format_i18n( $membership->manual_extension_seconds / DAY_IN_SECONDS, 2 ) . __( '日', 'picot-subscription-membership' ) ) . '</p>';
			if ( $membership->cancel_at_period_end ) {
				$html .= '<p>' . esc_html__( '現在の契約期間終了まで利用できます。', 'picot-subscription-membership' ) . '</p>'; }
			if ( $membership->stripe_customer_id && Picot_Subscription_Membership_Stripe_Gateway::settings()['portal_enabled'] ) {
				$html .= '<p>' . esc_html__( 'プランの変更・解約、支払い方法の変更はStripe Customer Portalで行えます。', 'picot-subscription-membership' ) . '</p><form method="post"><input type="hidden" name="psm_portal" value="1">' . wp_nonce_field( 'psm_portal', '_wpnonce', true, false ) . '<button class="wp-element-button psm-account__button" type="submit">' . esc_html__( 'プラン・支払い方法を管理', 'picot-subscription-membership' ) . '</button></form>';
			} else {
				$html .= '<p>' . esc_html__( 'プラン変更と支払い方法の管理は、現在利用できません。サイト管理者にお問い合わせください。', 'picot-subscription-membership' ) . '</p>';
			}
			$html .= '</section>';
		}
		$contact_link = self::public_page_link( 'contact_page_id', __( 'お問い合わせ', 'picot-subscription-membership' ) );
		if ( $contact_link ) {
			/* translators: %s: a link to the support contact page. */
			$contact_message = sprintf( __( 'サポートが必要な場合は%sをご利用ください。', 'picot-subscription-membership' ), $contact_link );
			$html           .= '<p class="psm-account-support">' . wp_kses( $contact_message, array( 'a' => array( 'href' => array() ) ) ) . '</p>';
		}
		global $wpdb;
		$purchases = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, purchased_at FROM ' . Picot_Subscription_Membership_DB::table( 'purchases' ) . ' WHERE user_id = %d AND status = %s ORDER BY purchased_at DESC LIMIT 20', $user->ID, 'paid' ) );
		if ( $purchases ) {
			$purchase_items = '';
			foreach ( $purchases as $purchase ) {
				$post = get_post( $purchase->post_id );
				if ( ! $post || 'publish' !== $post->post_status ) {
					continue; }
				/* translators: %s: article purchase date. */
				$purchased_on    = sprintf( __( '購入日: %s', 'picot-subscription-membership' ), get_date_from_gmt( $purchase->purchased_at, get_option( 'date_format' ) ) );
				$purchase_items .= '<li><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a><br><small>' . esc_html( $purchased_on ) . '</small></li>';
			}
			if ( $purchase_items ) {
				$html .= '<section class="psm-account-purchases"><h3>' . esc_html__( '購入済み記事', 'picot-subscription-membership' ) . '</h3><ul>' . $purchase_items . '</ul></section>'; }
		}
		return $html . '</div>';
	}

	/** Return the account-action notice for the currently viewed page. */
	private static function account_notice() {
			$raw_notice = $_GET['psm_account_notice'] ?? '';
		$portal_error   = false;
		if ( ! is_scalar( $raw_notice ) || '' === $raw_notice ) {
				$raw_notice = $_GET['psm_portal_error'] ?? '';
			$portal_error   = true;
		}
		if ( ! is_scalar( $raw_notice ) || '' === $raw_notice ) {
			return ''; }
		$raw_type = $_GET['psm_account_notice_type'] ?? '';
		$type     = $portal_error || ( is_scalar( $raw_type ) && 'error' === sanitize_key( wp_unslash( $raw_type ) ) ) ? 'psm-error' : 'psm-success';
		return '<p class="' . esc_attr( $type ) . '" role="status">' . esc_html( sanitize_text_field( wp_unslash( $raw_notice ) ) ) . '</p>';
	}

	/** Handle front-end account profile and password updates. */
	public static function handle_account_actions() {
		if ( ! is_user_logged_in() || ( ! isset( $_POST['psm_account_profile'] ) && ! isset( $_POST['psm_account_password'] ) ) ) {
			return; }
		$return = wp_validate_redirect( wp_get_referer(), home_url( '/' ) );
		$user   = wp_get_current_user();
		$nonce  = isset( $_POST['_wpnonce'] ) && is_scalar( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( isset( $_POST['psm_account_profile'] ) ) {
			if ( ! wp_verify_nonce( $nonce, 'psm_account_profile' ) ) {
				self::redirect_account_notice( $return, __( '不正なリクエストです。', 'picot-subscription-membership' ), 'error' ); }
			$name  = isset( $_POST['psm_account_display_name'] ) && is_scalar( $_POST['psm_account_display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['psm_account_display_name'] ) ) : '';
			$email = isset( $_POST['psm_account_email'] ) && is_scalar( $_POST['psm_account_email'] ) ? sanitize_email( wp_unslash( $_POST['psm_account_email'] ) ) : '';
			if ( '' === $name || ! is_email( $email ) ) {
				self::redirect_account_notice( $return, __( '表示名と有効なメールアドレスを入力してください。', 'picot-subscription-membership' ), 'error' ); }
			$existing = get_user_by( 'email', $email );
			if ( $existing && (int) $existing->ID !== (int) $user->ID ) {
				self::redirect_account_notice( $return, __( 'このメールアドレスはすでに使用されています。', 'picot-subscription-membership' ), 'error' ); }
			$updated = wp_update_user(
				array(
					'ID'           => $user->ID,
					'display_name' => $name,
					'user_email'   => $email,
				)
			);
			if ( is_wp_error( $updated ) ) {
				self::redirect_account_notice( $return, $updated->get_error_message(), 'error' ); }
			self::redirect_account_notice( $return, __( 'アカウント情報を更新しました。', 'picot-subscription-membership' ) );
		}
		if ( ! wp_verify_nonce( $nonce, 'psm_account_password' ) ) {
			self::redirect_account_notice( $return, __( '不正なリクエストです。', 'picot-subscription-membership' ), 'error' ); }
		$current = isset( $_POST['psm_account_current_password'] ) && is_string( $_POST['psm_account_current_password'] ) ? wp_unslash( $_POST['psm_account_current_password'] ) : '';
		$new     = isset( $_POST['psm_account_new_password'] ) && is_string( $_POST['psm_account_new_password'] ) ? wp_unslash( $_POST['psm_account_new_password'] ) : '';
		$confirm = isset( $_POST['psm_account_confirm_password'] ) && is_string( $_POST['psm_account_confirm_password'] ) ? wp_unslash( $_POST['psm_account_confirm_password'] ) : '';
		if ( ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			self::redirect_account_notice( $return, __( '現在のパスワードが正しくありません。', 'picot-subscription-membership' ), 'error' ); }
		if ( strlen( $new ) < 8 ) {
			self::redirect_account_notice( $return, __( '新しいパスワードは8文字以上で入力してください。', 'picot-subscription-membership' ), 'error' ); }
		if ( $new !== $confirm ) {
			self::redirect_account_notice( $return, __( '新しいパスワードが一致しません。', 'picot-subscription-membership' ), 'error' ); }
		wp_set_password( $new, $user->ID );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID );
		self::redirect_account_notice( $return, __( 'パスワードを更新しました。', 'picot-subscription-membership' ) );
	}

	/** Redirect back to the account page with a transient one-time notice. */
	private static function redirect_account_notice( $return_url, $message, $type = 'success' ) {
		wp_safe_redirect(
			add_query_arg(
				array(
					'psm_account_notice'      => rawurlencode( $message ),
					'psm_account_notice_type' => 'error' === $type ? 'error' : 'success',
				),
				$return_url
			)
		);
		exit;
	}
	public static function plans() {
		global $wpdb;
		$currency         = Picot_Subscription_Membership_Stripe_Gateway::current_currency();
		$rows             = $wpdb->get_results( $wpdb->prepare( 'SELECT p.name,p.description,pr.id,pr.billing_interval,pr.amount,pr.currency FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' p JOIN ' . Picot_Subscription_Membership_DB::table( 'prices' ) . ' pr ON p.id = pr.plan_id WHERE p.active = 1 AND pr.active = 1 AND pr.currency = %s ORDER BY p.sort_order,pr.amount', $currency ) );
		$error            = isset( $_GET['psm_checkout_error'] ) ? '<p class="psm-error" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['psm_checkout_error'] ) ) ) . '</p>' : '';
		$policies_ready   = Picot_Subscription_Membership_Stripe_Gateway::has_required_policy_pages();
		$price_tax_notice = Picot_Subscription_Membership_Stripe_Gateway::price_tax_notice();
		$html             = '<div class="psm-plans">' . $error . self::policy_links() . ( $price_tax_notice ? '<p class="psm-price-tax-notice">' . esc_html( $price_tax_notice ) . '</p>' : '' );
		foreach ( $rows as $row ) {
			$html .= '<article class="psm-plans__plan"><h3>' . esc_html( $row->name ) . '</h3><p>' . wp_kses_post( $row->description ) . '</p><p class="psm-plans__price">' . esc_html( Picot_Subscription_Membership_Stripe_Gateway::display_amount( $row->amount, $row->currency ) . ' / ' . $row->billing_interval ) . '</p>';
			if ( ! is_user_logged_in() ) {
				$html .= '<p>' . esc_html__( 'ログイン後にお申し込みいただけます。', 'picot-subscription-membership' ) . '</p>';
			} elseif ( ! $policies_ready ) {
				$html .= '<p class="psm-error">' . esc_html__( '利用規約、プライバシーポリシー、解約・返金ポリシーのページを設定してから決済を開始してください。', 'picot-subscription-membership' ) . '</p>';
			} else {
				$html .= '<form method="post"><input type="hidden" name="psm_checkout_price" value="' . esc_attr( $row->id ) . '">' . wp_nonce_field( 'psm_checkout', '_wpnonce', true, false ) . '<button class="wp-element-button psm-plans__button" type="submit">' . esc_html__( '申し込む', 'picot-subscription-membership' ) . '</button></form>';
			}
			$html .= '</article>';
		}
		return $rows ? $html . '</div>' : $html . '<p>' . esc_html__( '現在の表示言語用の料金プランはまだ設定されていません。', 'picot-subscription-membership' ) . '</p></div>';
	}

	public static function login() {
		return '<div class="psm-login">' . wp_login_form(
			array(
				'echo'     => false,
				'remember' => true,
			)
		) . '<p class="psm-login__help"><a href="' . esc_url( wp_lostpassword_url() ) . '">' . esc_html__( 'パスワードをお忘れですか？', 'picot-subscription-membership' ) . '</a></p></div>';
	}

	public static function register() {
		if ( is_user_logged_in() ) {
			return '<p>' . esc_html__( 'すでにログインしています。', 'picot-subscription-membership' ) . '</p>'; }
		if ( ! Picot_Subscription_Membership_Stripe_Gateway::has_required_policy_pages() ) {
			return '<div class="psm-register-wrap"><p class="psm-error" role="alert">' . esc_html__( '会員登録を開始するには、利用規約、プライバシーポリシー、解約・返金ポリシーのページを設定してください。', 'picot-subscription-membership' ) . '</p>' . self::policy_links() . '</div>'; }
		$error        = isset( $_GET['psm_register_error'] ) ? '<p class="psm-error" role="alert">' . esc_html( sanitize_text_field( wp_unslash( $_GET['psm_register_error'] ) ) ) . '</p>' : '';
		$terms_link   = self::public_page_link( 'terms_page_id', __( '利用規約', 'picot-subscription-membership' ) );
		$privacy_link = self::public_page_link( 'privacy_page_id', __( 'プライバシーポリシー', 'picot-subscription-membership' ) );
		/* translators: %s: a link to the Terms of Service page. */
		$terms_consent = sprintf( __( '%sに同意する', 'picot-subscription-membership' ), $terms_link );
		/* translators: %s: a link to the Privacy Policy page. */
		$privacy_consent   = sprintf( __( '%sに同意する', 'picot-subscription-membership' ), $privacy_link );
		$allowed_link_html = array( 'a' => array( 'href' => array() ) );
		return '<div class="psm-register-wrap">' . $error . self::policy_links() . '<form method="post" class="psm-register"><p class="psm-form-field"><label>' . esc_html__( 'メールアドレス', 'picot-subscription-membership' ) . '<input required type="email" name="psm_email" autocomplete="email"></label></p><p class="psm-form-field"><label>' . esc_html__( '表示名', 'picot-subscription-membership' ) . '<input required type="text" name="psm_display_name" autocomplete="name"></label></p><p class="psm-form-field"><label>' . esc_html__( 'パスワード', 'picot-subscription-membership' ) . '<input required type="password" name="psm_password" minlength="8" autocomplete="new-password"></label></p><p class="psm-honeypot" aria-hidden="true"><label>' . esc_html__( 'Webサイト', 'picot-subscription-membership' ) . '<input type="text" name="psm_website" tabindex="-1" autocomplete="off"></label></p><p class="psm-register__consent"><label><input required type="checkbox" name="psm_terms" value="1"> ' . wp_kses( $terms_consent, $allowed_link_html ) . '</label></p><p class="psm-register__consent"><label><input required type="checkbox" name="psm_privacy" value="1"> ' . wp_kses( $privacy_consent, $allowed_link_html ) . '</label></p><input type="hidden" name="psm_register" value="1">' . wp_nonce_field( 'psm_register', '_wpnonce', true, false ) . '<button class="wp-element-button psm-register__button" type="submit">' . esc_html__( '会員登録', 'picot-subscription-membership' ) . '</button></form></div>';
	}
}
add_action( 'template_redirect', array( 'Picot_Subscription_Membership_Shortcodes', 'clear_canceled_subscription_checkout' ), 1 );
add_action( 'template_redirect', array( 'Picot_Subscription_Membership_Shortcodes', 'handle_account_actions' ), 2 );
add_action(
	'template_redirect',
	function () {
		$return = wp_validate_redirect( wp_get_referer(), home_url( '/' ) );
		if ( isset( $_POST['psm_register'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'psm_register' ) && ! is_user_logged_in() ) {
			$raw_email    = $_POST['psm_email'] ?? '';
			$raw_name     = $_POST['psm_display_name'] ?? '';
			$raw_password = $_POST['psm_password'] ?? '';
			$raw_honeypot = $_POST['psm_website'] ?? '';
			$email        = is_scalar( $raw_email ) ? sanitize_email( wp_unslash( $raw_email ) ) : '';
			$name         = is_scalar( $raw_name ) ? sanitize_text_field( wp_unslash( $raw_name ) ) : '';
			$password     = is_string( $raw_password ) ? wp_unslash( $raw_password ) : '';
			$honeypot     = is_scalar( $raw_honeypot ) ? sanitize_text_field( wp_unslash( $raw_honeypot ) ) : 'invalid';
			$terms        = isset( $_POST['psm_terms'] ) && is_scalar( $_POST['psm_terms'] ) && '1' === $_POST['psm_terms'];
			$privacy      = isset( $_POST['psm_privacy'] ) && is_scalar( $_POST['psm_privacy'] ) && '1' === $_POST['psm_privacy'];
			if ( ! $email || ! is_email( $email ) || ! $name || strlen( $password ) < 8 || ! $terms || ! $privacy || $honeypot ) {
				wp_safe_redirect( add_query_arg( 'psm_register_error', rawurlencode( __( '入力内容を確認してください。', 'picot-subscription-membership' ) ), $return ) );
				exit;
			} if ( ! Picot_Subscription_Membership_Stripe_Gateway::has_required_policy_pages() ) {
				wp_safe_redirect( add_query_arg( 'psm_register_error', rawurlencode( __( '会員登録を開始するには、利用規約、プライバシーポリシー、解約・返金ポリシーのページを設定してください。', 'picot-subscription-membership' ) ), $return ) );
				exit;
			} $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$rate_key = 'psm_register_rate_' . md5( $ip );
				$attempts = (int) get_transient( $rate_key );
				if ( $attempts >= 5 ) {
						wp_safe_redirect( add_query_arg( 'psm_register_error', rawurlencode( __( 'しばらくしてからもう一度お試しください。', 'picot-subscription-membership' ) ), $return ) );
					exit;
				} set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );
			} $user_id = wp_insert_user(
				array(
					'user_login'   => $email,
					'user_email'   => $email,
					'display_name' => $name,
					'user_pass'    => $password,
					'role'         => 'subscriber',
				)
			);
			if ( is_wp_error( $user_id ) ) {
					wp_safe_redirect( add_query_arg( 'psm_register_error', rawurlencode( $user_id->get_error_message() ), $return ) );
				exit;
			} $policy_ids = Picot_Subscription_Membership_Stripe_Gateway::required_policy_page_ids();
			update_user_meta( $user_id, '_psm_terms_accepted_at', current_time( 'mysql', true ) );
			update_user_meta( $user_id, '_psm_terms_page_id', $policy_ids['terms'] );
			update_user_meta( $user_id, '_psm_terms_page_modified_gmt', get_post_modified_time( 'c', true, $policy_ids['terms'] ) );
			update_user_meta( $user_id, '_psm_privacy_accepted_at', current_time( 'mysql', true ) );
			update_user_meta( $user_id, '_psm_privacy_page_id', $policy_ids['privacy'] );
			update_user_meta( $user_id, '_psm_privacy_page_modified_gmt', get_post_modified_time( 'c', true, $policy_ids['privacy'] ) );
			wp_set_current_user( $user_id );
			wp_set_auth_cookie( $user_id );
			wp_safe_redirect( $return );
			exit;
		} if ( isset( $_POST['psm_checkout_price'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'psm_checkout' ) && is_user_logged_in() ) {
			$s = Picot_Subscription_Membership_Stripe_Gateway::create_checkout( get_current_user_id(), absint( $_POST['psm_checkout_price'] ), home_url( '/' ), $return );
			if ( ! is_wp_error( $s ) ) {
				wp_safe_redirect( $s['url'] );
				exit;
			} wp_safe_redirect( add_query_arg( 'psm_checkout_error', rawurlencode( $s->get_error_message() ), $return ) );
			exit;
		} if ( isset( $_POST['psm_portal'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'psm_portal' ) && is_user_logged_in() ) {
			$s = Picot_Subscription_Membership_Stripe_Gateway::create_portal( get_current_user_id(), $return );
			if ( ! is_wp_error( $s ) ) {
				wp_safe_redirect( $s['url'] );
				exit;
			} wp_safe_redirect( add_query_arg( 'psm_portal_error', rawurlencode( $s->get_error_message() ), $return ) );
			exit;
		} }
);
