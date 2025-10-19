<?php
/**
 * Plugin Name: IECt – Payku Bridge Slim
 * Description: Helper HTTP minimal para Payku sandbox (testing-apirest).
 */

if (!defined('IECT_PAYKU_BASE')) {
	define('IECT_PAYKU_BASE', 'https://testing-apirest.payku.cl/api'); // único base en sandbox
}
if (!defined('IECT_PAYKU_TIMEOUT')) {
	define('IECT_PAYKU_TIMEOUT', 10);
}

function iect_http_get_json($path, $headers = []) {
	$path = '/' . ltrim($path, '/');                     // normaliza
	$path = preg_replace('#/v1/+v1/#', '/v1/', $path);   // evita v1/v1
	$url  = rtrim(IECT_PAYKU_BASE, '/') . $path;

	$args = [
		'timeout' => IECT_PAYKU_TIMEOUT,
		'headers' => array_merge([
			'Accept' => 'application/json',
		], $headers),
	];

	$r = wp_remote_get($url, $args);
	if (is_wp_error($r)) {
		// log opcional
		error_log(json_encode(['REWRITE'=>$url, 'code'=>'WP_ERROR']));
		return null;
	}
	$code = wp_remote_retrieve_response_code($r);
	error_log(json_encode(['REWRITE'=>$url, 'code'=>$code]));
	if ($code < 200 || $code >= 300) {
		return null;
	}
	$body = wp_remote_retrieve_body($r);
	$j = json_decode($body, true);
	return is_array($j) ? $j : null;
}
