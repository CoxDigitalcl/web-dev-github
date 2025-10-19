<?php
/**
 * Plugin Name: Payku Webhook (PMPro)
 * Description: Endpoint REST para recibir notificaciones de Payku (suscripción y pagos) e integrarlas con Paid Memberships Pro.
 * Version: 0.3.0
 */

if ( ! defined('ABSPATH') ) exit;

add_action('rest_api_init', function () {
  register_rest_route('payku/v1', '/webhook', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => 'payku_pmpro_webhook_handler',
  ]);

  // GET opcional de salud
  register_rest_route('payku/v1', '/webhook-ping', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function(){ return new \WP_REST_Response(['ok'=>true], 200); }
  ]);
});

function payku_pmpro_webhook_handler(\WP_REST_Request $req){
  // --- 0) Preparación & logging crudo (headers + body)
  $topic   = sanitize_key($req->get_param('topic') ?: 'generic');
  $payload = $req->get_json_params();
  if (empty($payload)) {
    $raw = $req->get_body();
    $tmp = json_decode($raw, true);
    if (is_array($tmp)) $payload = $tmp;
  }
  $headers = $req->get_headers();

  // Log por tópico (registro crudo)
  $logfile = trailingslashit(WP_CONTENT_DIR) . "payku-webhook-{$topic}.log";
  @file_put_contents($logfile, date('c').' '.wp_json_encode(['headers'=>$headers,'body'=>$payload]).PHP_EOL, FILE_APPEND);

  // --- 1) Extraer datos clave (robusto)
  $subscription_id = $payload['subscription_id']
    ?? ($payload['subscription'] ?? ($payload['id'] ?? ($payload['data']['id'] ?? ($payload['subscriptions']['id'] ?? null))));

  $status = $payload['status']
    ?? ($payload['data']['status'] ?? null);

  $tx_id     = $payload['transaction_id'] ?? ($payload['transaction_key'] ?? ($payload['data']['transaction_id'] ?? null));
  $client_id = $payload['client'] ?? ($payload['subscriptions']['client'] ?? ($payload['data']['client'] ?? null));

  // --- 2) Localizar usuario por meta
  $user_id = 0;
  if ($subscription_id) {
    $user_id = payku_pmpro_find_user_by_meta('_payku_subscription_id', $subscription_id);
  }
  if (!$user_id && $client_id) {
    $user_id = payku_pmpro_find_user_by_meta('_payku_client_id', $client_id);
  }

  // DEBUG: estado de resolución de usuario/level
  $level_id = $user_id ? (int) get_user_meta($user_id, '_payku_level_id', true) : 0;
  $debug = [
    'topic'            => $topic,
    'status'           => $status,
    'subscription_id'  => $subscription_id,
    'client_id'        => $client_id,
    'resolved_user_id' => $user_id,
    'level_id'         => $level_id,
    'tx_id'            => $tx_id,
  ];
  @file_put_contents($logfile, date('c').' DEBUG '.wp_json_encode($debug).PHP_EOL, FILE_APPEND);

  // Guarda último status/payload para soporte (si resolvimos user)
  if ($user_id) {
    update_user_meta($user_id, '_payku_last_status', $status ?: 'unknown');
    update_user_meta($user_id, '_payku_last_payload', wp_json_encode($payload));
  } else {
    // DEBUG: no link
    @file_put_contents($logfile, date('c')." DEBUG not_linked (user not found by su/client)".PHP_EOL, FILE_APPEND);
  }

  // --- 3) Reglas por tópico/estado
  $ok_states      = ['active','approved','paid','authorized','success'];
  $failed_states  = ['failed','rejected','cancelled','canceled','expired'];

  if ($topic === 'subscription') {
    if ($subscription_id && in_array(strtolower((string)$status), $ok_states, true)) {
      @file_put_contents($logfile, date('c')." ACTION subscription->activate user={$user_id} level={$level_id} su={$subscription_id}".PHP_EOL, FILE_APPEND);
      payku_pmpro_activate_membership($user_id, $subscription_id, $tx_id);
    } elseif ($subscription_id && in_array(strtolower((string)$status), $failed_states, true)) {
      @file_put_contents($logfile, date('c')." ACTION subscription->cancel user={$user_id} su={$subscription_id}".PHP_EOL, FILE_APPEND);
      payku_pmpro_cancel_membership($user_id);
    } else {
      @file_put_contents($logfile, date('c')." DEBUG subscription->no_action (status={$status})".PHP_EOL, FILE_APPEND);
    }
  } elseif ($topic === 'payment') {
    if (in_array(strtolower((string)$status), $ok_states, true)) {
      @file_put_contents($logfile, date('c')." ACTION payment->activate/complete user={$user_id} level={$level_id} su={$subscription_id} tx={$tx_id}".PHP_EOL, FILE_APPEND);
      payku_pmpro_activate_membership($user_id, $subscription_id, $tx_id, /*close_order=*/true);
    } elseif (in_array(strtolower((string)$status), $failed_states, true)) {
      @file_put_contents($logfile, date('c')." ACTION payment->fail user={$user_id} su={$subscription_id}".PHP_EOL, FILE_APPEND);
      payku_pmpro_fail_order($user_id, $subscription_id);
    } else {
      @file_put_contents($logfile, date('c')." DEBUG payment->no_action (status={$status})".PHP_EOL, FILE_APPEND);
    }
  } else {
    // genérico
    if (in_array(strtolower((string)$status), $ok_states, true)) {
      @file_put_contents($logfile, date('c')." ACTION generic->activate user={$user_id} level={$level_id} su={$subscription_id}".PHP_EOL, FILE_APPEND);
      payku_pmpro_activate_membership($user_id, $subscription_id, $tx_id);
    } else {
      @file_put_contents($logfile, date('c')." DEBUG generic->no_action (status={$status})".PHP_EOL, FILE_APPEND);
    }
  }

  return new \WP_REST_Response(['ok' => true], 200);
}


// ===== Utilidades =====

function payku_pmpro_find_user_by_meta($key, $value){
  $users = get_users([
    'meta_key'   => $key,
    'meta_value' => $value,
    'fields'     => 'ID',
    'number'     => 1,
  ]);
  return $users ? (int)$users[0] : 0;
}

function payku_pmpro_get_level_for_user($user_id){
  // Guardaste esto al crear la orden/suscripción
  $lvl = (int) get_user_meta($user_id, '_payku_level_id', true);
  return $lvl > 0 ? $lvl : 0;
}

function payku_pmpro_activate_membership($user_id, $subscription_id, $tx_id = null, $close_order = false){
  if (!$user_id) return;

  // 1) Activa la membresía
  $level_id = payku_pmpro_get_level_for_user($user_id);
  if ($level_id > 0 && function_exists('pmpro_changeMembershipLevel')) {
    pmpro_changeMembershipLevel($level_id, $user_id);
  }

  // 2) Cierra orden si existe (o crea una de respaldo)
  payku_pmpro_complete_order($user_id, $subscription_id, $tx_id, $close_order);
}

function payku_pmpro_cancel_membership($user_id){
  if ($user_id && function_exists('pmpro_cancelMembershipLevel')) {
    pmpro_cancelMembershipLevel($user_id);
  }
}

function payku_pmpro_complete_order($user_id, $subscription_id, $tx_id = null, $force_create = false){
  // Requiere clase de PMPro
  if ( ! class_exists('MemberOrder') ) {
    if (file_exists(WP_PLUGIN_DIR.'/paid-memberships-pro/classes/class.memberorder.php')) {
      require_once WP_PLUGIN_DIR.'/paid-memberships-pro/classes/class.memberorder.php';
    } elseif (file_exists(WP_PLUGIN_DIR.'/paid-memberships-pro/includes/classes/class.memberorder.php')) {
      require_once WP_PLUGIN_DIR.'/paid-memberships-pro/includes/classes/class.memberorder.php';
    }
  }
  if ( ! class_exists('MemberOrder') ) return;

  // Busca una orden con la suscripción asociada (guardada en checkout)
  $order_id = 0;
  $q = new WP_Query([
    'post_type'      => 'pmpro_order',
    'posts_per_page' => 1,
    'post_status'    => 'any',
    'meta_query'     => [[
      'key'   => '_payku_subscription_id',
      'value' => $subscription_id,
    ]],
    'fields' => 'ids',
  ]);
  if ($q->have_posts()) $order_id = (int) $q->posts[0];

  if ($order_id) {
    // Actualiza orden existente a success
    $order = new MemberOrder($order_id);
    if ($tx_id) $order->payment_transaction_id = $tx_id;
    $order->status = 'success';
    $order->saveOrder();
  } elseif ($force_create && $user_id) {
    // Crea una orden "de respaldo" por si no existía
    $order = new MemberOrder();
    $order->user_id                 = $user_id;
    $order->membership_id           = payku_pmpro_get_level_for_user($user_id);
    $order->gateway                 = 'payku';
    $order->gateway_environment     = (defined('PAYKU_ENV') && PAYKU_ENV==='live') ? 'live' : 'sandbox';
    $order->payment_transaction_id  = $tx_id ?: ('payku-'.uniqid());
    $order->status                  = 'success';
    $order->subtotal                = 0;
    $order->tax                     = 0;
    $order->total                   = 0;
    $order->saveOrder();
    if ($subscription_id) {
      update_post_meta($order->id, '_payku_subscription_id', $subscription_id);
    }
  }
}
