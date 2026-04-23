<?php
/**
 * geocode_proxy.php — Proxy server-side per Nominatim (OpenStreetMap)
 *
 * Il browser non può chiamare direttamente le API di geocoding per via di CORS.
 * Questo proxy PHP fa la chiamata lato server con il corretto User-Agent
 * (obbligatorio per le policy di Nominatim) e restituisce GeoJSON al client.
 *
 * Endpoint:
 *   ?q=Milano                  → ricerca testuale (autocomplete)
 *   ?lat=45.46&lon=9.18        → reverse geocoding (GPS → indirizzo)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$USER_AGENT = 'OnePassage/1.0 (localhost; contact@onepassage.local)';

$q   = isset($_GET['q'])   ? trim($_GET['q'])   : '';
$lat = isset($_GET['lat']) ? trim($_GET['lat']) : '';
$lon = isset($_GET['lon']) ? trim($_GET['lon']) : '';

if ($q !== '') {
    if (mb_strlen($q) < 2) { echo json_encode(['features' => []]); exit; }
    $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
        'q' => $q, 'format' => 'geojson',
        'addressdetails' => 1, 'limit' => 7, 'accept-language' => 'it',
    ]);
} elseif ($lat !== '' && $lon !== '') {
    $url = 'https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'lat' => $lat, 'lon' => $lon, 'format' => 'geojson',
        'addressdetails' => 1, 'accept-language' => 'it',
    ]);
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Parametri mancanti.']);
    exit;
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_USERAGENT      => $USER_AGENT,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_SSL_VERIFYPEER => true,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $curlErr) {
    http_response_code(503);
    echo json_encode(['error' => 'Geocoding non disponibile: ' . $curlErr]);
    exit;
}
if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode(['error' => 'Errore geocoding HTTP ' . $httpCode]);
    exit;
}

$data = json_decode($response, true);

// /reverse restituisce una singola Feature — la normalizziamo in array
if (isset($data['type']) && $data['type'] === 'Feature') {
    $data = ['features' => [$data]];
}

echo json_encode($data);
