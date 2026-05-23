<?php
/**
 * ticketmaster_proxy.php
 * Proxy server-side per Ticketmaster Discovery API v2
 * Evita di esporre la API key nel frontend JS.
 *
 * GET params:
 *   ?q=nome_evento   → ricerca eventi in Italia
 *
 * Risposta: JSON array di eventi normalizzati
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ──────────────────────────────────────────────────────────────
// CONFIGURAZIONE — ottieni la chiave gratuita su
// https://developer.ticketmaster.com (piano Developer = gratis)
// ──────────────────────────────────────────────────────────────
define('TM_API_KEY', 'TUA_CHIAVE_TICKETMASTER');  // ← da configurare

$query = trim($_GET['q'] ?? '');
if (strlen($query) < 2) {
    echo json_encode([]);
    exit;
}

// Ticketmaster Discovery API — eventi in Italia, musica + sport
$url = 'https://app.ticketmaster.com/discovery/v2/events.json?' . http_build_query([
    'apikey'          => TM_API_KEY,
    'keyword'         => $query,
    'countryCode'     => 'IT',
    'classificationName' => 'Music,Sports',
    'size'            => 10,
    'sort'            => 'date,asc',
    'startDateTime'   => gmdate('Y-m-d\TH:i:s\Z'), // solo eventi futuri
]);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_USERAGENT      => 'OnePassage/1.0',
    CURLOPT_SSL_VERIFYPEER => true,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    echo json_encode(['error' => 'API non raggiungibile']);
    exit;
}

$data   = json_decode($response, true);
$events = $data['_embedded']['events'] ?? [];
$result = [];

foreach ($events as $ev) {
    $venue   = $ev['_embedded']['venues'][0] ?? [];
    $locName = $venue['name'] ?? '';
    $city    = $venue['city']['name']          ?? '';
    $country = $venue['country']['name']       ?? '';
    $lat     = (float)($venue['location']['latitude']  ?? 0);
    $lon     = (float)($venue['location']['longitude'] ?? 0);

    // Coordinate mancanti → geocodifichiamo il venue via il nostro proxy
    if ($lat == 0 && $lon == 0 && $locName) {
        $geoUrl  = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST']
                 . dirname($_SERVER['SCRIPT_NAME']) . '/geocode_proxy.php?q=' . urlencode("$locName $city Italia");
        $geoResp = @file_get_contents($geoUrl);
        if ($geoResp) {
            $geo = json_decode($geoResp, true);
            $feat = $geo['features'][0] ?? null;
            if ($feat) {
                $lat = (float)($feat['geometry']['coordinates'][1] ?? 0);
                $lon = (float)($feat['geometry']['coordinates'][0] ?? 0);
            }
        }
    }

    // Data evento
    $dataRaw = $ev['dates']['start']['localDate'] ?? '';
    $oraRaw  = $ev['dates']['start']['localTime'] ?? '20:00:00';
    $data_evento = $dataRaw ? $dataRaw . ' ' . $oraRaw : null;

    // Immagine (alta risoluzione preferita)
    $imgUrl = '';
    foreach (($ev['images'] ?? []) as $img) {
        if ($img['ratio'] === '16_9' && $img['width'] >= 1024) {
            $imgUrl = $img['url'];
            break;
        }
    }

    $result[] = [
        'id'          => $ev['id'],                      // ticketmaster_id
        'nome'        => $ev['name'],
        'data'        => $data_evento,
        'luogo'       => trim("$locName, $city"),
        'lat'         => $lat,
        'lon'         => $lon,
        'url'         => $ev['url'] ?? '',
        'immagine'    => $imgUrl,
    ];
}

echo json_encode($result);
