<?php
/** Validación del initData que Telegram envía a la Mini App. */

function validar_init_data($init_data, $bot_token, $max_age = 86400) {
    $data = [];
    foreach (explode('&', $init_data) as $par) {
        if ($par === '') continue;
        $kv = explode('=', $par, 2);
        $data[urldecode($kv[0])] = isset($kv[1]) ? urldecode($kv[1]) : '';
    }
    if (empty($data['hash'])) throw new RuntimeException('Falta el hash');
    $hash = $data['hash'];
    unset($data['hash']);
    ksort($data, SORT_STRING);
    $lineas = [];
    foreach ($data as $k => $v) $lineas[] = "$k=$v";
    $secreto = hash_hmac('sha256', $bot_token, 'WebAppData', true);
    $esperado = hash_hmac('sha256', implode("\n", $lineas), $secreto);
    if (!hash_equals($esperado, $hash)) throw new RuntimeException('Firma inválida');
    $auth = (int)($data['auth_date'] ?? 0);
    if ($max_age && time() - $auth > $max_age) throw new RuntimeException('Sesión expirada, vuelve a abrir la app');
    $user = json_decode($data['user'] ?? '{}', true);
    if (!is_array($user) || !isset($user['id'])) throw new RuntimeException('initData sin usuario');
    return $user;
}

function usuario_telegram($bot_token) {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) $h = $v;
    }
    if ($h === '') $h = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
    if (strpos($h, 'tma ') === 0) $h = substr($h, 4);
    if ($h === '') responder(401, ['detail' => 'Falta autorización']);
    try {
        return validar_init_data($h, $bot_token);
    } catch (RuntimeException $e) {
        responder(401, ['detail' => $e->getMessage()]);
    }
}
