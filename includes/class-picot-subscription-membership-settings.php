<?php
/**
 * Plugin settings and onboarding screens.
 *
 * @package Picot_Subscription_Membership
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.WP.I18n.MissingTranslatorsComment -- State-changing settings handlers verify their dedicated nonces.

/** Settings and onboarding screen for Picot Subscription Membership. */
final class Picot_Subscription_Membership_Settings {
	/**
	 * Register the settings screen hooks.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( array( '_wpnonce', 'psm_notice', 'psm_notice_type', 'psm_setup_step', 'psm_page_key', 'psm_page_group', 'psm_return_page' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ! is_scalar( $_POST[ $key ] ) ) {
				unset( $_POST[ $key ] );
			} if ( isset( $_GET[ $key ] ) && ! is_scalar( $_GET[ $key ] ) ) {
				unset( $_GET[ $key ] );
			} if ( isset( $_REQUEST[ $key ] ) && ! is_scalar( $_REQUEST[ $key ] ) ) {
				unset( $_REQUEST[ $key ] ); }
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 99 );
		add_filter( 'plugin_action_links_picot-subscription-membership/picot-subscription-membership.php', array( __CLASS__, 'plugin_action_links' ) );
		add_action( 'admin_post_psm_save_all_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_psm_save_setup_basics', array( __CLASS__, 'save_setup_basics' ) );
		add_action( 'admin_post_psm_save_setup_test_stripe', array( __CLASS__, 'save_setup_test_stripe' ) );
		add_action( 'admin_post_psm_test_stripe_connection', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_post_psm_create_pages', array( __CLASS__, 'create_pages' ) );
	}

	/**
	 * Register the plugin settings and setup-guide menu entries.
	 *
	 * @return void
	 */
	public static function register_menu() {
		remove_submenu_page( 'psm', 'psm-settings' );
		add_submenu_page( 'psm', __( '初期設定ガイド', 'picot-subscription-membership' ), __( '初期設定ガイド', 'picot-subscription-membership' ), 'manage_membership_settings', 'psm-setup-guide', array( __CLASS__, 'render_setup_guide' ) );
		add_submenu_page( 'psm', __( 'Membership 設定', 'picot-subscription-membership' ), __( '設定', 'picot-subscription-membership' ), 'manage_membership_settings', 'psm-settings', array( __CLASS__, 'render' ) );
	}

	/**
	 * Add a settings shortcut to the Plugins list.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public static function plugin_action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=psm-settings' ) ) . '">' . esc_html__( '設定', 'picot-subscription-membership' ) . '</a>' );
		return $links;
	}

	/**
	 * Return the saved settings with safe defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function settings() {
		$settings             = get_option( 'psm_settings', array() );
		$settings             = is_array( $settings ) ? $settings : array();
		$core_privacy_page_id = absint( get_option( 'wp_page_for_privacy_policy', 0 ) );
		if ( 0 !== $core_privacy_page_id && isset( $settings['privacy_page_id'] ) && absint( $settings['privacy_page_id'] ) === $core_privacy_page_id ) {
			// The WordPress site-wide Privacy page is deliberately not used as this plugin's generated template Page.
			$settings['privacy_page_id'] = 0;
		}
		return wp_parse_args(
			$settings,
			array(
				'mode'                        => 'test',
				'test_publishable_key'        => '',
				'test_secret_key'             => '',
				'test_webhook_secret'         => '',
				'live_publishable_key'        => '',
				'live_secret_key'             => '',
				'live_webhook_secret'         => '',
				'locale'                      => 'ja_JP',
				'currency'                    => 'jpy',
				'grace_days'                  => 0,
				'portal_enabled'              => 1,
				'post_types'                  => array( 'post', 'page' ),
				'account_page_id'             => 0,
				'plans_page_id'               => 0,
				'login_page_id'               => 0,
				'register_page_id'            => 0,
				'terms_page_id'               => 0,
				// Do not automatically adopt WordPress's site-wide privacy page. This plugin uses its own selectable Page template.
				'privacy_page_id'             => 0,
				'subscription_policy_page_id' => 0,
				'contact_page_id'             => 0,
				'price_tax_notice'            => '',
				'delete_data_on_uninstall'    => 0,
			)
		);
	}

	/**
	 * Get a scalar value from the submitted request.
	 *
	 * @param string $key      Submitted field name.
	 * @param mixed  $fallback Value to use when the submitted value is invalid.
	 * @return mixed
	 */
	private static function posted_scalar( $key, $fallback = '' ) {
		$value = $_POST[ $key ] ?? $fallback;
		return is_scalar( $value ) ? wp_unslash( $value ) : $fallback;
	}

	/**
	 * Output a Page selector.
	 *
	 * @param string $name     Setting field name.
	 * @param int    $selected Selected Page ID.
	 * @return void
	 */
	private static function page_select( $name, $selected ) {
		$dropdown = wp_dropdown_pages(
			array(
				'name'              => sanitize_key( $name ),
				'selected'          => (int) $selected,
				'show_option_none'  => esc_html__( '未設定', 'picot-subscription-membership' ),
				'option_none_value' => 0,
				'echo'              => false,
			)
		);
		echo wp_kses(
			$dropdown,
			array(
				'select' => array(
					'name'  => true,
					'id'    => true,
					'class' => true,
				),
				'option' => array(
					'value'    => true,
					'selected' => true,
				),
			)
		);
	}

	/**
	 * Return a selected Page, or null when the saved ID is absent or is not a Page.
	 *
	 * @param int $page_id Page ID.
	 * @return WP_Post|null
	 */
	private static function selected_page( $page_id ) {
		$page = absint( $page_id ) ? get_post( absint( $page_id ) ) : null;
		return $page && 'page' === $page->post_type ? $page : null;
	}

	/**
	 * Output inline new-tab links for the public and editor views of a selected Page.
	 *
	 * @param int $page_id Page ID.
	 * @return void
	 */
	private static function render_selected_page_links( $page_id ) {
		$page = self::selected_page( $page_id );
		if ( ! $page ) {
			return;
		}
		$edit_url = get_edit_post_link( $page->ID, 'raw' );
		echo '<a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '表示', 'picot-subscription-membership' ) . '</a>';
		if ( false !== $edit_url ) {
			echo ' | <a href="' . esc_url( $edit_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '編集', 'picot-subscription-membership' ) . '</a>';
		}
	}

	/**
	 * Explain how WordPress permalink settings affect generated page URLs.
	 *
	 * @return void
	 */
	private static function render_permalink_notice() {
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			return;
		}
		$settings_url = admin_url( 'options-permalink.php' );
		/* translators: %s: link to WordPress permalink settings. */
		$message = sprintf( __( '現在はWordPressのパーマリンク設定が「基本」になっているため、作成した会員ページは ?page_id= のURLで表示されます。/mypage などの固定URLを使うには、%sで「基本」以外の形式を保存してください。', 'picot-subscription-membership' ), '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'パーマリンク設定を開く', 'picot-subscription-membership' ) . '</a>' );
		echo '<div class="notice notice-warning inline"><p>' . wp_kses( $message, array( 'a' => array( 'href' => array() ) ) ) . '</p></div>';
	}

	/**
	 * Add copy behavior for the shortcode fields rendered on the Settings screen.
	 *
	 * @return void
	 */
	private static function render_shortcode_copy_script() {
		echo '<script>(function(){document.querySelectorAll(".picot-subscription-membership-copy-shortcode").forEach(function(button){button.addEventListener("click",function(){var input=document.getElementById(button.getAttribute("data-copy-target"));if(!input){return;}var value=input.value;var copied=function(){var original=button.getAttribute("data-copy-label");button.textContent=button.getAttribute("data-copied-label");window.setTimeout(function(){button.textContent=original;},1600);};input.focus();input.select();if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(value).then(copied).catch(function(){if(document.execCommand("copy")){copied();}});}else if(document.execCommand("copy")){copied();}});});}());</script>';
	}

	/**
	 * Return the label for a generated membership Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function membership_page_label( $key ) {
		$labels = array(
			'account_page_id'  => __( 'マイページ', 'picot-subscription-membership' ),
			'plans_page_id'    => __( 'プラン一覧', 'picot-subscription-membership' ),
			'login_page_id'    => __( 'ログイン', 'picot-subscription-membership' ),
			'register_page_id' => __( '会員登録', 'picot-subscription-membership' ),
		);
		return $labels[ $key ] ?? '';
	}

	/**
	 * Return the title for a generated membership Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function generated_membership_page_title( $key ) {
		$titles = array(
			'account_page_id'  => __( '会員マイページ', 'picot-subscription-membership' ),
			'plans_page_id'    => __( '会員プラン', 'picot-subscription-membership' ),
			'login_page_id'    => __( '会員ログイン', 'picot-subscription-membership' ),
			'register_page_id' => __( '会員登録', 'picot-subscription-membership' ),
		);
		return $titles[ $key ] ?? '';
	}

	/**
	 * Return the stable, public URL slug for each generated membership Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function generated_membership_page_slug( $key ) {
		$slugs = array(
			'account_page_id'  => 'mypage',
			'plans_page_id'    => 'plans',
			'login_page_id'    => 'login',
			'register_page_id' => 'member',
		);
		return $slugs[ $key ] ?? '';
	}

	/**
	 * Return an editable core-block layout for a generated membership Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function generated_membership_page_content( $key ) {
		$layouts = array(
			'account_page_id'  => array(
				__( 'マイページ', 'picot-subscription-membership' ),
				__( 'アカウント情報・パスワード、会員プラン、契約状態、利用可能期限、購入済み記事を確認・管理できます。', 'picot-subscription-membership' ),
				__( 'マイページでできること', 'picot-subscription-membership' ),
				array(
					__( '表示名・メールアドレスとパスワードを、このページで変更できます。', 'picot-subscription-membership' ),
					__( '有効な会員は、現在のプラン、更新予定日、利用可能期限を確認できます。', 'picot-subscription-membership' ),
					array(
						'text' => __( 'プラン変更・解約・支払い方法の変更は、会員情報の「プラン・支払い方法を管理」から行えます。', 'picot-subscription-membership' ),
						'url'  => '#psm-membership-management',
					),
				),
				'[membership_account]',
			),
			'plans_page_id'    => array(
				__( '会員プラン', 'picot-subscription-membership' ),
				__( 'ご希望の会員プランを選択してお申し込みください。', 'picot-subscription-membership' ),
				__( '会員プログラムについて', 'picot-subscription-membership' ),
				array(
					array(
						'text' => __( '会員プランは定期課金です。選択した請求間隔でStripeを通じて自動的に更新されます。', 'picot-subscription-membership' ),
						'url'  => 'https://stripe.com/billing',
					),
					__( '加入後は、対象となる会員限定記事をすぐに閲覧できます。', 'picot-subscription-membership' ),
					__( 'プランの変更や解約は、マイページのStripe Customer Portalから手続きできます。', 'picot-subscription-membership' ),
				),
				'[membership_plans]',
			),
			'login_page_id'    => array( __( '会員ログイン', 'picot-subscription-membership' ), __( '登録済みの会員の方は、メールアドレスとパスワードでログインしてください。', 'picot-subscription-membership' ), __( 'ログインについて', 'picot-subscription-membership' ), array( __( 'ログインすると、会員限定記事、購入済み記事、マイページを利用できます。', 'picot-subscription-membership' ), __( 'パスワードを忘れた場合は、ログインフォームの「パスワードをお忘れですか？」から再設定できます。', 'picot-subscription-membership' ), __( 'セキュリティのため、共用端末ではログイン状態を保存しないでください。', 'picot-subscription-membership' ) ), '[membership_login]' ),
			'register_page_id' => array(
				__( '会員登録', 'picot-subscription-membership' ),
				__( 'はじめての方は、会員登録後にプランをお申し込みいただけます。', 'picot-subscription-membership' ),
				__( '登録から利用開始まで', 'picot-subscription-membership' ),
				array(
					__( '会員登録は無料です。登録後、プラン一覧からご希望の有料プランにお申し込みいただけます。', 'picot-subscription-membership' ),
					array(
						'text' => __( '決済はStripeの安全な決済画面で行われ、カード情報はこのサイトには保存されません。', 'picot-subscription-membership' ),
						'url'  => 'https://stripe.com/payments/checkout',
					),
					__( 'お申し込み後は、マイページからプランと支払い方法を管理できます。', 'picot-subscription-membership' ),
					__( '登録により、利用規約とプライバシーポリシーに同意したものとします。', 'picot-subscription-membership' ),
				),
				'[membership_register]',
			),
		);
		if ( empty( $layouts[ $key ] ) ) {
			return ''; }
		$layout  = $layouts[ $key ];
		$content = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">" . esc_html( $layout[0] ) . "</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>" . esc_html( $layout[1] ) . "</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:group {\"className\":\"psm-page-guidance\"} -->\n<div class=\"wp-block-group psm-page-guidance\"><!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . esc_html( $layout[2] ) . "</h3>\n<!-- /wp:heading -->\n\n<!-- wp:list -->\n<ul class=\"wp-block-list\">";
		foreach ( $layout[3] as $item ) {
			if ( is_array( $item ) && isset( $item['text'], $item['url'] ) ) {
				$is_external = wp_http_validate_url( $item['url'] );
				$content    .= '<li><a href="' . esc_url( $item['url'] ) . '"' . ( $is_external ? ' target="_blank" rel="noopener noreferrer"' : '' ) . '>' . esc_html( $item['text'] ) . '</a></li>';
				continue;
			}
			$content .= '<li>' . esc_html( $item ) . '</li>';
		}
		return $content . "</ul>\n<!-- /wp:list --></div>\n<!-- /wp:group -->\n\n<!-- wp:shortcode -->\n" . $layout[4] . "\n<!-- /wp:shortcode -->\n";
	}

	/**
	 * Return the label used for a generated policy or support Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function policy_page_label( $key ) {
		$labels = array(
			'terms_page_id'               => __( '利用規約ページ', 'picot-subscription-membership' ),
			'privacy_page_id'             => __( 'プライバシーポリシーページ', 'picot-subscription-membership' ),
			'subscription_policy_page_id' => __( '解約・返金ポリシーページ', 'picot-subscription-membership' ),
			'contact_page_id'             => __( 'お問い合わせページ（任意）', 'picot-subscription-membership' ),
		);
		return $labels[ $key ] ?? '';
	}

	/**
	 * Return the settings-screen description for a policy or support Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function policy_page_description( $key ) {
		$descriptions = array(
			'terms_page_id'               => __( '会員登録と決済前に表示する利用規約です。料金、利用条件、禁止事項などを実際の運営方針に合わせて記載してください。', 'picot-subscription-membership' ),
			'privacy_page_id'             => __( '個人情報の取得目的、保存・第三者提供、問い合わせ先などを記載するページです。WordPressの「設定」→「プライバシー」で指定したページを選べます。', 'picot-subscription-membership' ),
			'subscription_policy_page_id' => __( '自動更新、解約方法と反映時期、返金の可否・条件、価格に税が含まれるかどうかを明記してください。', 'picot-subscription-membership' ),
			'contact_page_id'             => __( '会員や購入者がサポートへ連絡するための公開ページです。設定すると、会員ページと申込ページにリンクを表示します。', 'picot-subscription-membership' ),
		);
		return $descriptions[ $key ] ?? '';
	}

	/**
	 * Return the front-end title for a generated policy or support Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function generated_policy_page_title( $key ) {
		$titles = array(
			'terms_page_id'               => __( '利用規約', 'picot-subscription-membership' ),
			'privacy_page_id'             => __( 'プライバシーポリシー', 'picot-subscription-membership' ),
			'subscription_policy_page_id' => __( '解約・返金ポリシー', 'picot-subscription-membership' ),
			'contact_page_id'             => __( 'お問い合わせ', 'picot-subscription-membership' ),
		);
		return $titles[ $key ] ?? '';
	}

	/**
	 * Return the stable public slug for each generated policy or support Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function generated_policy_page_slug( $key ) {
		$slugs = array(
			'terms_page_id'               => 'terms',
			'privacy_page_id'             => 'membership-privacy-policy',
			'subscription_policy_page_id' => 'cancellation-refund-policy',
			'contact_page_id'             => 'contact',
		);
		return $slugs[ $key ] ?? '';
	}

	/**
	 * Return an editable core-block template for a policy or support Page.
	 *
	 * @param string $key Settings key.
	 * @return string
	 */
	private static function generated_policy_page_content( $key ) {
		$layouts = array(
			'terms_page_id'               => array(
				__( '利用規約', 'picot-subscription-membership' ),
				array(
					array( __( '適用', 'picot-subscription-membership' ), __( '本規約は、本サイトで提供する会員向けコンテンツおよび個別購入コンテンツの利用条件を定めるものです。利用者は、会員登録、申込みまたは購入を行った時点で本規約に同意したものとします。', 'picot-subscription-membership' ) ),
					array( __( '事業者情報', 'picot-subscription-membership' ), __( '事業者名: [事業者名] ／ 所在地: [所在地] ／ 連絡先: [メールアドレスまたはお問い合わせページ]。提供するサービスの内容や対象地域に応じて、必要な情報を追記してください。', 'picot-subscription-membership' ) ),
					array( __( '料金・支払い', 'picot-subscription-membership' ), __( '会員プランおよび個別購入コンテンツの価格、請求時期、支払い方法は、申込画面または購入画面に表示します。決済はStripeが提供する決済画面を通じて処理されます。', 'picot-subscription-membership' ) ),
					array( __( '自動更新・解約・返金', 'picot-subscription-membership' ), __( '会員プランは、表示された請求間隔で自動更新されます。解約方法、解約の反映時期、返金の可否および条件は、解約・返金ポリシーに定める内容に従います。', 'picot-subscription-membership' ) ),
					array( __( '禁止事項・免責', 'picot-subscription-membership' ), __( '利用者は、コンテンツの不正利用、第三者の権利侵害、サービス運営を妨げる行為などを行ってはなりません。サービスの停止、変更またはコンテンツの提供に関する責任の範囲は、適用法令の範囲で制限されます。', 'picot-subscription-membership' ) ),
					array( __( '規約の変更', 'picot-subscription-membership' ), __( '本規約を変更する場合は、本サイト上で変更内容と適用日を告知します。継続してサービスを利用した場合、変更後の規約に同意したものとして扱われる場合があります。', 'picot-subscription-membership' ) ),
				),
			),
			'privacy_page_id'             => array(
				__( 'プライバシーポリシー', 'picot-subscription-membership' ),
				array(
					array( __( '基本方針', 'picot-subscription-membership' ), __( '本サイトは、会員向けサービスおよびコンテンツ販売の運営に必要な範囲で個人情報を取り扱います。個人情報は、利用目的の達成に必要な範囲で適切に管理します。', 'picot-subscription-membership' ) ),
					array( __( '取得する情報', 'picot-subscription-membership' ), __( '会員登録時に、メールアドレス、表示名、ログイン情報などを取得します。お問い合わせ時には、氏名、連絡先、問い合わせ内容などを取得する場合があります。カード情報はStripeの決済画面で処理されます。', 'picot-subscription-membership' ) ),
					array( __( '利用目的', 'picot-subscription-membership' ), __( '取得した情報は、アカウント管理、本人確認、決済・購入の処理、会員限定コンテンツの提供、お問い合わせへの対応、重要なお知らせの送付、不正利用の防止のために利用します。', 'picot-subscription-membership' ) ),
					array( __( '外部サービス・情報の共有', 'picot-subscription-membership' ), __( '決済処理のためにStripeなどの外部サービスを利用します。法令に基づく場合または業務委託に必要な場合を除き、本人の同意なく個人情報を第三者へ提供しません。', 'picot-subscription-membership' ) ),
					array( __( '開示等の請求・お問い合わせ', 'picot-subscription-membership' ), __( '個人情報に関する開示、訂正、削除、利用停止等の請求は、[メールアドレスまたはお問い合わせページ]までご連絡ください。本人確認のうえ、法令および合理的な範囲で対応します。', 'picot-subscription-membership' ) ),
				),
			),
			'subscription_policy_page_id' => array(
				__( '解約・返金ポリシー', 'picot-subscription-membership' ),
				array(
					array( __( '請求と自動更新', 'picot-subscription-membership' ), __( '会員プランは、申込画面に表示された料金と請求間隔により課金されます。利用者が解約手続きを完了しない限り、契約は各請求期間の終了時に自動更新されます。', 'picot-subscription-membership' ) ),
					array( __( '解約方法と利用可能期間', 'picot-subscription-membership' ), __( '解約は、マイページから開くStripe Customer Portal、または[お問い合わせページ]を通じて手続きできます。解約後も、原則として現在の請求期間の終了日まで会員向けサービスを利用できます。', 'picot-subscription-membership' ) ),
					array( __( '返金', 'picot-subscription-membership' ), __( '返金の可否・条件: [返金の可否、対象期間、申請方法を記載してください]。法令上の義務がある場合を除き、すでに提供された会員期間または個別購入コンテンツについての返金可否は、この方針に従います。', 'picot-subscription-membership' ) ),
					array( __( '価格・税金・決済失敗', 'picot-subscription-membership' ), __( '表示価格の税金の扱いは、申込画面または購入画面に表示します。決済に失敗した場合は、Stripeからの案内に従って支払い方法を更新してください。利用停止時期は、サイトで定める猶予期間がある場合を除き、決済状況に応じます。', 'picot-subscription-membership' ) ),
				),
			),
			'contact_page_id'             => array(
				__( 'お問い合わせ', 'picot-subscription-membership' ),
				array(
					array( __( 'お問い合わせ窓口', 'picot-subscription-membership' ), __( 'お問い合わせは、[問い合わせフォームURL] または [サポート用メールアドレス]から受け付けます。対応時間: [受付時間・休業日]。通常、[返信目安]以内に返信します。', 'picot-subscription-membership' ) ),
					array( __( '会員・購入に関するお問い合わせ', 'picot-subscription-membership' ), __( '会員登録、プラン変更、解約、個別購入に関するお問い合わせでは、登録メールアドレス、対象のプランまたは購入日時をお知らせください。確認後、必要な対応をご案内します。', 'picot-subscription-membership' ) ),
					array( __( '送信しない情報', 'picot-subscription-membership' ), __( '安全のため、パスワード、カード番号、セキュリティコードなどの決済情報はお問い合わせフォームやメールで送信しないでください。決済情報の変更はStripe Customer Portalをご利用ください。', 'picot-subscription-membership' ) ),
				),
			),
		);
		if ( empty( $layouts[ $key ] ) ) {
			return '';
		}
		$layout  = $layouts[ $key ];
		$content = "<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">" . esc_html( $layout[0] ) . "</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>" . esc_html__( 'このテンプレートには一般的な会員制サービスの内容をあらかじめ入力しています。角括弧（[ ]）の箇所と、実際のサービス内容、販売地域、適用法令に関わる箇所だけを公開前に必ず確認・編集してください。', 'picot-subscription-membership' ) . "</p>\n<!-- /wp:paragraph -->\n";
		foreach ( $layout[1] as $section ) {
			$content .= "\n<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">" . esc_html( $section[0] ) . "</h3>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>" . esc_html( $section[1] ) . "</p>\n<!-- /wp:paragraph -->\n";
		}
		return $content;
	}

	/**
	 * Render a separate, valid form used by a per-page creation button.
	 *
	 * @param string $key         Settings key.
	 * @param string $return_page Admin page to return to.
	 * @param int    $setup_step  Setup-guide step to return to.
	 * @return void
	 */
	private static function render_page_creator_form( $key, $return_page = 'psm-settings', $setup_step = 0 ) {
		$form_id = 'picot-subscription-membership-create-' . sanitize_html_class( $key );
		echo '<form id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:none">';
		echo '<input type="hidden" name="action" value="psm_create_pages"><input type="hidden" name="psm_page_key" value="' . esc_attr( $key ) . '"><input type="hidden" name="psm_return_page" value="' . esc_attr( $return_page ) . '">';
		if ( $setup_step ) {
			echo '<input type="hidden" name="psm_setup_step" value="' . esc_attr( $setup_step ) . '">';
		}
		wp_nonce_field( 'psm_create_pages' );
		echo '</form>';
	}

	/**
	 * Check whether a Page contains only an earlier plugin-generated layout.
	 *
	 * @param string $content   Page content.
	 * @param string $shortcode Required membership shortcode.
	 * @return bool
	 */
	private static function can_upgrade_generated_membership_page( $content, $shortcode ) {
		if ( trim( $content ) === $shortcode ) {
			return true; }
		$blocks = array_values( array_filter( parse_blocks( $content ), static fn( $block ) => ! empty( $block['blockName'] ) ) );
		if ( 3 === count( $blocks ) ) {
			return 'core/heading' === $blocks[0]['blockName'] && 'core/paragraph' === $blocks[1]['blockName'] && 'core/shortcode' === $blocks[2]['blockName'] && trim( wp_strip_all_tags( $blocks[2]['innerHTML'] ) ) === $shortcode; }
		if ( 4 !== count( $blocks ) || 'core/heading' !== $blocks[0]['blockName'] || 'core/paragraph' !== $blocks[1]['blockName'] || 'core/group' !== $blocks[2]['blockName'] || 'core/shortcode' !== $blocks[3]['blockName'] ) {
			return false; }
		return ! empty( $blocks[2]['attrs']['className'] ) && 'psm-page-guidance' === $blocks[2]['attrs']['className'] && trim( wp_strip_all_tags( $blocks[3]['innerHTML'] ) ) === $shortcode;
	}

	/**
	 * Return an English-safe post type name when the plugin display language is English.
	 *
	 * @param WP_Post_Type $post_type Post type object.
	 * @return string
	 */
	private static function post_type_label( $post_type ) {
		$label = $post_type->labels->singular_name ?? $post_type->name;
		if ( 'en_US' !== Picot_Subscription_Membership_Stripe_Gateway::current_locale() ) {
			return $label; }
		$core_labels = array(
			'post'       => 'Post',
			'page'       => 'Page',
			'attachment' => 'Media',
		);
		if ( isset( $core_labels[ $post_type->name ] ) ) {
			return $core_labels[ $post_type->name ]; }
		if ( ! preg_match( '/[^\x00-\x7F]/', $label ) ) {
			return $label; }
		return ucwords( str_replace( array( '-', '_' ), ' ', $post_type->name ) );
	}

	/**
	 * Render an admin notice passed through the redirect URL.
	 *
	 * @return void
	 */
	private static function notice() {
		if ( empty( $_GET['psm_notice'] ) ) {
			return; }
		$type = isset( $_GET['psm_notice_type'] ) && 'error' === $_GET['psm_notice_type'] ? 'error' : 'success';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['psm_notice'] ) ) ) . '</p></div>';
	}

	/**
	 * Render the step-by-step setup guide.
	 *
	 * @return void
	 */
	public static function render_setup_guide() {
		if ( ! current_user_can( 'manage_membership_settings' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		$settings          = self::settings();
		$setup_step        = min( 8, max( 1, absint( $_GET['psm_setup_step'] ?? 1 ) ) );
		$setup_guide_url   = admin_url( 'admin.php?page=psm-setup-guide' );
		$settings_url      = admin_url( 'admin.php?page=psm-settings' );
		$plans_url         = admin_url( 'admin.php?page=psm-plans' );
		$new_post_url      = admin_url( 'post-new.php' );
		$new_page_url      = add_query_arg( 'post_type', 'page', admin_url( 'post-new.php' ) );
		$webhook_url       = rest_url( 'membership/v1/stripe/webhook' );
		$test_api_keys_url = 'https://dashboard.stripe.com/test/apikeys';
		$test_webhooks_url = 'https://dashboard.stripe.com/test/webhooks';
		echo '<div class="wrap"><h1>' . esc_html__( '初期設定ガイド', 'picot-subscription-membership' ) . '</h1>';
		self::notice();
		echo '<p>' . esc_html__( '上から順に完了すると、Testモードで会員登録・Stripe決済・有料記事の閲覧を確認できます。各ステップのボタン、URL、入力欄を使って進めてください。', 'picot-subscription-membership' ) . '</p>';
		self::render_permalink_notice();
		/* translators: 1: current setup step number, 2: total setup steps. */
		echo '<p><strong>' . esc_html( sprintf( __( 'ステップ %1$d / %2$d', 'picot-subscription-membership' ), $setup_step, 8 ) ) . '</strong></p><div id="picot-subscription-membership-setup-wizard" data-current-step="' . esc_attr( $setup_step ) . '" data-guide-url="' . esc_url( $setup_guide_url ) . '" data-back-label="' . esc_attr__( '戻る', 'picot-subscription-membership' ) . '" data-next-label="' . esc_attr__( '次へ', 'picot-subscription-membership' ) . '" data-skip-label="' . esc_attr__( 'スキップして次へ', 'picot-subscription-membership' ) . '" data-restart-label="' . esc_attr__( '最初から見る', 'picot-subscription-membership' ) . '" style="max-width:1000px">';
		echo '<h2>1. ' . esc_html__( '販売設定を確認', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( '最初に表示言語と販売通貨を決めます。選んだ通貨は会員プランと個別購入記事の価格に共通して使われます。', 'picot-subscription-membership' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_save_setup_basics"><input type="hidden" name="psm_setup_step" value="2">';
		wp_nonce_field( 'psm_save_setup_basics' );
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="psm-guide-locale">' . esc_html__( '表示言語', 'picot-subscription-membership' ) . '</label></th><td><select id="psm-guide-locale" name="locale">';
		foreach ( Picot_Subscription_Membership_I18n::available_locales() as $locale => $label ) {
			echo '<option value="' . esc_attr( $locale ) . '" ' . selected( $settings['locale'], $locale, false ) . '>' . esc_html( $label ) . '</option>'; }
		echo '</select></td></tr><tr><th scope="row"><label for="psm-guide-currency">' . esc_html__( '販売通貨', 'picot-subscription-membership' ) . '</label></th><td><select id="psm-guide-currency" name="currency">';
		foreach ( Picot_Subscription_Membership_Stripe_Gateway::supported_currencies() as $currency ) {
			echo '<option value="' . esc_attr( $currency ) . '" ' . selected( $settings['currency'], $currency, false ) . '>' . esc_html( Picot_Subscription_Membership_Stripe_Gateway::currency_label( $currency ) ) . '</option>'; }
		echo '</select></td></tr></table>';
		submit_button( __( '保存して次へ', 'picot-subscription-membership' ), 'primary', 'submit', false );
		echo '</form>';
		echo '<hr><h2>2. ' . esc_html__( 'Stripe Test用APIキーを入力', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( '実決済の前に、必ずStripeのTestモードで動作確認します。', 'picot-subscription-membership' ) . '</p><p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $test_api_keys_url ) . '">' . esc_html__( 'Stripe Test APIキー画面を開く', 'picot-subscription-membership' ) . '</a></p><ol><li>' . esc_html__( '「Stripe Test APIキー画面を開く」を押します。Stripe Dashboardから開く場合は、「開発者」→「APIキー」を選び、Testモードになっていることを確認します。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( '「Publishable key」の pk_test_ から始まる値をコピーします。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( '「Secret key」の sk_test_ から始まる値を作成・コピーします。Secret Keyは第三者へ共有しないでください。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( '下の入力欄へ貼り付けて保存します。Secret Keyを空欄のまま保存すると、すでに保存済みの値は変更しません。', 'picot-subscription-membership' ) . '</li></ol><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_save_setup_test_stripe">';
		wp_nonce_field( 'psm_save_setup_test_stripe' );
		echo '<input type="hidden" name="psm_setup_step" value="3">';
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="psm-guide-test-publishable-key">Test Publishable Key</label></th><td><input id="psm-guide-test-publishable-key" class="regular-text code" type="text" name="test_publishable_key" value="' . esc_attr( $settings['test_publishable_key'] ) . '" autocomplete="off"></td></tr><tr><th scope="row"><label for="psm-guide-test-secret-key">Test Secret Key</label></th><td><input id="psm-guide-test-secret-key" class="large-text code" type="password" name="test_secret_key" value="" autocomplete="new-password" placeholder="' . esc_attr( empty( $settings['test_secret_key'] ) ? __( '未設定', 'picot-subscription-membership' ) : __( '設定済み（変更時のみ入力）', 'picot-subscription-membership' ) ) . '"></td></tr></table>';
		submit_button( __( '保存して次へ', 'picot-subscription-membership' ), 'primary', 'submit', false );
		echo '</form>';
		echo '<hr><h2>3. ' . esc_html__( 'Stripe Test Webhookを登録', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( '決済完了や解約などの状態をStripeから受け取るために、Webhookを登録します。', 'picot-subscription-membership' ) . '</p><p><strong>' . esc_html__( 'ローカル環境ではWebhookを登録・受信できません。', 'picot-subscription-membership' ) . '</strong><br>' . esc_html__( 'localhostやMAMPなどのローカルURLにはStripeからアクセスできないため、公開済みのHTTPS環境（本番またはステージング）で登録・確認してください。', 'picot-subscription-membership' ) . '</p><p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $test_webhooks_url ) . '">' . esc_html__( 'Stripe Test Webhook画面を開く', 'picot-subscription-membership' ) . '</a></p><p><strong>' . esc_html__( '登録するWebhook URL', 'picot-subscription-membership' ) . '</strong><br><code>' . esc_html( $webhook_url ) . '</code></p><ol><li>' . esc_html__( '「Stripe Test Webhook画面を開く」を押します。Stripe Dashboardから開く場合は、「開発者」→「Webhook」を選び、Testモードになっていることを確認します。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( 'StripeのWebhook画面で「Create an event destination」を選びます。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( '自分のアカウントのイベントを選び、Webhook endpointを送信先として選択します。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( '上のWebhook URLを入力し、画面に表示されるイベントを選択して作成します。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( '作成した送信先を開き、Signing secretを表示して whsec_ から始まる値を下の欄へ貼り付けます。', 'picot-subscription-membership' ) . '</li></ol><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_save_setup_test_stripe">';
		wp_nonce_field( 'psm_save_setup_test_stripe' );
		echo '<input type="hidden" name="psm_setup_step" value="4">';
		echo '<table class="form-table" role="presentation"><tr><th scope="row"><label for="psm-guide-test-webhook-secret">Test Webhook Signing Secret</label></th><td><input id="psm-guide-test-webhook-secret" class="large-text code" type="password" name="test_webhook_secret" value="" autocomplete="new-password" placeholder="' . esc_attr( empty( $settings['test_webhook_secret'] ) ? __( '未設定', 'picot-subscription-membership' ) : __( '設定済み（変更時のみ入力）', 'picot-subscription-membership' ) ) . '"></td></tr></table>';
		submit_button( __( '保存して次へ', 'picot-subscription-membership' ), 'primary', 'submit', false );
		echo '</form>';
		echo '<hr><h2>4. ' . esc_html__( '会員ページを作成して表示を確認', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( '下のボタンを実行すると、WordPressの固定ページとして、マイページ・プラン一覧・ログイン・会員登録の4ページを実際に新規作成し、それぞれに必要なショートコードを自動入力します。すでに設定済みのページは上書きしません。', 'picot-subscription-membership' ) . '</p><p>' . esc_html__( '作成したページは、見出し・説明文・会員機能のショートコードを含む通常のブロックレイアウトです。固定ページの編集画面で、文章・ブロック・レイアウトを自由に編集できます。', 'picot-subscription-membership' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_create_pages"><input type="hidden" name="psm_return_page" value="psm-setup-guide">';
		wp_nonce_field( 'psm_create_pages' );
		echo '<input type="hidden" name="psm_setup_step" value="5">';
		submit_button( __( '4つの固定ページを作成して次へ', 'picot-subscription-membership' ), 'primary', 'submit', false );
		echo '</form><p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $new_page_url ) . '">' . esc_html__( '固定ページを手動で新規作成', 'picot-subscription-membership' ) . '</a></p><ul class="picot-subscription-membership-setup-status-list">';
		foreach ( array( 'account_page_id', 'plans_page_id', 'login_page_id', 'register_page_id' ) as $key ) {
			$page = self::selected_page( $settings[ $key ] );
			echo '<li><strong class="picot-subscription-membership-setup-status-name">' . esc_html( self::membership_page_label( $key ) ) . '</strong><code class="picot-subscription-membership-setup-status-url">/' . esc_html( self::generated_membership_page_slug( $key ) ) . '</code>';
			if ( $page ) {
				echo '<span class="picot-subscription-membership-setup-status-actions"><a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '表示', 'picot-subscription-membership' ) . '</a> | <a href="' . esc_url( get_edit_post_link( $page->ID ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '編集', 'picot-subscription-membership' ) . '</a></span>';
			} else {
				echo '<span class="picot-subscription-membership-setup-status-missing">' . esc_html__( '未作成', 'picot-subscription-membership' ) . '</span>';
			}
			echo '</li>';
		}
		echo '</ul><hr><h2>5. ' . esc_html__( '規約・サポートページを作成して内容を確認', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( '利用規約、プライバシーポリシー、解約・返金ポリシーは、会員登録と決済を始める前に必要です。未作成のページは、下のボタンで編集可能な固定ページテンプレートとして作成できます。', 'picot-subscription-membership' ) . '</p><p>' . esc_html__( '作成したページは一般的な構成の通常のブロックレイアウトです。固定ページの編集画面で、事業者情報、料金、解約・返金条件、連絡先などを実際の運営方針に合わせて編集してください。', 'picot-subscription-membership' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_create_pages"><input type="hidden" name="psm_page_group" value="policy"><input type="hidden" name="psm_return_page" value="psm-setup-guide">';
		wp_nonce_field( 'psm_create_pages' );
		echo '<input type="hidden" name="psm_setup_step" value="6">';
		submit_button( __( '4つの規約・サポートページを作成して次へ', 'picot-subscription-membership' ), 'primary', 'submit', false );
		echo '</form><p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $new_page_url ) . '">' . esc_html__( '固定ページを手動で新規作成', 'picot-subscription-membership' ) . '</a></p><ul class="picot-subscription-membership-setup-status-list">';
		foreach ( array( 'terms_page_id', 'privacy_page_id', 'subscription_policy_page_id', 'contact_page_id' ) as $key ) {
			$page = self::selected_page( $settings[ $key ] );
			echo '<li><strong class="picot-subscription-membership-setup-status-name">' . esc_html( self::policy_page_label( $key ) ) . '</strong><code class="picot-subscription-membership-setup-status-url">/' . esc_html( self::generated_policy_page_slug( $key ) ) . '</code>';
			if ( $page ) {
				echo '<span class="picot-subscription-membership-setup-status-actions"><a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '表示', 'picot-subscription-membership' ) . '</a> | <a href="' . esc_url( get_edit_post_link( $page->ID ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( '編集', 'picot-subscription-membership' ) . '</a></span>';
			} else {
				echo '<span class="picot-subscription-membership-setup-status-missing">' . esc_html__( '未作成', 'picot-subscription-membership' ) . '</span><form class="picot-subscription-membership-setup-status-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_create_pages"><input type="hidden" name="psm_page_key" value="' . esc_attr( $key ) . '"><input type="hidden" name="psm_return_page" value="psm-setup-guide"><input type="hidden" name="psm_setup_step" value="5">';
				wp_nonce_field( 'psm_create_pages' );
				submit_button( __( 'ページテンプレートを作成', 'picot-subscription-membership' ), 'secondary', 'submit', false );
				echo '</form>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '<hr><h2>6. ' . esc_html__( '会員プランを登録', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( 'Stripeで商品と定期価格を作成し、Stripe Price IDを取得します。次にプラン画面で、プラン名・Stripe Price ID・価格・請求間隔を入力して保存します。価格と通貨はStripe側のPriceと一致させてください。', 'picot-subscription-membership' ) . '</p><p><a class="button" target="_blank" rel="noopener noreferrer" href="https://dashboard.stripe.com/test/products">' . esc_html__( 'Stripe Testの商品・価格を開く', 'picot-subscription-membership' ) . '</a> <a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $plans_url ) . '">' . esc_html__( 'プラン入力フォームを開く', 'picot-subscription-membership' ) . '</a></p>';
		echo '<hr><h2>7. ' . esc_html__( '有料記事を作成してTest決済', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( '新規投稿で「有料会員限定記事にする」にチェックを入れて公開します。個別販売する場合は「この記事を個別販売する」にもチェックを入れ、選択中の販売通貨で価格を入力します。非会員に見せる概要と無償時の記事は本文下のMembership 公開概要に入力します。', 'picot-subscription-membership' ) . '</p><p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $new_post_url ) . '">' . esc_html__( '新規投稿を開く', 'picot-subscription-membership' ) . '</a></p>';
		echo '<hr><h2>8. ' . esc_html__( '公開前にLive設定へ切り替え', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( 'サイトをHTTPSで公開してから、設定でLiveモードを選択し、Live用のAPIキーとWebhook Signing Secretを入力します。TestとLiveのキー、商品、価格、Webhookは共有できません。少額の実決済とWebhook受信を確認してから公開してください。', 'picot-subscription-membership' ) . '</p><p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Live設定を開く', 'picot-subscription-membership' ) . '</a></p>';
		echo '</div><script>(function(){const wizard=document.getElementById("picot-subscription-membership-setup-wizard");if(!wizard){return;}const current=Math.max(1,Math.min(8,Number(wizard.dataset.currentStep)||1));const guideUrl=wizard.dataset.guideUrl;const backLabel=wizard.dataset.backLabel;const nextLabel=wizard.dataset.nextLabel;const skipLabel=wizard.dataset.skipLabel;const restartLabel=wizard.dataset.restartLabel;const addSkip=function(parent,nextStep){const skip=document.createElement("p");skip.className="picot-subscription-membership-setup-skip";const skipLink=document.createElement("a");skipLink.href=guideUrl+"&psm_setup_step="+nextStep;skipLink.textContent=skipLabel;skip.appendChild(skipLink);parent.appendChild(skip);};const headings=Array.from(wizard.children).filter((node)=>node.tagName==="H2");const sections=[];headings.forEach((heading,index)=>{const section=document.createElement("section");section.className="picot-subscription-membership-setup-step";heading.parentNode.insertBefore(section,heading);section.appendChild(heading);let node=section.nextSibling;while(node&&!(node.nodeType===1&&node.tagName==="H2")){const next=node.nextSibling;section.appendChild(node);node=next;}section.hidden=index!==current-1;sections.push(section);});sections.forEach((section,index)=>{const step=index+1;const nextStep=Math.min(8,step+1);let hasMainNext=false;const nav=document.createElement("p");nav.className="picot-subscription-membership-setup-navigation";if(step>1){const back=document.createElement("a");back.className="button";back.href=guideUrl+"&psm_setup_step="+(step-1);back.textContent=backLabel;nav.appendChild(back);nav.appendChild(document.createTextNode(" "));}const forms=section.querySelectorAll("form");forms.forEach((form)=>{if(!form.querySelector("input[name=psm_setup_step]")){const input=document.createElement("input");input.type="hidden";input.name="psm_setup_step";input.value=String(nextStep);form.appendChild(input);}const mainNext=form.querySelector(".button-primary");if(step<8&&mainNext&&!hasMainNext){addSkip(form,nextStep);hasMainNext=true;}});if(!forms.length){const next=document.createElement("a");next.className="button button-primary";next.href=guideUrl+"&psm_setup_step="+(step<8?nextStep:1);next.textContent=step<8?nextLabel:restartLabel;nav.appendChild(next);}section.appendChild(nav);if(step<8&&!hasMainNext){addSkip(section,nextStep);}});})();</script></div>';
		self::render_setup_guide_layout_assets();
	}

	/** Add the two-column presentation used only by the setup guide. */
	private static function render_setup_guide_layout_assets() {
		echo '<script>window.picotSubscriptionMembershipSetupGuideLabels=' . wp_json_encode(
			array(
				'copy'   => __( 'コピー', 'picot-subscription-membership' ),
				'copied' => __( 'コピーしました', 'picot-subscription-membership' ),
			)
		) . ';</script>';
		echo <<<'HTML'
<style>
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-step {
		margin: 20px 0;
		padding: 24px;
		border: 1px solid #dcdcde;
		border-radius: 8px;
		background: #fff;
		box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-step > h2 {
		margin: 0 0 20px;
		padding-bottom: 14px;
		border-bottom: 1px solid #dcdcde;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-layout {
		display: grid;
		grid-template-columns: minmax(0, 1.35fr) minmax(300px, 0.85fr);
		gap: 30px;
		align-items: start;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions {
		padding-left: 30px;
		border-left: 1px solid #dcdcde;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions form {
		margin: 0 0 16px;
		padding: 18px;
		border: 1px solid #dcdcde;
		border-radius: 6px;
		background: #f6f7f7;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions .form-table {
		margin-top: 0;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions .form-table th,
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions .form-table td {
		display: block;
		width: auto;
		padding: 0 0 10px;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions .form-table input,
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions .form-table select {
		width: 100%;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions .button {
		margin: 0 8px 8px 0;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-list {
		margin: 18px 0 0;
		padding: 0;
		list-style: none;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-list li {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		gap: 8px 12px;
		margin: 0 0 8px;
		padding: 12px 14px;
		border: 1px solid #dcdcde;
		border-radius: 6px;
		background: #fff;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-name {
		flex: 1 0 100%;
		min-width: 150px;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-url {
		padding: 2px 6px;
		background: #f0f0f1;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-actions,
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-missing {
		margin-left: auto;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-missing {
		color: #8c3333;
		font-weight: 600;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-form {
		margin: 0;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-form .button {
		margin: 0;
}
#picot-subscription-membership-setup-wizard .psm-guide-copy-control {
		display: grid;
		grid-template-columns: minmax(0, 1fr) auto;
		gap: 8px;
		align-items: stretch;
		margin: 4px 0 12px;
}
#picot-subscription-membership-setup-wizard .psm-guide-copy-control input {
		min-width: 0;
		width: 100%;
		max-width: none;
		box-sizing: border-box;
}
#picot-subscription-membership-setup-wizard .psm-guide-copy-control .button {
		margin: 0;
		min-height: 30px;
		white-space: nowrap;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-navigation {
		margin: 24px 0 0;
		padding-top: 18px;
		border-top: 1px solid #dcdcde;
}
#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-skip {
		margin: 8px 0 0;
		font-size: 12px;
}
@media screen and (max-width: 782px) {
	#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-step {
		padding: 18px;
	}
	#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-layout {
		grid-template-columns: 1fr;
		gap: 20px;
	}
	#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-actions {
		padding: 20px 0 0;
		border-top: 1px solid #dcdcde;
		border-left: 0;
	}
	#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-name {
		min-width: 0;
		width: 100%;
	}
	#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-actions,
	#picot-subscription-membership-setup-wizard .picot-subscription-membership-setup-status-missing {
		margin-left: 0;
	}
	#picot-subscription-membership-setup-wizard .psm-guide-copy-control {
		grid-template-columns: 1fr;
	}
}
</style>
<script>
(function () {
	const wizard = document.getElementById('picot-subscription-membership-setup-wizard');
	if (!wizard) {
		return;
	}
	const labels = window.picotSubscriptionMembershipSetupGuideLabels || {};

	wizard.querySelectorAll('code').forEach(function (code) {
		const url = code.textContent.trim();
		if (!/^https?:\/\//i.test(url)) {
			return;
		}
		const control = document.createElement('span');
		control.className = 'psm-guide-copy-control';
		const input = document.createElement('input');
		input.type = 'text';
		input.className = 'regular-text code';
		input.value = url;
		input.readOnly = true;
		const copy = document.createElement('button');
		copy.type = 'button';
		copy.className = 'button';
		copy.textContent = labels.copy || 'Copy';
		copy.addEventListener('click', function () {
			input.focus();
			input.select();
			const copied = function () {
				copy.textContent = labels.copied || 'Copied';
				window.setTimeout(function () { copy.textContent = labels.copy || 'Copy'; }, 1600);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(url).then(copied).catch(function () {
					if (document.execCommand('copy')) { copied(); }
				});
			} else if (document.execCommand('copy')) {
				copied();
			}
		});
		control.appendChild(input);
		control.appendChild(copy);
		code.replaceWith(control);
	});

	wizard.querySelectorAll('.picot-subscription-membership-setup-step').forEach(function (step) {
		const heading = step.querySelector(':scope > h2');
		const navigation = step.querySelector(':scope > .picot-subscription-membership-setup-navigation');
		const skip = step.querySelector(':scope > .picot-subscription-membership-setup-skip');
		if (!heading || !navigation) {
			return;
		}

		const layout = document.createElement('div');
		layout.className = 'picot-subscription-membership-setup-layout';
		const explanation = document.createElement('div');
		explanation.className = 'picot-subscription-membership-setup-explanation';
		const actions = document.createElement('div');
		actions.className = 'picot-subscription-membership-setup-actions';

		Array.from(step.children).forEach(function (node) {
			if (node === heading || node === navigation || node === skip) {
				return;
			}
			const isStatusList = node.classList.contains('picot-subscription-membership-setup-status-list');
			const isAction = !isStatusList && (node.matches('form') || Boolean(node.querySelector('.button, input[type="submit"]')));
			(isAction ? actions : explanation).appendChild(node);
		});

		layout.appendChild(explanation);
		layout.appendChild(actions);
		step.insertBefore(layout, navigation);
	});
}());
</script>
HTML;
	}

	/**
	 * Render the main plugin settings screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_membership_settings' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		$s                    = self::settings();
		$available_locales    = Picot_Subscription_Membership_I18n::available_locales();
		$post_types           = get_post_types( array( 'public' => true ), 'objects' );
		$webhook_url          = rest_url( 'membership/v1/stripe/webhook' );
		$stripe_dashboard_url = 'https://dashboard.stripe.com/';
		$stripe_api_keys_url  = 'https://dashboard.stripe.com/apikeys';
		echo '<div class="wrap"><style>.psm-settings-help > p, .psm-settings-help > ol, .psm-settings-help > ul, .psm-settings-help > ol li, .psm-settings-help > ul li { font-weight: 400; }</style><h1>' . esc_html__( 'Membership 設定', 'picot-subscription-membership' ) . '</h1>';
		self::notice();
		echo '<p>' . esc_html__( '必要な説明は、各項目の「説明を表示」から確認できます。はじめはTestモードで決済の流れを確認してください。', 'picot-subscription-membership' ) . '</p>';
		self::render_permalink_notice();
		echo '<details class="psm-settings-help" style="margin:1em 0"><summary><strong>' . esc_html__( 'Stripeの初期設定手順を表示', 'picot-subscription-membership' ) . '</strong></summary><ol>';
		echo '<li>' . sprintf(
			wp_kses(
				__( '<a href="%s" target="_blank" rel="noopener noreferrer">Stripe Dashboard</a>にログインし、最初はテストモードを有効にします。', 'picot-subscription-membership' ),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_url( $stripe_dashboard_url )
		) . '</li>';
		echo '<li>' . sprintf(
			wp_kses(
				__( 'Dashboardの「開発者」→「APIキー」で、Publishable KeyとSecret Keyを取得します。<a href="%s" target="_blank" rel="noopener noreferrer">APIキー画面を開く</a>。', 'picot-subscription-membership' ),
				array(
					'a' => array(
						'href'   => array(),
						'target' => array(),
						'rel'    => array(),
					),
				)
			),
			esc_url( $stripe_api_keys_url )
		) . '</li>';
		echo '<li>' . esc_html__( 'Dashboardの「開発者」→「Webhooks」で下記のWebhook URLをエンドポイントとして追加し、そのエンドポイントのSigning secretを取得します。', 'picot-subscription-membership' ) . '</li>';
		echo '<li>' . esc_html__( 'キーを保存後に「Stripe接続を確認」を実行します。本番公開時はLive用のキーとWebhookを別途登録し、サイトをHTTPSにしてください。', 'picot-subscription-membership' ) . '</li></ol></details>';
		if ( 'live' === $s['mode'] && ! Picot_Subscription_Membership_Stripe_Gateway::site_uses_https() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'LiveモードにはHTTPSで公開されたサイトが必要です。現在のサイトURLではLiveモードを保存・利用できません。', 'picot-subscription-membership' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="psm_save_all_settings">';
		wp_nonce_field( 'psm_save_all_settings' );
		echo '<h2>' . esc_html__( 'Stripeの接続設定', 'picot-subscription-membership' ) . '</h2><table class="form-table" role="presentation">';
			echo '<tr><th scope="row">' . esc_html__( '動作モード', 'picot-subscription-membership' ) . '</th><td><label><input type="radio" name="mode" value="test" ' . checked( $s['mode'], 'test', false ) . '> Test</label>　<label><input type="radio" name="mode" value="live" ' . checked( $s['mode'], 'live', false ) . '> Live</label><details class="psm-settings-help"><summary>' . esc_html__( 'TestとLiveの違いを表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( 'Testは開発・動作確認用、Liveは実際の決済用です。TestとLiveのキーおよびWebhook Signing Secretは完全に分離されています。', 'picot-subscription-membership' ) . '</p></details></td></tr>';
			echo '<tr><th scope="row">' . esc_html__( '表示言語', 'picot-subscription-membership' ) . '</th><td><select name="locale">';
		foreach ( $available_locales as $locale => $label ) {
			echo '<option value="' . esc_attr( $locale ) . '" ' . selected( $s['locale'], $locale, false ) . '>' . esc_html( $label ) . '</option>'; }
			echo '</select><details class="psm-settings-help"><summary>' . esc_html__( '表示言語の説明を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( '日本語と英語の基本表示に加え、このプラグインの翻訳ファイルがlanguagesフォルダまたはWordPressの言語パックにある言語だけが候補に表示されます。翻訳ファイルを追加すると、次回の画面表示時に選択肢へ自動的に追加されます。', 'picot-subscription-membership' ) . '</p></details></td></tr>';
			echo '<tr><th scope="row">' . esc_html__( '販売通貨', 'picot-subscription-membership' ) . '</th><td><select name="currency">';
		foreach ( Picot_Subscription_Membership_Stripe_Gateway::supported_currencies() as $currency ) {
			echo '<option value="' . esc_attr( $currency ) . '" ' . selected( $s['currency'], $currency, false ) . '>' . esc_html( Picot_Subscription_Membership_Stripe_Gateway::currency_label( $currency ) ) . '</option>'; }
			echo '</select><details class="psm-settings-help"><summary>' . esc_html__( '販売通貨の説明を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( 'Stripeがカード決済で対応する通貨から選べます。選択した通貨でプランと個別記事の価格を設定し、同じ通貨のStripe Price IDを登録してください。アカウントの国や有効な決済手段によって利用できる通貨は異なります。', 'picot-subscription-membership' ) . '</p></details></td></tr>';
		foreach ( array(
			'test' => 'Test',
			'live' => 'Live',
		) as $mode => $label ) {
			$mode_description  = 'test' === $mode
				? __( 'Stripe Dashboardで「テストモード」を有効にして取得する値です。キーは pk_test_ / sk_test_ で始まります。', 'picot-subscription-membership' )
				: __( '実際の決済に使う値です。Stripe Dashboardを本番モードで表示して取得します。キーは pk_live_ / sk_live_ で始まります。', 'picot-subscription-membership' );
			$mode_api_keys_url = 'test' === $mode ? 'https://dashboard.stripe.com/test/apikeys' : 'https://dashboard.stripe.com/apikeys';
			$mode_webhooks_url = 'test' === $mode ? 'https://dashboard.stripe.com/test/webhooks' : 'https://dashboard.stripe.com/webhooks';
			/* translators: %s: Stripe mode label, such as Test or Live. */
			$api_keys_link_label = sprintf( __( '%s用のAPIキー画面を開く', 'picot-subscription-membership' ), $label );
			/* translators: %s: Stripe mode label, such as Test or Live. */
			$webhooks_link_label = sprintf( __( '%s用のWebhook画面を開く', 'picot-subscription-membership' ), $label );
			/* translators: %s: Stripe mode label, such as Test or Live. */
			echo '<tr><th colspan="2" scope="col"><h3>' . esc_html( sprintf( __( '%sモード用の情報', 'picot-subscription-membership' ), $label ) ) . '</h3><details class="psm-settings-help"><summary>' . esc_html__( 'キーの取得方法・注意を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html( $mode_description ) . '</p><p><a class="button" href="' . esc_url( $mode_api_keys_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $api_keys_link_label ) . '</a> <a class="button" href="' . esc_url( $mode_webhooks_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $webhooks_link_label ) . '</a></p><ol><li>' . esc_html__( 'APIキー画面でPublishable Key（pk_）とSecret Key（sk_）を確認し、この画面の該当欄へ貼り付けます。Live用のSecret Keyは表示された時点で安全な場所へ保管してください。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( 'Webhook画面でイベント送信先を作成し、この設定画面に表示されているWebhook URLを登録します。作成後、その送信先のSigning secret（whsec_）をコピーして該当欄へ貼り付けます。', 'picot-subscription-membership' ) . '</li></ol><ul><li>' . esc_html__( 'Publishable Keyは「開発者」→「APIキー」にある公開可能キーです。現在の決済はStripe Checkoutへ移動する方式のため、空欄でも決済できます。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( 'Secret Keyは同じ画面のシークレットキーです。第三者に渡さず、空欄のまま保存すると設定済みの値は変更されません。', 'picot-subscription-membership' ) . '</li><li>' . esc_html__( 'Webhook Signing Secretは「開発者」→「Webhooks」で、このサイトのエンドポイントを開き「Signing secretを表示」から取得する whsec_ で始まる値です。', 'picot-subscription-membership' ) . '</li></ul></details></th></tr>';
			echo '<tr><th scope="row">' . esc_html( $label . ' Publishable Key' ) . '</th><td><input class="regular-text code" type="text" name="' . esc_attr( $mode . '_publishable_key' ) . '" value="' . esc_attr( $s[ $mode . '_publishable_key' ] ) . '" autocomplete="off"></td></tr>';
			echo '<tr><th scope="row">' . esc_html( $label . ' Secret Key' ) . '</th><td><input class="large-text code" type="password" name="' . esc_attr( $mode . '_secret_key' ) . '" value="" autocomplete="new-password" placeholder="' . esc_attr( empty( $s[ $mode . '_secret_key' ] ) ? __( '未設定', 'picot-subscription-membership' ) : __( '設定済み（変更時のみ入力）', 'picot-subscription-membership' ) ) . '"></td></tr>';
			echo '<tr><th scope="row">' . esc_html( $label . ' Webhook Signing Secret' ) . '</th><td><input class="large-text code" type="password" name="' . esc_attr( $mode . '_webhook_secret' ) . '" value="" autocomplete="new-password" placeholder="' . esc_attr( empty( $s[ $mode . '_webhook_secret' ] ) ? __( '未設定', 'picot-subscription-membership' ) : __( '設定済み（変更時のみ入力）', 'picot-subscription-membership' ) ) . '"></td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Webhook URL', 'picot-subscription-membership' ) . '</th><td><input class="large-text code" type="text" readonly value="' . esc_attr( $webhook_url ) . '" onclick="this.select();"><details class="psm-settings-help"><summary>' . esc_html__( 'Webhookの登録方法・受信イベントを表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( 'このURLをStripe Dashboardの「開発者」→「Webhooks」でエンドポイントとして登録します。TestモードとLiveモードで、それぞれ登録してください。', 'picot-subscription-membership' ) . '</p><p><strong>' . esc_html__( '受信するイベント', 'picot-subscription-membership' ) . '</strong><br><code>checkout.session.completed</code> / <code>customer.subscription.created</code> / <code>customer.subscription.updated</code> / <code>customer.subscription.deleted</code> / <code>invoice.paid</code> / <code>invoice.payment_failed</code> / <code>invoice.payment_action_required</code> / <code>charge.refunded</code></p></details></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Customer Portal', 'picot-subscription-membership' ) . '</th><td><label><input type="checkbox" name="portal_enabled" value="1" ' . checked( $s['portal_enabled'], 1, false ) . '> ' . esc_html__( 'マイページからStripe Customer Portalを利用する', 'picot-subscription-membership' ) . '</label><details class="psm-settings-help"><summary>' . esc_html__( 'Customer Portalの説明を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( '会員がプラン変更・解約、カード情報の変更、請求書の確認を行えるStripeの画面です。利用前にStripe Dashboard側でCustomer Portalを有効化し、プラン変更を許可する設定にしてください。', 'picot-subscription-membership' ) . '</p></details></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( '決済失敗時の猶予日数', 'picot-subscription-membership' ) . '</th><td><input type="number" min="0" max="365" name="grace_days" value="' . esc_attr( $s['grace_days'] ) . '" class="small-text"> ' . esc_html__( '日', 'picot-subscription-membership' ) . '<details class="psm-settings-help"><summary>' . esc_html__( '猶予日数の説明を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( '支払い失敗により会員状態が「past_due」になった後も、有料記事を閲覧できる日数です。0にすると猶予を設けず、すぐに閲覧を停止します。', 'picot-subscription-membership' ) . '</p></details></td></tr></table>';
		echo '<h2>' . esc_html__( 'コンテンツ保護', 'picot-subscription-membership' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( '保護対象の投稿タイプ', 'picot-subscription-membership' ) . '</th><td>';
		foreach ( $post_types as $post_type ) {
			echo '<label style="display:block;margin-bottom:4px"><input type="checkbox" name="post_types[]" value="' . esc_attr( $post_type->name ) . '" ' . checked( in_array( $post_type->name, (array) $s['post_types'], true ), true, false ) . '> ' . esc_html( self::post_type_label( $post_type ) ) . ' <code>' . esc_html( $post_type->name ) . '</code></label>'; }
		echo '<details class="psm-settings-help"><summary>' . esc_html__( '保護対象の投稿タイプの説明を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( '選択した投稿タイプの編集画面に、会員限定・単品購入の設定欄を表示します。すでに会員限定に設定した記事を公開へ戻す設定ではないため、個別の記事編集画面で解除してください。', 'picot-subscription-membership' ) . '</p></details></td></tr></table>';
		echo '<h2>' . esc_html__( '会員ページ', 'picot-subscription-membership' ) . '</h2><details class="psm-settings-help"><summary>' . esc_html__( 'ショートコードと表示内容を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( '各ショートコードを固定ページまたは投稿の本文へ1つだけ入力すると、その場所に会員向けの機能を表示できます。作成済みの会員ページには、対応するショートコードが自動で入力されます。', 'picot-subscription-membership' ) . '</p><ul><li><code>[membership_account]</code><br>' . esc_html__( 'ログイン中の会員のアカウント情報・パスワード、プラン、契約状態、利用可能期限、購入済み記事を確認・変更できます。Customer Portalを有効にしている場合は、プラン・支払い方法を管理するボタンも表示します。未ログインの場合はログインフォームを表示します。', 'picot-subscription-membership' ) . '</li><li><code>[membership_plans]</code><br>' . esc_html__( '設定した販売通貨の有効な会員プランを一覧表示します。ログイン済みのユーザーは、選択したプランのStripe Checkoutへ進めます。', 'picot-subscription-membership' ) . '</li><li><code>[membership_login]</code><br>' . esc_html__( 'WordPressのログインフォームを表示します。既存会員がログインして、会員ページや購入済み記事へアクセスするために使います。', 'picot-subscription-membership' ) . '</li><li><code>[membership_register]</code><br>' . esc_html__( '新規ユーザーの会員登録フォームを表示します。メールアドレス・表示名・パスワードと利用規約・プライバシーポリシーへの同意を受け付け、WordPressの購読者アカウントを作成します。', 'picot-subscription-membership' ) . '</li></ul></details><table class="form-table" role="presentation">';
		foreach ( array(
			'account_page_id'  => array( 'マイページ', '[membership_account]' ),
			'plans_page_id'    => array( 'プラン一覧', '[membership_plans]' ),
			'login_page_id'    => array( 'ログイン', '[membership_login]' ),
			'register_page_id' => array( '会員登録', '[membership_register]' ),
		) as $key => $data ) {
			$form_id      = 'picot-subscription-membership-create-' . sanitize_html_class( $key );
			$shortcode_id = 'picot-subscription-membership-shortcode-' . sanitize_html_class( $key );
			echo '<tr><th scope="row">' . esc_html( self::membership_page_label( $key ) ) . '</th><td>';
			self::page_select( $key, $s[ $key ] );
			echo ' <button type="submit" class="button" form="' . esc_attr( $form_id ) . '">' . esc_html__( '自動作成・更新', 'picot-subscription-membership' ) . '</button><br><input id="' . esc_attr( $shortcode_id ) . '" class="regular-text code" type="text" readonly value="' . esc_attr( $data[1] ) . '" onclick="this.select();"> <button type="button" class="button picot-subscription-membership-copy-shortcode" data-copy-target="' . esc_attr( $shortcode_id ) . '" data-copy-label="' . esc_attr__( 'コピー', 'picot-subscription-membership' ) . '" data-copied-label="' . esc_attr__( 'コピーしました', 'picot-subscription-membership' ) . '">' . esc_html__( 'コピー', 'picot-subscription-membership' ) . '</button><br>';
			self::render_selected_page_links( $s[ $key ] );
			echo ' <span class="description">(' . esc_html__( '自動作成URL', 'picot-subscription-membership' ) . ': <code>/' . esc_html( self::generated_membership_page_slug( $key ) ) . '</code>)</span></td></tr>';
		}
		echo '</table><h2>' . esc_html__( '規約・サポートページ', 'picot-subscription-membership' ) . '</h2><p>' . esc_html__( '有料会員の申込前に必要な規約と方針を、公開済みの固定ページから選択してください。利用規約・プライバシーポリシー・解約／返金ポリシーが未設定の場合、会員登録と決済は開始できません。', 'picot-subscription-membership' ) . '</p><table class="form-table" role="presentation">';
		foreach ( array( 'terms_page_id', 'privacy_page_id', 'subscription_policy_page_id', 'contact_page_id' ) as $key ) {
			$form_id = 'picot-subscription-membership-create-' . sanitize_html_class( $key );
			echo '<tr><th scope="row">' . esc_html( self::policy_page_label( $key ) ) . '</th><td>';
			self::page_select( $key, $s[ $key ] );
			if ( ! self::selected_page( $s[ $key ] ) ) {
				echo ' <button type="submit" class="button" form="' . esc_attr( $form_id ) . '">' . esc_html__( 'ページテンプレートを作成', 'picot-subscription-membership' ) . '</button>';
			}
			echo '<br>';
			self::render_selected_page_links( $s[ $key ] );
			echo ' <span class="description">(' . esc_html__( '自動作成URL', 'picot-subscription-membership' ) . ': <code>/' . esc_html( self::generated_policy_page_slug( $key ) ) . '</code>)</span><br><details class="psm-settings-help"><summary>' . esc_html__( '説明を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html( self::policy_page_description( $key ) ) . '</p></details></td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( '価格・税に関する注記（任意）', 'picot-subscription-membership' ) . '</th><td><textarea class="large-text" rows="3" name="price_tax_notice">' . esc_textarea( $s['price_tax_notice'] ) . '</textarea><details class="psm-settings-help"><summary>' . esc_html__( '説明を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( 'プラン一覧と個別購入ボタンの近くに表示する注記です。例: 「表示価格は税込です」「税額は決済時に計算されます」。販売地域と税務方針に合う内容を設定してください。', 'picot-subscription-membership' ) . '</p></details></td></tr>';
		echo '</table><h2>' . esc_html__( 'データ管理', 'picot-subscription-membership' ) . '</h2><table class="form-table" role="presentation"><tr><th scope="row">' . esc_html__( 'アンインストール時のデータ削除', 'picot-subscription-membership' ) . '</th><td><label><input type="checkbox" name="delete_data_on_uninstall" value="1" ' . checked( $s['delete_data_on_uninstall'], 1, false ) . '> ' . esc_html__( '独自テーブルと設定を削除する', 'picot-subscription-membership' ) . '</label><details class="psm-settings-help"><summary>' . esc_html__( '削除されるデータ・注意を表示', 'picot-subscription-membership' ) . '</summary><p>' . esc_html__( '通常はオフのままにしてください。プラグインを停止するだけではデータは削除されません。有効にしてアンインストールすると会員・決済・購入履歴を含むプラグインのデータを復元できない形で削除します。事前にバックアップを取ってください。', 'picot-subscription-membership' ) . '</p></details></td></tr></table>';
		submit_button( __( '設定を保存', 'picot-subscription-membership' ) );
		echo '</form>';
		foreach ( array( 'account_page_id', 'plans_page_id', 'login_page_id', 'register_page_id', 'terms_page_id', 'privacy_page_id', 'subscription_policy_page_id', 'contact_page_id' ) as $key ) {
			self::render_page_creator_form( $key );
		}
		self::render_shortcode_copy_script();
		echo '</div>';
	}

	/**
	 * Save the main plugin settings form.
	 *
	 * @return void
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_membership_settings' ) || ! check_admin_referer( 'psm_save_all_settings' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		$s         = self::settings();
		$s['mode'] = 'live' === self::posted_scalar( 'mode' ) ? 'live' : 'test';
		if ( 'live' === $s['mode'] && ! Picot_Subscription_Membership_Stripe_Gateway::site_uses_https() ) {
			self::redirect( __( 'Stripe Liveモードを有効にするには、サイトをHTTPSで公開してください。', 'picot-subscription-membership' ), 'error' ); }
		$locale        = sanitize_text_field( self::posted_scalar( 'locale', 'ja_JP' ) );
		$s['locale']   = Picot_Subscription_Membership_I18n::is_available_locale( $locale ) ? $locale : 'ja_JP';
		$currency      = strtolower( sanitize_key( self::posted_scalar( 'currency', 'jpy' ) ) );
		$s['currency'] = in_array( $currency, Picot_Subscription_Membership_Stripe_Gateway::supported_currencies(), true ) ? $currency : 'jpy';
		foreach ( array( 'test', 'live' ) as $mode ) {
			$s[ $mode . '_publishable_key' ] = sanitize_text_field( self::posted_scalar( $mode . '_publishable_key' ) );
			foreach ( array( 'secret_key', 'webhook_secret' ) as $field ) {
				$value = trim( sanitize_text_field( self::posted_scalar( $mode . '_' . $field ) ) );
				if ( '' !== $value ) {
					$s[ $mode . '_' . $field ] = $value; }
			}
		}
		$s['grace_days']     = min( 365, absint( self::posted_scalar( 'grace_days', 0 ) ) );
		$s['portal_enabled'] = isset( $_POST['portal_enabled'] ) ? 1 : 0;
		$raw_post_types      = $_POST['post_types'] ?? array();
		$raw_post_types      = is_array( $raw_post_types ) ? array_filter( $raw_post_types, 'is_scalar' ) : array();
		$s['post_types']     = array_values( array_intersect( array_map( 'sanitize_key', $raw_post_types ), array_keys( get_post_types( array( 'public' => true ) ) ) ) );
		foreach ( array( 'account_page_id', 'plans_page_id', 'login_page_id', 'register_page_id', 'terms_page_id', 'privacy_page_id', 'subscription_policy_page_id', 'contact_page_id' ) as $key ) {
			$s[ $key ] = absint( self::posted_scalar( $key, 0 ) ); }
		$s['price_tax_notice']         = sanitize_textarea_field( self::posted_scalar( 'price_tax_notice' ) );
		$s['delete_data_on_uninstall'] = isset( $_POST['delete_data_on_uninstall'] ) ? 1 : 0;
		update_option( 'psm_settings', $s, false );
		self::redirect( __( '設定を保存しました。', 'picot-subscription-membership' ) );
	}

	/** Save the language and currency selected in the first setup-guide step. */
	public static function save_setup_basics() {
		if ( ! current_user_can( 'manage_membership_settings' ) || ! check_admin_referer( 'psm_save_setup_basics' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		$settings             = self::settings();
		$locale               = sanitize_text_field( self::posted_scalar( 'locale', 'ja_JP' ) );
		$settings['locale']   = Picot_Subscription_Membership_I18n::is_available_locale( $locale ) ? $locale : 'ja_JP';
		$currency             = strtolower( sanitize_key( self::posted_scalar( 'currency', 'jpy' ) ) );
		$settings['currency'] = in_array( $currency, Picot_Subscription_Membership_Stripe_Gateway::supported_currencies(), true ) ? $currency : 'jpy';
		update_option( 'psm_settings', $settings, false );
		self::redirect( __( '販売設定を保存しました。', 'picot-subscription-membership' ), 'success', 'psm-setup-guide', 2 );
	}

	/** Save only the Test credentials entered from the setup guide. */
	public static function save_setup_test_stripe() {
		if ( ! current_user_can( 'manage_membership_settings' ) || ! check_admin_referer( 'psm_save_setup_test_stripe' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		$settings         = self::settings();
		$settings['mode'] = 'test';
		foreach ( array( 'test_publishable_key', 'test_secret_key', 'test_webhook_secret' ) as $key ) {
			$value = trim( sanitize_text_field( self::posted_scalar( $key ) ) );
			if ( '' !== $value ) {
				$settings[ $key ] = $value; }
		}
		update_option( 'psm_settings', $settings, false );
		$next_step = min( 8, max( 1, absint( self::posted_scalar( 'psm_setup_step', 1 ) ) ) );
		self::redirect( __( 'Test用のStripe設定を保存しました。', 'picot-subscription-membership' ), 'success', 'psm-setup-guide', $next_step );
	}

	/**
	 * Test the active Stripe API credentials.
	 *
	 * @return void
	 */
	public static function test_connection() {
		if ( ! current_user_can( 'manage_membership_settings' ) || ! check_admin_referer( 'psm_test_stripe_connection' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		$s              = self::settings();
		$mode           = 'live' === $s['mode'] ? 'live' : 'test';
		$secret         = trim( $s[ $mode . '_secret_key' ] );
		$webhook_secret = trim( $s[ $mode . '_webhook_secret' ] );
		if ( 'live' === $mode && ! Picot_Subscription_Membership_Stripe_Gateway::site_uses_https() ) {
			self::redirect( __( 'Stripe Liveモードでは、サイトをHTTPSで公開してください。', 'picot-subscription-membership' ), 'error' ); }
		if ( ! $webhook_secret ) {
			self::redirect( __( '現在のモードのWebhook Signing Secretを設定してください。', 'picot-subscription-membership' ), 'error' ); }
		if ( ! $secret || ! preg_match( '/^(?:sk|rk)_' . $mode . '_/', $secret ) ) {
			self::redirect( __( '現在のモードに一致するStripe Secret Keyを設定してください。', 'picot-subscription-membership' ), 'error' ); }
		$response = Picot_Subscription_Membership_Stripe_Gateway::request( 'GET', 'balance' );
		self::redirect( is_wp_error( $response ) ? $response->get_error_message() : __( 'Stripe APIへの接続に成功しました。', 'picot-subscription-membership' ), is_wp_error( $response ) ? 'error' : 'success' );
	}

	/**
	 * Create or update the selected generated Pages.
	 *
	 * @return void
	 */
	public static function create_pages() {
		if ( ! current_user_can( 'manage_membership_settings' ) || ! current_user_can( 'publish_pages' ) || ! check_admin_referer( 'psm_create_pages' ) ) {
			wp_die( esc_html__( 'この操作を行う権限がありません。', 'picot-subscription-membership' ) ); }
		$s                = self::settings();
		$membership_pages = array(
			'account_page_id'  => '[membership_account]',
			'plans_page_id'    => '[membership_plans]',
			'login_page_id'    => '[membership_login]',
			'register_page_id' => '[membership_register]',
		);
		$policy_page_keys = array( 'terms_page_id', 'privacy_page_id', 'subscription_policy_page_id', 'contact_page_id' );
		$requested_key    = sanitize_key( self::posted_scalar( 'psm_page_key' ) );
		$requested_group  = sanitize_key( self::posted_scalar( 'psm_page_group' ) );
		if ( $requested_group && 'policy' !== $requested_group ) {
			self::redirect( __( '作成するページの指定が正しくありません。', 'picot-subscription-membership' ), 'error' );
		}
		if ( $requested_group && $requested_key ) {
			self::redirect( __( '作成するページの指定が正しくありません。', 'picot-subscription-membership' ), 'error' );
		}
		if ( $requested_key && ! isset( $membership_pages[ $requested_key ] ) && ! in_array( $requested_key, $policy_page_keys, true ) ) {
			self::redirect( __( '作成するページの指定が正しくありません。', 'picot-subscription-membership' ), 'error' );
		}
		$pages          = $requested_key ? array( $requested_key => $membership_pages[ $requested_key ] ?? '' ) : ( 'policy' === $requested_group ? array_fill_keys( $policy_page_keys, '' ) : $membership_pages );
		$slug_conflicts = array();
		foreach ( $pages as $key => $shortcode ) {
			$existing_page      = self::selected_page( $s[ $key ] );
			$is_membership_page = isset( $membership_pages[ $key ] );
			$slug               = $is_membership_page ? self::generated_membership_page_slug( $key ) : self::generated_policy_page_slug( $key );
			$slug_page          = $slug ? get_page_by_path( $slug, OBJECT, 'page' ) : null;
			$has_slug_conflict  = $slug_page && ( ! $existing_page || (int) $slug_page->ID !== (int) $existing_page->ID );
			if ( $has_slug_conflict ) {
				$slug_conflicts[] = '/' . $slug; }
			if ( $existing_page ) {
				if ( $is_membership_page && 'page' === $existing_page->post_type && self::can_upgrade_generated_membership_page( $existing_page->post_content, $shortcode ) ) {
					$update = array(
						'ID'           => $existing_page->ID,
						'post_content' => self::generated_membership_page_content( $key ),
					);
					if ( ! $has_slug_conflict ) {
						$update['post_name'] = $slug; }
					wp_update_post( $update );
				}
				continue;
			}
			if ( $has_slug_conflict ) {
				continue; }
			$id = wp_insert_post(
				array(
					'post_title'   => $is_membership_page ? self::generated_membership_page_title( $key ) : self::generated_policy_page_title( $key ),
					'post_name'    => $slug,
					'post_content' => $is_membership_page ? self::generated_membership_page_content( $key ) : self::generated_policy_page_content( $key ),
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( ! is_wp_error( $id ) && $id ) {
				$s[ $key ] = (int) $id; }
		}
		update_option( 'psm_settings', $s, false );
		$return_page = 'psm-setup-guide' === self::posted_scalar( 'psm_return_page' ) ? 'psm-setup-guide' : 'psm-settings';
		$next_step   = min( 8, max( 1, absint( self::posted_scalar( 'psm_setup_step', 1 ) ) ) );
		if ( $slug_conflicts ) {
			/* translators: %s: comma-separated generated page URLs that already exist. */
			self::redirect( sprintf( __( 'ページを作成しました。ただし、次のURLは既存の固定ページで使用されているため変更していません: %s', 'picot-subscription-membership' ), implode( ', ', $slug_conflicts ) ), 'error', $return_page, $next_step );
		}
		self::redirect( $requested_key ? __( '固定ページを作成しました。', 'picot-subscription-membership' ) : ( 'policy' === $requested_group ? __( '規約・サポートページを作成しました。', 'picot-subscription-membership' ) : __( '会員用ページを作成しました。', 'picot-subscription-membership' ) ), 'success', $return_page, $next_step );
	}

	/**
	 * Redirect back to a plugin admin page with a notice.
	 *
	 * @param string $message    Notice text.
	 * @param string $type       Notice type.
	 * @param string $page       Admin page slug.
	 * @param int    $setup_step Setup-guide step.
	 * @return void
	 */
	private static function redirect( $message, $type = 'success', $page = 'psm-settings', $setup_step = 0 ) {
		$page = in_array( $page, array( 'psm-settings', 'psm-setup-guide' ), true ) ? $page : 'psm-settings';
		$args = array(
			'page'            => $page,
			'psm_notice'      => rawurlencode( $message ),
			'psm_notice_type' => $type,
		);
		if ( 'psm-setup-guide' === $page && $setup_step ) {
			$args['psm_setup_step'] = min( 8, max( 1, absint( $setup_step ) ) );
		} wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit; }
}
