<?php
/**
 * Plugin Name: IECT – Payku Webhook → PMPro (Consolidado 2025-10-15)
 * Description: Recibe webhooks de Payku (topic=payment/subscription) y crea/actualiza pedidos y membresías de PMPro.
 * Author: ChatGPT
 * Version: 1.6.0
 */

if (!defined('ABSPATH')) exit;

// === CONFIGURACIÓN ===================================================

// Si YA los definiste en otro archivo, estos no se sobreescriben.
if (!defined('IECT_PAYKU_PUBLIC')) define('IECT_PAYKU_PUBLIC', 'tkpu553d38c495f1c706f6e23da1addb');
if (!defined('IECT_PAYKU_SECRET')) define('IECT_PAYKU_SECRET', 'tkpi645e704ae96f081d3c38d46bc6eb');

// Mapa Plan (Payku) → Nivel (PMPro). Agrega líneas aquí a medida que sumes planes.
if (!function_exists('iect_payku_level_map')) {
  function iect_payku_level_map() : array {
    return [
      // 'plXXXXXXXXXXXX' => 1, // ← ejemplo
      'plf1e685f0478b0770faf2' => 1, // Plan 25.000 → Nivel 1
    ];
  }
}

// Nivel por defecto si no se puede mapear plan (no cambia el comportamiento actual).
if (!defined('IECT_FALLBACK_LEVEL')) define('IECT_FALLBACK_LEVEL', 1);

// Archivo de log
if (!defined('IECT_WEBHOOK_LOG')) define('IECT_WEBHOOK_LOG', WP_CONTENT_DIR . '/payku-webhook-to-pmpro.log');

// =====================================================================

// Log helper
if (!function_exists('iect_log')) {
  function iect_log($msg) {
    $line = (is_array($msg) || is_object($msg)) ? json_encode($msg, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) : $msg;
    @file_put_contents(IECT_WEBHOOK_LOG, '['.gmdate('c')."] ".$line."\n", FILE_APPEND);
  }
}

/**
 * GET JSON desde Payku probando variantes de host/ruta/headers.
 * - REST oficial: testing-apirest.payku.cl / apirest.payku.cl
 * - “Des/app” como último recurso (no REST), por compatibilidad.
 */
if (!function_exists('iect_payku_get_json')) {
  function iect_payku_get_json($resource, $id) {
    $bases = [
      'https://testing-apirest.payku.cl/api',  // sandbox REST
      'https://testing-apirest.payku.cl/api/v1',
      'https://apirest.payku.cl/api',          // producción REST
      'https://apirest.payku.cl/api/v1',
      'https://des.payku.cl/api',              // legado (no REST)
      'https://app.payku.cl/api',
    ];
    $paths = [
      "/{$resource}/{$id}",
      ($resource === 'customers' ? "/clients/{$id}" : "/customers/{$id}"),
      "/v1/{$resource}/{$id}",
      "/maclient/{$id}", // por si el recurso se expone como “maclient”
    ];
    $header_sets = [
      [ 'Authorization' => 'Bearer '.IECT_PAYKU_SECRET, 'Accept' => 'application/json' ],
      [ 'X-Public' => IECT_PAYKU_PUBLIC, 'X-Secret' => IECT_PAYKU_SECRET, 'Accept' => 'application/json' ],
    ];

    foreach ($bases as $base) {
      foreach ($paths as $p) {
        $url = rtrim($base, '/').$p;
        foreach ($header_sets as $h) {
          $r = wp_remote_get($url, ['timeout' => 8, 'headers' => $h]);
          $code = is_wp_error($r) ? 'WP_ERROR' : wp_remote_retrieve_response_code($r);
          iect_log([ 'REWRITE' => $url, 'code' => $code ]);
          if (!is_wp_error($r) && $code >= 200 && $code < 300) {
            $j = json_decode(wp_remote_retrieve_body($r), true);
            if (is_array($j)) return $j;
          }
        }
      }
    }
    return null;
  }
}

if (!function_exists('iect_resolve_plan_id')) {
  function iect_resolve_plan_id($subscription_id) {
    if (!$subscription_id) return null;
    foreach (['subscriptions','maclient'] as $res) {
      $j = iect_payku_get_json($res, $subscription_id);
      if (is_array($j)) {
        if (isset($j['plan']['id'])) return $j['plan']['id'];
        if (isset($j['plan_id'])) return $j['plan_id'];
        if (isset($j['plan'])) return $j['plan'];
      }
    }
    return null;
  }
}

if (!function_exists('iect_resolve_email')) {
  function iect_resolve_email($client_id) {
    if (!$client_id) return null;
    foreach (['customers','clients','maclient'] as $res) {
      $j = iect_payku_get_json($res, $client_id);
      if (is_array($j)) {
        if (!empty($j['email'])) return $j['email'];
        if (!empty($j['customer']['email'])) return $j['customer']['email'];
        if (!empty($j['data']['email'])) return $j['data']['email'];
      }
    }
    return null;
  }
}

// ====== REST route del webhook ======================================================
add_action('rest_api_init', function(){
  register_rest_route('payku/v1', '/webhook', [
    'methods'  => 'POST',
    'callback' => 'iect_payku_webhook_handler',
    'permission_callback' => '__return_true',
  ]);
});

function iect_payku_webhook_handler(WP_REST_Request $req) {
  $params = $req->get_json_params();
  $topic  = $req->get_param('topic');
  $status = $params['status'] ?? '';

  iect_log("Webhook IN topic={$topic} status={$status} tx_id=".($params['transaction_id'] ?? '')." order=".($params['order'] ?? '')." sub=".($params['subscriptions']['id'] ?? '')." client=".($params['subscriptions']['client'] ?? ''));

  if ($topic === 'payment' && $status === 'success') {
    return iect_handle_payment_success($params);
  } elseif ($topic === 'subscription') {
    return new WP_REST_Response(['ok'=>true], 200);
  }
  return new WP_REST_Response(['ignored'=>true], 200);
}

function iect_handle_payment_success(array $p) {
  $tx_id   = $p['transaction_id'] ?? '';
  $sub_id  = $p['subscriptions']['id'] ?? '';
  $client  = $p['subscriptions']['client'] ?? '';

  // 1) Email (si viene vacío, intentamos por API – si falla, seguimos como hoy)
  $email = $p['email'] ?? '';
  if (!$email) {
    $email = iect_resolve_email($client);
    iect_log('Email resolver: '.($email ? 'OK' : 'NO (NOT_FOUND)'));
  }

  // 2) Nivel según Plan (si no se obtiene, usamos fallback – igual que hoy)
  $plan_id  = iect_resolve_plan_id($sub_id);
  $map      = iect_payku_level_map();
  $level_id = ($plan_id && isset($map[$plan_id])) ? $map[$plan_id] : IECT_FALLBACK_LEVEL;
  if (!$plan_id) iect_log("WARN: plan no mapeado/indetectable; usando nivel por defecto={$level_id}");

  // 3) Usuario: buscar/crear
  $user = $email ? get_user_by('email', $email) : false;
  if (!$user) {
    $uname = 'payku_'.($client ?: wp_generate_password(6,false));
    $pwd   = wp_generate_password(18);
    $uid   = wp_create_user($uname, $pwd, $email ?: ($uname.'@noemail.local'));
    if (is_wp_error($uid)) {
      iect_log('ERROR: no se pudo crear usuario: '.$uid->get_error_message());
      return new WP_REST_Response(['error'=>'user_create_failed'], 200);
    }
    $user = get_user_by('id', $uid);
  }
  $user_id = $user->ID;

  // Metadatos Payku
  if ($client) update_user_meta($user_id, '_payku_client_id', $client);
  if ($sub_id) update_user_meta($user_id, '_payku_subscription_id', $sub_id);

  // 4) Dar membresía
  if (function_exists('pmpro_changeMembershipLevel')) {
    pmpro_changeMembershipLevel($level_id, $user_id);
  }

  // 5) Crear orden PMPro (igual que venías haciendo)
  if (!class_exists('MemberOrder') && function_exists('pmpro_getClassForOrder')) require_once pmpro_getClassForOrder();
  if (class_exists('MemberOrder')) {
    $morder = new MemberOrder();
    $morder->user_id = $user_id;
    $morder->membership_id = $level_id;
    $morder->payment_transaction_id = $tx_id;
    $morder->subscription_transaction_id = $sub_id;
    $morder->gateway = 'payku';
    $morder->gateway_environment = 'sandbox';
    $morder->status = 'success';
    $morder->total = 0;
    $morder->saveOrder();
    iect_log("ORDER creada id={$morder->id} code={$morder->code} level={$level_id}");
  }

  return new WP_REST_Response(['ok'=>true], 200);
}
