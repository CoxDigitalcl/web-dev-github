<?php
/**
 * Plugin Name: PMPro Payku Gateway
 * Description: Integrates Payku recurring payments with Paid Memberships Pro.
 * Version: 0.1.0
 * Author: OpenAI Assistant
 * License: GPL-2.0-or-later
 * Text Domain: pmpro-payku
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

const PMPRO_PAYKU_PLUGIN_VERSION = '0.1.0';
const PMPRO_PAYKU_PLUGIN_FILE    = __FILE__;
const PMPRO_PAYKU_PLUGIN_DIR     = __DIR__;

require_once PMPRO_PAYKU_PLUGIN_DIR . '/includes/functions-helpers.php';
require_once PMPRO_PAYKU_PLUGIN_DIR . '/includes/class-payku-settings.php';
require_once PMPRO_PAYKU_PLUGIN_DIR . '/includes/class-payku-api-client.php';
require_once PMPRO_PAYKU_PLUGIN_DIR . '/includes/class-payku-webhook-handler.php';
require_once PMPRO_PAYKU_PLUGIN_DIR . '/includes/class-pmpro-gateway-payku.php';

register_activation_hook( __FILE__, 'pmpro_payku_activate_plugin' );
register_deactivation_hook( __FILE__, 'pmpro_payku_deactivate_plugin' );

/**
 * Ensure the plugin is activated alongside PMPro.
 */
function pmpro_payku_activate_plugin() {
    if ( ! class_exists( 'PMProGateway' ) ) {
        deactivate_plugins( plugin_basename( __FILE__ ) );
        wp_die( esc_html__( 'Paid Memberships Pro must be active before activating the PMPro Payku Gateway plugin.', 'pmpro-payku' ) );
    }

    pmpro_payku_register_endpoints();
    flush_rewrite_rules();
}

/**
 * Flush rewrite rules on deactivation.
 */
function pmpro_payku_deactivate_plugin() {
    flush_rewrite_rules();
}

/**
 * Initialize plugin hooks.
 */
function pmpro_payku_bootstrap() {
    if ( ! class_exists( 'PMProGateway' ) ) {
        return;
    }

    PMPro_Payku_Settings::init();

    add_filter( 'pmpro_gateways', 'pmpro_payku_register_gateway' );
    add_filter( 'pmpro_payment_options', 'pmpro_payku_payment_options' );
    add_filter( 'pmpro_payment_option_fields', 'pmpro_payku_payment_option_fields', 10, 2 );
    add_filter( 'pmpro_get_gateways', 'pmpro_payku_add_checkout_label' );
    add_filter( 'pmpro_get_gateway_class', 'pmpro_payku_get_gateway_class', 10, 2 );
    add_filter( 'pmpro_include_billing_address_fields', 'pmpro_payku_include_billing_fields', 10, 2 );
    add_action( 'rest_api_init', 'pmpro_payku_register_endpoints' );
    add_filter( 'pmpro_valid_gateways', 'pmpro_payku_validate_membership_gateway', 10, 2 );
}
add_action( 'plugins_loaded', 'pmpro_payku_bootstrap' );

/**
 * Register Payku gateway for PMPro.
 *
 * @param array $gateways Existing gateways.
 *
 * @return array
 */
function pmpro_payku_register_gateway( $gateways ) {
    $gateways['payku'] = 'Payku';

    return $gateways;
}

/**
 * Map Payku gateway slug to class name.
 *
 * @param string $class Class name.
 * @param string $gateway Gateway slug.
 *
 * @return string
 */
function pmpro_payku_get_gateway_class( $class, $gateway ) {
    if ( 'payku' === $gateway ) {
        $class = 'PMProGateway_payku';
    }

    return $class;
}

/**
 * Inject Payku gateway option values.
 *
 * @param array $options PMPro options.
 *
 * @return array
 */
function pmpro_payku_payment_options( $options ) {
    $payku_options = array(
        'payku_environment',
        'payku_api_key',
        'payku_secret_key',
        'payku_public_token',
        'payku_secret_token',
        'payku_webhook_secret',
    );

    return array_merge( $options, $payku_options );
}

/**
 * Render Payku specific settings fields in PMPro payment settings.
 *
 * @param array $values Existing option values.
 * @param array $gateway Gateway options.
 */
function pmpro_payku_payment_option_fields( $values, $gateway ) {
    PMPro_Payku_Settings::render_settings_fields( $values );
}

/**
 * Provide a friendly label for the Payku gateway.
 *
 * @param array $gateways Gateways.
 *
 * @return array
 */
function pmpro_payku_add_checkout_label( $gateways ) {
    $gateways['payku'] = esc_html__( 'Payku', 'pmpro-payku' );

    return $gateways;
}

/**
 * Exclude billing fields since Payku handles them externally.
 *
 * @param bool      $include Include billing fields flag.
 * @param \PMPro_Level $level Current level.
 *
 * @return bool
 */
function pmpro_payku_include_billing_fields( $include, $level ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
    if ( 'payku' === pmpro_getGateway() ) {
        return false;
    }

    return $include;
}

/**
 * Restrict levels that require Payku to only use Payku gateway.
 *
 * @param array           $valid_gateways Valid gateways.
 * @param \MemberOrder $order Order object.
 *
 * @return array
 */
function pmpro_payku_validate_membership_gateway( $valid_gateways, $order ) {
    if ( empty( $order ) || empty( $order->membership_level ) ) {
        return $valid_gateways;
    }

    if ( isset( $order->membership_level->gateway ) && 'payku' === $order->membership_level->gateway ) {
        $valid_gateways = array( 'payku' );
    }

    return $valid_gateways;
}

/**
 * Register webhook REST routes.
 */
function pmpro_payku_register_endpoints() {
    PMPro_Payku_Webhook_Handler::register_routes();
}
