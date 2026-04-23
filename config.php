<?php
/**
 * OnePassage - File di Configurazione
 * Gestisce connessione PDO e sessioni utente
 */

// Configurazione Database
// Cambia le tue define così:
define('DB_HOST', 'sql312.infinityfree.com');
define('DB_NAME', 'if0_41738102_onepassage_db');
define('DB_USER', 'if0_41738102');
define('DB_PASS', 'Grigore2502');

// Inizializza sessione
session_start();

try {
    // Connessione PDO con opzioni di sicurezza
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch(PDOException $e) {
    die("Errore di connessione: " . $e->getMessage());
}

/**
 * Funzione per calcolare distanza tra due coordinate (Formula di Haversine)
 * @param float $lat1 Latitudine punto 1
 * @param float $lon1 Longitudine punto 1
 * @param float $lat2 Latitudine punto 2
 * @param float $lon2 Longitudine punto 2
 * @return float Distanza in KM
 */
function calcolaDistanza($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // Raggio Terra in KM
    
    $latFrom = deg2rad($lat1);
    $lonFrom = deg2rad($lon1);
    $latTo = deg2rad($lat2);
    $lonTo = deg2rad($lon2);
    
    $latDelta = $latTo - $latFrom;
    $lonDelta = $lonTo - $lonFrom;
    
    $a = sin($latDelta / 2) * sin($latDelta / 2) +
         cos($latFrom) * cos($latTo) *
         sin($lonDelta / 2) * sin($lonDelta / 2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    return $earthRadius * $c;
}

/**
 * Verifica se l'utente è autenticato
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Funzione per sanitizzare l'output HTML
 * @param string $text
 * @return string
 */
function h($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
?>