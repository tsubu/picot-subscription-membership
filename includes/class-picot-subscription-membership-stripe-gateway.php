<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queries use plugin-owned, prefixed tables and current payment data.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers come only from Picot_Subscription_Membership_DB::table(); all query values use placeholders.

final class Picot_Subscription_Membership_Stripe_Gateway {
	public static function allow_redirect_hosts( $hosts ) {
		return array_unique( array_merge( (array) $hosts, array( 'checkout.stripe.com', 'billing.stripe.com' ) ) ); }
	public static function settings() {
		return wp_parse_args(
			get_option( 'psm_settings', array() ),
			array(
				'mode'                        => 'test',
				'locale'                      => 'ja_JP',
				'currency'                    => 'jpy',
				'grace_days'                  => 0,
				'portal_enabled'              => 1,
				'terms_page_id'               => 0,
				'privacy_page_id'             => (int) get_option( 'wp_page_for_privacy_policy', 0 ),
				'subscription_policy_page_id' => 0,
				'contact_page_id'             => 0,
				'price_tax_notice'            => '',
			)
		); }
	public static function required_policy_page_ids() {
		$settings = self::settings();
		return array(
			'terms'        => absint( $settings['terms_page_id'] ?? 0 ),
			'privacy'      => absint( $settings['privacy_page_id'] ?? 0 ),
			'subscription' => absint( $settings['subscription_policy_page_id'] ?? 0 ),
		); }
	public static function has_required_policy_pages() {
		foreach ( self::required_policy_page_ids() as $page_id ) {
			if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
				return false;
			}
		} return true; }
	public static function price_tax_notice() {
		return sanitize_textarea_field( self::settings()['price_tax_notice'] ?? '' ); }
	public static function is_live_mode() {
		return 'live' === self::settings()['mode']; }
	public static function site_uses_https() {
		return 'https' === strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) ); }
	private static function live_mode_environment_error() {
		return self::is_live_mode() && ! self::site_uses_https() ? new WP_Error( 'live_requires_https', __( 'Stripe Liveモードでは、サイトをHTTPSで公開してから決済機能を利用してください。', 'picot-subscription-membership' ) ) : null; }
	private static function secret_key() {
		$s = self::settings();
		return trim( ( 'live' === $s['mode'] ? ( $s['live_secret_key'] ?? '' ) : ( $s['test_secret_key'] ?? '' ) ) ); }
	private static function pending_subscription_checkout_key( $user_id ) {
		return 'psm_pending_subscription_checkout_' . absint( $user_id ); }
	public static function clear_pending_subscription_checkout( $user_id ) {
		delete_transient( self::pending_subscription_checkout_key( $user_id ) ); }
	private static function pending_article_purchase_key( $user_id, $post_id ) {
		return 'psm_pending_article_purchase_' . absint( $user_id ) . '_' . absint( $post_id ); }
	public static function clear_pending_article_purchase( $user_id, $post_id ) {
		delete_transient( self::pending_article_purchase_key( $user_id, $post_id ) ); }
	public static function supported_currencies() {
		return array( 'usd', 'aed', 'afn', 'all', 'amd', 'ang', 'aoa', 'ars', 'aud', 'awg', 'azn', 'bam', 'bbd', 'bdt', 'bgn', 'bif', 'bmd', 'bnd', 'bob', 'brl', 'bsd', 'bwp', 'byn', 'bzd', 'cad', 'cdf', 'chf', 'clp', 'cny', 'cop', 'crc', 'cve', 'czk', 'djf', 'dkk', 'dop', 'dzd', 'egp', 'etb', 'eur', 'fjd', 'fkp', 'gbp', 'gel', 'gip', 'gmd', 'gnf', 'gtq', 'gyd', 'hkd', 'hnl', 'htg', 'huf', 'idr', 'ils', 'inr', 'isk', 'jmd', 'jpy', 'kes', 'kgs', 'khr', 'kmf', 'krw', 'kyd', 'kzt', 'lak', 'lbp', 'lkr', 'lrd', 'lsl', 'mad', 'mdl', 'mga', 'mkd', 'mmk', 'mnt', 'mop', 'mur', 'mvr', 'mwk', 'mxn', 'myr', 'mzn', 'nad', 'ngn', 'nio', 'nok', 'npr', 'nzd', 'pab', 'pen', 'pgk', 'php', 'pkr', 'pln', 'pyg', 'qar', 'ron', 'rsd', 'rub', 'rwf', 'sar', 'sbd', 'scr', 'sek', 'sgd', 'shp', 'sle', 'sos', 'srd', 'std', 'szl', 'thb', 'tjs', 'top', 'try', 'ttd', 'twd', 'tzs', 'uah', 'ugx', 'uyu', 'uzs', 'vnd', 'vuv', 'wst', 'xaf', 'xcd', 'xcg', 'xof', 'xpf', 'yer', 'zar', 'zmw' ); }
	public static function zero_decimal_currencies() {
		return array( 'bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf' ); }
	public static function currency_is_zero_decimal( $currency ) {
		return in_array( strtolower( $currency ), self::zero_decimal_currencies(), true ); }
	/**
	 * Return currencies that Stripe represents in minor units but only permits as whole amounts.
	 *
	 * Stripe requires ISK and UGX values such as 5.00 to be submitted as 500.
	 *
	 * @param string $currency ISO 4217 currency code.
	 * @return bool
	 */
	public static function currency_requires_whole_unit_amount( $currency ) {
		return in_array( strtolower( $currency ), array( 'isk', 'ugx' ), true ); }
	/**
	 * Return whether a currency should be entered and displayed as a whole unit.
	 *
	 * @param string $currency ISO 4217 currency code.
	 * @return bool
	 */
	public static function currency_uses_integer_display( $currency ) {
		return self::currency_is_zero_decimal( $currency ) || self::currency_requires_whole_unit_amount( $currency ); }
	/** Return the primary country or regional flag used in currency selectors. */
	public static function currency_flag( $currency ) {
		$flags = array(
			'usd' => '🇺🇸',
			'aed' => '🇦🇪',
			'afn' => '🇦🇫',
			'all' => '🇦🇱',
			'amd' => '🇦🇲',
			'ang' => '🇨🇼',
			'aoa' => '🇦🇴',
			'ars' => '🇦🇷',
			'aud' => '🇦🇺',
			'awg' => '🇦🇼',
			'azn' => '🇦🇿',
			'bam' => '🇧🇦',
			'bbd' => '🇧🇧',
			'bdt' => '🇧🇩',
			'bgn' => '🇧🇬',
			'bif' => '🇧🇮',
			'bmd' => '🇧🇲',
			'bnd' => '🇧🇳',
			'bob' => '🇧🇴',
			'brl' => '🇧🇷',
			'bsd' => '🇧🇸',
			'bwp' => '🇧🇼',
			'byn' => '🇧🇾',
			'bzd' => '🇧🇿',
			'cad' => '🇨🇦',
			'cdf' => '🇨🇩',
			'chf' => '🇨🇭',
			'clp' => '🇨🇱',
			'cny' => '🇨🇳',
			'cop' => '🇨🇴',
			'crc' => '🇨🇷',
			'cve' => '🇨🇻',
			'czk' => '🇨🇿',
			'djf' => '🇩🇯',
			'dkk' => '🇩🇰',
			'dop' => '🇩🇴',
			'dzd' => '🇩🇿',
			'egp' => '🇪🇬',
			'etb' => '🇪🇹',
			'eur' => '🇪🇺',
			'fjd' => '🇫🇯',
			'fkp' => '🇫🇰',
			'gbp' => '🇬🇧',
			'gel' => '🇬🇪',
			'gip' => '🇬🇮',
			'gmd' => '🇬🇲',
			'gnf' => '🇬🇳',
			'gtq' => '🇬🇹',
			'gyd' => '🇬🇾',
			'hkd' => '🇭🇰',
			'hnl' => '🇭🇳',
			'htg' => '🇭🇹',
			'huf' => '🇭🇺',
			'idr' => '🇮🇩',
			'ils' => '🇮🇱',
			'inr' => '🇮🇳',
			'isk' => '🇮🇸',
			'jmd' => '🇯🇲',
			'jpy' => '🇯🇵',
			'kes' => '🇰🇪',
			'kgs' => '🇰🇬',
			'khr' => '🇰🇭',
			'kmf' => '🇰🇲',
			'krw' => '🇰🇷',
			'kyd' => '🇰🇾',
			'kzt' => '🇰🇿',
			'lak' => '🇱🇦',
			'lbp' => '🇱🇧',
			'lkr' => '🇱🇰',
			'lrd' => '🇱🇷',
			'lsl' => '🇱🇸',
			'mad' => '🇲🇦',
			'mdl' => '🇲🇩',
			'mga' => '🇲🇬',
			'mkd' => '🇲🇰',
			'mmk' => '🇲🇲',
			'mnt' => '🇲🇳',
			'mop' => '🇲🇴',
			'mur' => '🇲🇺',
			'mvr' => '🇲🇻',
			'mwk' => '🇲🇼',
			'mxn' => '🇲🇽',
			'myr' => '🇲🇾',
			'mzn' => '🇲🇿',
			'nad' => '🇳🇦',
			'ngn' => '🇳🇬',
			'nio' => '🇳🇮',
			'nok' => '🇳🇴',
			'npr' => '🇳🇵',
			'nzd' => '🇳🇿',
			'pab' => '🇵🇦',
			'pen' => '🇵🇪',
			'pgk' => '🇵🇬',
			'php' => '🇵🇭',
			'pkr' => '🇵🇰',
			'pln' => '🇵🇱',
			'pyg' => '🇵🇾',
			'qar' => '🇶🇦',
			'ron' => '🇷🇴',
			'rsd' => '🇷🇸',
			'rub' => '🇷🇺',
			'rwf' => '🇷🇼',
			'sar' => '🇸🇦',
			'sbd' => '🇸🇧',
			'scr' => '🇸🇨',
			'sek' => '🇸🇪',
			'sgd' => '🇸🇬',
			'shp' => '🇸🇭',
			'sle' => '🇸🇱',
			'sos' => '🇸🇴',
			'srd' => '🇸🇷',
			'std' => '🇸🇹',
			'szl' => '🇸🇿',
			'thb' => '🇹🇭',
			'tjs' => '🇹🇯',
			'top' => '🇹🇴',
			'try' => '🇹🇷',
			'ttd' => '🇹🇹',
			'twd' => '🇹🇼',
			'tzs' => '🇹🇿',
			'uah' => '🇺🇦',
			'ugx' => '🇺🇬',
			'uyu' => '🇺🇾',
			'uzs' => '🇺🇿',
			'vnd' => '🇻🇳',
			'vuv' => '🇻🇺',
			'wst' => '🇼🇸',
			'xcg' => '🇨🇼',
			'yer' => '🇾🇪',
			'zar' => '🇿🇦',
			'zmw' => '🇿🇲',
		);
		return $flags[ strtolower( $currency ) ] ?? '';
	}
	public static function currency_symbol( $currency ) {
		$symbols        = array(
			'aed' => 'د.إ',
			'afn' => '؋',
			'all' => 'L',
			'amd' => '֏',
			'ang' => 'ƒ',
			'aoa' => 'Kz',
			'ars' => '$',
			'aud' => 'A$',
			'awg' => 'ƒ',
			'azn' => '₼',
			'bam' => 'KM',
			'bbd' => 'Bds$',
			'bdt' => '৳',
			'bgn' => 'лв',
			'bif' => 'FBu',
			'bmd' => 'BD$',
			'bnd' => 'B$',
			'bob' => 'Bs.',
			'brl' => 'R$',
			'bsd' => 'B$',
			'bwp' => 'P',
			'byn' => 'Br',
			'bzd' => 'BZ$',
			'cad' => 'C$',
			'cdf' => 'FC',
			'chf' => 'CHF',
			'clp' => 'CLP$',
			'cny' => '¥',
			'cop' => 'COL$',
			'crc' => '₡',
			'cve' => 'Esc',
			'czk' => 'Kč',
			'djf' => 'Fdj',
			'dkk' => 'kr',
			'dop' => 'RD$',
			'dzd' => 'د.ج',
			'egp' => 'E£',
			'etb' => 'Br',
			'eur' => '€',
			'fjd' => 'FJ$',
			'fkp' => '£',
			'gbp' => '£',
			'gel' => '₾',
			'gip' => '£',
			'gmd' => 'D',
			'gnf' => 'FG',
			'gtq' => 'Q',
			'gyd' => 'G$',
			'hkd' => 'HK$',
			'hnl' => 'L',
			'htg' => 'G',
			'huf' => 'Ft',
			'idr' => 'Rp',
			'ils' => '₪',
			'inr' => '₹',
			'isk' => 'kr',
			'jmd' => 'J$',
			'jpy' => '¥',
			'kes' => 'KSh',
			'kgs' => 'сом',
			'khr' => '៛',
			'kmf' => 'CF',
			'krw' => '₩',
			'kyd' => 'CI$',
			'kzt' => '₸',
			'lak' => '₭',
			'lbp' => 'ل.ل',
			'lkr' => 'Rs',
			'lrd' => 'L$',
			'lsl' => 'L',
			'mad' => 'د.م.',
			'mdl' => 'L',
			'mga' => 'Ar',
			'mkd' => 'ден',
			'mmk' => 'K',
			'mnt' => '₮',
			'mop' => 'MOP$',
			'mur' => '₨',
			'mvr' => 'Rf',
			'mwk' => 'MK',
			'mxn' => 'MX$',
			'myr' => 'RM',
			'mzn' => 'MT',
			'nad' => 'N$',
			'ngn' => '₦',
			'nio' => 'C$',
			'nok' => 'kr',
			'npr' => 'रू',
			'nzd' => 'NZ$',
			'pab' => 'B/.',
			'pen' => 'S/',
			'pgk' => 'K',
			'php' => '₱',
			'pkr' => '₨',
			'pln' => 'zł',
			'pyg' => '₲',
			'qar' => 'ر.ق',
			'ron' => 'lei',
			'rsd' => 'дин.',
			'rub' => '₽',
			'rwf' => 'FRw',
			'sar' => '﷼',
			'sbd' => 'SI$',
			'scr' => '₨',
			'sek' => 'kr',
			'sgd' => 'S$',
			'shp' => '£',
			'sle' => 'Le',
			'sos' => 'Sh',
			'srd' => '$',
			'std' => 'Db',
			'szl' => 'E',
			'thb' => '฿',
			'tjs' => 'ЅМ',
			'top' => 'T$',
			'try' => '₺',
			'ttd' => 'TT$',
			'twd' => 'NT$',
			'tzs' => 'TSh',
			'uah' => '₴',
			'ugx' => 'USh',
			'uyu' => '$U',
			'uzs' => 'soʻm',
			'vnd' => '₫',
			'vuv' => 'VT',
			'wst' => 'WS$',
			'xaf' => 'FCFA',
			'xcd' => 'EC$',
			'xcg' => 'Cg',
			'xof' => 'CFA',
			'xpf' => '₣',
			'yer' => '﷼',
			'zar' => 'R',
			'zmw' => 'ZK',
		);
		$symbols['usd'] = '$';
		return $symbols[ strtolower( $currency ) ] ?? '';
	}
	public static function current_locale() {
		$locale = self::settings()['locale'];
		return class_exists( 'Picot_Subscription_Membership_I18n' ) && Picot_Subscription_Membership_I18n::is_available_locale( $locale ) ? $locale : 'ja_JP'; }
	public static function current_currency() {
		$currency = strtolower( self::settings()['currency'] );
		return in_array( $currency, self::supported_currencies(), true ) ? $currency : 'jpy'; }
	public static function currency_label( $currency ) {
		$currency = strtoupper( $currency );
		$flag     = self::currency_flag( $currency );
		$symbol   = self::currency_symbol( $currency );
		return ( $flag ? $flag . ' ' : '' ) . ( $symbol ? $currency . ' (' . $symbol . ')' : $currency ); }
	public static function normalize_amount( $value, $currency ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return 0; }
		if ( self::currency_is_zero_decimal( $currency ) ) {
			return ctype_digit( $value ) ? absint( $value ) : 0; }
		if ( self::currency_requires_whole_unit_amount( $currency ) ) {
			return ctype_digit( $value ) ? absint( $value ) * 100 : 0; }
		return preg_match( '/^\d+(?:\.\d{1,2})?$/', $value ) ? (int) round( (float) $value * 100 ) : 0;
	}
	/**
	 * Check a charge amount against the conservative card-payment maximum accepted by Stripe.
	 *
	 * @param int    $amount   Stripe minor-unit amount.
	 * @param string $currency ISO 4217 currency code.
	 * @return bool
	 */
	public static function is_valid_charge_amount( $amount, $currency ) {
		$amount = absint( $amount );
		return $amount > 0 && $amount <= 99999999 && ( ! self::currency_requires_whole_unit_amount( $currency ) || 0 === $amount % 100 );
	}
	public static function display_amount( $amount, $currency = '' ) {
		$currency  = $currency ? strtolower( $currency ) : self::current_currency();
		$formatted = number_format_i18n( self::currency_is_zero_decimal( $currency ) ? absint( $amount ) : absint( $amount ) / 100, self::currency_uses_integer_display( $currency ) ? 0 : 2 );
		$symbol    = self::currency_symbol( $currency );
		return $symbol ? $symbol . $formatted : strtoupper( $currency ) . ' ' . $formatted;
	}
	public static function article_prices( $post_id ) {
		$stored = get_post_meta( $post_id, '_membership_purchase_prices', true );
		$prices = array();
		if ( is_array( $stored ) ) {
			foreach ( self::supported_currencies() as $currency ) {
				$amount = absint( $stored[ $currency ] ?? 0 );
				if ( $amount > 0 ) {
					$prices[ $currency ] = $amount; }
			}
		}
		if ( empty( $prices ) ) {
			$legacy_amount = absint( get_post_meta( $post_id, '_membership_purchase_amount', true ) );
			if ( $legacy_amount > 0 ) {
				$prices['jpy'] = $legacy_amount; }
		}
		return $prices;
	}
	public static function article_price( $post_id, $currency = '' ) {
		$prices   = self::article_prices( $post_id );
		$currency = $currency ? strtolower( $currency ) : self::current_currency();
		return isset( $prices[ $currency ] ) ? array(
			'currency' => $currency,
			'amount'   => $prices[ $currency ],
		) : null;
	}
	public static function request( $method, $path, $params = array(), $idempotency_key = '' ) {
		$key = self::secret_key();
		if ( ! $key ) {
			$message = __( 'Stripe Secret Key が設定されていません。', 'picot-subscription-membership' );
			Picot_Subscription_Membership_DB::log( 'stripe_api_error', $message );
			return new WP_Error( 'stripe_not_configured', __( '決済機能は現在利用できません。', 'picot-subscription-membership' ) ); }
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => 30,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		);
		if ( $idempotency_key ) {
			$args['headers']['Idempotency-Key'] = $idempotency_key; }
		if ( 'GET' === $args['method'] ) {
			$path .= '?' . http_build_query( $params, '', '&' );
		} else {
			$args['body'] = $params; }
		$response = wp_remote_request( 'https://api.stripe.com/v1/' . ltrim( $path, '/' ), $args );
		if ( is_wp_error( $response ) ) {
			Picot_Subscription_Membership_DB::log( 'stripe_api_error', $response->get_error_message() );
			return new WP_Error( 'stripe_connection_error', __( '決済サービスに接続できませんでした。時間をおいてもう一度お試しください。', 'picot-subscription-membership' ) ); }
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 300 ) {
			$message = sanitize_text_field( $body['error']['message'] ?? __( 'Stripe API エラー', 'picot-subscription-membership' ) );
			Picot_Subscription_Membership_DB::log( 'stripe_api_error', $message );
			return new WP_Error( 'stripe_api_error', __( '決済サービスでエラーが発生しました。時間をおいてもう一度お試しください。', 'picot-subscription-membership' ), array( 'status' => wp_remote_retrieve_response_code( $response ) ) ); }
		return $body;
	}
	private static function checkout_idempotency_key( $user_id, $scope ) {
		$meta_key = '_psm_checkout_' . substr( hash( 'sha256', $scope ), 0, 32 );
		$stored   = get_user_meta( $user_id, $meta_key, true );
		if ( is_array( $stored ) && ! empty( $stored['key'] ) && ! empty( $stored['created_at'] ) && ( time() - (int) $stored['created_at'] ) < HOUR_IN_SECONDS ) {
			return $stored['key']; }
		$candidate = wp_generate_uuid4();
		$record    = array(
			'key'        => $candidate,
			'created_at' => time(),
		);
		if ( ! $stored && add_user_meta( $user_id, $meta_key, $record, true ) ) {
			return $candidate; }
		if ( ! $stored ) {
			$stored = get_user_meta( $user_id, $meta_key, true );
			if ( is_array( $stored ) && ! empty( $stored['key'] ) ) {
				return $stored['key']; }
		}
		update_user_meta( $user_id, $meta_key, $record );
		return $candidate;
	}
	public static function create_checkout( $user_id, $price_id, $success_url, $cancel_url ) {
		global $wpdb;
		$error = self::live_mode_environment_error();
		if ( $error ) {
			return $error; }
		if ( ! self::has_required_policy_pages() ) {
			return new WP_Error( 'policy_pages_not_configured', __( '利用規約、プライバシーポリシー、解約・返金ポリシーのページを設定してから決済を開始してください。', 'picot-subscription-membership' ) ); }
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'invalid_user', __( 'ユーザー情報が見つかりません。', 'picot-subscription-membership' ) ); }
		$price = $wpdb->get_row( $wpdb->prepare( 'SELECT pr.* FROM ' . Picot_Subscription_Membership_DB::table( 'prices' ) . ' pr INNER JOIN ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' p ON p.id = pr.plan_id WHERE pr.id = %d AND pr.active = 1 AND p.active = 1', $price_id ) );
		if ( ! $price ) {
			return new WP_Error( 'invalid_price', __( '料金プランが見つかりません。', 'picot-subscription-membership' ) ); }
		if ( self::current_currency() !== strtolower( $price->currency ) ) {
			return new WP_Error( 'price_currency_unavailable', __( '現在の表示言語用の料金プランはまだ設定されていません。', 'picot-subscription-membership' ) ); }
		$m = Picot_Subscription_Membership_Membership::get_for_user( $user_id );
		if ( $m && $m->stripe_subscription_id && in_array( $m->membership_status, array( 'active', 'trialing', 'past_due', 'paused', 'pending' ), true ) ) {
			return new WP_Error( 'subscription_exists', __( 'すでに処理中または有効な契約があります。契約の変更・確認はCustomer Portalから行ってください。', 'picot-subscription-membership' ) ); }
		if ( $m && 'canceled' === $m->membership_status && $m->effective_access_until && strtotime( $m->effective_access_until . ' UTC' ) >= time() ) {
			return new WP_Error( 'subscription_cancels_at_period_end', __( '現在の契約は期間終了時に解約予定です。期間終了後に新しいプランへお申し込みください。', 'picot-subscription-membership' ) ); }
		$pending = get_transient( self::pending_subscription_checkout_key( $user_id ) );
		if ( is_array( $pending ) && ! empty( $pending['url'] ) ) {
			if ( (int) ( $pending['price_id'] ?? 0 ) === (int) $price_id ) {
				return array( 'url' => esc_url_raw( $pending['url'] ) );
			} return new WP_Error( 'checkout_pending', __( 'すでに別のプランの申込処理が進行中です。完了またはキャンセルしてから再度お試しください。', 'picot-subscription-membership' ) ); }
		$params = array(
			'mode'                                    => 'subscription',
			'line_items[0][price]'                    => $price->stripe_price_id,
			'line_items[0][quantity]'                 => 1,
			'success_url'                             => esc_url_raw( $success_url ),
			'cancel_url'                              => esc_url_raw( $cancel_url ),
			'expires_at'                              => time() + 31 * MINUTE_IN_SECONDS,
			'client_reference_id'                     => (string) $user_id,
			'metadata[wp_user_id]'                    => (string) $user_id,
			'subscription_data[metadata][wp_user_id]' => (string) $user_id,
		);
		if ( $m && $m->stripe_customer_id ) {
			$params['customer'] = $m->stripe_customer_id;
		} else {
			$params['customer_email'] = $user->user_email; }
		$session = self::request( 'POST', 'checkout/sessions', $params, 'checkout:' . $user_id . ':' . $price_id . ':' . self::checkout_idempotency_key( $user_id, 'subscription:' . $price_id ) );
		if ( ! is_wp_error( $session ) && ! empty( $session['url'] ) ) {
			set_transient(
				self::pending_subscription_checkout_key( $user_id ),
				array(
					'url'      => esc_url_raw( $session['url'] ),
					'price_id' => (int) $price_id,
				),
				31 * MINUTE_IN_SECONDS
			); }
		return $session;
	}
	public static function create_portal( $user_id, $return_url ) {
		$error = self::live_mode_environment_error();
		if ( $error ) {
			return $error; }
		if ( empty( self::settings()['portal_enabled'] ) ) {
			return new WP_Error( 'portal_disabled', __( '契約管理機能は現在利用できません。', 'picot-subscription-membership' ) ); }
		$m = Picot_Subscription_Membership_Membership::get_for_user( $user_id );
		if ( ! $m || ! $m->stripe_customer_id ) {
			return new WP_Error( 'customer_missing', __( 'Stripe顧客情報がありません。', 'picot-subscription-membership' ) ); }
		return self::request(
			'POST',
			'billing_portal/sessions',
			array(
				'customer'   => $m->stripe_customer_id,
				'return_url' => esc_url_raw( $return_url ),
			),
			'portal:' . $user_id . ':' . wp_generate_uuid4()
		);
	}
	public static function create_article_purchase( $user_id, $post_id, $success_url, $cancel_url ) {
		$error = self::live_mode_environment_error();
		if ( $error ) {
			return $error; }
		if ( ! self::has_required_policy_pages() ) {
			return new WP_Error( 'policy_pages_not_configured', __( '利用規約、プライバシーポリシー、解約・返金ポリシーのページを設定してから決済を開始してください。', 'picot-subscription-membership' ) ); }
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'invalid_user', __( 'ユーザー情報が見つかりません。', 'picot-subscription-membership' ) ); }
		$post  = get_post( $post_id );
		$price = self::article_price( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! get_post_meta( $post_id, '_membership_purchase_enabled', true ) || ! $price || ! self::is_valid_charge_amount( $price['amount'], $price['currency'] ) ) {
			return new WP_Error( 'invalid_article_purchase', __( 'この記事は個別購入できません。', 'picot-subscription-membership' ) ); }
		$pending = get_transient( self::pending_article_purchase_key( $user_id, $post_id ) );
		if ( is_array( $pending ) && ! empty( $pending['url'] ) ) {
			return array( 'url' => esc_url_raw( $pending['url'] ) ); }
		$params  = array(
			'mode'                                      => 'payment',
			'payment_method_types[0]'                   => 'card',
			'line_items[0][quantity]'                   => 1,
			'line_items[0][price_data][currency]'       => $price['currency'],
			'line_items[0][price_data][unit_amount]'    => $price['amount'],
			'line_items[0][price_data][product_data][name]' => wp_html_excerpt( wp_strip_all_tags( get_the_title( $post ) ), 250, '' ),
			'success_url'                               => esc_url_raw( $success_url ),
			'cancel_url'                                => esc_url_raw( $cancel_url ),
			'expires_at'                                => time() + 31 * MINUTE_IN_SECONDS,
			'client_reference_id'                       => (string) $user_id,
			'metadata[wp_user_id]'                      => (string) $user_id,
			'metadata[psm_purchase_post_id]'            => (string) $post_id,
			'payment_intent_data[metadata][wp_user_id]' => (string) $user_id,
			'payment_intent_data[metadata][psm_purchase_post_id]' => (string) $post_id,
		);
		$session = self::request( 'POST', 'checkout/sessions', $params, 'article-purchase:' . $user_id . ':' . $post_id . ':' . self::checkout_idempotency_key( $user_id, 'article:' . $post_id ) );
		if ( ! is_wp_error( $session ) && ! empty( $session['url'] ) ) {
			set_transient( self::pending_article_purchase_key( $user_id, $post_id ), array( 'url' => esc_url_raw( $session['url'] ) ), 31 * MINUTE_IN_SECONDS ); }
		return $session;
	}
	public static function retrieve_subscription( $subscription_id ) {
		return self::request( 'GET', 'subscriptions/' . rawurlencode( $subscription_id ) ); }
	public static function verify_signature( $payload, $header ) {
		$s      = self::settings();
		$secret = 'live' === $s['mode'] ? ( $s['live_webhook_secret'] ?? '' ) : ( $s['test_webhook_secret'] ?? '' );
		if ( ! $secret || ! $header ) {
			return false; }
		$values = array();
		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 === count( $pair ) ) {
				$values[ $pair[0] ][] = $pair[1]; }
		}
		$timestamp = $values['t'][0] ?? '';
		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > 300 ) {
			return false; }
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $values['v1'] ?? array() as $signature ) {
			if ( hash_equals( $expected, $signature ) ) {
				return true; }
		}
		return false;
	}
}
