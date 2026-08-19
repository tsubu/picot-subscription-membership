<?php
/**
 * Plugin Name: Picot Subscription Membership
 * Description: Stripe と連携する会員制・サブスクリプション・コンテンツ閲覧制限プラグイン。
 * Version: 1.5.0
 * Requires at least: 6.6
 * Requires PHP: 8.0
 * Author: Picot
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: picot-subscription-membership
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'PICOT_SUBSCRIPTION_MEMBERSHIP_VERSION', '1.5.0' );
define( 'PICOT_SUBSCRIPTION_MEMBERSHIP_FILE', __FILE__ );
define( 'PICOT_SUBSCRIPTION_MEMBERSHIP_DIR', plugin_dir_path( __FILE__ ) );
define( 'PICOT_SUBSCRIPTION_MEMBERSHIP_URL', plugin_dir_url( __FILE__ ) );

require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-db.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-membership.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-stripe-gateway.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-content.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-shortcodes.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-rest.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-admin.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-settings.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/class-picot-subscription-membership-i18n.php';
require_once PICOT_SUBSCRIPTION_MEMBERSHIP_DIR . 'includes/functions.php';

final class Picot_Subscription_Membership_Plugin {
	public static function init() {
		if ( get_option( 'psm_db_version' ) !== PICOT_SUBSCRIPTION_MEMBERSHIP_VERSION ) {
			Picot_Subscription_Membership_DB::install(); }
		self::ensure_capabilities();
		add_filter( 'allowed_redirect_hosts', array( 'Picot_Subscription_Membership_Stripe_Gateway', 'allow_redirect_hosts' ) );
		Picot_Subscription_Membership_Content::init();
		Picot_Subscription_Membership_Shortcodes::init();
		Picot_Subscription_Membership_REST::init();
		Picot_Subscription_Membership_Settings::init();
		Picot_Subscription_Membership_I18n::init();
		if ( is_admin() ) {
			Picot_Subscription_Membership_Admin::init();
		}
		add_action( 'psm_daily_sync', array( 'Picot_Subscription_Membership_Membership', 'run_daily_sync' ) );
	}

	public static function activate() {
		Picot_Subscription_Membership_DB::install();
		self::ensure_capabilities();
		add_option(
			'psm_settings',
			array(
				'mode'                     => 'test',
				'locale'                   => 'ja_JP',
				'currency'                 => 'jpy',
				'grace_days'               => 0,
				'portal_enabled'           => 1,
				'delete_data_on_uninstall' => 0,
				'post_types'               => array( 'post', 'page' ),
			)
		);
		if ( ! wp_next_scheduled( 'psm_daily_sync' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'psm_daily_sync' ); }
	}

	/** Grant the custom capabilities to every existing role that can manage WordPress options. */
	public static function ensure_capabilities() {
		foreach ( array( 'manage_memberships', 'manage_membership_plans', 'manage_membership_settings', 'view_membership_payments', 'manage_membership_periods', 'manage_membership_webhooks' ) as $cap ) {
			foreach ( wp_roles()->role_objects as $role ) {
				if ( $role->has_cap( 'manage_options' ) ) {
					$role->add_cap( $cap ); }
			}
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'psm_daily_sync' ); }
}

register_activation_hook( __FILE__, array( 'Picot_Subscription_Membership_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Picot_Subscription_Membership_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'Picot_Subscription_Membership_Plugin', 'init' ) );
