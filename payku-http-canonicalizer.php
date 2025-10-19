<?php
/**
 * Plugin Name: Payku HTTP Canonicalizer
 * Description: Normaliza las URL hacia Payku antes de que salgan de WordPress (solo Payku). No toca front ni .htaccess.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) { exit; }

// === Config mínima ===
// Entorno: 'prod' (apirest.payku.cl) por defecto.
// Si alguna vez necesitas test, define('PAYKU_ENV', 'test') en wp-config.php.
if (!defined('PAYKU_ENV')) {
    define('PAYKU_ENV', 'prod');
}

// Activar logs si hace falta (solo cuando se reescribe algo)
// define('PAYKU_CANON_LOG', true);

/**
 * ¿Es un host de Payku?
 */
function payku_is_payku_host($host) {
    return (bool) preg_match('/(^|\\.)payku\\.cl$/i', $host);
}

/**
 * Host objetivo según entorno.
 */
function payku_target_host() {
    return (PAYKU_ENV === 'test') ? 'testing-apirest.payku.cl' : 'apirest.payku.cl';
}

/**
 * Canonicaliza la ruta Payku:
 * - fuerza https + host correcto
 * - elimina duplicados /api, /v1
 * - elimina segmentos tipo /nsNUM
 * - colapsa // a /
 */
function payku_canonicalize_url($url) {
    $p = wp_parse_url($url);
    if (!$p || empty($p['host'])) {
        return $url;
    }

    // Solo tratamos dominios de Payku
    if (!payku_is_payku_host($p['host'])) {
        return $url;
    }

    $scheme = 'https';
    $host   = payku_target_host();

    $path = isset($p['path']) ? $p['path'] : '/';
    $path = '/' . ltrim($path, '/');

    // Eliminar segmentos tipo /ns26, /ns123, etc. (por seguridad; en prod no existen)
    $path = preg_replace('#/(ns\\d+)(?=/|$)#i', '', $path);

    // Colapsar // => /
    $path = preg_replace('#/+#', '/', $path);

    // Asegurar /api al inicio (una sola vez)
    // - quitar repeticiones /api/api...
    $path = preg_replace('#^/(?:api/)+#', '/api/', $path);
    if (strpos($path, '/api/') !== 0) {
        $path = '/api' . ($path === '/' ? '' : $path);
    }

    // Quitar duplicados /v1/v1...
    $path = preg_replace('#/v1(?:/v1)+(/|$)#', '/v1$1', $path);

    // Reconstruir
    $new = $scheme . '://' . $host . $path;
    if (!empty($p['query']))    { $new .= '?' . $p['query']; }
    if (!empty($p['fragment'])) { $new .= '#' . $p['fragment']; }

    return $new;
}

/**
 * Hook principal: antes de que WP haga la request, si es Payku,
 * rehace la URL y devuelve esa respuesta (preempt).
 * Doc: pre_http_request permite "cortar camino" y retornar la respuesta final.
 * Esto evita tocar front/.htaccess y no afecta otras requests.
 */
add_filter('pre_http_request', function ($preempt, $args, $url) {
    // Evitar bucle si ya pasamos por aquí
    if (!empty($args['headers']['X-Payku-Canonicalized'])) {
        return false; // continuar normal
    }

    $parsed = wp_parse_url($url);
    if (!$parsed || empty($parsed['host']) || !payku_is_payku_host($parsed['host'])) {
        return false; // no es Payku => no intervenimos
    }

    $new_url = payku_canonicalize_url($url);
    if ($new_url === $url) {
        return false; // nada que cambiar
    }

    // Marcar para no entrar en bucle en la request reintento
    if (empty($args['headers']) || !is_array($args['headers'])) {
        $args['headers'] = [];
    }
    $args['headers']['X-Payku-Canonicalized'] = '1';

    // (Opcional) pequeños saneos de args
    if (empty($args['timeout']) || $args['timeout'] < 5) {
        $args['timeout'] = 10;
    }

    if (defined('PAYKU_CANON_LOG') && PAYKU_CANON_LOG) {
        error_log('[payku-canon] ' . $url . ' => ' . $new_url);
    }

    // Hacemos la request con la URL corregida y devolvemos la respuesta
    return wp_remote_request($new_url, $args);
}, 10, 3);
