<?php
/**
 * chat_poll.php — Polling leggero per nuovi messaggi
 *
 * Apre il DB, legge i messaggi con id > last_id, risponde JSON e chiude.
 * Nessuna connessione persistente — ogni richiesta dura ~10-50ms.
 *
 * GET: ?request=ID&last_id=LAST_MSG_ID
 */

require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store');

if (!isLoggedIn()) {
    echo json_encode(['messages' => []]);
    exit;
}

$requestId = isset($_GET['request'])  ? (int)$_GET['request']  : 0;
$lastId    = isset($_GET['last_id'])  ? (int)$_GET['last_id']  : 0;
$userId    = $_SESSION['user_id'];

if (!$requestId) {
    echo json_encode(['messages' => []]);
    exit;
}

/* Verifica accesso */
$check = $pdo->prepare("
    SELECT id FROM ride_requests
    WHERE id = ? AND (user_id = ? OR driver_id = ?)
    LIMIT 1
");
$check->execute([$requestId, $userId, $userId]);
if (!$check->fetch()) {
    echo json_encode(['messages' => []]);
    exit;
}

/* Leggi solo i messaggi nuovi */
$stmt = $pdo->prepare("
    SELECT cm.id, cm.sender_id, cm.messaggio, cm.created_at, cm.encrypted
    FROM chat_messages cm
    WHERE cm.request_id = ? AND cm.id > ?
    ORDER BY cm.created_at ASC
    LIMIT 50
");
$stmt->execute([$requestId, $lastId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* Marca come letti i messaggi ricevuti */
if (!empty($rows)) {
    $pdo->prepare("
        UPDATE chat_messages SET letto = 1
        WHERE request_id = ? AND receiver_id = ? AND id > ?
    ")->execute([$requestId, $userId, $lastId]);
}

$messages = array_map(fn($row) => [
    'id'        => (int)$row['id'],
    'testo'     => $row['messaggio'],
    'time'      => date('d/m H:i', strtotime($row['created_at'])),
    'is_mine'   => ((int)$row['sender_id'] === $userId),
    'encrypted' => (bool)$row['encrypted'],
], $rows);

echo json_encode(['messages' => $messages], JSON_UNESCAPED_UNICODE);
