<?php
/**
 * chat_send.php — Endpoint AJAX per inviare messaggi
 *
 * Riceve POST JSON: { request_id, messaggio }
 * Risponde JSON:    { ok: true, id, time } oppure { ok: false, error }
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Non autenticato.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Metodo non consentito.']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true);
$requestId = isset($input['request_id']) ? (int)$input['request_id'] : 0;
$messaggio = isset($input['messaggio'])  ? trim($input['messaggio'])  : '';
$encrypted = isset($input['encrypted'])  ? (int)(bool)$input['encrypted'] : 0;
$userId    = $_SESSION['user_id'];

// Messaggi cifrati sono in base64 e possono essere più lunghi
$maxLen = $encrypted ? 4096 : 500;
if (!$requestId || $messaggio === '' || mb_strlen($messaggio) > $maxLen) {
    echo json_encode(['ok' => false, 'error' => 'Dati non validi.']);
    exit;
}

/* Verifica accesso + recupera receiver */
$stmt = $pdo->prepare("
    SELECT user_id, driver_id FROM ride_requests
    WHERE id = ? AND (user_id = ? OR driver_id = ?)
");
$stmt->execute([$requestId, $userId, $userId]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['ok' => false, 'error' => 'Accesso negato.']);
    exit;
}

$receiverId = ($row['driver_id'] == $userId) ? $row['user_id'] : $row['driver_id'];

try {
    $ins = $pdo->prepare("
        INSERT INTO chat_messages (request_id, sender_id, receiver_id, messaggio, encrypted)
        VALUES (?, ?, ?, ?, ?)
    ");
    $ins->execute([$requestId, $userId, $receiverId, $messaggio, $encrypted]);
    $newId = $pdo->lastInsertId();

    echo json_encode([
        'ok'   => true,
        'id'   => (int)$newId,
        'time' => date('d/m H:i'),
    ]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'Errore database.']);
}
