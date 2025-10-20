<?php
/**
 * Webhook handler for Payku subscription events.
 *
 * @package pmpro-payku
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMPro_Payku_Webhook_Handler {
    /**
     * Register WordPress REST API routes.
     */
    public static function register_routes() {
        register_rest_route(
            'pmpro-payku/v1',
            '/webhook',
            array(
                'methods'             => array( 'POST' ),
                'callback'            => array( __CLASS__, 'handle_webhook' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle incoming Payku webhook events.
     *
     * @param WP_REST_Request $request REST request.
     *
     * @return WP_REST_Response
     */
    public static function handle_webhook( WP_REST_Request $request ) {
        $settings       = pmpro_payku_get_settings();
        $webhook_secret = $settings['payku_webhook_secret'] ?? '';

        $signature = $request->get_header( 'X-Payku-Signature' );
        $body      = $request->get_body();

        if ( empty( $signature ) || ! self::verify_signature( $signature, $body, $webhook_secret ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
        }

        $payload = json_decode( $body, true );

        if ( empty( $payload['event'] ) ) {
            return new WP_REST_Response( array( 'error' => 'Missing event' ), 400 );
        }

        $event = sanitize_text_field( $payload['event'] );

        switch ( $event ) {
            case 'subscription.activated':
                self::handle_subscription_activated( $payload );
                break;
            case 'subscription.cancelled':
                self::handle_subscription_cancelled( $payload );
                break;
            case 'subscription.payment_succeeded':
                self::handle_payment_succeeded( $payload );
                break;
            case 'subscription.payment_failed':
                self::handle_payment_failed( $payload );
                break;
            default:
                // Unsupported event, but return 200 to acknowledge receipt.
                break;
        }

        return new WP_REST_Response( array( 'success' => true ), 200 );
    }

    /**
     * Validate webhook signature using shared secret.
     *
     * @param string $signature Incoming signature header.
     * @param string $payload   Raw request body.
     * @param string $secret    Shared secret.
     *
     * @return bool
     */
    protected static function verify_signature( $signature, $payload, $secret ) {
        if ( empty( $secret ) ) {
            return false;
        }

        $expected = hash_hmac( 'sha256', $payload, $secret );

        return hash_equals( $expected, $signature );
    }

    /**
     * Activate membership when subscription activates.
     *
     * @param array $payload Payload.
     */
    protected static function handle_subscription_activated( $payload ) {
        $subscription_id = sanitize_text_field( $payload['data']['subscription_id'] ?? '' );
        if ( empty( $subscription_id ) ) {
            return;
        }

        $order = self::get_order_by_subscription( $subscription_id );
        if ( ! $order ) {
            return;
        }

        $order->status = 'success';
        $order->saveOrder();

        pmpro_changeMembershipLevel( $order->membership_level, $order->user_id );
    }

    /**
     * Cancel membership when subscription cancelled.
     *
     * @param array $payload Payload.
     */
    protected static function handle_subscription_cancelled( $payload ) {
        $subscription_id = sanitize_text_field( $payload['data']['subscription_id'] ?? '' );
        if ( empty( $subscription_id ) ) {
            return;
        }

        $order = self::get_order_by_subscription( $subscription_id );
        if ( ! $order ) {
            return;
        }

        pmpro_cancelMembershipLevel( $order->membership_id, $order->user_id );
    }

    /**
     * Mark order as paid when payment succeeds.
     *
     * @param array $payload Payload.
     */
    protected static function handle_payment_succeeded( $payload ) {
        $subscription_id = sanitize_text_field( $payload['data']['subscription_id'] ?? '' );
        $transaction_id  = sanitize_text_field( $payload['data']['transaction_id'] ?? '' );

        if ( empty( $subscription_id ) ) {
            return;
        }

        $order = self::get_order_by_subscription( $subscription_id );
        if ( ! $order ) {
            return;
        }

        $order->status                    = 'success';
        $order->payment_transaction_id    = $transaction_id;
        $order->subscription_transaction_id = $subscription_id;
        $order->saveOrder();

        pmpro_insert_order_note( $order->id, sprintf( 'Payku payment succeeded. Transaction ID: %s', $transaction_id ) );
    }

    /**
     * Add note when payment fails.
     *
     * @param array $payload Payload.
     */
    protected static function handle_payment_failed( $payload ) {
        $subscription_id = sanitize_text_field( $payload['data']['subscription_id'] ?? '' );
        $transaction_id  = sanitize_text_field( $payload['data']['transaction_id'] ?? '' );
        $message         = sanitize_text_field( $payload['data']['message'] ?? '' );

        if ( empty( $subscription_id ) ) {
            return;
        }

        $order = self::get_order_by_subscription( $subscription_id );
        if ( ! $order ) {
            return;
        }

        pmpro_insert_order_note( $order->id, sprintf( 'Payku payment failed. Transaction ID: %1$s. Message: %2$s', $transaction_id, $message ) );
    }

    /**
     * Retrieve PMPro order by Payku subscription id.
     *
     * @param string $subscription_id Subscription identifier.
     *
     * @return MemberOrder|null
     */
    protected static function get_order_by_subscription( $subscription_id ) {
        global $wpdb;

        $order_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT order_id FROM {$wpdb->pmpro_membership_ordermeta} WHERE meta_key = %s AND meta_value = %s ORDER BY id DESC LIMIT 1",
                'payku_subscription_id',
                $subscription_id
            )
        );

        if ( empty( $order_id ) ) {
            return null;
        }

        $order = new MemberOrder( $order_id );
        $order->getMembershipLevel();

        return $order;
    }
}
