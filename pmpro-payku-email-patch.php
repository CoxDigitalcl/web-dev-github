<?php
/**
 * Plugin Name: IECT ¨C PMPro Payku Email Patch
 * Description: Inyecta el email del checkout en el payload real enviado a Payku.
 */

add_filter('http_request_args', function($args, $url){
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || stripos($host, 'payku') === false) return $args;

    // 1) Tomar correo del checkout (o del usuario logueado como fallback)
    $email = '';
    if (!empty($_POST['bemail'])) {
        $email = sanitize_email($_POST['bemail']);
    } elseif (is_user_logged_in()) {
        $email = wp_get_current_user()->user_email;
    }
    if (!$email) return $args;

    // 2) Leer cuerpo de la petici¨®n (JSON o array)
    $is_json = false; $body = [];
    if (isset($args['body'])) {
        if (is_string($args['body'])) {
            $decoded = json_decode($args['body'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $is_json = true; $body = $decoded;
            }
        } elseif (is_array($args['body'])) {
            $body = $args['body'];
        }
    }

    // 3) Inyectar email en posiciones t¨ªpicas
    $inject = function (&$arr, $email) {
        if (empty($arr['email']))  $arr['email']  = $email;
        if (empty($arr['emails'])) $arr['emails'] = $email; // algunas APIs usan "emails"
    };
    $inject($body, $email);
    foreach (['customer','payer','buyer','client','subscriber'] as $node) {
        if (isset($body[$node]) && is_array($body[$node])) {
            $inject($body[$node], $email);
        }
    }

    // 4) Re-armar el cuerpo y devolver
    if ($is_json) {
        $args['headers']['Content-Type'] = 'application/json; charset=utf-8';
        $args['body'] = wp_json_encode($body);
    } else {
        $args['body'] = $body;
    }
    return $args;
}, 10, 2);
