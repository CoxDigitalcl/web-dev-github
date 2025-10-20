<?php
/**
 * Basic API client for Payku REST requests.
 *
 * @package pmpro-payku
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMPro_Payku_API_Client {
    /**
     * Payku API base URL.
     *
     * @var string
     */
    protected $base_url;

    /**
     * Secret token for Authorization header.
     *
     * @var string
     */
    protected $secret_token;

    /**
     * Public token for JS integration.
     *
     * @var string
     */
    protected $public_token;

    /**
     * API key (client id) for Payku.
     *
     * @var string
     */
    protected $api_key;

    /**
     * API secret key for Payku.
     *
     * @var string
     */
    protected $secret_key;

    /**
     * Constructor.
     *
     * @param array $settings Payku settings array.
     */
    public function __construct( $settings ) {
        $this->base_url     = trailingslashit( pmpro_payku_get_api_base_url() );
        $this->secret_token = $settings['payku_secret_token'] ?? '';
        $this->public_token = $settings['payku_public_token'] ?? '';
        $this->api_key      = $settings['payku_api_key'] ?? '';
        $this->secret_key   = $settings['payku_secret_key'] ?? '';
    }

    /**
     * Create a subscription on Payku.
     *
     * @param array $payload Subscription payload.
     *
     * @return array|WP_Error
     */
    public function create_subscription( $payload ) {
        return $this->request( 'POST', 'v1/inscriptions', $payload );
    }

    /**
     * Cancel a subscription on Payku.
     *
     * @param string $subscription_id Payku subscription ID.
     *
     * @return array|WP_Error
     */
    public function cancel_subscription( $subscription_id ) {
        return $this->request( 'POST', sprintf( 'v1/subscriptions/%s/cancel', rawurlencode( $subscription_id ) ), array() );
    }

    /**
     * Retrieve a subscription.
     *
     * @param string $subscription_id Payku subscription ID.
     *
     * @return array|WP_Error
     */
    public function get_subscription( $subscription_id ) {
        return $this->request( 'GET', sprintf( 'v1/subscriptions/%s', rawurlencode( $subscription_id ) ) );
    }

    /**
     * Perform an authenticated request against Payku API.
     *
     * @param string $method HTTP method.
     * @param string $path   API path.
     * @param array  $body   Request payload.
     *
     * @return array|WP_Error
     */
    protected function request( $method, $path, $body = array() ) {
        $url = $this->base_url . ltrim( $path, '/' );

        $headers = array(
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'Authorization' => 'Bearer ' . $this->secret_token,
            'X-Api-Key'     => $this->api_key,
            'X-Api-Secret'  => $this->secret_key,
        );

        $args = array(
            'method'      => strtoupper( $method ),
            'headers'     => $headers,
            'timeout'     => 45,
            'data_format' => 'body',
        );

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $data   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status < 200 || $status >= 300 ) {
            if ( null === $data ) {
                $data = array( 'message' => sprintf( 'HTTP %d error', $status ) );
            }

            return new WP_Error( 'payku_api_error', pmpro_payku_extract_error_message( $data ), $data );
        }

        return $data;
    }
}
