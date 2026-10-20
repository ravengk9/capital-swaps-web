<?php
/** Capital Swaps — API de la Mini App (app.capitalswaps.com/api). */
declare(strict_types=1);

// Carpeta privada FUERA de public_html: /home/<usuario>/capitalswaps-private
$home = explode('/domains/', __DIR__)[0];
define('CS_PRIVADO', getenv('CS_PRIVADO') ?: $home . '/capitalswaps-private');
define('CS_CACHE_DIR', CS_PRIVADO . '/cache');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $codigo, $datos): void {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

$cfg = json_decode((string)@file_get_contents(CS_PRIVADO . '/config.json'), true);
if (!is_array($cfg) || empty($cfg['bot_token'])) responder(500, ['detail' => 'Servidor sin configurar']);
$cred = $cfg['credenciales'] ?? json_decode((string)base64_decode($cfg['google_credentials_b64'] ?? ''), true);
$cfg['credenciales'] = $cred;
$cfg['sheet_id'] = $cfg['sheet_id'] ?? '1wJsNIrCqhzzHkvxmJWuV8t3TeD77-rLYGXKIBo3Pj5g';
define('CS_CONFIG', $cfg);
if (!is_dir(CS_CACHE_DIR)) @mkdir(CS_CACHE_DIR, 0700, true);

require __DIR__ . '/lib/util.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/sheets.php';
require __DIR__ . '/lib/precios.php';

// Funciona como /api/me (con .htaccess) o como /api/index.php/me (sin él)
$ruta = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$ruta = preg_replace('#^.*?/api(/index\.php)?#', '', $ruta);
$ruta = '/' . trim($ruta, '/');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') responder(405, ['detail' => 'Método no permitido']);

if ($ruta === '/health') responder(200, ['ok' => true]);

$user = usuario_telegram($cfg['bot_token']);

function proyeccion(float $capital, float $ea): array {
    $rm = pow(1 + $ea, 1 / 12) - 1;
    $out = ['tasa_ea' => $ea, 'tasa_mensual' => $rm, 'tasa_quincenal' => pow(1 + $ea, 1 / 24) - 1, 'proyeccion' => []];
    foreach ([3, 6, 12] as $n) {
        $out['proyeccion'][] = ['meses' => $n, 'capital' => round($capital * pow(1 + $rm, $n)),
                                'ganancia' => round($capital * (pow(1 + $rm, $n) - 1))];
    }
    return $out;
}

try {
    switch ($ruta) {
        case '/me':
            $base = ['telegram_id' => $user['id'], 'first_name' => $user['first_name'] ?? ''];
            $socio = buscar_socio($user['id']);
            if (!$socio) responder(200, $base + ['es_socio' => false]);
            $resp = $base + ['es_socio' => true] + $socio;
            $nivel = $socio['nivel_info'];
            $resp['nivel'] = $nivel ? mb_strtoupper($nivel['nombre'], 'UTF-8') : null;
            if ($nivel && $nivel['ea'] !== null) $resp += proyeccion($socio['capital'], $nivel['ea']);
            else $resp['tasa_quincenal'] = null;
            responder(200, $resp);
        case '/tasas':
            responder(200, leer_tasas());
        case '/historial':
            $socio = buscar_socio($user['id']);
            responder(200, ['operaciones' => historial($user['id'], $socio['nombre'] ?? null)]);
        case '/precios':
            responder(200, precios());
        default:
            responder(404, ['detail' => 'No encontrado']);
    }
} catch (Throwable $e) {
    error_log('[capitalswaps] ' . $ruta . ': ' . $e->getMessage());
    responder(503, ['detail' => 'No se pudo leer la información, intenta de nuevo']);
}
