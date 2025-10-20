<?php
/**
 * Settings for the Payku gateway integration.
 *
 * @package pmpro-payku
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PMPro_Payku_Settings {
    /**
     * Hook settings initialization.
     */
    public static function init() {
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
    }

    /**
     * Register plugin settings with WordPress.
     */
    public static function register_settings() {
        $settings = array(
            'payku_environment',
            'payku_api_key',
            'payku_secret_key',
            'payku_public_token',
            'payku_secret_token',
            'payku_webhook_secret',
        );

        foreach ( $settings as $setting ) {
            register_setting( 'pmpro-gateways', $setting, array( __CLASS__, 'sanitize_setting' ) );
        }
    }

    /**
     * Sanitize individual settings values.
     *
     * @param mixed $value Raw value.
     *
     * @return string
     */
    public static function sanitize_setting( $value ) {
        if ( is_string( $value ) ) {
            return sanitize_text_field( $value );
        }

        return '';
    }

    /**
     * Render Payku settings fields within PMPro payment settings page.
     *
     * @param array $values Stored options.
     */
    public static function render_settings_fields( $values ) {
        $environment = isset( $values['payku_environment'] ) ? $values['payku_environment'] : get_option( 'payku_environment', 'production' );
        ?>
        <tr class="pmpro_settings_divider">
            <td colspan="2">
                <h3><?php esc_html_e( 'Payku Settings', 'pmpro-payku' ); ?></h3>
            </td>
        </tr>
        <tr class="pmpro_settings">
            <th scope="row">
                <label for="payku_environment"><?php esc_html_e( 'Environment', 'pmpro-payku' ); ?></label>
            </th>
            <td>
                <select id="payku_environment" name="payku_environment" class="regular-text">
                    <option value="production" <?php selected( $environment, 'production' ); ?>><?php esc_html_e( 'Production', 'pmpro-payku' ); ?></option>
                    <option value="sandbox" <?php selected( $environment, 'sandbox' ); ?>><?php esc_html_e( 'Sandbox', 'pmpro-payku' ); ?></option>
                </select>
                <p class="description"><?php esc_html_e( 'Choose the Payku environment to send API requests to.', 'pmpro-payku' ); ?></p>
            </td>
        </tr>
        <tr class="pmpro_settings">
            <th scope="row">
                <label for="payku_api_key"><?php esc_html_e( 'API Key', 'pmpro-payku' ); ?></label>
            </th>
            <td>
                <input id="payku_api_key" name="payku_api_key" value="<?php echo esc_attr( $values['payku_api_key'] ?? get_option( 'payku_api_key', '' ) ); ?>" class="regular-text" type="text" />
                <p class="description"><?php esc_html_e( 'Private API key provided by Payku.', 'pmpro-payku' ); ?></p>
            </td>
        </tr>
        <tr class="pmpro_settings">
            <th scope="row">
                <label for="payku_secret_key"><?php esc_html_e( 'Secret Key', 'pmpro-payku' ); ?></label>
            </th>
            <td>
                <input id="payku_secret_key" name="payku_secret_key" value="<?php echo esc_attr( $values['payku_secret_key'] ?? get_option( 'payku_secret_key', '' ) ); ?>" class="regular-text" type="password" autocomplete="new-password" />
                <p class="description"><?php esc_html_e( 'Secret key used for signing subscription requests.', 'pmpro-payku' ); ?></p>
            </td>
        </tr>
        <tr class="pmpro_settings">
            <th scope="row">
                <label for="payku_public_token"><?php esc_html_e( 'Public Token', 'pmpro-payku' ); ?></label>
            </th>
            <td>
                <input id="payku_public_token" name="payku_public_token" value="<?php echo esc_attr( $values['payku_public_token'] ?? get_option( 'payku_public_token', '' ) ); ?>" class="regular-text" type="text" />
                <p class="description"><?php esc_html_e( 'Public token used for Payku checkout widget integration.', 'pmpro-payku' ); ?></p>
            </td>
        </tr>
        <tr class="pmpro_settings">
            <th scope="row">
                <label for="payku_secret_token"><?php esc_html_e( 'Secret Token', 'pmpro-payku' ); ?></label>
            </th>
            <td>
                <input id="payku_secret_token" name="payku_secret_token" value="<?php echo esc_attr( $values['payku_secret_token'] ?? get_option( 'payku_secret_token', '' ) ); ?>" class="regular-text" type="password" autocomplete="new-password" />
                <p class="description"><?php esc_html_e( 'Secret token for creating subscriptions on the server.', 'pmpro-payku' ); ?></p>
            </td>
        </tr>
        <tr class="pmpro_settings">
            <th scope="row">
                <label for="payku_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'pmpro-payku' ); ?></label>
            </th>
            <td>
                <input id="payku_webhook_secret" name="payku_webhook_secret" value="<?php echo esc_attr( $values['payku_webhook_secret'] ?? get_option( 'payku_webhook_secret', '' ) ); ?>" class="regular-text" type="password" autocomplete="new-password" />
                <p class="description"><?php esc_html_e( 'Token used to validate Payku webhook signatures.', 'pmpro-payku' ); ?></p>
            </td>
        </tr>
        <tr class="pmpro_settings">
            <th scope="row"><?php esc_html_e( 'Webhook URL', 'pmpro-payku' ); ?></th>
            <td>
                <code><?php echo esc_html( pmpro_payku_get_webhook_url() ); ?></code>
                <p class="description"><?php esc_html_e( 'Configure this URL in your Payku panel to receive subscription events.', 'pmpro-payku' ); ?></p>
            </td>
        </tr>
        <?php
    }

    /**
     * Enqueue admin CSS for settings page.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_admin_assets( $hook ) {
        if ( 'membershiplevels_page_pmpro-paymentsettings' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'pmpro-payku-admin',
            plugins_url( 'assets/css/admin.css', PMPRO_PAYKU_PLUGIN_FILE ),
            array(),
            PMPRO_PAYKU_PLUGIN_VERSION
        );
    }
}
