<?php
require_once 'config.php';

if (!isLoggedIn()) { header('Location: auth.php'); exit; }

$requestId = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$action    = isset($_GET['action']) ? trim($_GET['action']) : '';
$userId    = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT rr.*, ro.posti_disponibili, ro.id AS offer_id_real,
           e.data_evento, e.nome_evento
    FROM ride_requests rr
    JOIN ride_offers ro ON rr.offer_id = ro.id
    JOIN events e       ON ro.event_id = e.id
    WHERE rr.id = ?
");
$stmt->execute([$requestId]);
$richiesta = $stmt->fetch();

if (!$richiesta) { header('Location: dashboard.php'); exit; }

$isDriver    = ((int)$richiesta['driver_id'] === (int)$userId);
$isPassenger = ((int)$richiesta['user_id']   === (int)$userId);

// ── ACCETTA ──
if ($action === 'accetta') {
    if (!$isDriver || $richiesta['stato'] !== 'in_attesa') { header('Location: dashboard.php'); exit; }
    try {
        $pdo->prepare("UPDATE ride_requests SET stato = 'accettato' WHERE id = ?")->execute([$requestId]);
        $pdo->prepare("INSERT INTO chat_messages (request_id, sender_id, receiver_id, messaggio, encrypted) VALUES (?,?,?,?,0)")
            ->execute([$requestId, $userId, $richiesta['user_id'], 'Ho accettato la tua richiesta! Organizziamoci per il viaggio 👍']);
        // Email notifica al passeggero
        $passRow = $pdo->prepare("SELECT nome, email FROM users WHERE id=?");
        $passRow->execute([$richiesta['user_id']]); $passRow = $passRow->fetch();
        if ($passRow) inviaEmail(
            $passRow['email'], $passRow['nome'],
            'Il tuo passaggio per "'.$richiesta['nome_evento'].'" è stato accettato! ✅',
            emailEsitoRichiesta($passRow['nome'], $richiesta['nome_evento'], true)
        );
        $_SESSION['successo'] = 'Richiesta accettata!';
        header('Location: chat.php?request=' . $requestId); exit;
    } catch (PDOException $e) {
        $_SESSION['errore'] = 'Errore durante l\'accettazione.';
        header('Location: dashboard.php'); exit;
    }

// ── RIFIUTA ──
} elseif ($action === 'rifiuta') {
    if (!$isDriver || $richiesta['stato'] !== 'in_attesa') { header('Location: dashboard.php'); exit; }
    try {
        $pdo->prepare("UPDATE ride_requests SET stato = 'rifiutato' WHERE id = ?")->execute([$requestId]);
        $pdo->prepare("UPDATE ride_offers SET posti_disponibili = posti_disponibili + 1 WHERE id = ?")
            ->execute([$richiesta['offer_id']]);
        // Email notifica al passeggero
        $passRow = $pdo->prepare("SELECT nome, email FROM users WHERE id=?");
        $passRow->execute([$richiesta['user_id']]); $passRow = $passRow->fetch();
        if ($passRow) inviaEmail(
            $passRow['email'], $passRow['nome'],
            'Aggiornamento sulla tua richiesta per "'.$richiesta['nome_evento'].'"',
            emailEsitoRichiesta($passRow['nome'], $richiesta['nome_evento'], false)
        );
        $_SESSION['successo'] = 'Richiesta rifiutata.';
        header('Location: dashboard.php'); exit;
    } catch (PDOException $e) {
        $_SESSION['errore'] = 'Errore durante il rifiuto.';
        header('Location: dashboard.php'); exit;
    }

// ── CONFERMA DRIVER ──
} elseif ($action === 'conferma') {
    if (!$isDriver || $richiesta['stato'] !== 'accettato') { header('Location: dashboard.php'); exit; }
    try {
        $pdo->prepare("UPDATE ride_requests SET confermato_driver = 1, passaggio_confermato = confermato_passenger WHERE id = ?")
            ->execute([$requestId]);
        // Scala posto solo quando entrambi confermano
        $row = $pdo->prepare("SELECT passaggio_confermato FROM ride_requests WHERE id = ?");
        $row->execute([$requestId]);
        if ($row->fetchColumn()) {
            $pdo->prepare("UPDATE ride_offers SET posti_disponibili = GREATEST(0, posti_disponibili - 1) WHERE id = ?")
                ->execute([$richiesta['offer_id']]);
        }
        $pdo->prepare("INSERT INTO chat_messages (request_id, sender_id, receiver_id, messaggio, encrypted) VALUES (?,?,?,?,0)")
            ->execute([$requestId, $userId, $richiesta['user_id'], '✅ Ho confermato che il passaggio è accordato!']);
        $_SESSION['successo'] = 'Passaggio confermato!';
        header('Location: chat.php?request=' . $requestId); exit;
    } catch (PDOException $e) {
        $_SESSION['errore'] = 'Errore.'; header('Location: dashboard.php'); exit;
    }

// ── CONFERMA PASSEGGERO ──
} elseif ($action === 'conferma_passenger') {
    if (!$isPassenger || $richiesta['stato'] !== 'accettato') { header('Location: dashboard.php'); exit; }
    try {
        $pdo->prepare("UPDATE ride_requests SET confermato_passenger = 1, passaggio_confermato = confermato_driver WHERE id = ?")
            ->execute([$requestId]);
        $row = $pdo->prepare("SELECT passaggio_confermato FROM ride_requests WHERE id = ?");
        $row->execute([$requestId]);
        if ($row->fetchColumn()) {
            $pdo->prepare("UPDATE ride_offers SET posti_disponibili = GREATEST(0, posti_disponibili - 1) WHERE id = ?")
                ->execute([$richiesta['offer_id']]);
        }
        $pdo->prepare("INSERT INTO chat_messages (request_id, sender_id, receiver_id, messaggio, encrypted) VALUES (?,?,?,?,0)")
            ->execute([$requestId, $userId, $richiesta['driver_id'], '✅ Ho confermato che il passaggio è accordato!']);
        $_SESSION['successo'] = 'Passaggio confermato!';
        header('Location: chat.php?request=' . $requestId); exit;
    } catch (PDOException $e) {
        $_SESSION['errore'] = 'Errore.'; header('Location: dashboard.php'); exit;
    }

} else {
    header('Location: dashboard.php'); exit;
}
?>
