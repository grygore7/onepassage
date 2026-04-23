<?php
/**
 * crypto_key.php — Gestione chiavi E2E
 *
 * Azioni (POST JSON { action, ... }):
 *   save_public_key   → salva la chiave pubblica RSA dell'utente corrente
 *   get_public_key    → restituisce la chiave pubblica di un altro utente
 *   save_chat_key     → salva la chiave AES cifrata per questa chat
 *   get_chat_key      → restituisce la chiave AES cifrata per l'utente corrente
 */

require_once 'config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'error' => 'Non autenticato.']);
    exit;
}

$userId = $_SESSION['user_id'];
$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? ($_GET['action'] ?? '');

switch ($action) {

    /* ── Salva la chiave pubblica RSA dell'utente ── */
    case 'save_public_key':
        $jwk = $input['public_key'] ?? '';
        if (!$jwk) { echo json_encode(['ok' => false, 'error' => 'Chiave mancante.']); exit; }

        // Valida che sia JSON valido
        $decoded = json_decode($jwk);
        if (!$decoded || !isset($decoded->kty)) {
            echo json_encode(['ok' => false, 'error' => 'Formato JWK non valido.']);
            exit;
        }

        $pdo->prepare("UPDATE users SET public_key = ? WHERE id = ?")
            ->execute([$jwk, $userId]);

        echo json_encode(['ok' => true]);
        break;

    /* ── Leggi la chiave pubblica di un utente ── */
    case 'get_public_key':
        $targetId = isset($input['user_id']) ? (int)$input['user_id']
                  : (isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0);

        if (!$targetId) { echo json_encode(['ok' => false, 'error' => 'user_id mancante.']); exit; }

        $stmt = $pdo->prepare("SELECT public_key FROM users WHERE id = ?");
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();

        if (!$row || !$row['public_key']) {
            echo json_encode(['ok' => false, 'error' => 'Chiave pubblica non disponibile.']);
            exit;
        }
        echo json_encode(['ok' => true, 'public_key' => $row['public_key']]);
        break;

    /* ── Salva la chiave AES cifrata per questa chat ── */
    case 'save_chat_key':
        $requestId    = isset($input['request_id']) ? (int)$input['request_id'] : 0;
        $encryptedKey = $input['encrypted_key'] ?? '';
        // for_user_id: salva la chiave per un altro utente (usato per distribuire la chiave AES)
        $forUserId    = isset($input['for_user_id']) ? (int)$input['for_user_id'] : $userId;

        if (!$requestId || !$encryptedKey) {
            echo json_encode(['ok' => false, 'error' => 'Dati mancanti.']);
            exit;
        }

        // Verifica accesso alla chat (il richiedente deve essere un partecipante)
        $check = $pdo->prepare("SELECT user_id, driver_id FROM ride_requests WHERE id = ? AND (user_id = ? OR driver_id = ?)");
        $check->execute([$requestId, $userId, $userId]);
        $chatRow = $check->fetch();
        if (!$chatRow) { echo json_encode(['ok' => false, 'error' => 'Accesso negato.']); exit; }

        // for_user_id deve essere uno dei due partecipanti
        if ($forUserId !== $userId && $forUserId !== (int)$chatRow['user_id'] && $forUserId !== (int)$chatRow['driver_id']) {
            echo json_encode(['ok' => false, 'error' => 'for_user_id non valido.']);
            exit;
        }

        $pdo->prepare("
            INSERT INTO chat_keys (request_id, user_id, encrypted_key)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE encrypted_key = VALUES(encrypted_key)
        ")->execute([$requestId, $forUserId, $encryptedKey]);

        echo json_encode(['ok' => true]);
        break;

    /* ── Leggi la chiave AES cifrata per l'utente corrente ── */
    case 'get_chat_key':
        $requestId = isset($input['request_id']) ? (int)$input['request_id']
                   : (isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0);

        if (!$requestId) { echo json_encode(['ok' => false, 'error' => 'request_id mancante.']); exit; }

        $check = $pdo->prepare("SELECT id FROM ride_requests WHERE id = ? AND (user_id = ? OR driver_id = ?)");
        $check->execute([$requestId, $userId, $userId]);
        if (!$check->fetch()) { echo json_encode(['ok' => false, 'error' => 'Accesso negato.']); exit; }

        $stmt = $pdo->prepare("SELECT encrypted_key FROM chat_keys WHERE request_id = ? AND user_id = ?");
        $stmt->execute([$requestId, $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Chiave non trovata.']);
            exit;
        }
        echo json_encode(['ok' => true, 'encrypted_key' => $row['encrypted_key']]);
        break;

    /* ── Verifica se un utente ha già una chat_key (senza restituirne il contenuto) ── */
    case 'check_chat_key':
        $requestId = isset($input['request_id']) ? (int)$input['request_id']
                   : (isset($_GET['request_id']) ? (int)$_GET['request_id'] : 0);
        $checkUser = isset($input['user_id']) ? (int)$input['user_id']
                   : (isset($_GET['user_id'])  ? (int)$_GET['user_id']  : $userId);

        if (!$requestId) { echo json_encode(['ok' => false]); exit; }

        $stmt = $pdo->prepare("SELECT id FROM chat_keys WHERE request_id = ? AND user_id = ? LIMIT 1");
        $stmt->execute([$requestId, $checkUser]);
        echo json_encode(['ok' => (bool)$stmt->fetch()]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Azione non riconosciuta.']);
}
