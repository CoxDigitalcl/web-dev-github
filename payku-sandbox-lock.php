<?php
/**
 * MU-Plugin: Payku Sandbox Lock & Logger
 * Fuerza todas las llamadas a *.payku.cl hacia testing-apirest.payku.cl (SANDBOX),
 * normaliza el path /api/v1, inyecta headers y loguea request/response.
 */

if (!defined('ABSPATH')) { exit; }

// 1) Pega tu token SANDBOX aquí (formato completo "Bearer xxxxx")
if (!defined('PAYKU_SANDBOX_BEARER')) {
    define('PAYKU_SANDBOX_BEARER', 'Bearer TU_TOKEN_SANDBOX_AQUI');
}

// 2) Hook para interceptar antes de que WordPress haga la petición
add_filter('pre_http_request', function ($preempt, $args, $url) {
    // Evitar loop cuando nosotros mismos disparamos la request corregida
    if (!empty($args['_payku_sandbox_bypass'])) {
        return false; // no preempt
    }

    $p = wp_parse_url($url);
    if (!$p || empty($p['host'])) {
        return $preempt;
    }

    // Solo actuar si el destino es Payku
    if (strpos($p['host'], 'payku.cl') === false) {
        return $preempt;
    }

    // Siempre sandbox
    $sandbox_host = 'testing-apirest.payku.cl';

    // Normalizar path
    $path = isset($p['path']) ? $p['path'] : '/';
    // Quitar // y /v1/v1
    $path = preg_replace('#/+#', '/', $path);
    $path = str_replace('/v1/v1/', '/v1/', $path);

    // Asegurar prefijos /api y /api/v1
    if (strpos($path, '/api/') !== 0) {
        $path = '/api' . (strpos($path, '/') === 0 ? '' : '/') . ltrim($path, '/');
    }
    $path = preg_replace('#^/api/(?!v1/)#', '/api/v1/', $path);

    // Reconstruir URL
    $new_url = 'https://' . $sandbox_host . $path;
    if (!empty($p['query'])) {
        $new_url .= '?' . $p['query'];
    }

    // Inyectar/normalizar headers
    $args = is_array($args) ? $args : [];
    $headers = isset($args['headers']) ? (array)$args['headers'] : [];
    $headers['Accept'] = 'application/json';
    $headers['Content-Type'] = 'application/json';
    $headers['Authorization'] = PAYKU_SANDBOX_BEARER;
    $args['headers'] = $headers;

    // Marcar bypass para evitar re-entrada del filtro
    $args['_payku_sandbox_bypass'] = true;

    // Log del request
    payku_sandbox_log('[SANDBOX-LOCK] ' . $url . '  ->  ' . $new_url);
    if (!empty($args['method'])) {
        payku_sandbox_log('METHOD=' . $args['method']);
    }
    payku_sandbox_log('HEADERS=' . wp_json_encode($headers));
    if (!empty($args['body'])) {
        payku_sandbox_log('BODY=' . (is_string($args['body']) ? $args['body'] : wp_json_encode($args['body'])));
    }

    // Disparar la request corregida y devolver su respuesta (preempt)
    $resp = wp_remote_request($new_url, $args);

    // Log de la respuesta
    if (is_wp_error($resp)) {
        payku_sandbox_log('[SANDBOX-LOCK] WP_Error: ' . $resp->get_error_message());
    } else {
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        payku_sandbox_log('[SANDBOX-LOCK] RESPONSE code=' . $code . ' body=' . mb_substr($body, 0, 1200));
    }

    return $resp; // ¡preempt!
}, 9999, 3);

// Helper de log (respeta WP_DEBUG/LOG)
function payku_sandbox_log($msg) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[' . gmdate('c') . '] ' . $msg);
    }
}
