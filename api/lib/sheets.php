<?php
/** Lectura de Google Sheets con la cuenta de servicio (solo lectura). */

function b64url($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function google_token() {
    return cache_get('google_token', 3000, function () {
        $c = CS_CONFIG['credenciales'];
        $ahora = time();
        $cab = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $carga = b64url(json_encode([
            'iss' => $c['client_email'],
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $ahora, 'exp' => $ahora + 3600,
        ]));
        if (!openssl_sign("$cab.$carga", $firma, $c['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('No se pudo firmar el token de Google');
        }
        $r = http_json('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => "$cab.$carga." . b64url($firma),
        ]);
        return $r['access_token'];
    });
}

/** Lee varios rangos de una vez. Devuelve una lista de tablas (filas x columnas). */
function sheets_leer(array $rangos) {
    if (isset(CS_CONFIG['_prueba_rangos'])) {                       // solo para pruebas locales
        return array_map(fn($r) => CS_CONFIG['_prueba_rangos'][$r] ?? [], $rangos);
    }
    $q = implode('&', array_map(fn($r) => 'ranges=' . rawurlencode($r), $rangos));
    $url = 'https://sheets.googleapis.com/v4/spreadsheets/' . CS_CONFIG['sheet_id']
         . "/values:batchGet?$q&valueRenderOption=UNFORMATTED_VALUE&dateTimeRenderOption=FORMATTED_STRING";
    $r = http_json($url, null, ['Authorization: Bearer ' . google_token()]);
    return array_map(fn($vr) => $vr['values'] ?? [], $r['valueRanges'] ?? []);
}

function rellenar(array $filas, $ancho, $alto = 0) {
    $filas = array_map(fn($f) => array_pad($f, $ancho, ''), $filas);
    while (count($filas) < $alto) $filas[] = array_fill(0, $ancho, '');
    return $filas;
}

/* ---------- socios y niveles ----------
 * Hoja "resumen mes":
 *   fila 23, E/F/G = capital mínimo de Oro / Plata / Bronce
 *   fila 24, E/F/G = rentabilidad efectiva anual (E.A.)
 *   fila 25, E/F/G = nombre de cada nivel
 *   filas 27-35    = socios (A nombre, B capital, H ID Telegram, I email)
 */
const NIVELES_COLS = [4 => 'ORO', 5 => 'PLATA', 6 => 'BRONCE'];

function bloque_socios() {
    return cache_get('bloque', 60, function () {
        return rellenar(sheets_leer(["'resumen mes'!A23:I35"])[0], 9, 13);
    });
}

function niveles($filas) {
    $out = [];
    foreach (NIVELES_COLS as $col => $def) {
        $min = parse_number($filas[0][$col]);
        if ($min === null) continue;
        $nombre = trim((string)$filas[2][$col]) ?: $def;
        $out[] = ['id' => strtolower($def), 'nombre' => mb_convert_case($nombre, MB_CASE_TITLE, 'UTF-8'),
                  'minimo' => $min, 'ea' => parse_rate($filas[1][$col])];
    }
    usort($out, fn($a, $b) => $b['minimo'] <=> $a['minimo']);
    return $out;
}

function buscar_socio($tg_id) {
    $filas = bloque_socios();
    $niv = niveles($filas);
    foreach (array_slice($filas, 4) as $f) {
        if (norm_id($f[7]) !== (string)$tg_id) continue;
        $capital = parse_number($f[1]) ?? 0.0;
        $idx = null;
        foreach ($niv as $i => $n) if ($capital >= $n['minimo']) { $idx = $i; break; }
        $nivel = $idx !== null ? $niv[$idx] : null;
        $sig = $idx === null ? (end($niv) ?: null) : ($idx > 0 ? $niv[$idx - 1] : null);
        $info = null;
        if ($sig) {
            $base = $nivel ? $nivel['minimo'] : 0.0;
            $tramo = $sig['minimo'] - $base;
            $info = ['id' => $sig['id'], 'nombre' => $sig['nombre'], 'minimo' => $sig['minimo'],
                     'falta' => max(0.0, $sig['minimo'] - $capital),
                     'avance' => $tramo > 0 ? max(0.0, min(1.0, ($capital - $base) / $tramo)) : 1.0];
        }
        return ['nombre' => trim((string)$f[0]), 'capital' => $capital, 'email' => trim((string)$f[8]),
                'nivel_info' => $nivel, 'siguiente' => $info, 'niveles' => $niv];
    }
    return null;
}

/* ---------- tasas de compra / venta (hoja config, B1 y B2) ---------- */
function leer_tasas() {
    return cache_get('tasas', 60, function () {
        try {
            $v = rellenar(sheets_leer(["'config'!B1:B2"])[0], 1, 2);
        } catch (RuntimeException $e) {
            return ['compra' => null, 'venta' => null];
        }
        return ['compra' => parse_number($v[0][0]), 'venta' => parse_number($v[1][0])];
    });
}

/* ---------- historial (hoja "operaciones bot telegram") ----------
 *   CS compra : C=ID Telegram, E=COP, F=tasa, G=USDT
 *   VT venta  : C=ID Telegram, E="100.00 USDT", F=tasa, G=COP, H=red
 *   SA aporte : C=nombre, F=monto COP, H=capital después, N=ID Telegram
 *   SR retiro : igual que SA, más L=tasa y M=USDT
 *   I=estado, K="Prueba" para filas de prueba (se ocultan)
 */
function historial($tg_id, $nombre_socio = null, $limite = 50) {
    $filas = cache_get('operaciones', 60, function () {
        return rellenar(sheets_leer(["'operaciones bot telegram'!A2:N"])[0], 14);
    });
    $mi_id = (string)$tg_id;
    $mi_nombre = $nombre_socio ? normalizar($nombre_socio) : null;
    $items = [];
    foreach (array_reverse($filas) as $r) {
        $trans = strtoupper(trim((string)$r[0]));
        if ($trans === '' || stripos((string)$r[10], 'prueba') !== false) continue;
        $tipo = substr($trans, 0, 2);
        if ($tipo === 'CS' || $tipo === 'VT') {
            if (norm_id($r[2]) !== $mi_id) continue;
        } elseif ($tipo === 'SA' || $tipo === 'SR') {
            $mio = norm_id($r[13]) === $mi_id || ($mi_nombre && normalizar($r[2]) === $mi_nombre);
            if (!$mio) continue;
        } else {
            continue;
        }
        $o = ['id' => $trans, 'fecha' => trim((string)$r[1]), 'estado' => trim((string)$r[8]) ?: 'Pendiente'];
        if ($tipo === 'CS') $o += ['tipo' => 'compra', 'cop' => parse_number($r[4]), 'tasa' => parse_number($r[5]), 'usdt' => parse_number($r[6])];
        elseif ($tipo === 'VT') $o += ['tipo' => 'venta', 'usdt' => parse_number($r[4]), 'tasa' => parse_number($r[5]), 'cop' => parse_number($r[6]), 'red' => trim((string)$r[7])];
        elseif ($tipo === 'SA') $o += ['tipo' => 'aporte', 'cop' => parse_number($r[5]), 'capital_despues' => parse_number($r[7])];
        else $o += ['tipo' => 'retiro', 'cop' => parse_number($r[5]), 'capital_despues' => parse_number($r[7]), 'tasa' => parse_number($r[11]), 'usdt' => parse_number($r[12])];
        $items[] = $o;
        if (count($items) >= $limite) break;
    }
    return $items;
}
