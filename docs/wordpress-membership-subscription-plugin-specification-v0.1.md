# WordPress Membership & Subscription Plugin
## システム仕様書 v0.1

作成日：2026年8月17日

---

# 1. 概要

本プラグインは、既存のWordPressテーマを変更することなく、以下の機能を追加するMembership / Subscriptionプラグインである。

- 会員登録
- 無料会員管理
- 有料会員管理
- Stripeによる定期課金
- 月額・年額契約
- 決済成功後の利用可能期間自動更新
- 契約終了・解約管理
- 支払い失敗管理
- 管理者による利用期間延長
- 会員限定記事
- プラン別限定記事
- 記事の一部分のみ会員限定化
- 会員マイページ
- Stripe Customer Portal連携
- Webhookログ
- 決済履歴
- 会員管理

テーマは表示・デザインを担当し、本プラグインは会員・決済・閲覧権限を担当する。

---

# 2. 開発目的

主な利用用途を以下とする。

- ファンクラブ
- 有料ブログ
- 有料ニュースサイト
- オンラインサロン
- サブスクリプション型メディア
- 会員限定コンテンツ
- 有料動画サイト
- 会員限定ダウンロード
- オンラインコミュニティ

特定テーマ専用とはせず、一般的なWordPressテーマで利用可能な構造とする。

---

# 3. 基本設計方針

システムを以下の3領域に分離する。

```text
WordPress
│
├─ WordPress Core
│   ├─ Users
│   ├─ Posts
│   ├─ Pages
│   └─ Authentication
│
├─ Membership Plugin
│   ├─ Membership
│   ├─ Plans
│   ├─ Access Control
│   ├─ Stripe Integration
│   ├─ Membership Period
│   ├─ Admin
│   └─ My Page
│
└─ Stripe
    ├─ Customer
    ├─ Product
    ├─ Price
    ├─ Checkout
    ├─ Subscription
    ├─ Invoice
    └─ Customer Portal
```

WordPressのユーザーを会員アカウントの主体とし、契約・課金についてはStripeを一次情報源とする。

StripeではSubscriptionの作成・更新・支払い成功・失敗等をWebhookで通知でき、`invoice.paid` を契機としてサービスへのアクセスを付与する設計が公式に案内されている。

---

# 4. 対象環境

初期想定環境：

- WordPress 6.6以上
- PHP 8.0以上
- MySQL / MariaDB
- HTTPS必須
- Stripeアカウント
- Stripe Checkout
- Stripe Billing
- Stripe Customer Portal

Stripe LiveモードのWebhookはHTTPS環境を前提とする。

---

# 5. WordPressユーザー

会員アカウントにはWordPress標準ユーザーを使用する。

基本Role：

```text
subscriber
```

有料・無料・プラン種別についてはWordPress Roleでは管理しない。

```text
WordPress User
│
├─ WordPress Role
│   └─ subscriber
│
└─ Membership
    ├─ Plan
    ├─ Stripe Customer
    ├─ Stripe Subscription
    ├─ Status
    └─ Access Period
```

管理者用権限については独自Capabilityを追加する。

---

# 6. 管理者Capability

以下を定義する。

```text
manage_memberships
manage_membership_plans
manage_membership_settings
view_membership_payments
manage_membership_periods
manage_membership_webhooks
```

administratorにはすべて付与する。

REST APIや管理画面処理ではRole名ではなくCapabilityで判定する。

WordPress REST APIでも、認証後の操作権限は `current_user_can()` 等によるCapabilityチェックが推奨されている。

---

# 7. Membershipプラン

複数プランを作成できる。

例：

```text
無料
ライト
スタンダード
プレミアム
```

各プランには以下を設定する。

- プラン名
- スラッグ
- 説明
- 有効 / 無効
- 表示順
- Stripe Product ID
- 利用可能コンテンツ
- 管理用備考

---

# 8. 月額・年額料金

1つのMembershipプランに複数料金を設定できる構造とする。

例：

```text
プレミアム
├─ 月額 980円
└─ 年額 9,800円
```

Stripe Price ID単位で料金を管理する。

---

# 9. Stripe Checkout

カード情報入力画面はWordPress側で独自実装せず、Stripe Hosted Checkoutを使用する。

フロー：

```text
WordPress
↓
プラン選択
↓
申し込む
↓
Stripe Checkout
↓
決済
↓
WordPress
```

Stripe CheckoutはSubscriptionモードによる継続課金をサポートしている。

WordPress側ではカード番号・セキュリティコード等を保存しない。

---

# 10. 新規契約フロー

```text
会員登録
↓
WordPress User作成
↓
ログイン
↓
プラン選択
↓
Stripe Checkout Session作成
↓
Stripe Checkout
↓
初回決済
↓
Stripe Webhook
↓
invoice.paid
↓
Membership有効化
↓
有効期間設定
↓
限定コンテンツ利用開始
```

Checkoutからブラウザが戻ってきたことだけを理由に会員を有効化してはならない。

Membership有効化はStripe Webhookで確認した決済結果を基準とする。

---

# 11. Stripe Customerとの紐付け

WordPress UserとStripe Customerを1対1で対応させる。

```text
WP User #123
↓
Stripe Customer
cus_xxxxxxxxx
↓
Stripe Subscription
sub_xxxxxxxxx
```

Checkout Session作成時には、可能な限りWordPress User IDをStripe metadata等に保持し、Webhook受信時の対応確認に使用する。

---

# 12. 有効期間管理【重要】

本プラグインの中核機能とする。

Membershipには以下を保持する。

```text
stripe_period_start
stripe_period_end

manual_extension_seconds

grace_until

effective_access_until
```

## 12.1 Stripe有効期間

決済成功後、

```text
invoice.paid
```

を受信する。

その後Stripeから最新Subscription / Invoice情報を確認し、

```text
stripe_period_start
stripe_period_end
```

を同期する。

単純な

```text
現在日時 + 30日
```

方式は使用しない。

月額・年額・プラン変更・請求サイクル変更などに対応できるよう、Stripe側で確定した課金期間を使用する。

---

# 13. 自動更新

例：

```text
2026/08/17

初回決済成功
↓
有効期限
2026/09/17
```

次回：

```text
2026/09/17

Stripe自動決済成功
↓
invoice.paid
↓
新しい課金期間取得
↓
有効期限更新
2026/10/17
```

以降も自動的に更新する。

StripeではSubscription更新時に `customer.subscription.updated` が発生し、支払い成功時には `invoice.paid` が発生する。

---

# 14. 二重延長防止【重要】

Webhookは「一度しか届かない」ことを前提としてはならない。

Stripeは同じWebhookイベントを複数回送信する場合があり、イベントIDを記録して重複処理を防止することを推奨している。

そのため、

```text
stripe_event_id
```

をUNIQUE管理する。

処理済みイベントの場合：

```text
受信
↓
event_id確認
↓
処理済み
↓
Membership変更なし
↓
HTTP 200
```

とする。

---

# 15. Webhook順不同対策【重要】

Webhookの到着順序には依存しない。

Stripeはイベントが生成順に届くことを保証していない。たとえばSubscription作成時でも `customer.subscription.created` より `invoice.paid` を先に受信する可能性がある。

したがって、

```text
Webhook
↓
Stripe ID取得
↓
必要に応じてStripe APIから現在状態取得
↓
現在状態を基準としてMembership同期
```

とする。

Webhook payloadだけを唯一の状態情報源として扱わない。

---

# 16. 管理者による手動延長

管理画面からMembership利用期間を延長できる。

例：

```text
現在
2026/09/17

延長
+7日

最終利用可能日
2026/09/24
```

用途：

- キャンペーン
- 運営特典
- お詫び対応
- ファンクラブ特典
- イベント参加特典

---

# 17. Stripe期間と手動延長の分離

手動延長によってStripe情報を書き換えない。

```text
Stripe課金期間
2026/09/17

+

手動延長
7日

=

実利用期限
2026/09/24
```

データとして、

```text
stripe_period_end
manual_extension_seconds
effective_access_until
```

を分離する。

基本計算：

```text
effective_access_until
=
stripe_period_end
+
manual_extension_seconds
```

とする。

これにより次のStripe更新時にも手動延長情報を失わない。

---

# 18. 手動延長履歴

期間変更は必ず履歴を残す。

保存内容：

- Membership ID
- User ID
- 操作種別
- 延長秒数
- 延長日数
- 操作者
- 理由
- 操作日時

例：

```text
2026/08/20
管理者 #1
+7日
理由：キャンペーン特典
```

直接Membershipテーブルだけを書き換えて履歴を失う実装は禁止する。

---

# 19. 支払い失敗

Webhook：

```text
invoice.payment_failed
```

を処理する。

StripeではこのイベントによりSubscription請求失敗を検出できる。

管理設定として、

```text
支払い失敗時の猶予期間

○ なし
○ 1日
○ 3日
○ 7日
○ 任意
```

を設定可能とする。

---

# 20. 猶予期間

例：

```text
Stripe有効期限
2026/09/17

決済失敗
2026/09/17

猶予
3日

grace_until
2026/09/20
```

期間内は閲覧を許可する。

再決済成功時：

```text
invoice.paid
↓
Stripe期間更新
↓
grace_untilクリア
↓
通常active状態
```

とする。

---

# 21. 解約

基本的なユーザー解約は、

```text
現在の請求期間終了時に解約
```

とする。

Stripeでは `cancel_at_period_end=true` により、支払い済み期間終了時までSubscriptionを継続できる。

例：

```text
契約
8/17～9/17

8/25
解約操作

↓

9/17までは利用可能

↓

9/17
Subscription終了
```

---

# 22. 解約予約表示

マイページ：

```text
現在のプラン
プレミアム

次回更新
なし

利用可能期限
2026年9月17日

状態
解約予約済み

※現在の契約期間終了までご利用いただけます。
```

と表示する。

Stripe Customer Portalでも期間終了時解約を扱うことができる。

---

# 23. Customer Portal

以下については原則Stripe Customer Portalへ移動させる。

- カード変更
- 支払い方法変更
- 請求情報変更
- 解約
- 契約確認

マイページに、

```text
[契約・支払い方法を管理]
```

ボタンを設置する。

Customer Portalでは支払い方法変更やSubscriptionキャンセル等を設定できる。

MVPではプラン変更機能は無効とし、v1.1以降で対応する。

---

# 24. Membershipステータス

内部ステータス：

```text
pending
trialing
active
past_due
canceled
expired
paused
revoked
```

Stripe status自体も別項目で保存する。

```text
membership_status
stripe_status
```

を混同しない。

---

# 25. 閲覧権限

基本判定：

```text
ログインしている
AND
Membershipが利用可能状態
AND
現在日時 <= effective_access_until
AND
対象コンテンツのプラン条件を満たす
AND
revokedではない
```

---

# 26. コンテンツ閲覧レベル

各記事・固定ページ等に以下を設定できる。

```text
公開
ログイン会員限定
有料会員限定
指定プラン限定
```

指定プランの場合：

```text
□ ライト
□ スタンダード
□ プレミアム
```

複数選択可能とする。

---

# 27. 対象Post Type

初期状態：

```text
post
page
```

管理画面で対象Custom Post Typeを追加可能にする。

---

# 28. 記事設定UI

投稿編集画面にMembershipパネルを追加する。

```text
Membership

閲覧制限
[ 公開 ▼ ]

対象プラン
□ Light
□ Standard
□ Premium

限定時の表示
○ ログインを促す
○ Membership加入を促す
○ 独自メッセージ
```

---

# 29. 部分限定コンテンツ

記事全体だけでなく、記事途中から限定可能にする。

Gutenbergブロック：

```text
Membership Restricted Content
```

内部：

```text
対象

○ 有料会員
○ 指定プラン

□ Light
□ Standard
□ Premium
```

例：

```text
この記事の概要は一般公開です。

────────────────

[ Membership Restricted Content ]

ここから会員限定本文

[/ Membership Restricted Content ]
```

---

# 30. ショートコード

Classic Editor等への対応としてショートコードも提供する。

例：

```text
[membership_content]
限定内容
[/membership_content]
```

プラン指定：

```text
[membership_content plans="premium,standard"]
...
[/membership_content]
```

---

# 31. 情報漏洩防止【重要】

限定本文を単にCSSで非表示にしてはならない。

PHP側でアクセス判定を行い、許可されていないユーザーには本文自体を出力しない。

以下についても保護する。

- 通常Frontend
- REST API
- RSS / Atom Feed
- excerpt
- 検索結果
- Embed
- OEmbed
- Archive
- JSONレスポンス
- Gutenberg REST取得

---

# 32. Post Meta

コンテンツ閲覧設定についてはPost Metaを利用する。

例：

```text
_membership_access_mode

public
login
paid
plans
```

```text
_membership_allowed_plans
```

```text
_membership_restricted_message
```

WordPress公式でも、投稿に関連する追加データについてはPost Metaが実用的な場合は優先して利用する方針が示されている。

---

# 33. マイページ

ショートコードまたはBlockを提供する。

```text
[membership_account]
```

表示：

```text
マイページ

名前
円谷 太郎

メール
example@example.com

────────────────

現在のプラン
プレミアム

契約状態
有効

次回更新
2026年9月17日

利用可能期限
2026年9月24日

運営特典延長
7日

────────────────

[契約・支払い方法を管理]
```

---

# 34. プラン一覧

```text
[membership_plans]
```

またはGutenberg Blockを提供する。

表示内容：

- プラン名
- 説明
- 月額
- 年額
- 特典
- 申し込みボタン

---

# 35. ログイン・登録

以下のBlock / Shortcodeを提供する。

```text
[membership_login]

[membership_register]
```

会員登録：

- メールアドレス
- パスワード
- 表示名
- 利用規約同意
- プライバシーポリシー同意

WordPress Userを作成する。

---

# 36. 管理画面構成

```text
Membership
│
├─ ダッシュボード
├─ 会員
├─ プラン
├─ 決済履歴
├─ 期間変更履歴
├─ Webhookログ
├─ Stripe同期
└─ 設定
```

---

# 37. ダッシュボード

表示：

- 総会員数
- 無料会員数
- 有料会員数
- Active会員
- 解約予約
- Expired
- Past Due
- 新規会員
- プラン別会員数

売上詳細分析はMVPではStripe Dashboardへ委ねる。

---

# 38. 会員一覧

一覧項目：

```text
ユーザー
メール
プラン
Membership Status
Stripe Status
有効期限
次回更新
解約予約
Stripe Customer
```

検索：

- 名前
- メール
- プラン
- ステータス

絞り込み可能とする。

---

# 39. 会員詳細

表示：

```text
WordPress User

Membership

Stripe Customer ID

Stripe Subscription ID

現在プラン

Stripe有効期限

手動延長

実利用期限

契約状態

決済履歴

期間変更履歴

Webhook履歴
```

操作：

```text
[Stripeと同期]

[期間を延長]

[利用停止]

[利用停止解除]
```

---

# 40. プラン管理

管理項目：

- Plan Name
- Slug
- Description
- Stripe Product ID
- Active
- Sort Order

料金：

- Monthly
- Yearly
- Stripe Price ID
- Currency
- 表示金額

---

# 41. Stripe設定

```text
Stripe Mode
○ Test
○ Live

Publishable Key

Secret Key

Webhook Signing Secret

Customer Portal
ON / OFF
```

Secret KeyはFrontend JavaScriptへ出力してはならない。

---

# 42. 接続確認

管理画面に、

```text
[Stripe接続確認]
```

を設置する。

確認内容：

- API Key
- Stripe API疎通
- Test / Live整合
- Webhook URL
- Webhook Secret設定

---

# 43. Webhook Endpoint

例：

```text
/wp-json/membership/v1/stripe/webhook
```

Stripe Webhookの署名を必ず検証する。

Stripeは `Stripe-Signature` ヘッダーを使用した署名検証を推奨している。

---

# 44. 監視Webhook

MVPでは最低限以下を処理する。

```text
checkout.session.completed

customer.subscription.created
customer.subscription.updated
customer.subscription.deleted

invoice.paid
invoice.payment_failed
invoice.payment_action_required
```

必要に応じて：

```text
customer.subscription.paused
customer.subscription.resumed
```

も処理する。

---

# 45. Webhookイベント処理

## invoice.paid

```text
署名確認
↓
重複確認
↓
Invoice取得
↓
Subscription特定
↓
Stripe最新状態取得
↓
User / Membership特定
↓
支払い履歴保存
↓
stripe_period_end更新
↓
effective_access_until再計算
↓
Membership active
↓
Webhook処理済み
```

---

# 46. invoice.payment_failed

```text
受信
↓
Subscription特定
↓
membership_status更新
↓
grace_until設定
↓
Webhookログ
```

必要に応じてユーザーへ通知する。

---

# 47. customer.subscription.updated

更新対象：

- Stripe status
- Plan / Price
- cancel_at_period_end
- Billing period
- Subscription情報

Stripeでは更新、更新予約、プラン変更等で `customer.subscription.updated` が送信される。

---

# 48. customer.subscription.deleted

```text
Subscription終了
↓
Stripe status更新
↓
新規Stripe課金期間追加停止
↓
manual_extension確認
↓
effective_access_until到達後expired
```

手動延長が残っている場合は、その期間終了までアクセス可能とする。

---

# 49. Webhookログ

テーブルで保存する。

項目：

```text
Stripe Event ID
Event Type
Object ID
Event Created
Received At
Status
Processed At
Error
Attempt Count
Payload Hash
```

`Stripe Event ID` はUNIQUEとする。

---

# 50. Webhook再送

StripeはLiveモードで失敗したWebhook配信を最大3日間、自動的に再試行する。

したがってWebhook Handlerは必ず冪等にする。

```text
同じイベントを10回受信

↓

Membership期間は1回だけ更新
```

を保証する。

---

# 51. DB構成

独自テーブル：

```text
{prefix}_membership_plans

{prefix}_membership_prices

{prefix}_memberships

{prefix}_membership_payments

{prefix}_membership_adjustments

{prefix}_membership_webhook_events
```

WordPressではプラグインによる独自テーブル作成・更新に `dbDelta()` が利用できる。

---

# 52. membership_plans

```text
id BIGINT
name VARCHAR
slug VARCHAR
description TEXT
stripe_product_id VARCHAR
active BOOLEAN
sort_order INT
created_at DATETIME
updated_at DATETIME
```

---

# 53. membership_prices

```text
id BIGINT
plan_id BIGINT

billing_interval
month / year

interval_count INT

stripe_price_id VARCHAR

amount BIGINT
currency VARCHAR

active BOOLEAN

created_at
updated_at
```

---

# 54. memberships

```text
id BIGINT

user_id BIGINT

plan_id BIGINT
price_id BIGINT

stripe_customer_id VARCHAR
stripe_subscription_id VARCHAR

membership_status VARCHAR
stripe_status VARCHAR

stripe_period_start DATETIME
stripe_period_end DATETIME

manual_extension_seconds BIGINT

grace_until DATETIME

effective_access_until DATETIME

cancel_at_period_end BOOLEAN
canceled_at DATETIME

access_revoked_at DATETIME

last_invoice_id VARCHAR
last_stripe_event_created_at DATETIME

created_at DATETIME
updated_at DATETIME
```

MVPでは原則、

```text
1 WordPress User
=
1 Membership
=
最大1 Active Subscription
```

とする。

---

# 55. membership_payments

```text
id

membership_id
user_id

stripe_invoice_id
stripe_payment_intent_id

amount
currency

status

period_start
period_end

paid_at

created_at
```

`stripe_invoice_id` はUNIQUEとする。

これによりWebhook再送による決済履歴重複も防止する。

---

# 56. membership_adjustments

```text
id

membership_id
user_id

type

delta_seconds

reason

admin_user_id

created_at
```

type：

```text
extend
reduce
revoke
restore
```

---

# 57. membership_webhook_events

```text
id

stripe_event_id UNIQUE

event_type

object_id

event_created_at

received_at

status

attempt_count

payload_hash

error_message

processed_at
```

カード情報等をWebhookログへ不要に保存しない。

---

# 58. REST API

Namespace：

```text
membership/v1
```

想定API：

```text
POST /checkout

POST /portal

POST /stripe/webhook

GET /account

GET /plans
```

管理用：

```text
GET /admin/memberships

GET /admin/memberships/{id}

POST /admin/memberships/{id}/extend

POST /admin/memberships/{id}/revoke

POST /admin/memberships/{id}/restore

POST /admin/memberships/{id}/sync
```

管理REST APIにはCapabilityチェックを必須とする。

---

# 59. Stripe同期機能

Webhook障害時に備え、

```text
[Stripeと同期]
```

を実装する。

Subscription IDから現在状態を取得し、

- Plan
- Price
- Status
- Period
- Cancel state

等を再同期する。

---

# 60. 定期整合性チェック

WP-Cron等を利用し、MembershipとStripeの状態に大きな不整合がないか定期確認できる構造とする。

初期版では、

```text
1日1回
```

を基本とする。

ただし、大規模サイト向けには対象件数を分割処理できる設計とする。

---

# 61. テーマ依存を最小化

テーマファイルを直接変更しない。

利用するもの：

- WordPress Hooks
- Filters
- REST API
- Blocks
- Shortcodes
- Post Meta
- Templates

プラグイン停止後も通常テーマが利用できること。

---

# 62. PHP API

テーマ・他プラグイン向けに公開関数を提供する。

例：

```php
membership_user_can_access( $post_id, $user_id );

membership_get_user_membership( $user_id );

membership_is_active( $user_id );

membership_get_access_until( $user_id );
```

実際の関数にはプラグイン固有Prefix / Namespaceを付与する。

---

# 63. Hooks / Filters

将来拡張のため独自Hooksを設ける。

例：

```text
membership_activated

membership_payment_succeeded

membership_payment_failed

membership_expired

membership_extended

membership_canceled
```

Filters：

```text
membership_can_access

membership_restricted_message

membership_effective_access_until
```

---

# 64. セキュリティ

必須：

- Capability Check
- Nonce
- REST permission_callback
- Input validation
- sanitize
- escape
- prepared SQL
- Webhook Signature Verification
- HTTPS
- Secret Key非公開
- Event重複防止

WordPressでは管理操作の認可にCapabilityとNonce等を組み合わせることが推奨されている。

---

# 65. Stripe API冪等性

Checkout SessionなどStripe側でリソースを新規作成する処理ではIdempotency Keyの使用を検討する。

Stripe APIはIdempotency Keyによる安全なリトライをサポートしている。

例：

```text
checkout:{user_id}:{plan_id}:{request_uuid}
```

---

# 66. データ削除

プラグイン無効化時：

```text
データを削除しない
```

アンインストール時：

管理設定

```text
アンインストール時にデータ削除

OFF（初期値）
```

とする。

ONの場合のみ独自テーブル・Options等を削除する。

---

# 67. ログ

最低限：

- Stripe API Error
- Webhook Error
- Membership Sync Error
- Payment Failure
- Period Adjustment

を管理画面から確認可能にする。

本番環境ではSecret Keyやカード関連情報等をログへ出力しない。

---

# 68. 推奨ディレクトリ

```text
membership-plugin/
│
├─ membership-plugin.php
│
├─ uninstall.php
├─ composer.json
│
├─ src/
│   ├─ Core/
│   ├─ Admin/
│   ├─ Database/
│   ├─ Membership/
│   ├─ Plans/
│   ├─ Content/
│   ├─ Stripe/
│   ├─ Webhook/
│   ├─ Rest/
│   ├─ Cron/
│   ├─ Security/
│   └─ Blocks/
│
├─ assets/
│   ├─ css/
│   └─ js/
│
├─ templates/
│
├─ blocks/
│
├─ languages/
│
└─ vendor/
```

---

# 69. Stripe処理の分離

決済処理をMembership Coreから分離する。

概念：

```text
Membership
     │
Payment Gateway Interface
     │
Stripe Gateway
```

将来的に、

```text
PayPal
その他決済
```

へ対応可能な構造とする。

ただしv1ではStripeのみ実装する。

---

# 70. MVP機能

Version 1.0では以下を完成条件とする。

1. WordPress会員登録
2. ログイン
3. Membershipプラン
4. 月額料金
5. 年額料金
6. Stripe Checkout
7. Stripe Customer
8. Stripe Subscription
9. Webhook
10. 初回決済
11. 自動更新
12. 有効期限自動更新
13. 支払い失敗
14. 解約予約
15. Customer Portal
16. Membership一覧
17. Membership詳細
18. 手動期間延長
19. 期間変更履歴
20. 決済履歴
21. Webhookログ
22. 記事閲覧制限
23. プラン別閲覧制限
24. 部分限定
25. Gutenberg Block
26. Shortcode
27. マイページ
28. REST / Feed等の情報漏洩防止
29. Stripe手動同期
30. Test / Liveモード

---

# 71. v1.1候補

- Trial
- Coupon
- Promotion Code
- プランアップグレード
- ダウングレード
- Proration
- メール通知
- 更新日前通知
- 支払い失敗メール
- Membership期限通知
- 会員番号
- デジタル会員証

---

# 72. v1.2候補

- 会員限定ファイル
- 会員限定動画
- イベント
- チケット
- Discord連携
- LINE連携
- 外部REST API
- Webhook送信
- Membership Import / Export

---

# 73. 将来的なファンクラブ機能

Membership基盤の上に以下を追加可能とする。

```text
ファンクラブ

├─ 会員限定記事
├─ 限定画像
├─ 限定動画
├─ 限定ダウンロード
├─ デジタル会員証
├─ 会員番号
├─ 会員ランク
├─ 継続年数
├─ 限定イベント
├─ チケット
└─ 特典
```

Membership Coreとファンクラブ固有機能を分離する。

---

# 74. 重要なアクセス期間ルール

最重要仕様として以下を固定する。

## 決済成功

```text
invoice.paid
↓
最新Stripe情報取得
↓
stripe_period_end更新
↓
effective_access_until再計算
↓
アクセス継続
```

## 決済失敗

```text
invoice.payment_failed
↓
grace_until設定
↓
猶予後にアクセス停止
```

## 解約予約

```text
cancel_at_period_end = true
↓
支払い済み期間中はアクセス可能
```

## Stripe契約終了

```text
Subscription終了
↓
Stripe期間終了
↓
手動延長があれば継続
↓
最終期限後expired
```

## 管理者延長

```text
manual_extension_seconds += 延長時間
↓
effective_access_until再計算
```

---

# 75. 絶対条件

以下を実装上の絶対条件とする。

### 条件1

決済成功時に利用可能期間が自動更新されること。

### 条件2

Webhook再送によって有効期間が二重に延長されないこと。

### 条件3

Webhook受信順序が変わってもMembershipが壊れないこと。

### 条件4

解約しても支払い済み期間は原則利用できること。

### 条件5

手動延長分が次回Stripe同期で消えないこと。

### 条件6

限定本文がREST APIやRSS等から漏洩しないこと。

### 条件7

テーマ変更後もMembershipデータと契約状態が維持されること。

### 条件8

カード情報をWordPress DBへ保存しないこと。

### 条件9

Stripe Webhook署名を検証すること。

### 条件10

Stripe Event ID / Invoice IDによる冪等性を保証すること。

---

# 76. 完成時の全体像

```text
                  WordPress User
                       │
                       ▼
                 Membership
                       │
        ┌──────────────┼──────────────┐
        │              │              │
        ▼              ▼              ▼
      Plan       Access Period    Content Rule
        │              │              │
        │              │              ▼
        │              │        WordPress記事
        │              │
        ▼              │
      Stripe           │
        │              │
        ├─ Customer    │
        ├─ Price       │
        ├─ Checkout    │
        ├─ Subscription
        ├─ Invoice     │
        │              │
        ▼              │
   invoice.paid        │
        │              │
        └──────────────►
                期間自動更新
                       │
                       ▼
             effective_access_until
                       │
                       ▼
               閲覧権限判定
```

---

# 77. 開発優先順位

実装順序は以下を推奨する。

```text
Phase 1
DB / Membership Core

↓

Phase 2
Plan管理

↓

Phase 3
Stripe Customer / Checkout

↓

Phase 4
Webhook / Subscription同期

↓

Phase 5
有効期間計算

↓

Phase 6
管理者手動延長

↓

Phase 7
記事アクセス制御

↓

Phase 8
Gutenberg Block / Shortcode

↓

Phase 9
マイページ / Customer Portal

↓

Phase 10
ログ / 障害復旧 / セキュリティ

↓

Phase 11
テスト

↓

Phase 12
WordPress.org公開対応
```

---

# 78. MVP受入テスト

最低限、以下のテストをすべて通過すること。

- 新規ユーザー登録できる
- Stripe Checkoutへ移動できる
- 初回決済成功後にMembershipがActiveになる
- 初回決済後に有効期限が設定される
- 翌月決済成功で有効期限が更新される
- 同じ `invoice.paid` を複数回処理しても二重延長されない
- Webhookを順不同で送信しても最終状態が正常になる
- 支払い失敗時にPast Dueへ変更される
- 猶予期間が正しく動作する
- 再決済成功後にActiveへ復帰する
- 解約予約後も契約終了日まで閲覧できる
- 契約終了後に閲覧できなくなる
- 手動で7日延長できる
- Stripe更新後も7日延長が維持される
- 対象プランだけが限定記事を閲覧できる
- 非会員に限定本文が送信されない
- REST APIから限定本文が取得できない
- RSSから限定本文が取得できない
- Customer Portalへ移動できる
- Test / Liveモードが混在しない
- Webhook署名不正時にMembershipが変更されない

---

# 79. 最終方針

本プラグインを単なる「Stripe決済プラグイン」とせず、

**WordPress上でMembership・Subscription・コンテンツアクセス権限を統合管理する基盤プラグイン**

として設計する。

Stripeは、

```text
顧客
決済
Subscription
Invoice
請求
```

を管理する。

WordPressプラグインは、

```text
ユーザー
Membership
プラン
利用可能期間
コンテンツ
閲覧権限
管理
```

を担当する。

この責務分離を基本原則とする。