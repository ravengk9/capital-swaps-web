<?php
/** Precios de USD/COP, BTC y ETH desde CoinGecko, con caché de 2 minutos.
 *  Solo 2 consultas por actualización, para no chocar con el límite del plan gratuito. */

function serie_corta($serie) {
    $paso = max(1, intdiv(count($serie), 42));
    $out = [];
    for ($i = 0; $i < count($serie); $i += $paso) $out[] = round($serie[$i], 2);
    return $out;
}

function precios() {
    return cache_get('precios', 120, function () {
        $get = $GLOBALS['CS_HTTP_PRUEBA'] ?? 'http_json';     // solo para pruebas locales
        $cg = 'https://api.coingecko.com/api/v3';
        $q = '&sparkline=true&price_change_percentage=24h';

        // 1) En dólares: BTC y ETH (precio, cambio 24 h y gráfica en USD)
        $usd = $get("$cg/coins/markets?vs_currency=usd&ids=bitcoin,ethereum$q");
        // 2) En pesos: USDT (= dólar), BTC y ETH (precio en COP; el USDT trae además cambio y gráfica)
        try {
            $cop = $get("$cg/coins/markets?vs_currency=cop&ids=tether,bitcoin,ethereum$q");
        } catch (Throwable $e) {
            error_log('[capitalswaps] CoinGecko COP: ' . $e->getMessage());
            $cop = [];
        }
        $porId = [];
        foreach ($cop as $c) $porId[$c['id']] = $c;

        $monedas = [];
        if (isset($porId['tether']['current_price'])) {
            $t = $porId['tether'];
            $monedas[] = ['id' => 'usd-cop', 'simbolo' => 'USD/COP', 'nombre' => 'Dólar', 'usd' => null,
                          'cop' => $t['current_price'], 'cambio_24h' => $t['price_change_percentage_24h'] ?? null,
                          'serie_7d' => serie_corta($t['sparkline_in_7d']['price'] ?? [])];
        }
        $orden = ['bitcoin', 'ethereum'];
        usort($usd, fn($a, $b) => array_search($a['id'], $orden) <=> array_search($b['id'], $orden));
        foreach ($usd as $c) {
            $monedas[] = ['id' => $c['id'], 'simbolo' => strtoupper($c['symbol'] ?? ''), 'nombre' => $c['name'] ?? '',
                          'usd' => $c['current_price'] ?? null, 'cop' => $porId[$c['id']]['current_price'] ?? null,
                          'cambio_24h' => $c['price_change_percentage_24h'] ?? null,
                          'serie_7d' => serie_corta($c['sparkline_in_7d']['price'] ?? [])];
        }
        return ['monedas' => $monedas, 'actualizado' => time()];
    }, true);   // si CoinGecko falla del todo, se devuelve el último precio conocido
}
