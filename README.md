# Picot Subscription Membership

Picot Subscription Membership is a WordPress plugin for Stripe-powered subscriptions, paid member-only content, and one-time article purchases.

## Features

- Stripe Checkout for subscription plans and individual article purchases
- Stripe Customer Portal integration for plan, cancellation, and payment-method management
- Member-only, plan-specific, login-only, and individual-sale content controls
- Protected content redaction for themes, RSS, REST API responses, and excerpts
- Japanese and English administrative and front-end labels
- Setup guide, generated membership pages, policy-page templates, and operational logs

## Requirements

- WordPress 6.6 or later
- PHP 8.0 or later
- A Stripe account

## Installation

1. Copy this directory to `wp-content/plugins/`.
2. Activate **Picot Subscription Membership**.
3. Open **Membership → Setup Guide** and complete the Test-mode configuration.
4. Create Stripe Products and Prices, then register the corresponding Price IDs in **Membership → Plans**.
5. Before enabling Live mode, configure a publicly reachable HTTPS webhook endpoint and the Live-mode Stripe credentials.

## Development checks

Development-only PHPCS dependencies are intentionally kept outside this plugin directory. From the WordPress root, run:

```bash
wp-content/plugins/scripts/picot-subscription-membership/vendor/bin/phpcs \
  --standard=wp-content/plugins/scripts/picot-subscription-membership/phpcs.xml.dist \
  wp-content/plugins/picot-subscription-membership
```

## Security

Never commit Stripe Secret Keys, Webhook Signing Secrets, local WordPress configuration, or database exports. Configure credentials only through the WordPress settings screen or secure deployment configuration.

## License

GPL-2.0-or-later. See the plugin header for details.
