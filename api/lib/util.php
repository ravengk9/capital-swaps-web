<?php
/** Utilidades: números en formato colombiano, nombres, caché en archivos. */

function parse_number($v) {
    if ($v === null || $v === '') return null;
    if (is_int($v) || is_float($v)) return (float)$v;
    $s = preg_replace('/[^\d,.\-]/', '', (string)$v);
    if ($s === '' || $s === '-') return null;
    if (strpos($s, '.') !== false && strpos($s, ',') !== false) {      // 3.000.000,50
        $s = str_replace(',', '.', str_replace('.', '', $s));
    } elseif (strpos($s, ',') !== false) {                               // 2,5
        $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) {              // 3.000.000
        $s = str_replace('.', '', $s);
    }
    return is_numeric($s) ? (float)$s : null;
}

/** Tasa como fracción: '2,5%' -> 0.025, 0.025 -> 0.025, 2.5 -> 0.025 */
function parse_rate($v) {
    $n = parse_number($v);
    if ($n === null) return null;
    if ((is_string($v) && strpos($v, '%') !== false) || $n > 1) $n /= 100;
    return $n;
}

function norm_id($v) {
    $n = parse_number($v);
    return $n === null ? '' : number_format($n, 0, '', '');
}

function normalizar($nombre) {
    $s = trim((string)$nombre);
    if (class_exists('Normalizer')) {
        $s = Normalizer::normalize($s, Normalizer::FORM_D);
        $s = preg_replace('/\p{Mn}/u', '', $s);
    } else {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    }
    return preg_replace('/\s+/', ' ', mb_strtolower($s, 'UTF-8'));
}

/** Devuelve el valor guardado si tiene menos de $ttl segundos; si no, llama a $loader.
 *  Si $loader falla y $stale es true, devuelve el último valor conocido. */
function cache_get($key, $ttl, $loader, $stale = false) {
    $f = CS_CACHE_DIR . '/' . preg_replace('/[^a-z0-9_]/', '', $key) . '.json';
    $old = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    if ($old && isset($old['t']) && time() - $old['t'] < $ttl) return $old['v'];
    try {
        $v = $loader();
    } catch (Throwable $e) {
        if ($stale && $old) return $old['v'];
        throw $e;
    }
    $tmp = $f . '.' . getmypid();
    file_put_contents($tmp, json_encode(['t' => time(), 'v' => $v]));
    @chmod($tmp, 0600);
    rename($tmp, $f);
    return $v;
}

function http_json($url, $post = null, $headers = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: CapitalSwaps/1.0'], $headers),
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code >= 400) throw new RuntimeException("HTTP $code $url $err");
    $data = json_decode($body, true);
    if ($data === null) throw new RuntimeException("Respuesta no JSON de $url");
    return $data;
}
