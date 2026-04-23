<?php
require_once 'config.php';

if(!isLoggedIn()) {
    header('Location: auth.php');
    exit;
}

$requestId = isset($_GET['request']) ? (int)$_GET['request'] : 0;
$userId    = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT rr.*, e.nome_evento, e.data_evento, e.luogo,
           ro.punto_partenza, ro.prezzo_per_posto, ro.posti_disponibili,
           driver.nome as driver_nome, driver.cognome as driver_cognome,
           passenger.nome as passenger_nome, passenger.cognome as passenger_cognome
    FROM ride_requests rr
    JOIN ride_offers ro ON rr.offer_id = ro.id
    JOIN events e ON ro.event_id = e.id
    JOIN users driver ON rr.driver_id = driver.id
    JOIN users passenger ON rr.user_id = passenger.id
    WHERE rr.id = ? AND (rr.user_id = ? OR rr.driver_id = ?)
");
$stmt->execute([$requestId, $userId, $userId]);
$richiesta = $stmt->fetch();

if(!$richiesta) { header('Location: dashboard.php'); exit; }

$isDriver     = ($richiesta['driver_id'] == $userId);
$otherUserId  = $isDriver ? $richiesta['user_id']    : $richiesta['driver_id'];
$otherUserName = $isDriver
    ? $richiesta['passenger_nome'] . ' ' . substr($richiesta['passenger_cognome'], 0, 1) . '.'
    : $richiesta['driver_nome']   . ' ' . substr($richiesta['driver_cognome'],   0, 1) . '.';

// Il POST classico non è più usato — i messaggi si inviano via chat_send.php (AJAX)
// Manteniamo il blocco per compatibilità ma non viene mai raggiunto

$stmt = $pdo->prepare("
    SELECT cm.*, u.nome, u.cognome
    FROM chat_messages cm
    JOIN users u ON cm.sender_id = u.id
    WHERE cm.request_id = ?
    ORDER BY cm.created_at ASC
");
$stmt->execute([$requestId]);
$messaggi = $stmt->fetchAll();

// ID dell'ultimo messaggio caricato
$lastMsgId = !empty($messaggi) ? (int)end($messaggi)['id'] : 0;

// Salt crittografico deterministico — derivato da dati fissi della chat.
// Stesso valore garantito per entrambi gli utenti, nessuna tabella necessaria.
// Usa driver_id + user_id + request_id come input univoci condivisi.
$saltInput = implode('-', [
    'onepassage',
    $requestId,
    min($richiesta['driver_id'], $richiesta['user_id']),
    max($richiesta['driver_id'], $richiesta['user_id']),
    $richiesta['offer_id'],
]);
$chatSalt = hash('sha256', $saltInput);

$pdo->prepare("UPDATE chat_messages SET letto=1 WHERE request_id=? AND receiver_id=?")
    ->execute([$requestId, $userId]);

// Badge stato
switch($richiesta['stato']) {
    case 'in_attesa': $badgeClass='badge-pending'; $badgeIcon='clock'; $statoText='In Attesa'; break;
    case 'accettato': $badgeClass='badge-success'; $badgeIcon='check-circle'; $statoText='Accettato'; break;
    case 'rifiutato': $badgeClass='badge-danger';  $badgeIcon='times-circle'; $statoText='Rifiutato'; break;
    case 'concluso':  $badgeClass='badge-success'; $badgeIcon='flag-checkered'; $statoText='Concluso'; break;
    default:          $badgeClass='badge-pending'; $badgeIcon='question-circle'; $statoText=ucfirst($richiesta['stato']);
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/chat.css">
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
                <a href="come-funziona.php" class="nav-link">Come funziona</a>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="profilo.php?id=<?= $_SESSION['user_id'] ?>" class="btn-outline">Profilo</a>
                <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle theme">
                    <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/>
                        <line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                    <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
            </nav>
        </div>
    </header>

    <div class="section-md">
        <div class="container">

            <!-- Agreement Details (full width on top) -->
            <div class="card" style="margin-bottom: 24px;">
                <div class="agreement-header">
                    <div class="agreement-info">
                        <div class="agreement-title">
                            <i class="fas fa-handshake"></i> Dettagli Accordo
                        </div>
                        <div class="agreement-event"><?= h($richiesta['nome_evento']) ?></div>
                        <div class="agreement-meta">
                            <span class="agreement-meta-item">
                                <i class="fas fa-calendar"></i>
                                <?= date('d/m/Y H:i', strtotime($richiesta['data_evento'])) ?>
                            </span>
                            <span class="agreement-meta-item">
                                <i class="fas fa-map-marker-alt"></i>
                                <?= h($richiesta['luogo']) ?>
                            </span>
                        </div>
                        <div class="agreement-badges">
                            <span class="badge <?= $badgeClass ?>">
                                <i class="fas fa-<?= $badgeIcon ?>"></i> <?= $statoText ?>
                            </span>
                            <span class="badge badge-warning">
                                <i class="fas fa-euro-sign"></i>
                                €<?= number_format($richiesta['prezzo_per_posto'], 2) ?>
                            </span>
                            <span class="badge badge-pending">
                                <i class="fas fa-map-marker-alt"></i>
                                Partenza: <?= h($richiesta['punto_partenza']) ?>
                            </span>
                        </div>
                    </div>

                    <?php
                        $eventoPassato   = strtotime($richiesta['data_evento']) < time();
                        $confDriver      = !empty($richiesta['confermato_driver']);
                        $confPassenger   = !empty($richiesta['confermato_passenger']);
                        $tuttiConfermato = !empty($richiesta['passaggio_confermato']);
                        $mioConferma     = $isDriver ? $confDriver   : $confPassenger;
                        $suoConferma     = $isDriver ? $confPassenger : $confDriver;
                        $haRecensione    = $isDriver
                            ? !empty($richiesta['stelle_driver'])
                            : !empty($richiesta['stelle']);
                    ?>

                    <?php if($richiesta['stato'] === 'accettato' && !$mioConferma): ?>
                    <div class="confirm-ride-banner">
                        <div class="confirm-ride-text">
                            <i class="fas fa-handshake"></i>
                            <div>
                                <strong>Passaggio accordato?</strong>
                                <span>
                                    <?php if($suoConferma): ?>
                                        L'altro utente ha già confermato — confermalo anche tu!
                                    <?php else: ?>
                                        Confermalo quando siete d'accordo. I posti si aggiorneranno quando entrambi confermate.
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <?php elseif($richiesta['stato'] === 'accettato' && !$tuttiConfermato): ?>
                    <div class="confirm-ride-banner confirm-ride-banner--wait">
                        <div class="confirm-ride-text">
                            <i class="fas fa-clock"></i>
                            <div><strong>Hai confermato</strong><span>In attesa di conferma dell'altro utente.</span></div>
                        </div>
                    </div>
                    <?php elseif($richiesta['stato'] === 'accettato' && $tuttiConfermato): ?>
                    <div class="confirm-ride-banner confirm-ride-banner--done">
                        <div class="confirm-ride-text">
                            <i class="fas fa-check-double"></i>
                            <div><strong>Passaggio confermato!</strong><span>Entrambi avete confermato.</span></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="agreement-actions">
                        <?php if($richiesta['stato'] === 'in_attesa' && $isDriver): ?>
                            <a href="gestisci_richiesta.php?id=<?= $requestId ?>&action=accetta"
                               class="btn-action btn-action--star"
                               onclick="return confirm('Accettare questa richiesta?')">
                                <i class="fas fa-check"></i> Accetta
                            </a>
                            <a href="gestisci_richiesta.php?id=<?= $requestId ?>&action=rifiuta"
                               class="btn-action btn-action--danger"
                               onclick="return confirm('Rifiutare questa richiesta?')">
                                <i class="fas fa-times"></i> Rifiuta
                            </a>

                        <?php elseif($richiesta['stato'] === 'accettato' && !$mioConferma): ?>
                            <?php $confirmAction = $isDriver ? 'conferma' : 'conferma_passenger'; ?>
                            <a href="gestisci_richiesta.php?id=<?= $requestId ?>&action=<?= $confirmAction ?>"
                               class="btn-action btn-action--star"
                               onclick="return confirm('Confermare che il passaggio è accordato?')">
                                <i class="fas fa-check-double"></i> Conferma
                            </a>

                        <?php elseif(($richiesta['stato'] === 'accettato' || $richiesta['stato'] === 'concluso') && !$haRecensione && $eventoPassato): ?>
                            <a href="lascia_recensione.php?request=<?= $requestId ?>"
                               class="btn-action btn-action--star">
                                <i class="fas fa-star"></i> Lascia Recensione
                            </a>
                        <?php elseif(($richiesta['stato'] === 'accettato' || $richiesta['stato'] === 'concluso') && $haRecensione): ?>
                            <span class="btn-action btn-action--done" style="cursor:default;">
                                <i class="fas fa-star"></i> Recensione lasciata
                            </span>
                        <?php endif; ?>

                        <a href="profilo.php?id=<?= $otherUserId ?>" class="btn-action">
                            <i class="fas fa-user"></i> Vedi Profilo
                        </a>

                        <?php if(in_array($richiesta['stato'], ['accettato','concluso'])): ?>
                        <a href="segnalazione.php?request=<?= $requestId ?>" class="btn-report">
                            <i class="fas fa-flag"></i> Segnala problema
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Chat Card -->
            <div class="card chat-card">
                <!-- Header -->
                <div class="chat-card-header">
                    <div class="chat-user-avatar">
                        <?= strtoupper(substr($otherUserName, 0, 1)) ?>
                    </div>
                    <div>
                        <div class="chat-user-name"><?= h($otherUserName) ?></div>
                        <div class="chat-user-role"><?= $isDriver ? 'Passeggero' : 'Accompagnatore' ?></div>

                    </div>
                </div>

                <!-- Messages -->
                <div class="chat-messages" id="chatMessages"
                     data-request="<?= $requestId ?>"
                     data-last-id="<?= $lastMsgId ?>"
                     data-user-id="<?= $userId ?>"
                     data-other-user-id="<?= $otherUserId ?>">
                    <?php if(empty($messaggi)): ?>
                    <div class="chat-empty">
                        <i class="fas fa-comments"></i>
                        <p>Nessun messaggio. Inizia la conversazione!</p>
                    </div>
                    <?php else: ?>
                    <?php foreach($messaggi as $msg): ?>
                    <div class="message <?= $msg['sender_id'] == $userId ? 'sent' : '' ?>"
                         data-id="<?= (int)$msg['id'] ?>"
                         data-encrypted="<?= !empty($msg['encrypted']) ? 1 : 0 ?>">
                        <div class="message-bubble">
                            <?php if(empty($msg['encrypted'])): ?>
                                <?= nl2br(h($msg['messaggio'])) ?>
                            <?php else: ?>
                                <em class="decrypt-pending" style="color:var(--color-text-muted);font-size:13px">
                                    <i class="fas fa-circle-notch fa-spin" style="font-size:10px"></i>
                                    Decifrazione…
                                </em>
                            <?php endif; ?>
                            <div class="message-time">
                                <?php if(!empty($msg['encrypted'])): ?><i class="fas fa-lock" style="font-size:9px;margin-right:3px;opacity:0.6"></i><?php endif; ?>
                                <?= date('d/m H:i', strtotime($msg['created_at'])) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Input -->
                <div class="chat-input-area" id="chatInputArea">
                    <input type="text" id="msgInput"
                           placeholder="Scrivi un messaggio..."
                           maxlength="500" autocomplete="off">
                    <button type="button" id="sendBtn" class="chat-send-btn" aria-label="Invia">
                        <i class="fas fa-paper-plane"></i>
                    </button>
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
        /* Mappa id_messaggio → ciphertext, costruita server-side in PHP */
        const CIPHER_MAP = <?php
            $cipherMap = [];
            foreach($messaggi as $m) {
                if(!empty($m['encrypted'])) {
                    $cipherMap[(int)$m['id']] = $m['messaggio'];
                }
            }
            echo json_encode($cipherMap);
        ?>;
        /* Salt condiviso della chat — generato una volta sul server */
        const CHAT_SALT       = <?= json_encode($chatSalt ?? '') ?>;
        const REQUEST_ID_PHP  = <?= $requestId ?>;
        const DATA_EVENTO_TS  = <?= strtotime($richiesta['data_evento']) ?> * 1000;
        const IS_DRIVER       = <?= $isDriver ? 'true' : 'false' ?>;
        const STATO_RICHIESTA = <?= json_encode($richiesta['stato']) ?>;
        const CONF_DRIVER     = <?= !empty($richiesta['confermato_driver'])    ? 'true' : 'false' ?>;
        const CONF_PASSENGER  = <?= !empty($richiesta['confermato_passenger']) ? 'true' : 'false' ?>;
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', async () => {
            const chatMessages  = document.getElementById('chatMessages');
            const msgInput      = document.getElementById('msgInput');
            const sendBtn       = document.getElementById('sendBtn');

            const REQUEST_ID = parseInt(chatMessages.dataset.request);
            const MY_USER_ID = parseInt(chatMessages.dataset.userId); // sempre corretto — viene da PHP $_SESSION
            let   lastId     = parseInt(chatMessages.dataset.lastId) || 0;
            let   pollTimer  = null;
            let   sending    = false;
            let   aesKey     = null;

            /* ══════════════════════════════════════════════════════
               CRYPTO — AES-256-GCM con chiave derivata da PBKDF2
               La chiave è derivata da: CHAT_SALT (segreto server)
               Il server conosce il salt ma non può decifrare i msg
               senza eseguire PBKDF2 lato client (computazionalmente
               costoso e non eseguito automaticamente).
            ══════════════════════════════════════════════════════ */

            async function deriveAESKey(salt) {
                const enc      = new TextEncoder();
                const keyMat   = await crypto.subtle.importKey(
                    'raw', enc.encode(salt), 'PBKDF2', false, ['deriveKey']
                );
                return crypto.subtle.deriveKey(
                    { name: 'PBKDF2', salt: enc.encode('onepassage-chat-' + REQUEST_ID),
                      iterations: 100000, hash: 'SHA-256' },
                    keyMat,
                    { name: 'AES-GCM', length: 256 },
                    false,
                    ['encrypt', 'decrypt']
                );
            }

            function buf2b64(buf) {
                return btoa(String.fromCharCode(...new Uint8Array(buf)));
            }
            function b642buf(b64) {
                const bin = atob(b64);
                const buf = new Uint8Array(bin.length);
                for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
                return buf.buffer;
            }

            async function aesEncrypt(key, plaintext) {
                const iv  = crypto.getRandomValues(new Uint8Array(12));
                const ct  = await crypto.subtle.encrypt(
                    { name: 'AES-GCM', iv },
                    key,
                    new TextEncoder().encode(plaintext)
                );
                const out = new Uint8Array(12 + ct.byteLength);
                out.set(iv);
                out.set(new Uint8Array(ct), 12);
                return buf2b64(out.buffer);
            }

            async function aesDecrypt(key, b64) {
                const data = new Uint8Array(b642buf(b64));
                const iv   = data.slice(0, 12);
                const ct   = data.slice(12);
                const pt   = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ct);
                return new TextDecoder().decode(pt);
            }

            /* ── Inizializza la chiave dalla salt fornita dal server ── */
            async function initCrypto() {
                if (!CHAT_SALT) {
                    console.warn('[E2E] Nessun salt disponibile — messaggi in chiaro.');
                    return;
                }
                try {
                    aesKey = await deriveAESKey(CHAT_SALT);
                    console.log('[E2E] ✓ Chiave AES derivata.');
                    await decryptExistingMessages();
                } catch (err) {
                    console.error('[E2E] Errore derivazione chiave:', err);
                }
            }

            async function decryptExistingMessages() {
                if (!aesKey) return;
                const nodes = chatMessages.querySelectorAll('.message[data-encrypted="1"]');
                for (const node of nodes) {
                    const msgId      = parseInt(node.dataset.id);
                    const ciphertext = CIPHER_MAP[msgId];
                    const bubble     = node.querySelector('.message-bubble');
                    const timeEl     = bubble.querySelector('.message-time');
                    if (!ciphertext) continue;
                    try {
                        const plain = await aesDecrypt(aesKey, ciphertext);
                        while (bubble.firstChild) bubble.removeChild(bubble.firstChild);
                        plain.split('\n').forEach((line, i, arr) => {
                            bubble.appendChild(document.createTextNode(line));
                            if (i < arr.length - 1) bubble.appendChild(document.createElement('br'));
                        });
                        bubble.appendChild(timeEl);
                    } catch (e) {
                        console.warn('[E2E] Decrypt fallito msg', msgId, e.message);
                        while (bubble.firstChild) bubble.removeChild(bubble.firstChild);
                        const em = document.createElement('em');
                        em.style.color    = 'var(--color-text-muted)';
                        em.style.fontSize = '13px';
                        em.textContent    = '🔒 Messaggio non decifrabile';
                        bubble.appendChild(em);
                        bubble.appendChild(timeEl);
                    }
                }
            }

            await initCrypto();

            /* ══════════════════════════════════════════════════════
               UI helpers
            ══════════════════════════════════════════════════════ */
            function scrollBottom(smooth) {
                chatMessages.scrollTo({ top: chatMessages.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
            }
            scrollBottom(false);

            function clearEmpty() {
                const e = chatMessages.querySelector('.chat-empty');
                if (e) e.remove();
            }

            function createNode(msg) {
                const wrap = document.createElement('div');
                wrap.className  = 'message' + (msg.is_mine ? ' sent' : '');
                wrap.dataset.id = msg.id;

                const bubble = document.createElement('div');
                bubble.className = 'message-bubble';
                msg.testo.split('\n').forEach((line, i, arr) => {
                    bubble.appendChild(document.createTextNode(line));
                    if (i < arr.length - 1) bubble.appendChild(document.createElement('br'));
                });

                const t = document.createElement('div');
                t.className = 'message-time';
                if (msg.encrypted) {
                    const lock = document.createElement('i');
                    lock.className = 'fas fa-lock';
                    lock.style.cssText = 'font-size:9px;margin-right:3px;opacity:0.5';
                    t.appendChild(lock);
                }
                t.appendChild(document.createTextNode(msg.time || ''));
                bubble.appendChild(t);
                wrap.appendChild(bubble);
                return wrap;
            }

            /* ══════════════════════════════════════════════════════
               POLLING
            ══════════════════════════════════════════════════════ */
            async function poll() {
                try {
                    const res  = await fetch(`chat_poll.php?request=${REQUEST_ID}&last_id=${lastId}`);
                    const data = await res.json();

                    if (data.messages && data.messages.length > 0) {
                        const atBottom = chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 80;
                        for (const msg of data.messages) {
                            if (document.querySelector(`.message[data-id="${msg.id}"]`)) continue;
                            // Ricalcola is_mine lato client — sender_id è incluso nella risposta
                            const isMine = msg.sender_id !== undefined
                                ? (msg.sender_id === MY_USER_ID)
                                : msg.is_mine;
                            let testo = msg.testo;
                            if (msg.encrypted && aesKey) {
                                try { testo = await aesDecrypt(aesKey, msg.testo); }
                                catch { testo = '🔒 Messaggio non decifrabile'; }
                            }
                            clearEmpty();
                            chatMessages.appendChild(createNode({ ...msg, testo, is_mine: isMine }));
                            lastId = Math.max(lastId, msg.id);
                        }
                        if (atBottom) scrollBottom(true);
                    }
                } catch { /* ignora errori passeggeri */ }
                pollTimer = setTimeout(poll, 1500);
            }

            poll();

            document.addEventListener('visibilitychange', () => {
                if (document.hidden) clearTimeout(pollTimer);
                else { clearTimeout(pollTimer); poll(); }
            });

            /* ══════════════════════════════════════════════════════
               INVIO
            ══════════════════════════════════════════════════════ */
            async function sendMessage() {
                if (sending) return;
                const testo = msgInput.value.trim();
                if (!testo) return;

                sending = true;
                sendBtn.disabled  = true;
                msgInput.disabled = true;

                try {
                    let messaggio  = testo;
                    let encrypted  = false;

                    if (aesKey) {
                        messaggio = await aesEncrypt(aesKey, testo);
                        encrypted = true;
                    }

                    const res  = await fetch('chat_send.php', {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({ request_id: REQUEST_ID, messaggio, encrypted }),
                    });
                    const data = await res.json();

                    if (data.ok) {
                        msgInput.value = '';
                        clearEmpty();
                        chatMessages.appendChild(createNode({
                            id: data.id, testo, time: data.time, is_mine: true, encrypted
                        }));
                        lastId = Math.max(lastId, data.id);
                        scrollBottom(true);
                        clearTimeout(pollTimer);
                        pollTimer = setTimeout(poll, 300);
                    } else {
                        alert('Errore invio: ' + (data.error || 'Riprova.'));
                    }
                } catch (err) {
                    alert('Errore di rete. Riprova.');
                } finally {
                    sending = false;
                    sendBtn.disabled  = false;
                    msgInput.disabled = false;
                    msgInput.focus();
                }
            }

            sendBtn.addEventListener('click', sendMessage);
            msgInput.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
            });
        });

                function toggleTheme() {
            const html = document.documentElement;
            const newTheme = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        }
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>

<!-- ═══════════════════ MODAL CONFERMA PASSAGGIO ═══════════════════ -->

<script>
/* ══════════════════════════════════════════════
});

// Blocco visuale del bottone se evento già iniziato
(function updateConfirmBtn() {
    const btn = document.getElementById('btnConferma');
    if (!btn) return;
    const { eventoInCorso, mioConferma } = getConfirmState();
    if (eventoInCorso) {
        btn.classList.add('btn-confirm-disabled');
        btn.title = "L'evento è già iniziato — usa il bottone Recensione";
    }
})();

// ESC per chiudere modal
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeConfirmModal(); });
</script>
</body>
</html>