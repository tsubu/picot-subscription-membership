<?php
/**
 * Translation support for the plugin display language.
 *
 * @package Picot_Subscription_Membership
 */

defined( 'ABSPATH' ) || exit;

/** Provides the English base copy while retaining the established Japanese copy. */
final class Picot_Subscription_Membership_I18n {
	/**
	 * Cached catalog locations, keyed by locale.
	 *
	 * @var array<string, string>
	 */
	private static $locale_files = array();

	/**
	 * Loaded catalog readers, keyed by locale.
	 *
	 * @var array<string, WP_Translation_File|false>
	 */
	private static $translation_files = array();

	/**
	 * Register translation hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'gettext', array( __CLASS__, 'translate' ), 10, 3 ); }

	/** Return built-in and installed translation locales for the language selector. */
	public static function available_locales() {
		$locales = array(
			'ja_JP' => '日本語',
			'en_US' => 'English',
		);
		global $wp_textdomain_registry;
		if ( ! $wp_textdomain_registry instanceof WP_Textdomain_Registry ) {
			return $locales; }
		foreach ( array( PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'languages', WP_LANG_DIR . '/plugins' ) as $path ) {
			foreach ( $wp_textdomain_registry->get_language_files_from_path( $path ) as $file ) {
				if ( ! preg_match( '/^picot-subscription-membership-([a-z]{2,3}(?:_[A-Z]{2})?)\.(?:mo|l10n\.php)$/', wp_basename( $file ), $matches ) ) {
					continue; }
				$locale                        = $matches[1];
				self::$locale_files[ $locale ] = $file;
				$locales[ $locale ]            = self::locale_label( $locale );
			}
		}
		return $locales;
	}

	/**
	 * Return a stable native label for common locales, or the locale code for an added catalog.
	 *
	 * @param string $locale Locale code.
	 * @return string
	 */
	private static function locale_label( $locale ) {
		$labels = array(
			'ar'    => 'العربية',
			'de_DE' => 'Deutsch',
			'en_GB' => 'English (UK)',
			'es_ES' => 'Español',
			'fr_FR' => 'Français',
			'it_IT' => 'Italiano',
			'ko_KR' => '한국어',
			'nl_NL' => 'Nederlands',
			'pt_BR' => 'Português (Brasil)',
			'pt_PT' => 'Português',
			'ru_RU' => 'Русский',
			'th'    => 'ไทย',
			'zh_CN' => '简体中文',
			'zh_TW' => '繁體中文',
		);
		return $labels[ $locale ] ?? $locale;
	}

	/**
	 * Check that a submitted locale is available to this plugin.
	 *
	 * @param string $locale Locale code.
	 * @return bool
	 */
	public static function is_available_locale( $locale ) {
		return array_key_exists( (string) $locale, self::available_locales() ); }

	/**
	 * Retrieve a translation from the catalog selected in the plugin settings.
	 *
	 * @param string $locale Locale code.
	 * @param string $text   Source text.
	 * @return string|false
	 */
	private static function file_translation( $locale, $text ) {
		if ( empty( self::$locale_files[ $locale ] ) ) {
			self::available_locales(); }
		if ( empty( self::$locale_files[ $locale ] ) || ! class_exists( 'WP_Translation_File' ) ) {
			return false; }
		if ( ! array_key_exists( $locale, self::$translation_files ) ) {
			self::$translation_files[ $locale ] = WP_Translation_File::create( self::$locale_files[ $locale ] ); }
		return self::$translation_files[ $locale ] ? self::$translation_files[ $locale ]->translate( $text ) : false;
	}

	/**
	 * Translate the plugin text according to the selected display locale.
	 *
	 * @param string $translation Existing translation.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public static function translate( $translation, $text, $domain ) {
		if ( 'picot-subscription-membership' !== $domain ) {
			return $translation; }
		$locale = Picot_Subscription_Membership_Stripe_Gateway::current_locale();
		if ( 'ja_JP' === $locale ) {
			return $translation; }
		if ( 'en_US' !== $locale ) {
			$file_translation = self::file_translation( $locale, $text );
			return is_string( $file_translation ) ? $file_translation : $translation; }
		$translations = self::english_translations();
		return $translations[ $text ] ?? $translation;
	}
	/**
	 * Return the built-in English translations.
	 *
	 * @return array<string, string>
	 */
	private static function english_translations() {
		static $translations = null;
		if ( null !== $translations ) {
			return $translations; }
		$translations = array(
			'有料会員限定記事にする'                                  => 'Make this a paid member-only article',
			'チェックすると、有効な有料会員だけが全文を読めます。'                   => 'When selected, only active paid members can read the full article.',
			'ログイン会員限定記事にする'                                => 'Make this a logged-in member-only article',
			'有料会員限定と同時に選択した場合は、有料会員限定が優先されます。'             => 'When both options are selected, paid member-only access takes priority.',
			'個別販売には、設定した販売通貨の価格を入力してください。'                 => 'Enter a price in the selected sales currency to sell this article individually.',
			'対象プラン（任意）'                                    => 'Eligible plans (optional)',
			'選択した場合は、そのプランの会員だけが閲覧できます。未選択なら、すべての有料会員が対象です。' => 'When selected, only members of those plans can view the article. Leave blank to allow all paid members.',
			'この記事を個別販売する'                                  => 'Sell this article individually',
			'販売ロケールに応じて、日本ではJPY、米国ではUSDの価格が使われます。両方の価格を設定してください。' => 'JPY is used for Japan and USD for the United States. Set both prices.',
			'JPYは1円以上、USDは0.01ドル以上で入力してください。'              => 'Enter at least ¥1 for JPY or $0.01 for USD.',
			'非会員に表示する概要と無償時に表示する記事は、本文エリアの「Membership 公開概要」メタボックスに入力してください。' => 'Enter the non-member summary and free-view message in the “Membership Public Summary” meta box in the main editor area.',
			'Membership 公開概要'                              => 'Membership Public Summary',
			'Membership 限定コンテンツ'                           => 'Membership Restricted Content',
			'会員限定コンテンツ'                                    => 'Member-only content',
			'閲覧制限'                                         => 'Content access',
			'対象'                                           => 'Access',
			'すべての有料会員'                                     => 'All paid members',
			'対象プラン'                                        => 'Eligible plans',
			'指定プランの会員'                                     => 'Members of selected plans',
			'有効なプランがありません。先にMembershipのプランを作成してください。'      => 'No active plans are available. Create a Membership plan first.',
			'指定プランの会員だけに表示されます。'                           => 'Visible only to members of the selected plans.',
			'有料会員だけに表示されます。'                               => 'Visible only to paid members.',
			'非会員に表示する概要を入力してください。ここに入力した内容だけが本文の代わりに出力されます。空欄の場合、WordPressの手動抜粋を使用します。限定本文はページソース、REST API、RSSには出力されません。' => 'Enter the summary shown to non-members. Only this content is output instead of the article body. If blank, the WordPress manual excerpt is used. Protected content is not exposed in page source, the REST API, or RSS.',
			'無償時に表示される記事'                                  => 'Free-view message',
			'この記事の続きを読むには会員プランへの加入が必要です。'                  => 'Join a membership plan to continue reading this article.',
			'この記事の続きを読むにはログインまたは会員登録が必要です。'                => 'Log in or register to continue reading this article.',
			'プランを見る'                                       => 'View plans',
			'会員登録・ログイン'                                    => 'Register or log in',
			'この記事を %s で購入'                                 => 'Buy this article for %s',
			'個別購入にはログインまたは会員登録が必要です。'                      => 'Log in or register to purchase this article.',
			'現在の表示言語用の料金プランはまだ設定されていません。'                  => 'No pricing plans have been configured for the current display language.',
			'申し込む'                                         => 'Subscribe',
			'ログイン後にお申し込みいただけます。'                           => 'You can subscribe after logging in.',
			'マイページ'                                        => 'My account',
			'マイページでできること'                                  => 'What you can do on this page',
			'表示名・メールアドレスとパスワードを、このページで変更できます。'             => 'You can update your display name, email address, and password on this page.',
			'有効な会員は、現在のプラン、更新予定日、利用可能期限を確認できます。'           => 'Active members can check their current plan, next renewal date, and access expiry.',
			'プラン変更・解約・支払い方法の変更は、会員情報の「プラン・支払い方法を管理」から行えます。' => 'Use “Manage plan and payment methods” in Membership information to change or cancel your plan and update payment methods.',
			'会員プログラムについて'                                  => 'About the membership program',
			'会員プランは定期課金です。選択した請求間隔でStripeを通じて自動的に更新されます。'  => 'Membership plans renew automatically through Stripe at the billing interval you select.',
			'加入後は、対象となる会員限定記事をすぐに閲覧できます。'                  => 'After subscribing, you can immediately access eligible member-only articles.',
			'プランの変更や解約は、マイページのStripe Customer Portalから手続きできます。' => 'You can change or cancel a plan through Stripe Customer Portal on your account page.',
			'ログインについて'                                     => 'About logging in',
			'ログインすると、会員限定記事、購入済み記事、マイページを利用できます。'          => 'After logging in, you can access member-only articles, purchased articles, and your account page.',
			'パスワードを忘れた場合は、ログインフォームの「パスワードをお忘れですか？」から再設定できます。' => 'If you forget your password, use “Lost your password?” on the login form to reset it.',
			'セキュリティのため、共用端末ではログイン状態を保存しないでください。'           => 'For security, do not keep your login session on a shared device.',
			'登録から利用開始まで'                                   => 'From registration to getting started',
			'会員登録は無料です。登録後、プラン一覧からご希望の有料プランにお申し込みいただけます。'  => 'Registration is free. After registering, choose and subscribe to a paid plan from the plan list.',
			'決済はStripeの安全な決済画面で行われ、カード情報はこのサイトには保存されません。'  => 'Payments are processed on Stripe’s secure checkout page, and this site does not store card details.',
			'お申し込み後は、マイページからプランと支払い方法を管理できます。'             => 'After subscribing, you can manage your plan and payment methods from your account page.',
			'登録により、利用規約とプライバシーポリシーに同意したものとします。'            => 'By registering, you agree to the Terms of Service and Privacy Policy.',
			'アカウント情報'                                      => 'Account information',
			'アカウント情報を保存'                                   => 'Save account information',
			'パスワードを変更'                                     => 'Change password',
			'現在のパスワード'                                     => 'Current password',
			'新しいパスワード'                                     => 'New password',
			'新しいパスワード（確認）'                                 => 'Confirm new password',
			'パスワードを更新'                                     => 'Update password',
			'プランの変更・解約、支払い方法の変更はStripe Customer Portalで行えます。' => 'You can change or cancel your plan and update payment methods in Stripe Customer Portal.',
			'プラン・支払い方法を管理'                                 => 'Manage plan and payment methods',
			'プラン変更と支払い方法の管理は、現在利用できません。サイト管理者にお問い合わせください。' => 'Plan changes and payment management are currently unavailable. Please contact the site administrator.',
			'表示名と有効なメールアドレスを入力してください。'                     => 'Enter a display name and a valid email address.',
			'このメールアドレスはすでに使用されています。'                       => 'This email address is already in use.',
			'アカウント情報を更新しました。'                              => 'Account information updated.',
			'現在のパスワードが正しくありません。'                           => 'The current password is incorrect.',
			'新しいパスワードは8文字以上で入力してください。'                     => 'Enter a new password with at least 8 characters.',
			'新しいパスワードが一致しません。'                             => 'The new passwords do not match.',
			'パスワードを更新しました。'                                => 'Password updated.',
			'現在のプラン'                                       => 'Current plan',
			'契約状態'                                         => 'Subscription status',
			'次回更新'                                         => 'Next renewal',
			'利用可能期限'                                       => 'Access until',
			'運営特典延長'                                       => 'Manual access extension',
			'購入済み記事'                                       => 'Purchased articles',
			'購入日: %s'                                      => 'Purchased: %s',
			'解約予約済み'                                       => 'Cancellation scheduled',
			'現在の契約期間終了まで利用できます。'                           => 'You can continue using the service until the end of the current billing period.',
			'契約・支払い方法を管理'                                  => 'Manage subscription and payment methods',
			'すでにログインしています。'                                => 'You are already logged in.',
			'メールアドレス'                                      => 'Email address',
			'表示名'                                          => 'Display name',
			'パスワード'                                        => 'Password',
			'パスワードをお忘れですか？'                                => 'Lost your password?',
			'Webサイト'                                       => 'Website',
			'利用規約に同意する'                                    => 'I agree to the Terms of Service',
			'プライバシーポリシーに同意する'                              => 'I agree to the Privacy Policy',
			'プライバシーポリシー'                                   => 'Privacy Policy',
			'解約・返金ポリシー'                                    => 'Cancellation and refund policy',
			'お問い合わせ'                                       => 'Contact us',
			'サポートが必要な場合は%sをご利用ください。'                       => 'If you need support, please use %s.',
			'お申し込み前にご確認ください'                               => 'Please review before subscribing',
			'%sに同意する'                                      => 'I agree to %s',
			'利用規約、プライバシーポリシー、解約・返金ポリシーのページを設定してから決済を開始してください。' => 'Configure the Terms of Service, Privacy Policy, and cancellation and refund policy pages before accepting payments.',
			'会員登録を開始するには、利用規約、プライバシーポリシー、解約・返金ポリシーのページを設定してください。' => 'Configure the Terms of Service, Privacy Policy, and cancellation and refund policy pages before enabling registration.',
			'会員登録'                                         => 'Register',
			'決済機能は現在利用できません。'                              => 'Payments are currently unavailable.',
			'Stripe Secret Key が設定されていません。'                => 'The Stripe Secret Key has not been configured.',
			'決済サービスに接続できませんでした。時間をおいてもう一度お試しください。'         => 'Could not connect to the payment service. Please try again later.',
			'決済サービスでエラーが発生しました。時間をおいてもう一度お試しください。'         => 'The payment service returned an error. Please try again later.',
			'Webhookイベントを記録できませんでした。Stripeが配信を再試行します。'     => 'Webhook event could not be recorded. Stripe will retry the delivery.',
			'ユーザー情報が見つかりません。'                              => 'User information was not found.',
			'料金プランが見つかりません。'                               => 'The pricing plan was not found.',
			'すでに処理中または有効な契約があります。契約の変更・確認はCustomer Portalから行ってください。' => 'You already have a pending or active subscription. Use the Customer Portal to review or change it.',
			'すでに別のプランの申込処理が進行中です。完了またはキャンセルしてから再度お試しください。' => 'A subscription for another plan is already pending. Complete or cancel it before trying again.',
			'契約管理機能は現在利用できません。'                            => 'Subscription management is currently unavailable.',
			'Stripe顧客情報がありません。'                            => 'Stripe customer information is unavailable.',
			'この記事は個別購入できません。'                              => 'This article is not available for individual purchase.',
			'不正な購入リクエストです。'                                => 'Invalid purchase request.',
			'ステップ %1$d / %2$d'                             => 'Step %1$d of %2$d',
			'戻る'                                           => 'Back',
			'次へ'                                           => 'Next',
			'スキップして次へ'                                     => 'Skip and continue',
			'最初から見る'                                       => 'Start over',
			'保存して次へ'                                       => 'Save and continue',
			'作成して次へ'                                       => 'Create and continue',
			'販売設定を保存しました。'                                 => 'Sales settings saved.',
			'コピー'                                          => 'Copy',
			'コピーしました'                                      => 'Copied',
			'会員情報'                                         => 'Membership information',
			'アカウント情報・パスワード、会員プラン、契約状態、利用可能期限、購入済み記事を確認・管理できます。' => 'View and manage account information, passwords, your membership plan, subscription status, access period, and purchased articles.',
			'ご希望の会員プランを選択してお申し込みください。'                     => 'Choose the membership plan that suits you and subscribe.',
			'登録済みの会員の方は、メールアドレスとパスワードでログインしてください。'         => 'Registered members can log in with their email address and password.',
			'はじめての方は、会員登録後にプランをお申し込みいただけます。'               => 'New visitors can register first and then subscribe to a plan.',
			'下のボタンを実行すると、WordPressの固定ページとして、マイページ・プラン一覧・ログイン・会員登録の4ページを実際に新規作成し、それぞれに必要なショートコードを自動入力します。すでに設定済みのページは上書きしません。' => 'The button below creates four actual WordPress Pages—account, plan list, login, and registration—and automatically adds the required shortcode to each. Pages already configured are not overwritten.',
			'作成したページは、見出し・説明文・会員機能のショートコードを含む通常のブロックレイアウトです。固定ページの編集画面で、文章・ブロック・レイアウトを自由に編集できます。' => 'Created pages use a standard block layout with a heading, explanatory text, and the membership shortcode. You can freely edit the text, blocks, and layout in the Page editor.',
			'4つの固定ページを作成して次へ'                              => 'Create four Pages and continue',
			'固定ページを手動で新規作成'                                => 'Create a Page manually',
			'「Stripe Test APIキー画面を開く」を押します。Stripe Dashboardから開く場合は、「開発者」→「APIキー」を選び、Testモードになっていることを確認します。' => 'Select “Open Stripe Test API keys.” To open it from Stripe Dashboard, choose Developers → API keys and make sure Test mode is selected.',
			'「Stripe Test Webhook画面を開く」を押します。Stripe Dashboardから開く場合は、「開発者」→「Webhook」を選び、Testモードになっていることを確認します。' => 'Select “Open Stripe Test Webhooks.” To open it from Stripe Dashboard, choose Developers → Webhooks and make sure Test mode is selected.',
			'実決済の前に、必ずStripeのTestモードで動作確認します。'             => 'Always test the payment flow in Stripe Test mode before accepting live payments.',
			'決済完了や解約などの状態をStripeから受け取るために、Webhookを登録します。'  => 'Register a webhook so Stripe can notify this site about completed payments, cancellations, and other status changes.',
			'ローカル環境ではWebhookを登録・受信できません。'                  => 'Webhooks cannot be registered or received in a local environment.',
			'localhostやMAMPなどのローカルURLにはStripeからアクセスできないため、公開済みのHTTPS環境（本番またはステージング）で登録・確認してください。' => 'Stripe cannot access local URLs such as localhost or MAMP. Register and test the webhook on a publicly accessible HTTPS production or staging site.',
			'新規投稿で「有料会員限定記事にする」にチェックを入れて公開します。個別販売する場合は「この記事を個別販売する」にもチェックを入れ、選択中の販売通貨で価格を入力します。非会員に見せる概要と無償時の記事は本文下のMembership 公開概要に入力します。' => 'Create and publish a post with “Make this a paid member-only article” selected. To sell it individually, also select “Sell this article individually” and enter a price in the selected sales currency. Enter the non-member summary and free-view message in the Membership Public Summary box below the editor.',
			'サイトをHTTPSで公開してから、設定でLiveモードを選択し、Live用のAPIキーとWebhook Signing Secretを入力します。TestとLiveのキー、商品、価格、Webhookは共有できません。少額の実決済とWebhook受信を確認してから公開してください。' => 'After publishing the site over HTTPS, select Live mode in Settings and enter the Live API keys and Webhook Signing Secret. Test and Live keys, products, prices, and webhooks are separate. Confirm a small live payment and webhook receipt before launch.',
			'設定'                                           => 'Settings',
			'現在はWordPressのパーマリンク設定が「基本」になっているため、作成した会員ページは ?page_id= のURLで表示されます。/mypage などの固定URLを使うには、%sで「基本」以外の形式を保存してください。' => 'WordPress is currently using Plain permalinks, so the generated membership pages use ?page_id= URLs. To use fixed URLs such as /mypage, open %s and save any option other than Plain.',
			'パーマリンク設定を開く'                                  => 'Permalink Settings',
			'Membership 設定'                                => 'Membership Settings',
			'設定状況'                                         => 'Setup status',
			'設定済み'                                         => 'Configured',
			'未設定・要確認'                                      => 'Not configured / review required',
			'会員ページ'                                        => 'Membership pages',
			'規約・サポートページ'                                   => 'Policy and support pages',
			'規約・サポートページのテンプレート'                            => 'Policy and support page templates',
			'規約・サポートページを作成して内容を確認'                         => 'Create policy and support pages and review their content',
			'作成したページは一般的な構成の通常のブロックレイアウトです。固定ページの編集画面で、事業者情報、料金、解約・返金条件、連絡先などを実際の運営方針に合わせて編集してください。' => 'Created pages use a standard block layout with a general structure. In the Page editor, update business information, fees, cancellation and refund terms, contact details, and other content to match your actual policies.',
			'4つの規約・サポートページを作成して次へ'                         => 'Create four policy and support Pages and continue',
			'規約・サポートページを作成しました。'                           => 'Policy and support pages created.',
			'利用規約、プライバシーポリシー、解約・返金ポリシーは、会員登録と決済を始める前に必要です。未作成のページは、下のボタンで編集可能な固定ページテンプレートとして作成できます。' => 'Terms of Service, Privacy Policy, and the cancellation and refund policy are required before enabling registration and payments. Create any missing page below as an editable Page template.',
			'自動作成・更新'                                      => 'Create or update automatically',
			'ページテンプレートを作成'                                 => 'Create page template',
			'作成するページの指定が正しくありません。'                         => 'The requested page is not valid.',
			'固定ページを作成しました。'                                => 'Page created.',
			'ページを作成しました。ただし、次のURLは既存の固定ページで使用されているため変更していません: %s' => 'Pages were created, but these URLs are already used by existing Pages and were not changed: %s',
			'利用規約'                                         => 'Terms of Service',
			'このテンプレートは一般的な構成です。実際のサービス内容、販売地域、適用法令に合わせて、公開前に必ず編集・確認してください。' => 'This template uses a general structure. Before publishing, edit and verify it for your actual service, sales region, and applicable laws.',
			'このテンプレートには一般的な会員制サービスの内容をあらかじめ入力しています。角括弧（[ ]）の箇所と、実際のサービス内容、販売地域、適用法令に関わる箇所だけを公開前に必ず確認・編集してください。' => 'This template is prefilled with common membership-service content. Before publishing, review and edit the bracketed [ ] fields and any content that depends on your actual service, sales region, or applicable law.',
			'適用'                                           => 'Application',
			'本規約は、本サイトで提供する会員向けコンテンツおよび個別購入コンテンツの利用条件を定めるものです。利用者は、会員登録、申込みまたは購入を行った時点で本規約に同意したものとします。' => 'These Terms set out the conditions for using the membership content and individually purchased content offered on this site. Users agree to these Terms when they register, subscribe, or make a purchase.',
			'事業者情報'                                        => 'Business information',
			'事業者名: [事業者名] ／ 所在地: [所在地] ／ 連絡先: [メールアドレスまたはお問い合わせページ]。提供するサービスの内容や対象地域に応じて、必要な情報を追記してください。' => 'Business name: [business name] / Address: [address] / Contact: [email address or contact page]. Add any information required for the services offered and regions served.',
			'料金・支払い'                                       => 'Fees and payment',
			'会員プランおよび個別購入コンテンツの価格、請求時期、支払い方法は、申込画面または購入画面に表示します。決済はStripeが提供する決済画面を通じて処理されます。' => 'Prices, billing dates, and payment methods for membership plans and individual content purchases are shown at checkout. Payments are processed through Stripe checkout.',
			'自動更新・解約・返金'                                   => 'Automatic renewal, cancellation, and refunds',
			'会員プランは、表示された請求間隔で自動更新されます。解約方法、解約の反映時期、返金の可否および条件は、解約・返金ポリシーに定める内容に従います。' => 'Membership plans renew automatically at the displayed billing interval. Cancellation methods, when cancellation takes effect, and refund eligibility and conditions are governed by the cancellation and refund policy.',
			'禁止事項・免責'                                      => 'Prohibited conduct and disclaimer',
			'利用者は、コンテンツの不正利用、第三者の権利侵害、サービス運営を妨げる行為などを行ってはなりません。サービスの停止、変更またはコンテンツの提供に関する責任の範囲は、適用法令の範囲で制限されます。' => 'Users must not misuse content, infringe third-party rights, or interfere with service operations. Liability for suspension, changes, or content provision is limited to the extent permitted by applicable law.',
			'規約の変更'                                        => 'Changes to these Terms',
			'本規約を変更する場合は、本サイト上で変更内容と適用日を告知します。継続してサービスを利用した場合、変更後の規約に同意したものとして扱われる場合があります。' => 'If these Terms change, the changes and effective date will be announced on this site. Continued use of the service may be treated as acceptance of the updated Terms.',
			'基本方針'                                         => 'General policy',
			'本サイトは、会員向けサービスおよびコンテンツ販売の運営に必要な範囲で個人情報を取り扱います。個人情報は、利用目的の達成に必要な範囲で適切に管理します。' => 'This site handles personal information only as needed to operate membership services and content sales. Personal information is managed appropriately within the scope needed to achieve the stated purposes.',
			'取得する情報'                                       => 'Information collected',
			'会員登録時に、メールアドレス、表示名、ログイン情報などを取得します。お問い合わせ時には、氏名、連絡先、問い合わせ内容などを取得する場合があります。カード情報はStripeの決済画面で処理されます。' => 'When users register, we collect information such as email addresses, display names, and login information. When users contact us, we may collect names, contact details, and enquiry content. Card information is processed on Stripe checkout.',
			'利用目的'                                         => 'How information is used',
			'取得した情報は、アカウント管理、本人確認、決済・購入の処理、会員限定コンテンツの提供、お問い合わせへの対応、重要なお知らせの送付、不正利用の防止のために利用します。' => 'Collected information is used for account administration, identity verification, payment and purchase processing, provision of member-only content, support, essential notices, and fraud prevention.',
			'外部サービス・情報の共有'                                 => 'External services and information sharing',
			'決済処理のためにStripeなどの外部サービスを利用します。法令に基づく場合または業務委託に必要な場合を除き、本人の同意なく個人情報を第三者へ提供しません。' => 'We use external services such as Stripe to process payments. We do not provide personal information to third parties without consent except where required by law or necessary for a service provider to perform work on our behalf.',
			'開示等の請求・お問い合わせ'                                => 'Requests for access and enquiries',
			'個人情報に関する開示、訂正、削除、利用停止等の請求は、[メールアドレスまたはお問い合わせページ]までご連絡ください。本人確認のうえ、法令および合理的な範囲で対応します。' => 'For requests concerning access to, correction, deletion, or suspension of use of personal information, contact [email address or contact page]. We will respond after verifying identity, within the limits of applicable law and reason.',
			'請求と自動更新'                                      => 'Billing and automatic renewal',
			'会員プランは、申込画面に表示された料金と請求間隔により課金されます。利用者が解約手続きを完了しない限り、契約は各請求期間の終了時に自動更新されます。' => 'Membership plans are billed at the price and billing interval shown at checkout. Unless the user completes cancellation, the subscription renews automatically at the end of each billing period.',
			'解約方法と利用可能期間'                                  => 'Cancellation and access period',
			'解約は、マイページから開くStripe Customer Portal、または[お問い合わせページ]を通じて手続きできます。解約後も、原則として現在の請求期間の終了日まで会員向けサービスを利用できます。' => 'Users may cancel through Stripe Customer Portal opened from their account page, or through [contact page]. After cancellation, members can generally continue using the service until the end of the current billing period.',
			'返金'                                           => 'Refunds',
			'返金の可否・条件: [返金の可否、対象期間、申請方法を記載してください]。法令上の義務がある場合を除き、すでに提供された会員期間または個別購入コンテンツについての返金可否は、この方針に従います。' => 'Refund eligibility and conditions: [state eligibility, applicable period, and request method]. Except where required by law, refunds for membership access already provided or individual content purchases are governed by this policy.',
			'価格・税金・決済失敗'                                   => 'Prices, tax, and failed payments',
			'表示価格の税金の扱いは、申込画面または購入画面に表示します。決済に失敗した場合は、Stripeからの案内に従って支払い方法を更新してください。利用停止時期は、サイトで定める猶予期間がある場合を除き、決済状況に応じます。' => 'How tax is handled for displayed prices is shown at checkout. If a payment fails, update the payment method as directed by Stripe. Service suspension timing depends on payment status unless this site provides a grace period.',
			'お問い合わせ窓口'                                     => 'Contact channel',
			'お問い合わせは、[問い合わせフォームURL] または [サポート用メールアドレス]から受け付けます。対応時間: [受付時間・休業日]。通常、[返信目安]以内に返信します。' => 'Contact us through [contact form URL] or [support email address]. Support hours: [business hours and holidays]. We normally respond within [expected response time].',
			'会員・購入に関するお問い合わせ'                              => 'Membership and purchase enquiries',
			'会員登録、プラン変更、解約、個別購入に関するお問い合わせでは、登録メールアドレス、対象のプランまたは購入日時をお知らせください。確認後、必要な対応をご案内します。' => 'For enquiries about registration, plan changes, cancellation, or individual purchases, provide the registered email address and the relevant plan or purchase date. We will review the request and explain the required next steps.',
			'送信しない情報'                                      => 'Information not to send',
			'安全のため、パスワード、カード番号、セキュリティコードなどの決済情報はお問い合わせフォームやメールで送信しないでください。決済情報の変更はStripe Customer Portalをご利用ください。' => 'For security, do not send passwords, card numbers, security codes, or other payment information through a contact form or email. Use Stripe Customer Portal to change payment information.',
			'運営者名、連絡先、所在地、提供するサービスの内容を記載してください。'           => 'Enter the operator name, contact details, location, and the services provided.',
			'利用条件・料金・解約'                                   => 'Conditions, pricing, and cancellation',
			'利用対象者、禁止事項、料金、支払時期、自動更新、解約、返金の扱いを実際の運営方針に合わせて記載してください。' => 'Describe eligibility, prohibited conduct, fees, payment timing, automatic renewal, cancellation, and refunds according to your actual policies.',
			'取得する情報と利用目的'                                  => 'Information collected and its use',
			'会員登録、決済、問い合わせで取得する情報と、その利用目的を記載してください。'       => 'Describe the information collected during registration, payment, and enquiries, and how it is used.',
			'保存・共有・問い合わせ'                                  => 'Retention, sharing, and enquiries',
			'情報の保存期間、第三者提供または委託、開示・訂正・削除の連絡方法を記載してください。'   => 'Describe retention periods, third-party sharing or processing, and how to request access, correction, or deletion.',
			'定期課金と解約'                                      => 'Recurring billing and cancellation',
			'請求の頻度、自動更新の時期、Stripe Customer Portalからの解約手順、解約後に利用できる期間を記載してください。' => 'Describe billing frequency, when automatic renewal occurs, cancellation through Stripe Customer Portal, and access after cancellation.',
			'返金・価格・税金'                                     => 'Refunds, prices, and tax',
			'返金の可否と条件、個別購入の扱い、表示価格と税金の扱いを明記してください。'        => 'Clearly state refund eligibility and conditions, how individual purchases are handled, and how displayed prices and tax are treated.',
			'サポート窓口'                                       => 'Support contact',
			'問い合わせフォーム、メールアドレス、対応時間、返信の目安を記載してください。'       => 'Enter the contact form, email address, support hours, and expected response time.',
			'お問い合わせ時のお願い'                                  => 'When contacting support',
			'登録メールアドレス、契約または購入に関する情報を添えるよう案内してください。カード番号などの決済情報は送らないよう明記してください。' => 'Ask users to include their registered email address and subscription or purchase information. State that payment details such as card numbers must not be sent.',
			'有料会員の申込前に必要な規約と方針を、公開済みの固定ページから選択してください。利用規約・プライバシーポリシー・解約／返金ポリシーが未設定の場合、会員登録と決済は開始できません。' => 'Select published pages for the policies required before members subscribe. Registration and payments cannot begin until the Terms of Service, Privacy Policy, and cancellation and refund policy are configured.',
			'利用規約ページ'                                      => 'Terms of Service page',
			'会員登録と決済前に表示する利用規約です。料金、利用条件、禁止事項などを実際の運営方針に合わせて記載してください。' => 'This is the Terms of Service shown before registration and payment. State pricing, conditions of use, prohibited conduct, and other details that match your actual operations.',
			'プライバシーポリシーページ'                                => 'Privacy Policy page',
			'個人情報の取得目的、保存・第三者提供、問い合わせ先などを記載するページです。WordPressの「設定」→「プライバシー」で指定したページを選べます。' => 'This page should explain why personal information is collected, how it is retained or shared, and how to contact you. You can select the page assigned under Settings → Privacy in WordPress.',
			'解約・返金ポリシーページ'                                 => 'Cancellation and refund policy page',
			'自動更新、解約方法と反映時期、返金の可否・条件、価格に税が含まれるかどうかを明記してください。' => 'Clearly state automatic renewal, how and when cancellation takes effect, whether and when refunds are available, and whether prices include tax.',
			'お問い合わせページ（任意）'                                => 'Contact page (optional)',
			'会員や購入者がサポートへ連絡するための公開ページです。設定すると、会員ページと申込ページにリンクを表示します。' => 'This public page lets members and purchasers contact support. When set, its link is shown on the account and subscription pages.',
			'価格・税に関する注記（任意）'                               => 'Price and tax notice (optional)',
			'プラン一覧と個別購入ボタンの近くに表示する注記です。例: 「表示価格は税込です」「税額は決済時に計算されます」。販売地域と税務方針に合う内容を設定してください。' => 'This notice appears near the plan list and individual-purchase button. For example: “Prices include tax” or “Tax is calculated at checkout.” Set wording that matches your sales region and tax policy.',
			'説明を表示'                                        => 'Show details',
			'自動作成URL'                                      => 'Automatic page URL',
			'Stripe Test設定'                                => 'Stripe Test settings',
			'Stripe Live設定'                                => 'Stripe Live settings',
			'会員ページを設定'                                     => 'Configure membership pages',
			'プランを作成'                                       => 'Create a plan',
			'設定画面を開く'                                      => 'Open settings',
			'プラン管理を開く'                                     => 'Open plan management',
			'手順'                                           => 'Steps',
			'会員ページを作成'                                     => 'Create membership pages',
			'Webhookを登録'                                   => 'Register the webhook',
			'有料記事を設定してTest決済'                              => 'Configure protected content and test payment',
			'Liveモードへ切り替え'                                 => 'Switch to Live mode',
			'Stripeの接続設定'                                  => 'Stripe connection settings',
			'動作モード'                                        => 'Operating mode',
			'表示言語'                                         => 'Display language',
			'日本語'                                          => 'Japanese',
			'英語'                                           => 'English',
			'表示言語の説明を表示'                                   => 'Show display language details',
			'日本語と英語の基本表示に加え、このプラグインの翻訳ファイルがlanguagesフォルダまたはWordPressの言語パックにある言語だけが候補に表示されます。翻訳ファイルを追加すると、次回の画面表示時に選択肢へ自動的に追加されます。' => 'Japanese and English are included by default. Other languages appear only when this plugin has a translation file in its languages folder or in the WordPress language pack. Added translation files appear in the selector on the next page load.',
			'販売通貨'                                         => 'Sales currency',
			'販売通貨の説明を表示'                                   => 'Show sales currency details',
			'Stripeがカード決済で対応する通貨から選べます。選択した通貨でプランと個別記事の価格を設定し、同じ通貨のStripe Price IDを登録してください。アカウントの国や有効な決済手段によって利用できる通貨は異なります。' => 'Choose from currencies that Stripe supports for card payments. Set plan and article prices in the selected currency, and register Stripe Price IDs in that same currency. Available currencies vary by your account country and enabled payment methods.',
			'Stripe接続を確認'                                  => 'Check Stripe connection',
			'プラン'                                          => 'Plans',
			'会員'                                           => 'Members',
			'決済履歴'                                         => 'Payment history',
			'期間変更履歴'                                       => 'Access adjustment history',
			'Stripe同期'                                     => 'Stripe synchronization',
			'ログ'                                           => 'Logs',
			'Webhookログ'                                    => 'Webhook log',
			'会員詳細'                                         => 'Member details',
			'検索'                                           => 'Search',
			'名前・メール'                                       => 'Name or email',
			'すべて'                                          => 'All',
			'絞り込む'                                         => 'Filter',
			'状態'                                           => 'Status',
			'操作'                                           => 'Actions',
			'有効化'                                          => 'Enable',
			'無効化'                                          => 'Disable',
			'利用停止'                                         => 'Suspend access',
			'利用停止を解除'                                      => 'Restore access',
			'この操作を行う権限がありません。'                             => 'You do not have permission to perform this action.',
			'不正なリクエストです。'                                  => 'Invalid request.',
			'会員情報が見つかりません。'                                => 'Membership information was not found.',
			'Stripe契約情報がありません。'                            => 'Stripe subscription information is unavailable.',
			'Stripeと同期'                                    => 'Sync with Stripe',
			'Stripeと同期しました。'                               => 'Synchronized with Stripe.',
			'支払い失敗を受信しました。'                                => 'A payment failure was received.',
			'支払い失敗を受信しましたが、Stripe上の現在状態により会員状態は変更しませんでした。' => 'A payment failure was received, but the membership status was not changed because of the current Stripe status.',
			'紐付く会員が見つかりません。'                               => 'No associated membership was found.',
			'延長日数を指定してください。'                               => 'Specify the number of days to extend.',
			'延長日数は-36500日から36500日の範囲で指定してください。'            => 'Specify an extension between -36500 and 36500 days.',
			'設定を保存しました。'                                   => 'Settings saved.',
			'会員用ページを作成しました。'                               => 'Membership pages created.',
			'会員用ページを作成しました。ただし、次のURLは既存の固定ページで使用されているため変更していません: %s' => 'Membership pages created. However, the following URLs are already used by existing pages and were not changed: %s',
			'現在のモードのWebhook Signing Secretを設定してください。'      => 'Configure the Webhook Signing Secret for the current mode.',
			'現在のモードに一致するStripe Secret Keyを設定してください。'       => 'Configure a Stripe Secret Key that matches the current mode.',
			'Stripe APIへの接続に成功しました。'                       => 'Successfully connected to the Stripe API.',
			'プラン名、Stripe Price ID、1以上の料金を入力してください。'        => 'Enter a plan name, Stripe Price ID, and a price greater than zero.',
			'プラン料金を保存しました。'                                => 'Plan price saved.',
			'このStripe Price IDはすでに登録されています。'               => 'This Stripe Price ID is already registered.',
			'プラン状態を更新しました。'                                => 'Plan status updated.',
			'Membership 閲覧制限'                              => 'Membership access restriction',
			'設定画面で選んだ販売通貨の価格を入力してください。通貨を変更した場合は、記事ごとに新しい通貨の価格を設定してください。' => 'Enter the price in the sales currency selected in Settings. If you change the currency, set a price in the new currency for each article.',
			'Stripe Liveモードでは、サイトをHTTPSで公開してから決済機能を利用してください。' => 'Publish the site over HTTPS before using payments in Stripe Live mode.',
			'Stripe API エラー'                               => 'Stripe API error',
			'現在の契約は期間終了時に解約予定です。期間終了後に新しいプランへお申し込みください。'   => 'Your current subscription is scheduled to end at the end of its billing period. Subscribe to a new plan after it ends.',
			'管理REST APIで利用を停止しました。'                        => 'Access was suspended through the management REST API.',
			'管理REST APIで利用停止を解除しました。'                      => 'Access was restored through the management REST API.',
			'初期設定ガイド'                                      => 'Setup guide',
			'次の順番で設定すると、会員登録から有料記事の閲覧までを安全に確認できます。最初は必ずStripeのTestモードで確認してください。' => 'Complete these steps in order to safely test registration through protected-content access. Always start with Stripe Test mode.',
			'項目'                                           => 'Item',
			'内容'                                           => 'Details',
			'会員登録・ログイン・プラン一覧・マイページを作成します。'                 => 'Create registration, login, plan-list, and account pages.',
			'Test用のSecret KeyとWebhook Signing Secretを登録します。' => 'Register the Test Secret Key and Webhook Signing Secret.',
			'Stripe Dashboardで作成したPrice IDを使い、販売するプランを登録します。' => 'Register plans for sale using Price IDs created in Stripe Dashboard.',
			'公開時にHTTPSサイトでLive用のキーとWebhook Signing Secretを登録します。' => 'Register Live keys and the Webhook Signing Secret after publishing the site over HTTPS.',
			'Stripe設定を開く'                                  => 'Open Stripe settings',
			'Live設定を開く'                                    => 'Open Live settings',
			'設定画面の「会員ページを作成」を実行します。既存のページは上書きされません。'       => 'Run “Create membership pages” in Settings. Existing pages are not overwritten.',
			'StripeのTest用キーを登録'                            => 'Register Stripe Test keys',
			'Stripe Dashboardの「開発者」→「APIキー」から sk_test_ で始まるSecret Keyを取得して登録します。' => 'In Stripe Dashboard, go to Developers → API keys and register the Secret Key beginning with sk_test_.',
			'Stripe Dashboardの「開発者」→「Webhooks」で次のURLをエンドポイントとして登録し、Signing secret（whsec_）を設定画面へ入力します。' => 'In Stripe Dashboard, go to Developers → Webhooks, add the following URL as an endpoint, and enter its Signing secret (whsec_) in Settings.',
			'Stripeで商品・価格を作成し、表示されたPrice IDをMembershipの「プラン」画面へ登録します。' => 'Create a product and price in Stripe, then register its Price ID on the Membership Plans screen.',
			'投稿編集画面の「有料会員限定記事にする」にチェックを入れ、会員登録・決済・概要表示・会員閲覧を確認します。' => 'In the post editor, select “Make this a paid member-only article” and verify registration, payment, summary display, and member access.',
			'サイトをHTTPSで公開後、Live用キーとLive用Webhookを登録し、少額の実決済で最終確認します。' => 'After publishing the site over HTTPS, register the Live keys and Live webhook, then make a small live payment for final verification.',
			'必要な説明は、各項目の「説明を表示」から確認できます。はじめはTestモードで決済の流れを確認してください。' => 'Open “Show details” for explanations of each item. Start by confirming the payment flow in Test mode.',
			'Stripeの初期設定手順を表示'                             => 'Show Stripe setup steps',
			'<a href="%s" target="_blank" rel="noopener noreferrer">Stripe Dashboard</a>にログインし、最初はテストモードを有効にします。' => 'Sign in to the <a href="%s" target="_blank" rel="noopener noreferrer">Stripe Dashboard</a> and first enable Test mode.',
			'Dashboardの「開発者」→「APIキー」で、Publishable KeyとSecret Keyを取得します。<a href="%s" target="_blank" rel="noopener noreferrer">APIキー画面を開く</a>。' => 'In Dashboard, go to Developers → API keys to obtain the Publishable Key and Secret Key. <a href="%s" target="_blank" rel="noopener noreferrer">Open API keys</a>.',
			'Dashboardの「開発者」→「Webhooks」で下記のWebhook URLをエンドポイントとして追加し、そのエンドポイントのSigning secretを取得します。' => 'In Dashboard, go to Developers → Webhooks, add the URL below as an endpoint, and obtain that endpoint’s Signing secret.',
			'キーを保存後に「Stripe接続を確認」を実行します。本番公開時はLive用のキーとWebhookを別途登録し、サイトをHTTPSにしてください。' => 'After saving the keys, run “Check Stripe connection.” Before going live, register the Live keys and webhook separately and use HTTPS for the site.',
			'LiveモードにはHTTPSで公開されたサイトが必要です。現在のサイトURLではLiveモードを保存・利用できません。' => 'Stripe Live mode requires a site published over HTTPS. Live mode cannot be saved or used with the current site URL.',
			'TestとLiveの違いを表示'                              => 'Show Test and Live differences',
			'Testは開発・動作確認用、Liveは実際の決済用です。TestとLiveのキーおよびWebhook Signing Secretは完全に分離されています。' => 'Test mode is for development and verification; Live mode is for real payments. Test and Live keys and Webhook Signing Secrets are fully separate.',
			'キーの取得方法・注意を表示'                                => 'Show how to obtain keys and important notes',
			'Stripe Dashboardで「テストモード」を有効にして取得する値です。キーは pk_test_ / sk_test_ で始まります。' => 'Obtain these values with Test mode enabled in Stripe Dashboard. Keys begin with pk_test_ / sk_test_.',
			'実際の決済に使う値です。Stripe Dashboardを本番モードで表示して取得します。キーは pk_live_ / sk_live_ で始まります。' => 'These values are used for real payments. Obtain them with Stripe Dashboard in Live mode. Keys begin with pk_live_ / sk_live_.',
			'Publishable Keyは「開発者」→「APIキー」にある公開可能キーです。現在の決済はStripe Checkoutへ移動する方式のため、空欄でも決済できます。' => 'The Publishable Key is the publishable key under Developers → API keys. Payments can work without it because checkout redirects to Stripe Checkout.',
			'Secret Keyは同じ画面のシークレットキーです。第三者に渡さず、空欄のまま保存すると設定済みの値は変更されません。' => 'The Secret Key is the secret key on the same page. Do not share it. Leaving this field blank preserves the existing value.',
			'Webhook Signing Secretは「開発者」→「Webhooks」で、このサイトのエンドポイントを開き「Signing secretを表示」から取得する whsec_ で始まる値です。' => 'The Webhook Signing Secret is the value beginning with whsec_ obtained from Developers → Webhooks by opening this site’s endpoint and choosing “Reveal signing secret.”',
			'Webhookの登録方法・受信イベントを表示'                       => 'Show webhook registration and received events',
			'このURLをStripe Dashboardの「開発者」→「Webhooks」でエンドポイントとして登録します。TestモードとLiveモードで、それぞれ登録してください。' => 'Register this URL as an endpoint in Stripe Dashboard under Developers → Webhooks. Register it separately for Test and Live mode.',
			'受信するイベント'                                     => 'Events received',
			'Customer Portalの説明を表示'                        => 'Show Customer Portal details',
			'マイページからStripe Customer Portalを利用する'           => 'Use the Stripe Customer Portal from the account page',
			'会員がプラン変更・解約、カード情報の変更、請求書の確認を行えるStripeの画面です。利用前にStripe Dashboard側でCustomer Portalを有効化し、プラン変更を許可する設定にしてください。' => 'This Stripe page lets members change or cancel plans, update cards, and view invoices. Enable Customer Portal in Stripe Dashboard and allow plan changes before use.',
			'保護対象の投稿タイプ'                                   => 'Protected post types',
			'保護対象の投稿タイプの説明を表示'                             => 'Show protected post type details',
			'選択した投稿タイプの編集画面に、会員限定・単品購入の設定欄を表示します。すでに会員限定に設定した記事を公開へ戻す設定ではないため、個別の記事編集画面で解除してください。' => 'Show member-only and individual-purchase settings in the editors of selected post types. This does not make already protected content public; remove protection in each content editor.',
			'決済失敗時の猶予日数'                                   => 'Grace period after payment failure',
			'猶予日数の説明を表示'                                   => 'Show grace-period details',
			'支払い失敗により会員状態が「past_due」になった後も、有料記事を閲覧できる日数です。0にすると猶予を設けず、すぐに閲覧を停止します。' => 'The number of days members can continue reading protected articles after a payment failure changes their status to “past_due.” Set to 0 to stop access immediately.',
			'データ管理'                                        => 'Data management',
			'アンインストール時のデータ削除'                              => 'Delete data on uninstall',
			'独自テーブルと設定を削除する'                               => 'Delete plugin tables and settings',
			'削除されるデータ・注意を表示'                               => 'Show deleted data and warnings',
			'通常はオフのままにしてください。プラグインを停止するだけではデータは削除されません。有効にしてアンインストールすると会員・決済・購入履歴を含むプラグインのデータを復元できない形で削除します。事前にバックアップを取ってください。' => 'Normally leave this off. Deactivating the plugin does not delete data. If enabled before uninstalling, membership, payment, purchase-history, and other plugin data is permanently deleted. Back up first.',
			'設定を保存'                                        => 'Save settings',
			'セットアップ支援'                                     => 'Setup assistance',
			'各ボタンの説明を表示'                                   => 'Show button details',
			'会員ページを自動作成すると、各ショートコードを入れた4ページを作成し、上の選択欄へ自動設定します。すでに選択済みのページは上書きしません。作成後は必要に応じてページのタイトルや固定ページのURLを調整してください。' => 'Creating membership pages automatically creates four pages containing the relevant shortcodes and sets the selectors above. Already selected pages are not overwritten. Adjust page titles and URLs as needed afterward.',
			'「Stripe接続を確認」は、現在選択中のモードのSecret Key・Webhook Signing Secretが入力済みか確認したうえで、Stripe APIへの接続を確認します。Webhookのイベント受信そのものは、Stripe DashboardのWebhookログでも確認してください。' => '“Check Stripe connection” first confirms that the Secret Key and Webhook Signing Secret for the selected mode are configured, then checks the Stripe API connection. Also verify event delivery in Stripe Dashboard’s webhook logs.',
			'未設定'                                          => 'Not set',
			'設定済み（変更時のみ入力）'                                => 'Configured (enter only to change)',
			'日'                                            => 'days',
			'あり'                                           => 'Yes',
			'現在プラン'                                        => 'Current plan',
			'Stripe有効期限'                                   => 'Stripe expiry',
			'手動延長'                                         => 'Manual extension',
			'実利用期限'                                        => 'Effective access expiry',
			'Webhook履歴'                                    => 'Webhook history',
			'Stripe上の現在の契約状態を取得して、会員情報を同期します。最大50件を処理します。個別同期は会員詳細から実行できます。' => 'Retrieve current subscription status from Stripe and synchronize membership records. Up to 50 records are processed; individual synchronization is available from member details.',
			'Stripe同期を実行しました。同期: %1$d件、失敗: %2$d件、期限切れ更新: %3$d件。' => 'Stripe synchronization completed. Synchronized: %1$d; failed: %2$d; expired records updated: %3$d.',
			'利用を停止しました。'                                   => 'Access suspended.',
			'利用停止を解除しました。'                                 => 'Access restored.',
			'管理者が利用を停止しました。'                               => 'An administrator suspended access.',
			'管理者が利用停止を解除しました。'                             => 'An administrator restored access.',
			'Stripe Liveモードでは、サイトをHTTPSで公開してください。'         => 'Publish the site over HTTPS before using Stripe Live mode.',
			'Stripe Liveモードを有効にするには、サイトをHTTPSで公開してください。'   => 'Publish the site over HTTPS before enabling Stripe Live mode.',
			'コンテンツ保護'                                      => 'Content protection',
			'各会員ページの役割を表示'                                 => 'Show the purpose of each membership page',
			'ショートコードと表示内容を表示'                              => 'Show shortcodes and what they display',
			'各ショートコードを固定ページまたは投稿の本文へ1つだけ入力すると、その場所に会員向けの機能を表示できます。作成済みの会員ページには、対応するショートコードが自動で入力されます。' => 'Enter one shortcode in the content of a page or post to show the relevant membership function at that location. The matching shortcode is added automatically to membership pages created by this plugin.',
			'ログイン中の会員のアカウント情報・パスワード、プラン、契約状態、利用可能期限、購入済み記事を確認・変更できます。Customer Portalを有効にしている場合は、プラン・支払い方法を管理するボタンも表示します。未ログインの場合はログインフォームを表示します。' => 'Logged-in members can view and update account information and passwords, and view their plan, subscription status, access expiry, and purchased articles. When Customer Portal is enabled, it also shows a button to manage plans and payment methods. Visitors who are not logged in see the login form.',
			'設定した販売通貨の有効な会員プランを一覧表示します。ログイン済みのユーザーは、選択したプランのStripe Checkoutへ進めます。' => 'Lists active membership plans in the configured sales currency. Logged-in users can continue to Stripe Checkout for their selected plan.',
			'WordPressのログインフォームを表示します。既存会員がログインして、会員ページや購入済み記事へアクセスするために使います。' => 'Shows the WordPress login form. Existing members use it to access membership pages and purchased articles.',
			'新規ユーザーの会員登録フォームを表示します。メールアドレス・表示名・パスワードと利用規約・プライバシーポリシーへの同意を受け付け、WordPressの購読者アカウントを作成します。' => 'Shows a registration form for new users. It collects an email address, display name, password, and consent to the Terms of Service and Privacy Policy, then creates a WordPress Subscriber account.',
			'マイページ: 会員情報、契約状況、購入済み記事を表示します。'               => 'Account page: Displays member information, subscription status, and purchased articles.',
			'プラン一覧: 月額・年額などの会員プランを選択し、Stripe Checkoutへ進みます。' => 'Plan list: Select monthly, annual, or other membership plans and proceed to Stripe Checkout.',
			'ログイン: 既存会員がログインします。'                          => 'Login: Existing members sign in.',
			'会員登録: 新規ユーザーがWordPressアカウントを作成します。'           => 'Registration: New users create a WordPress account.',
			'%sモード用の情報'                                    => '%s mode information',
			'%s用のAPIキー画面を開く'                               => 'Open %s API keys',
			'%s用のWebhook画面を開く'                             => 'Open %s Webhooks',
			'APIキー画面でPublishable Key（pk_）とSecret Key（sk_）を確認し、この画面の該当欄へ貼り付けます。Live用のSecret Keyは表示された時点で安全な場所へ保管してください。' => 'On the API keys page, find the Publishable Key (pk_) and Secret Key (sk_) and paste them into the corresponding fields here. Store a Live Secret Key securely as soon as it is displayed.',
			'Webhook画面でイベント送信先を作成し、この設定画面に表示されているWebhook URLを登録します。作成後、その送信先のSigning secret（whsec_）をコピーして該当欄へ貼り付けます。' => 'On the Webhooks page, create an event destination and register the Webhook URL displayed on this Settings screen. Then copy that destination’s Signing secret (whsec_) into the corresponding field.',
			'総WordPress会員'                                 => 'Total WordPress users',
			'無料会員'                                         => 'Free members',
			'有料会員'                                         => 'Paid members',
			'解約予約'                                         => 'Cancellation scheduled',
			'直近30日新規'                                      => 'New in last 30 days',
			'Membership登録'                                 => 'Membership records',
			'プラン別会員数'                                      => 'Members by plan',
			'会員数'                                          => 'Members',
			'ユーザー'                                         => 'User',
			'メール'                                          => 'Email',
			'詳細'                                           => 'Details',
			'理由'                                           => 'Reason',
			'延長'                                           => 'Extend',
			'プラン・料金を追加'                                    => 'Add a plan and price',
			'同じプラン名を入力すると、既存プランへ月額・年額などの料金を追加します。'         => 'Enter an existing plan name to add another price, such as a monthly or annual price, to that plan.',
			'名称'                                           => 'Name',
			'説明'                                           => 'Description',
			'表示順'                                          => 'Display order',
			'小さい値ほど先に表示されます。既存プランへの料金追加時は空欄のままにすると変更しません。' => 'Lower values are displayed first. Leave blank when adding a price to an existing plan to keep its current order.',
			'料金'                                           => 'Price',
			'月額'                                           => 'Monthly',
			'年額'                                           => 'Yearly',
			'販売通貨の単位で入力してください。小数を使わない通貨（JPYなど）とISK・UGXは整数、その他は小数第2位まで入力できます。Stripe側にも同じ通貨のPrice IDが必要です。' => 'Enter an amount in the sales currency. For zero-decimal currencies such as JPY, and for ISK and UGX, enter a whole number; for others, enter up to two decimal places. Stripe also needs a Price ID in the same currency.',
			'保存'                                           => 'Save',
			'登録済みプラン'                                      => 'Registered plans',
			'スラッグ'                                         => 'Slug',
			'有効'                                           => 'Active',
			'無効'                                           => 'Inactive',
			'種別'                                           => 'Type',
			'変更日数'                                         => 'Days changed',
			'操作者'                                          => 'Performed by',
			'日時'                                           => 'Date and time',
			'メッセージ'                                        => 'Message',
			'金額'                                           => 'Amount',
			'決済日時'                                         => 'Payment date',
			'受信日時'                                         => 'Received at',
			'エラー'                                          => 'Error',
			'会員マイページ'                                      => 'Membership account',
			'会員プラン'                                        => 'Membership plans',
			'会員ログイン'                                       => 'Membership login',
			'プラン一覧'                                        => 'Plan list',
			'ログイン'                                         => 'Login',
			'入力内容を確認してください。'                               => 'Please review the information you entered.',
			'しばらくしてからもう一度お試しください。'                         => 'Please wait a moment and try again.',
			'上から順に完了すると、Testモードで会員登録・Stripe決済・有料記事の閲覧を確認できます。各ステップのボタン、URL、入力欄を使って進めてください。' => 'Complete the steps in order to test registration, Stripe payments, and protected-article access in Test mode. Use the buttons, URLs, and input fields in each step.',
			'販売設定を確認'                                      => 'Confirm sales settings',
			'最初に表示言語と販売通貨を決めます。選んだ通貨は会員プランと個別購入記事の価格に共通して使われます。' => 'First choose the display language and sales currency. The selected currency is used for membership plans and individually purchased articles.',
			'表示言語・販売通貨を設定'                                 => 'Set display language and sales currency',
			'Stripe Test用APIキーを入力'                         => 'Enter Stripe Test API keys',
			'実決済の前に、必ずStripeのTestモードで動作確認します。Stripeへログイン後、次のボタンからTest用のAPIキー画面を開いてください。' => 'Always verify the integration in Stripe Test mode before accepting real payments. Sign in to Stripe, then use the button below to open the Test API keys page.',
			'Stripe Test APIキー画面を開く'                       => 'Open Stripe Test API keys',
			'「Publishable key」の pk_test_ から始まる値をコピーします。'   => 'Copy the value beginning with pk_test_ from “Publishable key.”',
			'「Secret key」の sk_test_ から始まる値を作成・コピーします。Secret Keyは第三者へ共有しないでください。' => 'Create or copy the value beginning with sk_test_ from “Secret key.” Never share the Secret Key with anyone.',
			'下の入力欄へ貼り付けて保存します。Secret Keyを空欄のまま保存すると、すでに保存済みの値は変更しません。' => 'Paste the values into the fields below and save. Leaving Secret Key blank preserves the value already stored.',
			'Test APIキーを保存'                                => 'Save Test API keys',
			'Stripe Test Webhookを登録'                       => 'Register a Stripe Test webhook',
			'決済完了や解約などの状態をStripeから受け取るために、Webhookを登録します。ローカル環境では外部からアクセスできないため、本番公開後はHTTPSの公開URLで登録してください。' => 'Register a webhook to receive payment completion, cancellation, and other status updates from Stripe. A local environment cannot be reached externally, so register the public HTTPS URL after publishing the site.',
			'登録するWebhook URL'                              => 'Webhook URL to register',
			'Stripe Test Webhook画面を開く'                     => 'Open Stripe Test Webhooks',
			'StripeのWebhook画面で「Create an event destination」を選びます。' => 'On Stripe’s Webhooks page, select “Create an event destination.”',
			'自分のアカウントのイベントを選び、Webhook endpointを送信先として選択します。' => 'Select events from your account and choose Webhook endpoint as the destination.',
			'上のWebhook URLを入力し、画面に表示されるイベントを選択して作成します。'    => 'Enter the Webhook URL above, select the events shown on screen, and create the destination.',
			'作成した送信先を開き、Signing secretを表示して whsec_ から始まる値を下の欄へ貼り付けます。' => 'Open the created destination, reveal its Signing secret, and paste the value beginning with whsec_ into the field below.',
			'Test Webhook Signing Secretを保存'               => 'Save Test Webhook Signing Secret',
			'会員ページを作成して表示を確認'                              => 'Create membership pages and confirm their display',
			'下のボタンを実行すると、マイページ・プラン一覧・ログイン・会員登録の4ページを作成し、それぞれに必要なショートコードを自動入力します。すでに設定済みのページは上書きしません。' => 'The button below creates four pages—account, plan list, login, and registration—and automatically adds the required shortcode to each. Pages already configured are not overwritten.',
			'編集'                                           => 'Edit',
			'表示'                                           => 'View',
			'未作成'                                          => 'Not created',
			'会員プランを登録'                                     => 'Register membership plans',
			'Stripeで商品と定期価格を作成し、Stripe Price IDを取得します。次にプラン画面で、プラン名・Stripe Price ID・価格・請求間隔を入力して保存します。価格と通貨はStripe側のPriceと一致させてください。' => 'Create a product and recurring price in Stripe and obtain its Stripe Price ID. Then enter the plan name, Stripe Price ID, price, and billing interval on the Plans screen. The price and currency must match the Stripe Price.',
			'Stripe Testの商品・価格を開く'                         => 'Open Stripe Test products and prices',
			'プラン入力フォームを開く'                                 => 'Open the plan form',
			'有料記事を作成してTest決済'                              => 'Create a protected article and test payment',
			'新規投稿を開き、「有料会員限定記事にする」にチェックを入れて公開します。個別販売する場合は「この記事を個別販売する」にもチェックを入れ、選択中の販売通貨で価格を入力します。非会員に見せる概要と無償時の記事は本文下のMembership 公開概要に入力します。' => 'Open a new post, select “Make this a paid member-only article,” and publish it. To sell it individually, also select “Sell this article individually” and enter a price in the selected sales currency. Enter the non-member summary and free-view message in Membership Public Summary below the editor.',
			'新規投稿を開く'                                      => 'Open a new post',
			'公開前にLive設定へ切り替え'                              => 'Switch to Live settings before launch',
			'サイトをHTTPSで公開してから、設定画面でLiveモードを選択し、Live用のAPIキーとWebhook Signing Secretを入力します。TestとLiveのキー、商品、価格、Webhookは共有できません。少額の実決済とWebhook受信を確認してから公開してください。' => 'After publishing the site over HTTPS, select Live mode in Settings and enter Live API keys and the Webhook Signing Secret. Test and Live keys, products, prices, and webhooks are separate. Confirm a small live payment and webhook delivery before launch.',
			'Test用のStripe設定を保存しました。'                       => 'Stripe Test settings saved.',
		);
		return $translations;
	}
}
