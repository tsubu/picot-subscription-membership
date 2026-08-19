<?php
defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Queries use plugin-owned, prefixed tables and access decisions require current data.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL identifiers come only from Picot_Subscription_Membership_DB::table(); all query values use placeholders.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.WP.I18n.MissingTranslatorsComment -- State-changing handlers verify their dedicated nonces; scalar request values are validated before use.

final class Picot_Subscription_Membership_Content {
	public static function init() {
		foreach ( array( 'psm_access_nonce', 'psm_teaser_nonce', 'psm_purchase_post_id', '_psm_purchase_nonce' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ! is_scalar( $_POST[ $key ] ) ) {
				unset( $_POST[ $key ] ); }
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_meta' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'teaser_meta_boxes' ) );
		add_action( 'save_post', array( __CLASS__, 'save_teaser_meta' ) );
		add_filter( 'the_content', array( __CLASS__, 'filter_content' ), 20 );
		add_filter( 'the_content_feed', array( __CLASS__, 'filter_content' ), 20 );
		add_filter( 'the_excerpt_rss', array( __CLASS__, 'filter_content' ), 20 );
		add_filter( 'get_the_excerpt', array( __CLASS__, 'filter_excerpt' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_action( 'template_redirect', array( __CLASS__, 'send_protected_no_cache_headers' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'clear_canceled_article_purchase' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_purchase_checkout' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_filters' ), 20 );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_meta_box_script' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'localize_block_editor' ) );
	}
	public static function enqueue_styles() {
		wp_enqueue_style( 'picot-subscription-membership', PICOT_SUBSCRIPTION_MEMBERSHIP_URL . 'assets/css/picot-subscription-membership.css', array(), PICOT_SUBSCRIPTION_MEMBERSHIP_VERSION ); }
	public static function enqueue_meta_box_script( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return; }
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::post_types(), true ) ) {
			return; }
		wp_enqueue_style( 'picot-subscription-membership-content-settings', PICOT_SUBSCRIPTION_MEMBERSHIP_URL . 'assets/css/content-settings.css', array(), PICOT_SUBSCRIPTION_MEMBERSHIP_VERSION );
		wp_enqueue_script( 'picot-subscription-membership-content-settings', PICOT_SUBSCRIPTION_MEMBERSHIP_URL . 'assets/js/content-settings.js', array(), PICOT_SUBSCRIPTION_MEMBERSHIP_VERSION, true );
		wp_localize_script(
			'picot-subscription-membership-content-settings',
			'PicotSubscriptionMembershipContentSettings',
			array(
				'priceRequiredMessage' => __( '個別販売には、設定した販売通貨の価格を入力してください。', 'picot-subscription-membership' ),
			)
		);
	}
	public static function post_types() {
		$s = Picot_Subscription_Membership_Stripe_Gateway::settings();
		return array_filter( array_map( 'sanitize_key', (array) ( $s['post_types'] ?? array( 'post', 'page' ) ) ) ); }
	public static function is_protected_post( $post_id ) {
		$mode             = get_post_meta( $post_id, '_membership_access_mode', true );
		$mode             = '' !== $mode ? $mode : 'public';
		$purchase_enabled = (bool) get_post_meta( $post_id, '_membership_purchase_enabled', true );
		$has_prices       = ! empty( Picot_Subscription_Membership_Stripe_Gateway::article_prices( $post_id ) );
		return 'public' !== $mode || ( $purchase_enabled && $has_prices );
	}
	public static function send_protected_no_cache_headers() {
		if ( is_singular() && self::is_protected_post( get_queried_object_id() ) ) {
			nocache_headers();
			return;
		} global $wp_query;
		foreach ( (array) ( $wp_query->posts ?? array() ) as $post ) {
			if ( self::is_protected_post( $post->ID ) ) {
				nocache_headers();
				return; }
		} }
	public static function meta_boxes() {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'psm-access', __( 'Membership 閲覧制限', 'picot-subscription-membership' ), array( __CLASS__, 'meta_box' ), $type, 'side' ); } }
	public static function teaser_meta_boxes() {
		foreach ( self::post_types() as $type ) {
			add_meta_box( 'psm-teaser', __( 'Membership 公開概要', 'picot-subscription-membership' ), array( __CLASS__, 'teaser_meta_box' ), $type, 'normal', 'high' ); } }
	public static function meta_box( $post ) {
		$mode             = get_post_meta( $post->ID, '_membership_access_mode', true );
		$mode             = '' !== $mode ? $mode : 'public';
		$plans            = (array) get_post_meta( $post->ID, '_membership_allowed_plans', true );
		$purchase_enabled = (bool) get_post_meta( $post->ID, '_membership_purchase_enabled', true );
		$prices           = Picot_Subscription_Membership_Stripe_Gateway::article_prices( $post->ID );
		$currency         = Picot_Subscription_Membership_Stripe_Gateway::current_currency();
		$amount           = absint( $prices[ $currency ] ?? 0 );
		$integer_display  = Picot_Subscription_Membership_Stripe_Gateway::currency_uses_integer_display( $currency );
		$amount_input     = Picot_Subscription_Membership_Stripe_Gateway::currency_is_zero_decimal( $currency ) ? $amount : ( $amount ? number_format( $amount / 100, $integer_display ? 0 : 2, '.', '' ) : '' );
		$minimum          = $integer_display ? '1' : '0.01';
		$step             = $integer_display ? '1' : '0.01';
		$maximum          = Picot_Subscription_Membership_Stripe_Gateway::currency_is_zero_decimal( $currency ) ? '99999999' : ( Picot_Subscription_Membership_Stripe_Gateway::currency_requires_whole_unit_amount( $currency ) ? '999999' : '999999.99' );
		wp_nonce_field( 'psm_save_access', 'psm_access_nonce' );
		echo '<p><label><input type="checkbox" name="psm_paid_only" value="1" ' . checked( in_array( $mode, array( 'paid', 'plans' ), true ), true, false ) . '> <strong>' . esc_html__( '有料会員限定記事にする', 'picot-subscription-membership' ) . '</strong></label><br><span class="description">' . esc_html__( 'チェックすると、有効な有料会員だけが全文を読めます。', 'picot-subscription-membership' ) . '</span></p><p><label><input type="checkbox" name="psm_login_only" value="1" ' . checked( 'login' === $mode, true, false ) . '> <strong>' . esc_html__( 'ログイン会員限定記事にする', 'picot-subscription-membership' ) . '</strong></label><br><span class="description">' . esc_html__( '有料会員限定と同時に選択した場合は、有料会員限定が優先されます。', 'picot-subscription-membership' ) . '</span></p><p><strong>' . esc_html__( '対象プラン（任意）', 'picot-subscription-membership' ) . '</strong><br><span class="description">' . esc_html__( '選択した場合は、そのプランの会員だけが閲覧できます。未選択なら、すべての有料会員が対象です。', 'picot-subscription-membership' ) . '</span><br>';
		global $wpdb;
		foreach ( $wpdb->get_results( 'SELECT id, name FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' WHERE active = 1 ORDER BY sort_order, name' ) as $plan ) {
			echo '<label><input type="checkbox" name="psm_plans[]" value="' . esc_attr( $plan->id ) . '" ' . checked( in_array( (string) $plan->id, array_map( 'strval', $plans ), true ), true, false ) . '> ' . esc_html( $plan->name ) . '</label><br>'; }
		echo '</p><hr><p><label><input id="picot-subscription-membership-purchase-enabled" type="checkbox" name="psm_purchase_enabled" value="1" ' . checked( $purchase_enabled, true, false ) . '> <strong>' . esc_html__( 'この記事を個別販売する', 'picot-subscription-membership' ) . '</strong></label><br><span class="description">' . esc_html__( '設定画面で選んだ販売通貨の価格を入力してください。通貨を変更した場合は、記事ごとに新しい通貨の価格を設定してください。', 'picot-subscription-membership' ) . '</span><label class="picot-subscription-membership-purchase-price">' . esc_html( Picot_Subscription_Membership_Stripe_Gateway::currency_label( $currency ) ) . '<input type="number" min="' . esc_attr( $minimum ) . '" max="' . esc_attr( $maximum ) . '" step="' . esc_attr( $step ) . '" class="small-text picot-subscription-membership-purchase-amount" name="psm_purchase_amount" value="' . esc_attr( $amount_input ) . '" ' . disabled( $purchase_enabled, false, false ) . '></label></p><p class="description">' . esc_html__( '非会員に表示する概要と無償時に表示する記事は、本文エリアの「Membership 公開概要」メタボックスに入力してください。', 'picot-subscription-membership' ) . '</p>';
	}
	public static function teaser_meta_box( $post ) {
		wp_nonce_field( 'psm_save_teaser', 'psm_teaser_nonce' );
		echo '<p>' . esc_html__( '非会員に表示する概要を入力してください。ここに入力した内容だけが本文の代わりに出力されます。空欄の場合、WordPressの手動抜粋を使用します。限定本文はページソース、REST API、RSSには出力されません。', 'picot-subscription-membership' ) . '</p><textarea name="psm_teaser" class="widefat" rows="6">' . esc_textarea( get_post_meta( $post->ID, '_membership_teaser', true ) ) . '</textarea><p><label>' . esc_html__( '無償時に表示される記事', 'picot-subscription-membership' ) . '<textarea name="psm_message" class="widefat" rows="6">' . esc_textarea( get_post_meta( $post->ID, '_membership_restricted_message', true ) ) . '</textarea></label></p>'; }
	public static function save_meta( $post_id ) {
		if ( ! isset( $_POST['psm_access_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['psm_access_nonce'] ) ), 'psm_save_access' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		} $raw_plans        = $_POST['psm_plans'] ?? array();
		$purchase_requested = isset( $_POST['psm_purchase_enabled'] );
		$currency           = Picot_Subscription_Membership_Stripe_Gateway::current_currency();
		$prices             = Picot_Subscription_Membership_Stripe_Gateway::article_prices( $post_id );
		$raw_amount         = $_POST['psm_purchase_amount'] ?? '';
		$amount             = Picot_Subscription_Membership_Stripe_Gateway::normalize_amount( $raw_amount, $currency );
		if ( $purchase_requested && Picot_Subscription_Membership_Stripe_Gateway::is_valid_charge_amount( $amount, $currency ) ) {
			$prices[ $currency ] = $amount;
		} elseif ( $purchase_requested ) {
			unset( $prices[ $currency ] );
		} else {
			$prices = array();
		} $plans = is_array( $raw_plans ) ? array_values( array_filter( array_map( static fn( $plan ) => is_scalar( $plan ) ? absint( $plan ) : 0, $raw_plans ) ) ) : array();
		$mode    = isset( $_POST['psm_paid_only'] ) ? ( $plans ? 'plans' : 'paid' ) : ( isset( $_POST['psm_login_only'] ) ? 'login' : 'public' );
		update_post_meta( $post_id, '_membership_access_mode', $mode );
		update_post_meta( $post_id, '_membership_allowed_plans', $plans );
		update_post_meta( $post_id, '_membership_purchase_enabled', $purchase_requested && $prices ? 1 : 0 );
		update_post_meta( $post_id, '_membership_purchase_prices', $purchase_requested ? $prices : array() );
		update_post_meta( $post_id, '_membership_purchase_amount', $purchase_requested ? absint( $prices['jpy'] ?? 0 ) : 0 ); }
	public static function save_teaser_meta( $post_id ) {
		if ( ! isset( $_POST['psm_teaser_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['psm_teaser_nonce'] ) ), 'psm_save_teaser' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		} $teaser = $_POST['psm_teaser'] ?? '';
		$message  = $_POST['psm_message'] ?? '';
		update_post_meta( $post_id, '_membership_teaser', is_scalar( $teaser ) ? wp_kses_post( wp_unslash( $teaser ) ) : '' );
		update_post_meta( $post_id, '_membership_restricted_message', is_scalar( $message ) ? sanitize_textarea_field( wp_unslash( $message ) ) : '' ); }
	public static function user_has_purchased( $post_id, $user_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Picot_Subscription_Membership_DB::table( 'purchases' ) . ' WHERE post_id = %d AND user_id = %d AND status = %s', $post_id, $user_id, 'paid' ) ); }
	public static function user_can_access( $post_id, $user_id = 0, $plans = array(), $mode = '' ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( get_current_user_id() === $user_id && ( ( $post_id && current_user_can( 'edit_post', $post_id ) ) || current_user_can( 'manage_memberships' ) ) ) {
			return true; }
		$purchase_enabled = $post_id && (bool) get_post_meta( $post_id, '_membership_purchase_enabled', true ) && ! empty( Picot_Subscription_Membership_Stripe_Gateway::article_prices( $post_id ) );
		if ( '' === $mode ) {
			if ( $post_id ) {
				$mode = get_post_meta( $post_id, '_membership_access_mode', true );
				$mode = '' !== $mode ? $mode : 'public';
			} else {
				$mode = 'paid';
			}
		}
		if ( 'public' === $mode && ! $purchase_enabled ) {
			return true; }
		if ( ! $user_id ) {
			return false; }
		if ( $post_id && self::user_has_purchased( $post_id, $user_id ) ) {
			return true; }
		// An individual-sale article requires its own purchase unless a paid-member restriction is selected as an additional access route.
		if ( in_array( $mode, array( 'public', 'login' ), true ) && $purchase_enabled ) {
			return false;
		}
		if ( 'login' === $mode && ! $purchase_enabled ) {
			return true; }
		$m = Picot_Subscription_Membership_Membership::get_for_user( $user_id );
		if ( ! Picot_Subscription_Membership_Membership::is_active( $user_id ) ) {
			return false; }
		$allowed = $plans;
		if ( empty( $allowed ) && $post_id ) {
			$allowed = (array) get_post_meta( $post_id, '_membership_allowed_plans', true );
		}
		if ( $allowed ) {
			$plan_ids   = array_filter( array_map( 'absint', $allowed ) );
			$plan_slugs = array_filter( $allowed, static fn( $plan ) => is_string( $plan ) && ! ctype_digit( $plan ) );
			if ( $plan_slugs ) {
				global $wpdb;
				$plan_ids = array_merge( $plan_ids, $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' WHERE slug IN (' . implode( ',', array_fill( 0, count( $plan_slugs ), '%s' ) ) . ')', ...array_map( 'sanitize_title', $plan_slugs ) ) ) ); }
			$allowed = $plan_ids;
		}
		$ok = 'plans' !== $mode || ( $m && in_array( (int) $m->plan_id, array_map( 'intval', $allowed ), true ) );
		return (bool) apply_filters( 'picot_membership_can_access', $ok, $post_id, $user_id, $m );
	}
	public static function restricted_message( $post_id = 0 ) {
		$message = $post_id ? get_post_meta( $post_id, '_membership_restricted_message', true ) : '';
		if ( '' === $message ) {
			$message = is_user_logged_in() ? __( 'この記事の続きを読むには会員プランへの加入が必要です。', 'picot-subscription-membership' ) : __( 'この記事の続きを読むにはログインまたは会員登録が必要です。', 'picot-subscription-membership' );
		}
		$settings     = Picot_Subscription_Membership_Stripe_Gateway::settings();
		$page_id      = is_user_logged_in() ? absint( $settings['plans_page_id'] ?? 0 ) : absint( $settings['register_page_id'] ?? 0 );
		$link_post_id = $post_id ? $post_id : get_the_ID();
		$link         = $page_id ? get_permalink( $page_id ) : wp_login_url( get_permalink( $link_post_id ) );
		$label        = is_user_logged_in() ? __( 'プランを見る', 'picot-subscription-membership' ) : __( '会員登録・ログイン', 'picot-subscription-membership' );
		$purchase     = '';
		$price        = $post_id && get_post_meta( $post_id, '_membership_purchase_enabled', true ) ? Picot_Subscription_Membership_Stripe_Gateway::article_price( $post_id ) : null;
		if ( $price ) {
			if ( ! is_user_logged_in() ) {
				$purchase = '<p>' . esc_html__( '個別購入にはログインまたは会員登録が必要です。', 'picot-subscription-membership' ) . '</p>';
			} elseif ( ! Picot_Subscription_Membership_Stripe_Gateway::has_required_policy_pages() ) {
				$purchase = '<p class="psm-error">' . esc_html__( '利用規約、プライバシーポリシー、解約・返金ポリシーのページを設定してから決済を開始してください。', 'picot-subscription-membership' ) . '</p>' . Picot_Subscription_Membership_Shortcodes::policy_links();
			} else {
				$price_tax_notice = Picot_Subscription_Membership_Stripe_Gateway::price_tax_notice();
				/* translators: %s: formatted individual article price. */
				$purchase_label = sprintf( __( 'この記事を %s で購入', 'picot-subscription-membership' ), Picot_Subscription_Membership_Stripe_Gateway::display_amount( $price['amount'], $price['currency'] ) );
				$purchase       = '<form method="post" class="psm-purchase-form"><input type="hidden" name="psm_purchase_post_id" value="' . esc_attr( $post_id ) . '">' . wp_nonce_field( 'psm_article_purchase_' . $post_id, '_psm_purchase_nonce', true, false ) . '<button type="submit" class="wp-element-button psm-restricted__link">' . esc_html( $purchase_label ) . '</button></form>' . ( $price_tax_notice ? '<p class="psm-price-tax-notice">' . esc_html( $price_tax_notice ) . '</p>' : '' ) . Picot_Subscription_Membership_Shortcodes::policy_links();
			}
		}
		return '<div class="psm-restricted"><p>' . wp_kses_post( apply_filters( 'picot_membership_restricted_message', $message, $post_id ) ) . '</p><p><a class="wp-element-button psm-restricted__link" href="' . esc_url( $link ) . '">' . esc_html( $label ) . '</a></p>' . $purchase . '</div>';
	}
	public static function clear_canceled_article_purchase() {
		$value   = $_GET['psm_purchase'] ?? '';
		$post_id = get_queried_object_id();
		if ( is_user_logged_in() && is_scalar( $value ) && 'cancel' === sanitize_key( wp_unslash( $value ) ) && $post_id ) {
			Picot_Subscription_Membership_Stripe_Gateway::clear_pending_article_purchase( get_current_user_id(), $post_id ); } }
	public static function handle_purchase_checkout() {
		if ( empty( $_POST['psm_purchase_post_id'] ) || ! is_user_logged_in() ) {
			return;
		} $post_id = absint( $_POST['psm_purchase_post_id'] );
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_psm_purchase_nonce'] ?? '' ) ), 'psm_article_purchase_' . $post_id ) ) {
			wp_die( esc_html__( '不正な購入リクエストです。', 'picot-subscription-membership' ) );
		} if ( self::user_has_purchased( $post_id, get_current_user_id() ) ) {
			wp_safe_redirect( get_permalink( $post_id ) );
			exit;
		} $return_url = get_permalink( $post_id );
		$session      = Picot_Subscription_Membership_Stripe_Gateway::create_article_purchase( get_current_user_id(), $post_id, add_query_arg( 'psm_purchase', 'success', $return_url ), add_query_arg( 'psm_purchase', 'cancel', $return_url ) );
		if ( is_wp_error( $session ) ) {
			wp_die( esc_html( $session->get_error_message() ) );
		} wp_safe_redirect( $session['url'] );
		exit; }
	public static function teaser( $post_id ) {
		$teaser = get_post_meta( $post_id, '_membership_teaser', true );
		if ( '' === trim( (string) $teaser ) ) {
			$teaser = get_post_field( 'post_excerpt', $post_id );
		} return (string) apply_filters( 'picot_membership_teaser', $teaser, $post_id ); }
	public static function restricted_output( $post_id ) {
		$teaser = self::teaser( $post_id );
		return ( '' !== trim( wp_strip_all_tags( $teaser ) ) ? '<div class="psm-teaser">' . wpautop( wp_kses_post( $teaser ) ) . '</div>' : '' ) . self::restricted_message( $post_id ); }
	public static function filter_content( $content ) {
		if ( is_admin() ) {
			return $content;
		} $post_id = get_the_ID();
		if ( $post_id && self::is_protected_post( $post_id ) ) {
			nocache_headers();
		} return $post_id && ! self::user_can_access( $post_id ) ? self::restricted_output( $post_id ) : $content; }
	public static function filter_excerpt( $excerpt, $post ) {
		if ( $post && self::is_protected_post( $post->ID ) ) {
			nocache_headers();
		}
		if ( $post && ! self::user_can_access( $post->ID ) ) {
			$teaser = self::teaser( $post->ID );
			return wp_strip_all_tags( '' !== $teaser ? $teaser : self::restricted_message( $post->ID ) );
		}
		return $excerpt;
	}
	public static function rest_filters() {
		foreach ( get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'names'
		) as $type ) {
			add_filter( 'rest_prepare_' . $type, array( __CLASS__, 'filter_rest' ), 20, 3 ); } }
	public static function filter_rest( $response, $post ) {
		if ( ! self::user_can_access( $post->ID ) && isset( $response->data['content'] ) ) {
			$response->data['content'] = array(
				'rendered'  => self::restricted_output( $post->ID ),
				'protected' => true,
			);
			$response->data['excerpt'] = array(
				'rendered'  => wpautop( wp_kses_post( self::teaser( $post->ID ) ) ),
				'protected' => true,
			);
		} return $response; }
	public static function register_block() {
		wp_register_script( 'picot-subscription-membership-restricted-content-editor', PICOT_SUBSCRIPTION_MEMBERSHIP_URL . 'assets/js/restricted-content-block.js', array( 'wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ), PICOT_SUBSCRIPTION_MEMBERSHIP_VERSION, true );
		register_block_type(
			'picot-subscription-membership/restricted-content',
			array(
				'editor_script'   => 'picot-subscription-membership-restricted-content-editor',
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'mode'  => array(
						'type'    => 'string',
						'default' => 'paid',
					),
					'plans' => array(
						'type'    => 'array',
						'default' => array(),
					),
				),
			)
		); }
	public static function localize_block_editor() {
		global $wpdb;
		$plans = $wpdb->get_results( 'SELECT slug, name FROM ' . Picot_Subscription_Membership_DB::table( 'plans' ) . ' WHERE active = 1 ORDER BY sort_order, name' );
		wp_localize_script(
			'picot-subscription-membership-restricted-content-editor',
			'PicotSubscriptionMembershipRestrictedContentBlock',
			array(
				'plans'  => array_map(
					static fn( $plan ) => array(
						'slug' => $plan->slug,
						'name' => $plan->name,
					),
					$plans
				),
				'labels' => array(
					'title'            => __( 'Membership 限定コンテンツ', 'picot-subscription-membership' ),
					'access'           => __( '閲覧制限', 'picot-subscription-membership' ),
					'target'           => __( '対象', 'picot-subscription-membership' ),
					'all_paid_members' => __( 'すべての有料会員', 'picot-subscription-membership' ),
					'specific_plans'   => __( '指定プランの会員', 'picot-subscription-membership' ),
					'eligible_plans'   => __( '対象プラン', 'picot-subscription-membership' ),
					'no_plans'         => __( '有効なプランがありません。先にMembershipのプランを作成してください。', 'picot-subscription-membership' ),
					'member_content'   => __( '会員限定コンテンツ', 'picot-subscription-membership' ),
					'plans_message'    => __( '指定プランの会員だけに表示されます。', 'picot-subscription-membership' ),
					'paid_message'     => __( '有料会員だけに表示されます。', 'picot-subscription-membership' ),
				),
			)
		); }
	public static function render_block( $attributes, $content ) {
		$mode  = isset( $attributes['mode'] ) && in_array( $attributes['mode'], array( 'paid', 'plans' ), true ) ? $attributes['mode'] : 'paid';
		$plans = isset( $attributes['plans'] ) && is_array( $attributes['plans'] ) ? array_values( array_filter( $attributes['plans'], 'is_scalar' ) ) : array();
		return self::user_can_access( 0, 0, $plans, $mode ) ? $content : self::restricted_message(); }
}
