<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: auth.php');
    exit;
}

$userId  = $_SESSION['user_id'];
$offerId = isset($_GET['offer']) ? (int)$_GET['offer'] : 0;

// Carica dettagli offerta
$stmt = $pdo->prepare("
    SELECT ro.*, e.nome_evento, e.luogo, e.data_evento,
           u.nome as driver_nome, u.cognome as driver_cognome, u.id as driver_id
    FROM ride_offers ro
    JOIN events e ON ro.event_id = e.id
    JOIN users u  ON ro.user_id  = u.id
    WHERE ro.id = ? AND ro.posti_disponibili > 0
");
$stmt->execute([$offerId]);
$offerta = $stmt->fetch();

if (!$offerta) { header('Location: ricerca.php'); exit; }
if ($offerta['driver_id'] == $userId) {
    header('Location: dettaglio_evento.php?id=' . $offerta['event_id']); exit;
}

// Già richiesto?
$check = $pdo->prepare("SELECT id FROM ride_requests WHERE user_id = ? AND offer_id = ?");
$check->execute([$userId, $offerId]);
if ($check->fetch()) {
    header('Location: dashboard.php'); exit;
}

/* ── AJAX POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    // Se esiste già una richiesta (doppio click / retry) restituisce l'ID esistente
    $dupCheck = $pdo->prepare("SELECT id FROM ride_requests WHERE user_id = ? AND offer_id = ?");
    $dupCheck->execute([$userId, $offerId]);
    if ($existing = $dupCheck->fetch()) {
        echo json_encode(['ok' => true, 'request_id' => (int)$existing['id']]);
        exit;
    }

    // Controlla disponibilità
    $recheck = $pdo->prepare("SELECT posti_disponibili FROM ride_offers WHERE id = ?");
    $recheck->execute([$offerId]);
    $row = $recheck->fetch();
    if (!$row || (int)$row['posti_disponibili'] < 1) {
        echo json_encode(['ok' => false, 'error' => 'Nessun posto disponibile.']);
        exit;
    }

    try {
        $ins = $pdo->prepare("INSERT INTO ride_requests (user_id, offer_id, driver_id, stato) VALUES (?, ?, ?, 'in_attesa')");
        $ins->execute([$userId, $offerId, $offerta['driver_id']]);
        $requestId = (int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE ride_offers SET posti_disponibili = posti_disponibili - 1 WHERE id = ?")
            ->execute([$offerId]);

        $pdo->prepare("INSERT INTO chat_messages (request_id, sender_id, receiver_id, messaggio, encrypted) VALUES (?, ?, ?, ?, 0)")
            ->execute([$requestId, $userId, $offerta['driver_id'],
                'Ciao! Ho richiesto un passaggio per ' . $offerta['nome_evento'] . '. Quando possiamo organizzarci?']);

        // Email notifica al driver
        $driverRow = $pdo->prepare("SELECT nome, email FROM users WHERE id=?");
        $driverRow->execute([$offerta['driver_id']]); $driverRow = $driverRow->fetch();
        $passNome = $_SESSION['user_nome'] . ' ' . ($_SESSION['user_cognome'] ?? '');
        if ($driverRow) inviaEmail(
            $driverRow['email'], $driverRow['nome'],
            'Nuova richiesta di passaggio per "'.$offerta['nome_evento'].'"',
            emailNuovaRichiesta($driverRow['nome'], $passNome, $offerta['nome_evento'])
        );
        echo json_encode(['ok' => true, 'request_id' => $requestId]);

    } catch (PDOException $e) {
        // Recupero race condition: se la INSERT era duplicata prende l'ID esistente
        $dupCheck2 = $pdo->prepare("SELECT id FROM ride_requests WHERE user_id = ? AND offer_id = ?");
        $dupCheck2->execute([$userId, $offerId]);
        if ($recovered = $dupCheck2->fetch()) {
            echo json_encode(['ok' => true, 'request_id' => (int)$recovered['id']]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Errore: ' . $e->getMessage()]);
        }
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Richiedi Passaggio - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/richiedi_passaggio.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<header class="header">
    <div class="header-container">
        <a href="index.php" class="logo">OnePassage</a>
        <nav class="nav">
            <a href="ricerca.php" class="nav-link">Eventi</a>
            <a href="dashboard.php" class="nav-link">Dashboard</a>
            <a href="profilo.php?id=<?= $_SESSION['user_id'] ?>" class="btn-outline">Profilo</a>
            <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle theme">
                <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
                <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>
        </nav>
    </div>
</header>

<!-- Toast successo -->
<div class="toast" id="successToast">
    <div class="toast-icon"><i class="fas fa-check-circle"></i></div>
    <div class="toast-body">
        <div class="toast-title">Richiesta inviata!</div>
        <div class="toast-sub">Apertura chat in corso…</div>
    </div>
</div>

<div class="section-md">
    <div class="container">
        <div class="page-container">

            <div class="page-intro">
                <h1><i class="fas fa-car" style="color:var(--color-accent)"></i> Conferma Richiesta</h1>
                <p>Controlla i dettagli prima di confermare il passaggio.</p>
            </div>

            <!-- Evento -->
            <div class="card">
                <div class="form-section-title"><i class="fas fa-music"></i> Evento</div>
                <h3 class="detail-title"><?= h($offerta['nome_evento']) ?></h3>
                <div class="meta-row">
                    <span class="meta-item"><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($offerta['data_evento'])) ?></span>
                    <span class="meta-item"><i class="fas fa-map-marker-alt"></i> <?= h($offerta['luogo']) ?></span>
                </div>
            </div>

            <!-- Accompagnatore -->
            <div class="card">
                <div class="form-section-title"><i class="fas fa-user"></i> Accompagnatore</div>
                <div class="driver-row">
                    <div class="driver-avatar-lg"><?= strtoupper(substr($offerta['driver_nome'], 0, 1)) ?></div>
                    <div>
                        <div class="driver-name"><?= h($offerta['driver_nome']) ?> <?= h(substr($offerta['driver_cognome'], 0, 1)) ?>.</div>
                        <a href="profilo.php?id=<?= $offerta['driver_id'] ?>" class="driver-profile-link">
                            <i class="fas fa-eye"></i> Vedi profilo
                        </a>
                    </div>
                </div>
                <div class="detail-table">
                    <div class="detail-row">
                        <span><i class="fas fa-map-marker-alt"></i> Partenza da</span>
                        <strong><?= h($offerta['punto_partenza']) ?></strong>
                    </div>
                    <div class="detail-row">
                        <span><i class="fas fa-chair"></i> Posti disponibili</span>
                        <strong><?= $offerta['posti_disponibili'] ?></strong>
                    </div>
                    <div class="detail-row">
                        <span><i class="fas fa-euro-sign"></i> Contributo spese</span>
                        <strong class="price-highlight">€<?= number_format($offerta['prezzo_per_posto'], 2, ',', '.') ?></strong>
                    </div>
                </div>
                <?php if ($offerta['note']): ?>
                <div class="notes-box">
                    <div class="notes-label"><i class="fas fa-sticky-note"></i> Note</div>
                    <p><?= nl2br(h($offerta['note'])) ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Steps info -->
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Cosa succede dopo?</strong>
                    <ol class="steps-list">
                        <li>La richiesta viene inviata all'accompagnatore</li>
                        <li>Ricevi notifica quando viene accettata o rifiutata</li>
                        <li>Se accettata, puoi chattare per organizzare i dettagli</li>
                        <li>Dopo l'evento puoi lasciare una recensione</li>
                    </ol>
                </div>
            </div>

            <div class="confirm-actions">
                <button type="button" id="confirmBtn" class="btn-primary">
                    <i class="fas fa-check"></i> Conferma Richiesta
                </button>
                <a href="dettaglio_evento.php?id=<?= $offerta['event_id'] ?>" class="btn-secondary">
                    <i class="fas fa-times"></i> Annulla
                </a>
            </div>

        </div>
    </div>
</div>

<footer class="footer">
    <div class="footer-content">
        <p>&copy; 2026 OnePassage. Viaggia insieme, risparmia e socializza.</p>
    </div>
</footer>

<script>
(function () {
    const saved = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
})();

function toggleTheme() {
    const h2 = document.documentElement;
    const t   = h2.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    h2.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
}

document.getElementById('confirmBtn').addEventListener('click', async function () {
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> Invio in corso…';

    try {
        const res  = await fetch(window.location.href, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({}),
        });
        const data = await res.json();

        if (data.ok) {
            // Toast animato
            document.getElementById('successToast').classList.add('show');
            // Redirect dopo 2s
            setTimeout(() => {
                window.location.href = 'chat.php?request=' + data.request_id;
            }, 2000);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check"></i> Conferma Richiesta';
            showError(data.error || 'Errore. Riprova.');
        }
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Conferma Richiesta';
        showError('Errore di rete. Riprova.');
    }
});

function showError(msg) {
    let el = document.getElementById('inlineError');
    if (!el) {
        el = document.createElement('div');
        el.id        = 'inlineError';
        el.className = 'alert alert-error';
        document.querySelector('.confirm-actions').insertAdjacentElement('beforebegin', el);
    }
    el.innerHTML = '<i class="fas fa-exclamation-circle"></i> <span>' + msg + '</span>';
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>
</body>
</html>
