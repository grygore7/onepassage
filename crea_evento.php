<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: auth.php');
    exit;
}

$userId  = $_SESSION['user_id'];
$errore  = '';
$successo    = false;
$nuovoEventoId = null;
$redirectTo  = isset($_GET['redirect'])  ? trim($_GET['redirect'])  : '';
$redirectTo  = isset($_POST['redirect']) ? trim($_POST['redirect']) : $redirectTo;
// Whitelist sicura dei redirect ammessi
$allowedRedirects = ['offri_passaggio'];
if (!in_array($redirectTo, $allowedRedirects, true)) $redirectTo = '';

/* ─────────────────────────────────────────────
   GESTIONE FORM POST
───────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nome        = trim($_POST['nome_evento']  ?? '');
    $data        = trim($_POST['data_evento']  ?? '');
    $orario      = trim($_POST['orario']       ?? '');
    $descrizione = trim($_POST['descrizione']  ?? '');
    $luogo       = trim($_POST['luogo']        ?? '');
    $lat         = $_POST['lat'] !== '' ? (float)$_POST['lat'] : null;
    $lon         = $_POST['lon'] !== '' ? (float)$_POST['lon'] : null;

    /* ── Validazione ── */
    if (empty($nome) || empty($data) || empty($orario) || empty($luogo)) {
        $errore = 'Compila tutti i campi obbligatori.';

    } elseif ($lat === null || $lon === null) {
        $errore = 'Seleziona un luogo valido dalla lista dei suggerimenti per ottenere le coordinate.';

    } else {
        $dataOra = $data . ' ' . $orario . ':00';

        /* Data non nel passato */
        if (strtotime($dataOra) < time()) {
            $errore = 'La data dell\'evento non può essere nel passato.';
        } else {
            /* Controllo duplicati: stesso nome e stessa data (±1 ora) */
            $checkStmt = $pdo->prepare("
                SELECT id FROM events
                WHERE nome_evento = ?
                  AND DATE(data_evento) = ?
                LIMIT 1
            ");
            $checkStmt->execute([$nome, $data]);

            if ($checkStmt->fetch()) {
                $errore = 'Esiste già un evento con lo stesso nome in quella data. Verifica prima di ripresentarlo.';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO events
                            (nome_evento, data_evento, luogo, descrizione,
                             latitudine, longitudine, creato_da, approvato)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmt->execute([
                        $nome, $dataOra, $luogo, $descrizione,
                        $lat, $lon, $userId
                    ]);
                    $nuovoEventoId = $pdo->lastInsertId();
                    $successo      = true;

                } catch (PDOException $e) {
                    $errore = 'Errore durante il salvataggio. Riprova.';
                }
            }
        }
    }
}

/* ─────────────────────────────────────────────
   Se successo → redirect dopo 3s a offri_passaggio
───────────────────────────────────────────── */
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crea Evento - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/crea_evento.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php if ($successo && $nuovoEventoId): ?>
    <?php
        $redirectUrl = $redirectTo === 'offri_passaggio'
            ? "offri_passaggio.php?event={$nuovoEventoId}"
            : "offri_passaggio.php?event={$nuovoEventoId}";
    ?>
    <meta http-equiv="refresh" content="3;url=<?= $redirectUrl ?>">
    <?php endif; ?>
</head>
<body>

<!-- ═══════════════════════════ HEADER ═══════════════════════════ -->
<?php include 'header_snippet.php'; ?>

<!-- ═══════════════════════════ MAIN ═══════════════════════════ -->
<div class="section-md">
    <div class="container">
        <div class="page-container">

            <?php if ($successo && $nuovoEventoId): ?>
            <!-- ── SUCCESS ── -->
            <div class="card success-card">
                <span class="success-icon"><i class="fas fa-calendar-check"></i></span>
                <h2>Evento creato con successo!</h2>
                <p>
                    Stai per essere reindirizzato alla pagina per offrire un passaggio
                    a questo evento. Se non vieni reindirizzato automaticamente clicca
                    il pulsante qui sotto.
                </p>
                <div class="success-actions">
                    <a href="offri_passaggio.php?event=<?= $nuovoEventoId ?>" class="btn-primary">
                        <i class="fas fa-car"></i> Offri un Passaggio
                    </a>
                    <a href="dettaglio_evento.php?id=<?= $nuovoEventoId ?>" class="btn-secondary">
                        <i class="fas fa-eye"></i> Vedi l'Evento
                    </a>
                </div>
                <div class="redirect-bar"><div class="redirect-bar-fill"></div></div>
            </div>

            <?php else: ?>
            <!-- ── INTRO ── -->
            <div class="page-intro">
                <h1><i class="fas fa-calendar-plus" style="color:var(--color-accent)"></i> Crea un Evento</h1>
                <?php if ($redirectTo === 'offri_passaggio'): ?>
                <div style="margin-top:12px; padding:12px 16px; background:rgba(16,185,129,0.08);
                            border:1px solid rgba(16,185,129,0.2); border-radius:12px;
                            font-size:14px; color:var(--color-text-secondary);
                            display:flex; align-items:center; gap:10px;">
                    <i class="fas fa-info-circle" style="color:var(--color-accent);flex-shrink:0"></i>
                    Dopo aver creato l'evento verrai reindirizzato automaticamente alla pagina <strong>Offri Passaggio</strong>.
                </div>
                <?php endif; ?>
                <p>Aggiungi un concerto, festival o evento alla community. Una volta creato potrai subito offrire un passaggio!</p>
            </div>

            <?php if ($errore): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= h($errore) ?></span>
            </div>
            <?php endif; ?>

            <!-- ── FORM ── -->
            <div class="card">
                <form method="POST" action="" id="creaEventoForm" novalidate>
                    <input type="hidden" name="redirect" value="<?= h($redirectTo) ?>">

                    <!-- Dettagli evento -->
                    <div class="form-section-title">
                        <i class="fas fa-music"></i> Dettagli Evento
                    </div>

                    <div class="form-group">
                        <label>Nome Evento <span style="color:#EF4444">*</span></label>
                        <input type="text" name="nome_evento" id="nomeEvento"
                               placeholder="Es: Concerti Live – Alcatraz Milano"
                               value="<?= isset($_POST['nome_evento']) ? h($_POST['nome_evento']) : '' ?>"
                               required maxlength="200">
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label>Data <span style="color:#EF4444">*</span></label>
                            <input type="date" name="data_evento" id="dataEvento"
                                   value="<?= isset($_POST['data_evento']) ? h($_POST['data_evento']) : '' ?>"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Orario <span style="color:#EF4444">*</span></label>
                            <input type="time" name="orario" id="orario"
                                   value="<?= isset($_POST['orario']) ? h($_POST['orario']) : '20:00' ?>"
                                   required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Descrizione</label>
                        <textarea name="descrizione" rows="4"
                                  placeholder="Artisti, genere musicale, biglietti, informazioni utili..."><?= isset($_POST['descrizione']) ? h($_POST['descrizione']) : '' ?></textarea>
                    </div>

                    <!-- Luogo con Geocoding -->
                    <div class="form-section-title" style="margin-top:8px;">
                        <i class="fas fa-map-marker-alt"></i> Posizione
                    </div>

                    <div class="form-group">
                        <label>Luogo / Venue <span style="color:#EF4444">*</span></label>
                        <div class="autocomplete-wrapper" id="luogoWrapper">
                            <input type="text" name="luogo" id="luogoInput"
                                   placeholder="Es: Alcatraz Milano, Mediolanum Forum..."
                                   value="<?= isset($_POST['luogo']) ? h($_POST['luogo']) : '' ?>"
                                   autocomplete="off" required>
                            <button type="button" class="geo-btn" id="geoBtn"
                                    title="Usa la mia posizione attuale">
                                <i class="fas fa-crosshairs" id="geoBtnIcon"></i>
                            </button>
                            <div class="autocomplete-dropdown" id="autocompleteDropdown"></div>
                        </div>
                        <!-- Hidden fields per le coordinate -->
                        <input type="hidden" name="lat" id="latInput"
                               value="<?= isset($_POST['lat']) ? h($_POST['lat']) : '' ?>">
                        <input type="hidden" name="lon" id="lonInput"
                               value="<?= isset($_POST['lon']) ? h($_POST['lon']) : '' ?>">
                        <!-- Badge coordinate -->
                        <span class="coords-badge" id="coordsBadge"
                              style="<?= (isset($_POST['lat']) && $_POST['lat'] !== '') ? '' : 'display:none' ?>">
                            <i class="fas fa-check-circle"></i>
                            Coordinate acquisite
                        </span>
                    </div>

                    <div class="info-box">
                        <i class="fas fa-info-circle"></i>
                        <span>
                            Il campo luogo usa l'API <strong>Photon / OpenStreetMap</strong> per suggerire indirizzi e venue
                            e salvare automaticamente le coordinate geografiche, necessarie per il calcolo delle distanze.
                        </span>
                    </div>

                    <button type="submit" class="btn-primary" style="width:100%; margin-top:8px;">
                        <i class="fas fa-calendar-plus"></i> Crea Evento
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<footer class="footer">
    <div class="footer-content">
        <p>&copy; 2026 OnePassage. Viaggia insieme, risparmia e socializza.</p>
    </div>
</footer>

<!-- ═══════════════════════════ SCRIPTS ═══════════════════════════ -->
<script>
/* ── Theme ── */
function toggleTheme() {
    const html     = document.documentElement;
    const newTheme = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', newTheme);
    localStorage.setItem('theme', newTheme);
}
(function () {
    const saved = localStorage.getItem('theme') || 'light';
    document.documentElement.setAttribute('data-theme', saved);
})();

/* ════════════════════════════════════════════
   AUTOCOMPLETE — Photon API (Komoot / OSM)
════════════════════════════════════════════ */
const luogoInput  = document.getElementById('luogoInput');
const dropdown    = document.getElementById('autocompleteDropdown');
const latInput    = document.getElementById('latInput');
const lonInput    = document.getElementById('lonInput');
const coordsBadge = document.getElementById('coordsBadge');

let debounceTimer = null;
let focusedIndex  = -1;
let currentItems  = [];

/* ── Helpers ── */

/**
 * Costruisce la label leggibile da un oggetto properties Photon.
 * Gestisce tutti i casi di campi mancanti/null.
 */
function formatLabel(props) {
    const a = props.address || props; // Nominatim annida in props.address
    const parts = [
        props.name   || props.display_name?.split(',')[0] || null,
        a.road       || a.street   || null,
        a.city       || a.town     || a.village || a.county || null,
        a.state      || null,
        a.country    || null,
    ].filter(v => v && v.trim() !== '');
    return parts.length > 0 ? parts.join(', ') : props.display_name || 'Luogo sconosciuto';
}

function formatSub(props) {
    const a = props.address || props;
    const parts = [
        a.city  || a.town || a.village || a.county || null,
        a.state || null,
        a.country || null,
    ].filter(v => v && v.trim() !== '');
    return parts.join(' · ');
}

function setCoords(lat, lon, label) {
    latInput.value   = lat;
    lonInput.value   = lon;
    luogoInput.value = label;
    coordsBadge.style.display = '';
    closeDropdown();
}

function clearCoords() {
    latInput.value  = '';
    lonInput.value  = '';
    coordsBadge.style.display = 'none';
}

function closeDropdown() {
    dropdown.classList.remove('open');
    dropdown.innerHTML = '';
    focusedIndex = -1;
    currentItems = [];
}

/** Mostra un messaggio di stato (loading / errore / vuoto) */
function showStatus(msg) {
    dropdown.innerHTML = '';
    const el = document.createElement('div');
    el.className = 'autocomplete-loading';
    el.innerHTML = msg;          // msg contiene HTML (icone FA) — sicuro perché è nostro
    dropdown.appendChild(el);
    dropdown.classList.add('open');
}

/**
 * Costruisce i nodi DOM degli item SENZA usare innerHTML per i testi,
 * così caratteri accentati e simboli speciali funzionano sempre.
 */
function renderItems(features) {
    dropdown.innerHTML = '';
    dropdown.classList.add('open');

    features.forEach((feature, i) => {
        const props = feature.properties;
        const main  = formatLabel(props);
        const sub   = formatSub(props);

        const item = document.createElement('div');
        item.className    = 'autocomplete-item';
        item.dataset.index = String(i);
        item.setAttribute('role', 'option');

        // Icona (HTML statico — sicuro)
        const iconSpan = document.createElement('span');
        iconSpan.className = 'autocomplete-item-icon';
        iconSpan.innerHTML = '<i class="fas fa-map-marker-alt"></i>';

        // Testo principale — textContent evita qualsiasi problema di encoding
        const textWrap = document.createElement('div');
        const mainDiv  = document.createElement('div');
        mainDiv.className   = 'autocomplete-item-main';
        mainDiv.textContent = main;   // ← textContent, non innerHTML
        textWrap.appendChild(mainDiv);

        if (sub) {
            const subDiv = document.createElement('div');
            subDiv.className   = 'autocomplete-item-sub';
            subDiv.textContent = sub; // ← textContent
            textWrap.appendChild(subDiv);
        }

        item.appendChild(iconSpan);
        item.appendChild(textWrap);

        // Click / keyboard selection
        item.addEventListener('mousedown', e => {
            e.preventDefault();
            selectItem(i);
        });

        dropdown.appendChild(item);
    });
}

/* ── Fetch suggestions tramite proxy PHP → Nominatim ── */
async function fetchSuggestions(query) {
    if (query.length < 2) { closeDropdown(); return; }

    showStatus('<i class="fas fa-circle-notch fa-spin"></i> Ricerca in corso…');

    try {
        const controller = new AbortController();
        const timeout    = setTimeout(() => controller.abort(), 8000);

        const res = await fetch(`geocode_proxy.php?q=${encodeURIComponent(query)}`, {
            signal: controller.signal
        });
        clearTimeout(timeout);

        if (!res.ok) { showStatus('Servizio non disponibile. Riprova.'); return; }

        const data = await res.json();
        if (data.error) { showStatus('Errore: ' + data.error); return; }

        if (!data.features || data.features.length === 0) {
            showStatus('Nessun risultato. Prova con un termine diverso.');
            return;
        }

        currentItems = data.features;
        focusedIndex = -1;
        renderItems(currentItems);

    } catch (err) {
        if (err.name === 'AbortError') {
            showStatus('Ricerca scaduta. Riprova.');
        } else {
            console.error('[Autocomplete] Fetch error:', err);
            showStatus('Errore di connessione. Verifica la rete e riprova.');
        }
    }
}

function selectItem(index) {
    const feature = currentItems[index];
    if (!feature) return;
    const coords = feature.geometry.coordinates; // [lon, lat]
    setCoords(coords[1], coords[0], formatLabel(feature.properties));
}

/* ── Input events ── */
luogoInput.addEventListener('input', () => {
    clearCoords();
    clearTimeout(debounceTimer);
    const q = luogoInput.value.trim();
    if (q.length < 2) { closeDropdown(); return; }
    debounceTimer = setTimeout(() => fetchSuggestions(q), 350);
});

luogoInput.addEventListener('keydown', e => {
    const items = dropdown.querySelectorAll('.autocomplete-item');
    if (!items.length) return;

    if (e.key === 'ArrowDown') {
        e.preventDefault();
        focusedIndex = Math.min(focusedIndex + 1, items.length - 1);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        focusedIndex = Math.max(focusedIndex - 1, 0);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        if (focusedIndex >= 0) selectItem(focusedIndex);
        return;
    } else if (e.key === 'Escape') {
        closeDropdown();
        return;
    }
    items.forEach((el, i) => el.classList.toggle('focused', i === focusedIndex));
});

luogoInput.addEventListener('blur', () => {
    setTimeout(closeDropdown, 200);
});

luogoInput.addEventListener('focus', () => {
    luogoInput.select();
    clearCoords();
});

/* ════════════════════════════════════════════
   GEOLOCALIZZAZIONE — GPS button
════════════════════════════════════════════ */
const geoBtn     = document.getElementById('geoBtn');
const geoBtnIcon = document.getElementById('geoBtnIcon');

geoBtn.addEventListener('click', () => {
    if (!navigator.geolocation) {
        alert('La geolocalizzazione non è supportata dal tuo browser.');
        return;
    }

    geoBtn.classList.add('loading');
    geoBtnIcon.className = 'fas fa-circle-notch';

    navigator.geolocation.getCurrentPosition(
        async pos => {
            const lat = pos.coords.latitude;
            const lon = pos.coords.longitude;
            try {
                const res  = await fetch(`geocode_proxy.php?lat=${lat}&lon=${lon}`);
                const data = await res.json();
                const props = data.features?.[0]?.properties || {};
                setCoords(lat, lon, formatLabel(props));
            } catch {
                setCoords(lat, lon, 'La mia posizione');
            }
            geoBtn.classList.remove('loading');
            geoBtnIcon.className = 'fas fa-crosshairs';
        },
        err => {
            geoBtn.classList.remove('loading');
            geoBtnIcon.className = 'fas fa-crosshairs';
            if (err.code === err.PERMISSION_DENIED) {
                alert('Permesso GPS negato. Abilita la posizione nelle impostazioni del browser.');
            } else {
                alert('Impossibile rilevare la posizione. Inserisci il luogo manualmente.');
            }
        },
        { timeout: 10000, maximumAge: 60000 }
    );
});

/* ── Form validation before submit ── */
document.getElementById('creaEventoForm')?.addEventListener('submit', function(e) {
    if (!latInput.value || !lonInput.value) {
        e.preventDefault();
        alert('Seleziona un luogo valido dalla lista dei suggerimenti per ottenere le coordinate geografiche.');
    }
});
</script>
</body>
</html>