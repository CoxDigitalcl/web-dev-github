<?php
/*
Plugin Name: PMPro – Payku Gateway (UI + Redirect)
Description: Pasarela de Payku para Paid Memberships Pro con UI (sandbox/producción), creación de cliente + suscripción y redirección a approval_url. Funciona junto al plugin de Webhook que ya instalaste.
Author: Tu Equipo
Version: 0.4.0
*/

if ( ! defined('ABSPATH') ) exit;

/**
 * ==========
 * 0) Helpers
 * ==========
 */

if (!function_exists('pmpropayku_api_base')) {
  function pmpropayku_api_base( $mode ) {
    // Dev/Sandbox (Payku usa "des" para desarrollo)
    return $mode === 'live' ? 'https://app.payku.cl/api' : 'https://des.payku.cl/api';
  }
}

if (!function_exists('pmpropayku_get_mode')) {
  function pmpropayku_get_mode() {
    $gw = get_option('pmpro_gateway');
    if ( $gw !== 'payku' ) return 'sandbox';
    $mode = get_option('pmpro_payku_mode', 'sandbox');
    return $mode === 'live' ? 'live' : 'sandbox';
  }
}

if (!function_exists('pmpropayku_get_tokens')) {
  function pmpropayku_get_tokens() {
    $mode = pmpropayku_get_mode();
    if ($mode === 'live') {
      return array(
        'pub'  => trim((string) get_option('pmpro_payku_pub_live', '')),
        'priv' => trim((string) get_option('pmpro_payku_priv_live','')),
      );
    } else {
      return array(
        'pub'  => trim((string) get_option('pmpro_payku_pub_sandbox', '')),
        'priv' => trim((string) get_option('pmpro_payku_priv_sandbox','')),
      );
    }
  }
}

/**
 * Firma HMAC-SHA256 (HEX) al estilo Payku:
 * concat = urlencode('/api/.../') + '&' + query(keys ordenadas, urlencode, ' ' => '+')
 * sign   = HMAC_SHA256(concat, token_priv)
 */
if (!function_exists('pmpropayku_sign')) {
  function pmpropayku_sign($endpoint_path, $data, $secret) {
    // 1) Normalizar: el path firmado SIEMPRE debe quedar "/api/.../"
    $endpoint_path = '/' . ltrim($endpoint_path, '/');       // "/suclient/" => "/suclient/"
    $path          = '/api' . rtrim($endpoint_path, '/');    // "/api/suclient"
    $path         .= '/';                                    // "/api/suclient/"

    // 2) Ordenar y codificar datos
    ksort($data);
    $pairs = [];
    foreach ($data as $k => $v) {
      $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
    }
    $query = implode('&', $pairs);
    $query = str_replace('%20', '+', $query);                // Payku usa + para espacios

    // 3) Concatenar y firmar (HEX)
    $concat = rawurlencode($path) . '&' . $query;
    return hash_hmac('sha256', $concat, (string) $secret);
  }
}

if (!function_exists('pmpropayku_request')) {
  function pmpropayku_request($method, $endpoint, $token_bearer, $token_priv, $data = array()) {
    $base     = rtrim(pmpropayku_api_base(pmpropayku_get_mode()), '/'); // ej: https://des.payku.cl/api
    $endpoint = '/' . ltrim($endpoint, '/');                             // ej: "/suclient/"
    $url      = $base . $endpoint;                                       // -> https://des.payku.cl/api/suclient/

    $headers = array(
      'Authorization' => 'Bearer ' . $token_bearer,
      'Content-Type'  => 'application/json',
      'Sign'          => pmpropayku_sign($endpoint, $data, $token_priv),
    );

    $args = array('method' => $method, 'headers' => $headers, 'timeout' => 30);
    if ($method !== 'GET') {
      $args['body'] = wp_json_encode($data);
    }

    $res  = wp_remote_request($url, $args);
    $code = wp_remote_retrieve_response_code($res);
    $body = json_decode(wp_remote_retrieve_body($res), true);

    return array($code, $body, $res);
  }
}

/**
 * ==========
 * 1) UI – Registrar gateway "payku" en PMPro
 * ==========
 */
add_filter('pmpro_gateways', function($gateways){
  $gateways['payku'] = 'Payku';
  return $gateways;
});
add_filter('pmpro_payment_gateway_select_options', function($options){
  $options['payku'] = 'Payku';
  return $options;
});

/**
 * Campos que PMPro debe persistir (aparecen en la página nativa de pasarelas)
 */
add_filter('pmpro_payment_options', function($options){
  $my = array(
    'pmpro_payku_mode',
    'pmpro_payku_pub_sandbox',
    'pmpro_payku_priv_sandbox',
    'pmpro_payku_pub_live',
    'pmpro_payku_priv_live',
    'pmpro_payku_return_url',
    'pmpro_payku_notify_subscription',
    'pmpro_payku_notify_payment',
  );
  return array_merge($options, $my);
}, 10);

/**
 * Render de campos cuando el gateway seleccionado es "payku"
 */
add_action('pmpro_payment_option_fields', function($gateway = null){
  if (!$gateway) $gateway = get_option('pmpro_gateway');
  if ($gateway !== 'payku') return;

  $mode   = get_option('pmpro_payku_mode', 'sandbox');
  $pub_s  = esc_attr((string) get_option('pmpro_payku_pub_sandbox',''));
  $priv_s = esc_attr((string) get_option('pmpro_payku_priv_sandbox',''));
  $pub_l  = esc_attr((string) get_option('pmpro_payku_pub_live',''));
  $priv_l = esc_attr((string) get_option('pmpro_payku_priv_live',''));

  $return = esc_url((string) get_option('pmpro_payku_return_url',''));
  $ns     = esc_url((string) get_option('pmpro_payku_notify_subscription',''));
  $np     = esc_url((string) get_option('pmpro_payku_notify_payment',''));
  ?>
  <tr class="pmpro_payku"><th colspan="2"><h3>Payku</h3></th></tr>

  <tr class="pmpro_payku">
    <th scope="row" valign="top"><label for="pmpro_payku_mode">Modo</label></th>
    <td>
      <select name="pmpro_payku_mode" id="pmpro_payku_mode">
        <option value="sandbox" <?php selected($mode, 'sandbox'); ?>>Sandbox / Desarrollo</option>
        <option value="live"     <?php selected($mode, 'live'); ?>>Producción</option>
      </select>
      <p class="description">Sandbox usa <code>https://des.payku.cl/api</code>; Producción usa <code>https://app.payku.cl/api</code>.</p>
    </td>
  </tr>

  <tr><th colspan="2"><h4>Credenciales Sandbox</h4></th></tr>
  <tr>
    <th><label for="pmpro_payku_pub_sandbox">Token Público (Sandbox)</label></th>
    <td><input type="text" name="pmpro_payku_pub_sandbox" id="pmpro_payku_pub_sandbox" value="<?php echo $pub_s; ?>" size="60"></td>
  </tr>
  <tr>
    <th><label for="pmpro_payku_priv_sandbox">Token Privado (Sandbox)</label></th>
    <td><input type="text" name="pmpro_payku_priv_sandbox" id="pmpro_payku_priv_sandbox" value="<?php echo $priv_s; ?>" size="60"></td>
  </tr>

  <tr><th colspan="2"><h4>Credenciales Producción</h4></th></tr>
  <tr>
    <th><label for="pmpro_payku_pub_live">Token Público (Live)</label></th>
    <td><input type="text" name="pmpro_payku_pub_live" id="pmpro_payku_pub_live" value="<?php echo $pub_l; ?>" size="60"></td>
  </tr>
  <tr>
    <th><label for="pmpro_payku_priv_live">Token Privado (Live)</label></th>
    <td><input type="text" name="pmpro_payku_priv_live" id="pmpro_payku_priv_live" value="<?php echo $priv_l; ?>" size="60"></td>
  </tr>

  <tr><th colspan="2"><h4>URLs</h4></th></tr>
  <tr>
    <th><label for="pmpro_payku_return_url">Return URL</label></th>
    <td>
      <input type="url" name="pmpro_payku_return_url" id="pmpro_payku_return_url" value="<?php echo $return; ?>" size="80">
      <p class="description">Página adonde vuelve el alumno tras completar Payku (éxito). Sugerencia: tu página de “Gracias”.</p>
    </td>
  </tr>
  <tr>
    <th><label for="pmpro_payku_notify_subscription">Notify URL (Suscripción)</label></th>
    <td>
      <input type="url" name="pmpro_payku_notify_subscription" id="pmpro_payku_notify_subscription" value="<?php echo $ns; ?>" size="80">
      <p class="description">Ej: <code><?php echo esc_html( site_url('/wp-json/payku/v1/webhook?topic=subscription') ); ?></code></p>
    </td>
  </tr>
  <tr>
    <th><label for="pmpro_payku_notify_payment">Notify URL (Cobro)</label></th>
    <td>
      <input type="url" name="pmpro_payku_notify_payment" id="pmpro_payku_notify_payment" value="<?php echo $np; ?>" size="80">
      <p class="description">Ej: <code><?php echo esc_html( site_url('/wp-json/payku/v1/webhook?topic=payment') ); ?></code></p>
    </td>
  </tr>
  <?php
}, 10, 1);


/**
 * ==========
 * 2) Metabox en cada Nivel – Payku Plan ID
 * ==========
 */
add_action('pmpro_membership_level_after_other_settings', function($level){
  $plan = get_option('pmpro_payku_plan_'.$level->id);
  ?>
  <h3>Payku</h3>
  <table class="form-table">
    <tr>
      <th scope="row"><label for="pmpro_payku_plan_<?php echo (int)$level->id; ?>">Plan ID</label></th>
      <td>
        <input type="text" name="pmpro_payku_plan_<?php echo (int)$level->id; ?>" id="pmpro_payku_plan_<?php echo (int)$level->id; ?>" value="<?php echo esc_attr($plan); ?>" size="50">
        <p class="description">ID del Plan de Payku para este nivel (ej: <code>plxxxxxxxx</code>).</p>
      </td>
    </tr>
  </table>
  <?php
});

add_action('pmpro_save_membership_level', function($level_id){
  if ( isset($_POST['pmpro_payku_plan_'.$level_id]) ) {
    update_option('pmpro_payku_plan_'.$level_id, sanitize_text_field($_POST['pmpro_payku_plan_'.$level_id]));
  }
});

/**
 * ==========
 * 3) Clase Gateway – Redirigir a Payku (crear cliente + suscripción)
 * ==========
 * Crea cliente/suscripción y redirige a approval_url.
 * El alta final la hace tu webhook (plugin aparte).
 */
add_action('init', function(){
  if ( class_exists('PMProGateway') && ! class_exists('PMProGateway_payku') ) {
    class PMProGateway_payku extends PMProGateway {
      public function __construct($gateway = null) { $this->gateway = 'payku'; return $this; }

      public static function init() {
        add_action('pmpro_checkout_preheader', array('PMProGateway_payku','pmpro_checkout_preheader'));
      }

      public static function pmpro_checkout_preheader() {
        global $pmpro_level, $current_user;

        // Solo al enviar el checkout
        if ( empty($_REQUEST['submit-checkout']) ) return;

        $level_id = isset($pmpro_level->id) ? (int) $pmpro_level->id : 0;
        if (!$level_id) {
          pmpro_setMessage('No se pudo determinar el nivel de membresía.','pmpro_error');
          return;
        }

        $plan_id  = get_option('pmpro_payku_plan_'.$level_id);
        if ( empty($plan_id) ) {
          pmpro_setMessage('Falta configurar el Payku Plan ID para este nivel.','pmpro_error');
          return;
        }

        // Tokens y URLs
        $tokens = pmpropayku_get_tokens();
        if ( empty($tokens['pub']) || empty($tokens['priv']) ) {
          pmpro_setMessage('Faltan credenciales Payku (tokens).','pmpro_error'); return;
        }

        $return_url = get_option('pmpro_payku_return_url', home_url('/'));
        $notify_s   = get_option('pmpro_payku_notify_subscription', site_url('/wp-json/payku/v1/webhook?topic=subscription'));
        $notify_p   = get_option('pmpro_payku_notify_payment', site_url('/wp-json/payku/v1/webhook?topic=payment'));

        // 1) Crear cliente (si no existe)
        $client_id = get_user_meta($current_user->ID, '_payku_client_id', true);
        if ( empty($client_id) ) {
          $full_name = trim($current_user->first_name.' '.$current_user->last_name);
          if (!$full_name) $full_name = $current_user->display_name ?: 'Alumno';

          $body_client = array(
            'email'       => $current_user->user_email,
            'name'        => $full_name,
            // Mapeos desde el formulario de PMPro (ajusta si tienes campos personalizados)
            'rut'         => isset($_REQUEST['bcompany']) ? sanitize_text_field($_REQUEST['bcompany']) : '11111111-1',
            'phone'       => sanitize_text_field($_REQUEST['bphone']    ?? '999999999'),
            'address'     => sanitize_text_field($_REQUEST['baddress1'] ?? 'Moneda 101'),
            'country'     => sanitize_text_field($_REQUEST['bcountry']  ?? 'Chile'),
            'region'      => sanitize_text_field($_REQUEST['bstate']    ?? 'Metropolitana'),
            'city'        => sanitize_text_field($_REQUEST['bcity']     ?? 'Santiago'),
            'postal_code' => sanitize_text_field($_REQUEST['bzipcode']  ?? '850000'),
          );

          list($code_c, $json_c) = pmpropayku_request(
  'POST',
  '/suclient/',
  $tokens['pub'],  // ✅ Bearer PÚBLICO (funcionó en tu test)
  $tokens['priv'], // secreto de la firma HMAC
  $body_client
);


          if ($code_c !== 200 || empty($json_c['id'])) {
            pmpro_setMessage('No se pudo crear el cliente en Payku: '.esc_html(wp_json_encode($json_c)),'pmpro_error');
            return;
          }
          $client_id = $json_c['id'];
          update_user_meta($current_user->ID, '_payku_client_id', $client_id);
        }

        // 2) Crear suscripción
        $body_sub = array(
          'plan'        => $plan_id,
          'client'      => $client_id,
          // (opcional) podrías agregar notify/return si Payku lo permite a nivel de creación
          // 'notify_url'  => $notify_s,
          // 'return_url'  => $return_url,
        );

        list($code_s, $json_s) = pmpropayku_request(
          'POST',
          '/sususcription/',     // sin "/api"
          $tokens['pub'],        // Bearer PÚBLICO para suscripción
          $tokens['priv'],
          $body_sub
        );

        if ($code_s !== 200 || empty($json_s['id']) || empty($json_s['url'])) {
          pmpro_setMessage('No se pudo crear la suscripción en Payku: '.esc_html(wp_json_encode($json_s)),'pmpro_error');
          return;
        }

        // Guardar vínculo en el usuario para que el webhook resuelva/active
        update_user_meta($current_user->ID, '_payku_subscription_id', $json_s['id']);
        update_user_meta($current_user->ID, '_payku_level_id',       $level_id);

        // 3) Redirigir a approval_url
        wp_redirect( $json_s['url'] );
        exit;
      }
    }
    PMProGateway_payku::init();
  }
});

/**
 * ==========
 * 4) (Opcional) Icono del gateway
 * ==========
 */
add_filter('pmpro_show_payment_gateway_icon', function($gateways){
  // Si subes un icono "payku.png" a esta carpeta, se mostrará aquí.
  $icon = plugin_dir_path(__FILE__) . 'payku.png';
  if ( file_exists($icon) ) {
    $gateways['payku'] = plugins_url('payku.png', __FILE__);
  }
  return $gateways;
});

/**
 * ==========
 * 5) Ajustes propios: Menú “Membresías → Payku” (compatible PMPro 2.x/3.x)
 * ==========
 */
if (!function_exists('pmpropayku_render_settings_page')) {
  function pmpropayku_render_settings_page() {
    if ( ! current_user_can('manage_options') ) return;

    // Guardado simple
    if ( isset($_POST['pmpropayku_save']) && check_admin_referer('pmpropayku_save_opts') ) {
      $fields = array(
        'pmpro_payku_mode',
        'pmpro_payku_pub_sandbox',
        'pmpro_payku_priv_sandbox',
        'pmpro_payku_pub_live',
        'pmpro_payku_priv_live',
        'pmpro_payku_return_url',
        'pmpro_payku_notify_subscription',
        'pmpro_payku_notify_payment',
      );
      foreach ($fields as $f) {
        if (isset($_POST[$f])) {
          update_option($f, sanitize_text_field($_POST[$f]));
        }
      }
      echo '<div class="updated"><p>Guardado.</p></div>';
    }

    $mode   = get_option('pmpro_payku_mode', 'sandbox');
    $pub_s  = esc_attr((string) get_option('pmpro_payku_pub_sandbox',''));
    $priv_s = esc_attr((string) get_option('pmpro_payku_priv_sandbox',''));
    $pub_l  = esc_attr((string) get_option('pmpro_payku_pub_live',''));
    $priv_l = esc_attr((string) get_option('pmpro_payku_priv_live',''));

    $return = esc_url((string) get_option('pmpro_payku_return_url',''));
    $ns     = esc_url((string) get_option('pmpro_payku_notify_subscription',''));
    $np     = esc_url((string) get_option('pmpro_payku_notify_payment',''));
    ?>
    <div class="wrap">
      <h1>Ajustes Payku</h1>
      <form method="post">
        <?php wp_nonce_field('pmpropayku_save_opts'); ?>
        <table class="form-table">
          <tr>
            <th><label for="pmpro_payku_mode">Modo</label></th>
            <td>
              <select name="pmpro_payku_mode" id="pmpro_payku_mode">
                <option value="sandbox" <?php selected($mode, 'sandbox'); ?>>Sandbox / Desarrollo</option>
                <option value="live"     <?php selected($mode, 'live'); ?>>Producción</option>
              </select>
            </td>
          </tr>
          <tr><th colspan="2"><h3>Credenciales Sandbox</h3></th></tr>
          <tr><th><label for="pmpro_payku_pub_sandbox">Token Público</label></th>
              <td><input type="text" name="pmpro_payku_pub_sandbox" id="pmpro_payku_pub_sandbox" value="<?php echo $pub_s; ?>" size="70"></td></tr>
          <tr><th><label for="pmpro_payku_priv_sandbox">Token Privado</label></th>
              <td><input type="text" name="pmpro_payku_priv_sandbox" id="pmpro_payku_priv_sandbox" value="<?php echo $priv_s; ?>" size="70"></td></tr>

          <tr><th colspan="2"><h3>Credenciales Producción</h3></th></tr>
          <tr><th><label for="pmpro_payku_pub_live">Token Público</label></th>
              <td><input type="text" name="pmpro_payku_pub_live" id="pmpro_payku_pub_live" value="<?php echo $pub_l; ?>" size="70"></td></tr>
          <tr><th><label for="pmpro_payku_priv_live">Token Privado</label></th>
              <td><input type="text" name="pmpro_payku_priv_live" id="pmpro_payku_priv_live" value="<?php echo $priv_l; ?>" size="70"></td></tr>

          <tr><th colspan="2"><h3>URLs</h3></th></tr>
          <tr><th><label for="pmpro_payku_return_url">Return URL</label></th>
              <td><input type="url" name="pmpro_payku_return_url" id="pmpro_payku_return_url" value="<?php echo $return; ?>" size="80"></td></tr>
          <tr><th><label for="pmpro_payku_notify_subscription">Notify URL (Suscripción)</label></th>
              <td><input type="url" name="pmpro_payku_notify_subscription" id="pmpro_payku_notify_subscription" value="<?php echo $ns; ?>" size="80"></td></tr>
          <tr><th><label for="pmpro_payku_notify_payment">Notify URL (Cobro)</label></th>
              <td><input type="url" name="pmpro_payku_notify_payment" id="pmpro_payku_notify_payment" value="<?php echo $np; ?>" size="80"></td></tr>
        </table>
        <p><button type="submit" class="button button-primary" name="pmpropayku_save" value="1">Guardar los ajustes</button></p>
      </form>
      <p><em>Tip:</em> los mismos campos aparecen también en “Membresías → Ajustes → Pasarela de pago” cuando eliges Payku.</p>
    </div>
    <?php
  }
}

add_action('admin_menu', function () {
  if ( ! current_user_can('manage_options') ) return;

  // Detectar padre disponible en PMPro (3.x usa "pmpro-dashboard")
  global $submenu;
  $candidatos = array('pmpro-dashboard','pmpro-membershiplevels','pmpro-paymentsettings');
  $padre = null;
  foreach ($candidatos as $cand) { if (isset($submenu[$cand])) { $padre = $cand; break; } }
  if (!$padre) $padre = 'options-general.php';

  add_submenu_page(
    $padre,
    'Ajustes Payku',
    'Payku',
    'manage_options',
    'pmpro-payku-settings',
    'pmpropayku_render_settings_page',
    20
  );
}, 99);
