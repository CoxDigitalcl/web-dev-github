<?php
/**
 * Plugin Name: IECt – Payku → PMPro (hardening)
 * Description: Endurece parseo de email/plan y evita colisión de usuarios.
 */

// --------------- Helpers de extracción --------------------------------------

function iect_arr_get($arr, $path) {
	if (!is_array($arr)) return null;
	$parts = explode('.', $path);
	$cur = $arr;
	foreach ($parts as $p) {
		if (!is_array($cur) || !array_key_exists($p, $cur)) return null;
		$cur = $cur[$p];
	}
	return $cur;
}

/**
 * Intenta extraer un email probando varias claves y estructuras típicas.
 * $id puede ser client_id o customer_id según envíe Payku en el webhook.
 */
function iect_payku_resolve_email($id, $headers = []) {
	if (empty($id)) return null;

	$candidatos = [];
	// Intenta en clients y customers, con y sin v1 (solo sandbox base)
	foreach (["/v1/clients/$id", "/clients/$id", "/v1/customers/$id", "/customers/$id"] as $path) {
		$j = iect_http_get_json($path, $headers);
		if (!$j) continue;

		// claves posibles
		foreach ([
			'email', 'correo',
			'data.email', 'data.correo',
			'client.email', 'customer.email',
		] as $k) {
			$val = iect_arr_get($j, $k);
			if (is_string($val)) $candidatos[] = trim($val);
		}
	}
	// Devuelve el primer candidato con formato de email válido
	foreach ($candidatos as $e) {
		if (is_email($e)) return $e;
	}
	return null;
}

/**
 * Intenta extraer el ID de plan de una suscripción.
 */
function iect_payku_get_plan_from_subscription($subscription_id, $headers = []) {
	if (empty($subscription_id)) return null;
	$j = iect_http_get_json("/v1/subscriptions/$subscription_id", $headers);
	if (!$j) $j = iect_http_get_json("/subscriptions/$subscription_id", $headers);
	if (!$j) return null;

	// Busca diferentes claves típicas
	foreach ([
		'plan', 'plan_id', 'planUuid', 'plan_uuid', 'data.plan', 'data.plan_id',
	] as $k) {
		$val = iect_arr_get($j, $k);
		if (!empty($val) && is_string($val)) return $val;
	}
	return null;
}

/**
 * Mapea el plan de Payku a un nivel de PMPro.
 * RELLENA con tus UUID/IDs reales apenas los tengas.
 */
function iect_map_plan_to_level($plan_id) {
	$map = [
		// 'uuid_plan_basico'  => 1,
		// 'uuid_plan_premium' => 2,
	];
	return $map[$plan_id] ?? 1; // fallback a nivel 1 (como hoy)
}

// --------------- Alta/actualización en PMPro --------------------------------

function iect_pmpro_ensure_user($maybe_email, $suggested_username = '') {
	$user_id = 0;

	// Si hay email y ya existe en WP, úsalo
	if ($maybe_email && email_exists($maybe_email)) {
		$user_id = email_exists($maybe_email);
	}

	// Si no existe, creémoslo con username único
	if (!$user_id) {
		$base = $suggested_username ?: ( $maybe_email ? sanitize_user( current(explode('@', $maybe_email)) ) : 'payku_user' );
		$base = $base ?: 'payku_user';
		$user  = $base;
		$i = 1;
		while (username_exists($user)) { // función oficial para chequear colisión
			$user = $base . '_' . $i++;
		}
		$rand_pass = wp_generate_password(16, true);
		$user_id = wp_create_user($user, $rand_pass, $maybe_email ?: '');
		if (is_wp_error($user_id)) {
			error_log('ERROR: no se pudo crear usuario: ' . $user_id->get_error_message());
			return 0;
		}
	}
	return (int)$user_id;
}

/**
 * Manejo del webhook (registra orden y asigna nivel).
 * Adapta los nombres de campos a los tuyos si ya tienes un handler previo:
 * topic, status, tx_id, order, sub, client, etc.
 */
function iect_payku_webhook_listener() {
	if (empty($_GET['iect_payku_webhook'])) return;

	// Cabeceras si Payku requiere auth para GETs de detalle:
	$headers = [];
	// $headers = ['X-Public' => '...', 'X-Secret' => '...']; // si aplica en tu entorno de sandbox

	$topic   = sanitize_text_field($_REQUEST['topic'] ?? '');
	$status  = sanitize_text_field($_REQUEST['status'] ?? '');
	$sub_id  = sanitize_text_field($_REQUEST['sub'] ?? '');
	$cli_id  = sanitize_text_field($_REQUEST['client'] ?? '');
	$order_n = sanitize_text_field($_REQUEST['order'] ?? '');

	error_log(sprintf('[Webhook IN] topic=%s status=%s order=%s sub=%s client=%s', $topic, $status, $order_n, $sub_id, $cli_id));

	if ($status !== 'success' && $topic !== 'subscription') {
		status_header(204);
		exit;
	}

	$email = iect_payku_resolve_email($cli_id, $headers);
	if (!$email) {
		error_log('Email resolver: NO (NOT_FOUND)');
	}
	$plan  = iect_payku_get_plan_from_subscription($sub_id, $headers);
	$level_id = iect_map_plan_to_level($plan);
	if (!$plan) {
		error_log('WARN: plan no mapeado/indetectable; usando nivel por defecto='.$level_id);
	}

	// Crea/ubica usuario
	$user_id = iect_pmpro_ensure_user($email, 'payku_'.$cli_id);

	// Crea orden PMPro (muy simplificado; ajusta a tu función actual)
	if ($user_id) {
		if (!class_exists('MemberOrder')) { require_once WP_PLUGIN_DIR . '/paid-memberships-pro/classes/class.memberorder.php'; }
		$order = new MemberOrder();
		$order->user_id = $user_id;
		$order->membership_id = $level_id;
		$order->InitialPayment = 0;
		$order->PaymentType = 'Payku';
		$order->payment_transaction_id = sanitize_text_field($_REQUEST['tx_id'] ?? '');
		$order->notes = 'Alta por webhook Payku';
		$order->saveOrder();
		error_log(sprintf('ORDER creada id=%d code=%s level=%d', $order->id, $order->code, $level_id));
	}

	status_header(200);
	exit;
}
add_action('init', 'iect_payku_webhook_listener');
