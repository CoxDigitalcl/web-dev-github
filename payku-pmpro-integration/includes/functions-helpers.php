<?php
/**
 * Misc helper functions for the Payku gateway integration.
 *
 * @package pmpro-payku
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Retrieve Payku settings from PMPro options.
 *
 * @return array<string, string>
 */
function pmpro_payku_get_settings() {
    $defaults = array(
        'payku_environment'   => 'production',
        'payku_api_key'       => '',
        'payku_secret_key'    => '',
        'payku_public_token'  => '',
        'payku_secret_token'  => '',
        'payku_webhook_secret'=> '',
    );

    foreach ( $defaults as $option => $default ) {
        $defaults[ $option ] = get_option( $option, $default );
    }

    return apply_filters( 'pmpro_payku_settings', $defaults );
}

/**
 * Determine if sandbox environment is selected.
 *
 * @return bool
 */
function pmpro_payku_is_sandbox() {
    $is_sandbox = 'sandbox' === pmpro_payku_get_settings()['payku_environment'];

    return apply_filters( 'pmpro_payku_is_sandbox', $is_sandbox );
}

/**
 * Build Payku API base URL based on environment.
 *
 * @return string
 */
function pmpro_payku_get_api_base_url() {
    return pmpro_payku_is_sandbox() ? 'https://api.sandbox.payku.cl' : 'https://api.payku.cl';
}

/**
 * Get the webhook URL for Payku callbacks.
 *
 * @return string
 */
function pmpro_payku_get_webhook_url() {
    return rest_url( '/pmpro-payku/v1/webhook' );
}

/**
 * Store Payku identifiers on the order object.
 *
 * @param \MemberOrder $order Order object.
 * @param array<string, string|int> $data Payku response data.
 */
function pmpro_payku_store_transaction_data( $order, $data ) {
    if ( empty( $order ) || empty( $order->id ) ) {
        return;
    }

    if ( isset( $data['subscription_id'] ) ) {
        $order->subscription_transaction_id = sanitize_text_field( wp_unslash( $data['subscription_id'] ) );
        update_pmpro_membership_order_meta( $order->id, 'payku_subscription_id', $order->subscription_transaction_id );
    }

    if ( isset( $data['transaction_id'] ) ) {
        $order->payment_transaction_id = sanitize_text_field( wp_unslash( $data['transaction_id'] ) );
        update_pmpro_membership_order_meta( $order->id, 'payku_transaction_id', $order->payment_transaction_id );
    }

    $order->saveOrder();
}

/**
 * Return the Payku subscription ID stored on a member order.
 *
 * @param \MemberOrder $order Order object.
 *
 * @return string|null
 */
function pmpro_payku_get_subscription_id( $order ) {
    if ( empty( $order ) || empty( $order->id ) ) {
        return null;
    }

    $subscription_id = get_pmpro_membership_order_meta( $order->id, 'payku_subscription_id' );

    return $subscription_id ?: null;
}

/**
 * Persist Payku subscription ID in order meta.
 *
 * @param \MemberOrder $order Order object.
 * @param string        $subscription_id Subscription identifier from Payku.
 */
function pmpro_payku_set_subscription_id( $order, $subscription_id ) {
    if ( empty( $order ) || empty( $order->id ) ) {
        return;
    }

    update_pmpro_membership_order_meta( $order->id, 'payku_subscription_id', sanitize_text_field( $subscription_id ) );
}

/**
 * Retrieve a readable Payku API error.
 *
 * @param array $response Decoded API response.
 *
 * @return string
 */
function pmpro_payku_extract_error_message( $response ) {
    if ( empty( $response ) ) {
        return __( 'Unknown Payku error.', 'pmpro-payku' );
    }

    if ( isset( $response['message'] ) ) {
        return sanitize_text_field( $response['message'] );
    }

    if ( isset( $response['errors'] ) && is_array( $response['errors'] ) ) {
        $first = reset( $response['errors'] );
        if ( is_string( $first ) ) {
            return sanitize_text_field( $first );
        }
    }

    return __( 'Unexpected response from Payku.', 'pmpro-payku' );
}
