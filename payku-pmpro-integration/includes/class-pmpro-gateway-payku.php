<?php
/**
 * Payku gateway implementation for PMPro.
 *
 * @package pmpro-payku
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMProGateway_payku extends PMProGateway {
    /**
     * Constructor.
     */
    public function __construct( $gateway = null ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
        $this->gateway = 'payku';
        return parent::__construct( $gateway );
    }

    /**
     * Initialize PMPro hooks for the gateway.
     */
    public function init() {
        parent::init();

        add_action( 'pmpro_checkout_preheader', array( $this, 'enqueue_checkout_scripts' ) );
        add_filter( 'pmpro_checkout_default_submit_button', array( $this, 'filter_submit_button' ), 10, 2 );
    }

    /**
     * Load Payku JS SDK on checkout page.
     */
    public function enqueue_checkout_scripts() {
        if ( ! pmpro_is_checkout() ) {
            return;
        }

        $settings     = pmpro_payku_get_settings();
        $public_token = $settings['payku_public_token'] ?? '';

        if ( empty( $public_token ) ) {
            return;
        }

        wp_enqueue_script(
            'payku-checkout',
            pmpro_payku_is_sandbox() ? 'https://cdn.payku.cl/js/payku-checkout-sandbox.js' : 'https://cdn.payku.cl/js/payku-checkout.js',
            array( 'jquery' ),
            PMPRO_PAYKU_PLUGIN_VERSION,
            true
        );

        wp_add_inline_script(
            'payku-checkout',
            sprintf(
                'window.pmproPayku = %s;',
                wp_json_encode(
                    array(
                        'publicToken' => $public_token,
                        'returnUrl'   => pmpro_url( 'confirmation' ),
                        'webhookUrl'  => pmpro_payku_get_webhook_url(),
                    )
                )
            )
        );
    }

    /**
     * Replace submit button text for Payku.
     *
     * @param string $button HTML.
     * @param string $submit_text Button text.
     *
     * @return string
     */
    public function filter_submit_button( $button, $submit_text ) { // phpcs:ignore Squiz.Commenting.FunctionComment.MissingParamComment
        if ( 'payku' !== pmpro_getGateway() ) {
            return $button;
        }

        $button = sprintf(
            '<input type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="%s" />',
            esc_attr__( 'Pagar con Payku', 'pmpro-payku' )
        );

        return $button;
    }

    /**
     * Process checkout to initialize Payku subscription.
     *
     * @param MemberOrder $order Order.
     *
     * @return bool
     */
    public function process( &$order ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        if ( empty( $order->code ) ) {
            $order->code = $order->getRandomCode();
        }

        $settings = pmpro_payku_get_settings();
        $client   = new PMPro_Payku_API_Client( $settings );

        $order->payment_type             = 'Payku';
        $order->Gateway                  = $this->gateway;
        $order->status                   = 'pending';
        $order->subscription_transaction_id = ''; // set later.
        $order->payment_transaction_id   = '';

        $order->saveOrder();

        $level         = $order->membership_level;
        $billing_email = isset( $order->Email ) ? $order->Email : $order->billing->email;

        $payload = array(
            'plan_name'        => $level->name,
            'plan_description' => $level->description ?? $level->name,
            'amount'           => intval( round( $level->billing_amount * 100 ) ), // cents.
            'currency'         => pmpro_get_currency(),
            'interval'         => $this->map_billing_period( $level ),
            'interval_count'   => intval( max( 1, (int) ( $level->cycle_number ?? 1 ) ) ),
            'return_url'       => pmpro_url( 'confirmation', '?level=' . $level->id ),
            'cancel_url'       => pmpro_url( 'levels' ),
            'webhook_url'      => pmpro_payku_get_webhook_url(),
            'customer'         => array(
                'email'     => $billing_email,
                'firstName' => $order->billing->firstname ?? '',
                'lastName'  => $order->billing->lastname ?? '',
                'phone'     => $order->billing->phone ?? '',
                'address'   => trim( ( $order->billing->street ?? '' ) . ' ' . ( $order->billing->street2 ?? '' ) ),
                'city'      => $order->billing->city ?? '',
                'state'     => $order->billing->state ?? '',
                'zip'       => $order->billing->zip ?? '',
                'country'   => $order->billing->country ?? '',
            ),
            'metadata' => array(
                'pmpro_order_code'  => $order->code,
                'pmpro_user_id'     => $order->user_id,
                'pmpro_level_id'    => $level->id,
                'pmpro_environment' => pmpro_payku_is_sandbox() ? 'sandbox' : 'production',
            ),
        );

        if ( ! empty( $level->trial_limit ) && ! empty( $level->trial_amount ) ) {
            $payload['trial'] = array(
                'amount'      => intval( round( $level->trial_amount * 100 ) ),
                'interval'    => $this->map_billing_period( $level, true ),
                'interval_count' => intval( $level->trial_limit ),
            );
        }

        $response = $client->create_subscription( $payload );

        if ( is_wp_error( $response ) ) {
            $order->status              = 'error';
            $order->errorcode           = $response->get_error_code();
            $order->error               = $response->get_error_message();
            $order->shorterror          = __( 'Error creando la subscripción en Payku.', 'pmpro-payku' );
            $order->saveOrder();

            return false;
        }

        $redirect_url     = $response['url'] ?? '';
        $subscription_id  = $response['subscription_id'] ?? ( $response['data']['subscription_id'] ?? '' );

        if ( empty( $redirect_url ) || empty( $subscription_id ) ) {
            $order->status     = 'error';
            $order->error      = __( 'Respuesta inesperada al crear subscripción en Payku.', 'pmpro-payku' );
            $order->shorterror = __( 'Respuesta inesperada de Payku.', 'pmpro-payku' );
            $order->saveOrder();

            return false;
        }

        pmpro_payku_set_subscription_id( $order, $subscription_id );
        pmpro_payku_store_transaction_data(
            $order,
            array(
                'subscription_id' => $subscription_id,
            )
        );

        $order->status = 'pending';
        $order->saveOrder();

        wp_safe_redirect( esc_url_raw( $redirect_url ) );
        exit;
    }

    /**
     * Map PMPro billing cycles to Payku intervals.
     *
     * @param object $level Membership level.
     * @param bool   $for_trial Whether the mapping is for trial.
     *
     * @return string
     */
    protected function map_billing_period( $level, $for_trial = false ) {
        $period = $for_trial ? ( $level->trial_period ?? 'month' ) : ( $level->cycle_period ?? 'month' );

        switch ( strtolower( $period ) ) {
            case 'day':
            case 'daily':
                return 'day';
            case 'week':
            case 'weekly':
                return 'week';
            case 'month':
            case 'monthly':
                return 'month';
            case 'year':
            case 'annual':
                return 'year';
            default:
                return 'month';
        }
    }

    /**
     * Cancel subscription via Payku when membership cancelled.
     *
     * @param MemberOrder $order Order.
     * @return bool
     */
    public function cancel( &$order ) { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
        $subscription_id = pmpro_payku_get_subscription_id( $order );

        if ( empty( $subscription_id ) ) {
            return false;
        }

        $client   = new PMPro_Payku_API_Client( pmpro_payku_get_settings() );
        $response = $client->cancel_subscription( $subscription_id );

        if ( is_wp_error( $response ) ) {
            pmpro_insert_order_note( $order->id, sprintf( 'Payku cancellation error: %s', $response->get_error_message() ) );
            return false;
        }

        pmpro_insert_order_note( $order->id, __( 'Payku subscription cancelled via API.', 'pmpro-payku' ) );

        return true;
    }
}
