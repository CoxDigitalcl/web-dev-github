<?php
/**
 * Plugin Name: IECT – Payku Return Bridge (Consolidado 2025-10-15)
 * Description: Detecta el retorno a la página "thank-you" y hace autologin + redirección a la confirmación de PMPro.
 * Version: 1.3.0
 */

if (!defined('ABSPATH')) exit;

if (!defined('IECT_RETURN_LOG')) define('IECT_RETURN_LOG', WP_CONTENT_DIR.'/payku-return-bridge.log');

function iect_rlog($msg){
  $line = (is_array($msg)||is_object($msg))?json_encode($msg):$msg;
  @file_put_contents(IECT_RETURN_LOG, '['.gmdate('c')."] ".$line."\n", FILE_APPEND);
}

add_action('template_redirect', function(){
  $path = parse_url(home_url($_SERVER['REQUEST_URI']), PHP_URL_PATH);
  $is_thanks = (strpos($path, '/thank-you') !== false) || (strpos($path, '/gracias-pago') !== false);
  iect_rlog("TEMPLATE_REDIRECT path={$path} is_thanks=".($is_thanks?1:0)." logged_in=".(is_user_logged_in()?1:0));

  if (!$is_thanks) return;
  if (is_user_logged_in()) return;

  $sub  = isset($_GET['subscription_id']) ? sanitize_text_field($_GET['subscription_id']) : '';
  $tx   = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';
  if (!$sub && !$tx) return;

  global $wpdb;
  $table = $wpdb->prefix.'pmpro_membership_orders';
  $order = false;
  if ($sub) $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE subscription_transaction_id=%s ORDER BY id DESC LIMIT 1", $sub));
  if (!$order && $tx) $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE payment_transaction_id=%s ORDER BY id DESC LIMIT 1", $tx));

  if ($order && $order->user_id) {
    wp_set_auth_cookie((int)$order->user_id, true);
    wp_set_current_user((int)$order->user_id);
    iect_rlog("LOGIN OK user_id={$order->user_id}");
    $redirect = home_url('/ns26/pago-de-membresia/confirmacion-de-membresia/');
    if (!empty($order->code)) $redirect = add_query_arg('order', $order->code, $redirect);
    iect_rlog("REDIRECT => {$redirect}");
    wp_safe_redirect($redirect);
    exit;
  } else {
    iect_rlog('NO ORDER MATCH (return bridge)');
  }
});
