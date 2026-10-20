<?php
/** Precios de USD/COP, BTC y ETH desde CoinGecko, con caché de 2 minutos. */

function serie_corta($serie) {
    $paso = max(1, intdiv(count($serie), 42));
    $out = [];
    for ($i = 0; $i < count($serie); $i += $paso) $out[] = round($serie[$i], 2);
    return $out;
}

function precios() {
    return cache_get('precios', 120, function () {
        $cg = 'https://api.coingecko.com/api/v3';
        $ids = ['bitcoin', 'ethereum'];
        $mercado = http_json("$cg/coins/markets?vs_currency=usd&ids=" . implode(',', $ids) . '&sparkline=true&price_change_percentage=24h');
        $cop = http_json("$cg/simple/price?ids=" . implode(',', $ids) . '&vs_currencies=cop');
        usort($mercado, fn($a, $b) => array_search($a['id'], $ids) <=> array_search($b['id'], $ids));
        $monedas = [];
        foreach ($mercado as $c) {
            $monedas[] = ['id' => $c['id'], 'simbolo' => strtoupper($c['symbol'] ?? ''), 'nombre' => $c['name'] ?? '',
                          'usd' => $c['current_price'] ?? null, 'cop' => $cop[$c['id']]['cop'] ?? null,
                          'cambio_24h' => $c['price_change_percentage_24h'] ?? null,
                          'serie_7d' => serie_corta($c['sparkline_in_7d']['price'] ?? [])];
        }
        try {   // Dólar en pesos: precio del USDT en COP. Si falla, se omite.
            $t = http_json("$cg/coins/markets?vs_currency=cop&ids=tether&sparkline=true&price_change_percentage=24h")[0];
            array_unshift($monedas, ['id' => 'usd-cop', 'simbolo' => 'USD/COP', 'nombre' => 'Dólar', 'usd' => null,
                'cop' => $t['current_price'] ?? null, 'cambio_24h' => $t['price_change_percentage_24h'] ?? null,
                'serie_7d' => serie_corta($t['sparkline_in_7d']['price'] ?? [])]);
        } catch (Throwable $e) {}
        return ['monedas' => $monedas, 'actualizado' => time()];
    }, true);   // si CoinGecko falla, se devuelve el último precio conocido
}
