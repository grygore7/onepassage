<?php
/**
 * OnePassage - File di Configurazione
 * Gestisce connessione PDO e sessioni utente
 */

// Recupera le variabili da Railway, se non esistono usa i valori locali
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'onepassage';
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';

// Definiamo le costanti usando le variabili recuperate
define('DB_HOST', $db_host);
define('DB_NAME', $db_name);
define('DB_USER', $db_user);
define('DB_PASS', $db_pass);

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
    // In produzione su Railway sarebbe meglio non mostrare $e->getMessage() per sicurezza,
    // ma per il debug ora va benissimo.
    die("Errore di connessione: " . $e->getMessage());
}

/**
 * Funzioni restanti (calcolaDistanza, isLoggedIn, h) rimangono identiche...
 */
function calcolaDistanza($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; 
    
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

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function h($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}
?>
