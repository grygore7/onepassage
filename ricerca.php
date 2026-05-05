<?php
require_once 'config.php';

/* ─────────────────────────────────────────────
   PARAMETRI GET
───────────────────────────────────────────── */
$searchQuery = isset($_GET['q'])          ? trim($_GET['q'])          : '';
$luogo       = isset($_GET['luogo'])      ? trim($_GET['luogo'])      : '';
$dataInizio  = isset($_GET['data_inizio']) ? $_GET['data_inizio']     : '';
$dataFine    = isset($_GET['data_fine'])   ? $_GET['data_fine']       : '';
$userLat     = isset($_GET['user_lat'])   && $_GET['user_lat'] !== '' ? (float)$_GET['user_lat'] : null;
$userLon     = isset($_GET['user_lon'])   && $_GET['user_lon'] !== '' ? (float)$_GET['user_lon'] : null;
$raggio      = isset($_GET['raggio'])     ? max(5, min(200, (int)$_GET['raggio'])) : 50;

/* ─────────────────────────────────────────────
   FORMULA DI HAVERSINE (PHP)
   Ritorna distanza in KM tra due coordinate.
   d = 2R · arcsin(√(sin²(Δφ/2) + cos φ₁·cos φ₂·sin²(Δλ/2)))
───────────────────────────────────────────── */
function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $R    = 6371.0; // raggio terrestre in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat / 2) ** 2
       + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
       * sin($dLon / 2) ** 2;

    return 2 * $R * asin(sqrt($a));
}

/* ─────────────────────────────────────────────
   QUERY EVENTI
   Se abbiamo coordinate utente, carichiamo anche
   lat/lon degli eventi per calcolare la distanza
───────────────────────────────────────────── */
$sql    = "SELECT id, nome_evento, luogo, data_evento, descrizione,
                  latitudine, longitudine
           FROM events
           WHERE approvato = 1";
// Nota: la distanza viene calcolata dal punto di partenza degli accompagnatori,
// non dalle coordinate dell'evento. Carichiamo i punti di partenza separatamente.
$params = [];

if ($searchQuery) {
    $sql    .= " AND (nome_evento LIKE ? OR descrizione LIKE ?)";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

// Il campo $luogo contiene la label dell'autocomplete (es. "Cornaredo, Milano...")
// Quando si cercano eventi per distanza (lat/lon presenti), NON filtriamo per testo luogo
// perché la distanza è già il filtro corretto. Usiamo il testo solo come ricerca testuale
// se NON ci sono coordinate (utente ha scritto a mano senza selezionare dal GPS).
if ($luogo && $userLat === null) {
    $sql    .= " AND luogo LIKE ?";
    $params[] = "%$luogo%";
}

if ($dataInizio) {
    $sql    .= " AND data_evento >= ?";
    $params[] = $dataInizio;
}

if ($dataFine) {
    $sql    .= " AND data_evento <= ?";
    $params[] = $dataFine . ' 23:59:59';
}

$sql .= " ORDER BY data_evento ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$eventiRaw = $stmt->fetchAll();

/* ─────────────────────────────────────────────
   CALCOLO DISTANZA + FILTRAGGIO PER RAGGIO
   (solo se l'utente ha fornito le coordinate)
───────────────────────────────────────────── */
$eventi = [];
$searchByDistance = ($userLat !== null && $userLon !== null);

// Carica punti di partenza delle offerte per calcolare distanza dall'accompagnatore
$offerCoordsCache = [];
if ($searchByDistance) {
    $stmtCoords = $pdo->prepare("
        SELECT event_id,
               MIN(latitudine_partenza)  as lat,
               MIN(longitudine_partenza) as lon
        FROM ride_offers
        WHERE posti_disponibili > 0
          AND latitudine_partenza != 0
          AND longitudine_partenza != 0
        GROUP BY event_id
    ");
    // Otteniamo tutte le offerte con coordinate, poi troviamo la più vicina per evento
    $stmtAll = $pdo->prepare("
        SELECT event_id, latitudine_partenza as lat, longitudine_partenza as lon
        FROM ride_offers
        WHERE posti_disponibili > 0
          AND latitudine_partenza != 0
          AND longitudine_partenza != 0
    ");
    $stmtAll->execute();
    foreach ($stmtAll->fetchAll() as $row) {
        $eid = $row['event_id'];
        $km  = haversineKm($userLat, $userLon, (float)$row['lat'], (float)$row['lon']);
        if (!isset($offerCoordsCache[$eid]) || $km < $offerCoordsCache[$eid]) {
            $offerCoordsCache[$eid] = $km; // distanza minima tra tutti gli accompagnatori
        }
    }
}

foreach ($eventiRaw as $ev) {
    if ($searchByDistance) {
        $eid = $ev['id'];
        if (isset($offerCoordsCache[$eid])) {
            $km = $offerCoordsCache[$eid];
            if ($km > $raggio) continue;
            $ev['distanza_km'] = round($km, 1);
        } else {
            // Nessun accompagnatore con coordinate — mostra lo stesso ma senza badge
            $ev['distanza_km'] = null;
        }
    } else {
        $ev['distanza_km'] = null;
    }
    $eventi[] = $ev;
}

/* Ordina per distanza crescente se disponibile */
if ($searchByDistance) {
    usort($eventi, fn($a, $b) => ($a['distanza_km'] ?? PHP_INT_MAX) <=> ($b['distanza_km'] ?? PHP_INT_MAX));
}

/* Conta offerte per ogni evento */
function getOfferCount(PDO $pdo, int $eventId): array
{
    $s = $pdo->prepare("
        SELECT COUNT(*) as disponibili, COALESCE(SUM(posti_disponibili), 0) as posti_totali
        FROM ride_offers WHERE event_id = ? AND posti_disponibili > 0
    ");
    $s->execute([$eventId]);
    return $s->fetch();
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ricerca Eventi - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/eventi.css">
    <link rel="stylesheet" href="css/ricerca_extra.css"><!-- autocomplete + geo -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ═══════════════════════════ HEADER ═══════════════════════════ -->
<header class="header">
    <div class="header-container">
        <a href="index.php" class="logo">OnePassage</a>
        <nav class="nav">
            <a href="ricerca.php" class="nav-link active">Eventi</a>
            <a href="come-funziona.php" class="nav-link">Come funziona</a>
            <?php if (isLoggedIn()): ?>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="profilo.php?id=<?= $_SESSION['user_id'] ?>" class="btn-outline">Profilo</a>
            <?php else: ?>
                <a href="auth.php" class="btn-outline">Accedi</a>
            <?php endif; ?>
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

<!-- ═══════════════════════════ CONTENT ═══════════════════════════ -->
<div class="section-md">
    <div class="container">

        <!-- ── Filters Card ── -->
        <div class="filters-container card">
            <form method="GET" action="ricerca.php" id="searchForm">
                <div class="filters-grid">

                    <!-- Cerca per nome -->
                    <div class="form-group" style="margin-bottom:0">
                        <label><i class="fas fa-search"></i> Cerca Evento</label>
                        <input type="text" name="q"
                               value="<?= h($searchQuery) ?>"
                               placeholder="Nome o descrizione…">
                    </div>

                    <!-- Luogo con Autocomplete + GPS -->
                    <div class="form-group" style="margin-bottom:0">
                        <label><i class="fas fa-map-marker-alt"></i> La mia posizione</label>
                        <div class="autocomplete-wrapper" id="luogoWrapper">
                            <input type="text" name="luogo" id="luogoInput"
                                   value="<?= h($luogo) ?>"
                                   placeholder="Città o venue…"
                                   autocomplete="off">
                            <button type="button" class="geo-search-btn" id="geoBtn"
                                    title="Usa GPS per rilevare la tua posizione">
                                <i class="fas fa-crosshairs" id="geoBtnIcon"></i>
                            </button>
                            <div class="autocomplete-dropdown" id="autocompleteDropdown"></div>
                        </div>
                        <!-- Hidden coords -->
                        <input type="hidden" name="user_lat" id="userLat"
                               value="<?= $userLat !== null ? h($userLat) : '' ?>">
                        <input type="hidden" name="user_lon" id="userLon"
                               value="<?= $userLon !== null ? h($userLon) : '' ?>">
                        <?php if ($searchByDistance): ?>
                        <span class="geo-active-badge">
                            <i class="fas fa-location-arrow"></i>
                            Ricerca per distanza attiva — raggio <?= $raggio ?> km
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Date -->
                    <div class="form-group" style="margin-bottom:0">
                        <label><i class="fas fa-calendar-day"></i> Da Data</label>
                        <input type="date" name="data_inizio" value="<?= h($dataInizio) ?>">
                    </div>

                    <div class="form-group" style="margin-bottom:0">
                        <label><i class="fas fa-calendar-day"></i> A Data</label>
                        <input type="date" name="data_fine" value="<?= h($dataFine) ?>">
                    </div>

                </div>

                <!-- Raggio (visibile solo se ci sono coordinate) -->
                <div class="raggio-row" id="raggioRow"
                     style="<?= $searchByDistance ? '' : 'display:none' ?>">
                    <label>
                        Raggio massimo: <strong id="raggioLabel"><?= $raggio ?> km</strong>
                    </label>
                    <input type="range" name="raggio" class="raggio-slider"
                           min="5" max="200" step="5" value="<?= $raggio ?>"
                           oninput="document.getElementById('raggioLabel').textContent = this.value + ' km'">
                </div>

                <div class="filter-actions">
                    <a href="ricerca.php" class="btn-secondary">
                        <i class="fas fa-times"></i> Reset
                    </a>
                    <?php if (isLoggedIn()): ?>
                    <a href="crea_evento.php" class="btn-secondary">
                        <i class="fas fa-plus"></i> Crea Evento
                    </a>
                    <?php endif; ?>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-search"></i> Cerca
                    </button>
                </div>
            </form>
        </div>

        <!-- ── Results Header ── -->
        <div class="results-header">
            <h2 class="section-title" style="margin-bottom:0">
                <?php if ($searchQuery): ?>
                    Risultati per "<?= h($searchQuery) ?>"
                <?php elseif ($searchByDistance): ?>
                    <i class="fas fa-location-arrow" style="color:var(--color-accent);font-size:0.8em"></i>
                    Vicino a te
                <?php else: ?>
                    Tutti gli Eventi
                <?php endif; ?>
            </h2>
            <span class="results-count"><?= count($eventi) ?> event<?= count($eventi) === 1 ? 'o' : 'i' ?> trovat<?= count($eventi) === 1 ? 'o' : 'i' ?></span>
        </div>

        <!-- ── Events Grid ── -->
        <?php if (empty($eventi)): ?>
        <div class="card empty-state">
            <div class="empty-state-icon"><i class="fas fa-search"></i></div>
            <h3 style="margin-bottom:12px">Nessun risultato</h3>
            <p class="subtitle">Prova a modificare i filtri o
                <?php if (isLoggedIn()): ?>
                    <a href="crea_evento.php" style="color:var(--color-accent);font-weight:600">crea tu il primo evento</a>!
                <?php else: ?>
                    allarga il raggio di ricerca.
                <?php endif; ?>
            </p>
        </div>

        <?php else: ?>
        <div class="events-grid">
            <?php foreach ($eventi as $evento): ?>
            <?php $count = getOfferCount($pdo, $evento['id']); ?>
            <a href="dettaglio_evento.php?id=<?= $evento['id'] ?>" class="event-card card">

                <div class="event-card-header">
                    <div class="event-icon">
                        <i class="fas fa-music"></i>
                    </div>
                    <?php if ($evento['distanza_km'] !== null): ?>
                    <span class="distance-badge">
                        <i class="fas fa-route"></i>
                        <?= $evento['distanza_km'] ?> km da te (partenza)
                    </span>
                    <?php endif; ?>
                </div>

                <h3 class="event-title"><?= h($evento['nome_evento']) ?></h3>

                <p class="event-location">
                    <i class="fas fa-map-marker-alt"></i>
                    <?= h($evento['luogo']) ?>
                </p>
                <p class="event-date">
                    <i class="fas fa-calendar-alt"></i>
                    <?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
                    alle <?= date('H:i', strtotime($evento['data_evento'])) ?>
                </p>

                <?php if ($evento['descrizione']): ?>
                <p class="event-description">
                    <?= h(mb_substr($evento['descrizione'], 0, 120)) ?>…
                </p>
                <?php endif; ?>

                <div class="event-meta">
                    <span class="badge badge-success">
                        <i class="fas fa-users"></i>
                        <?= $count['disponibili'] ?> accompagnator<?= $count['disponibili'] == 1 ? 'e' : 'i' ?>
                    </span>
                    <?php if ($count['posti_totali'] > 0): ?>
                    <span class="badge badge-pending">
                        <i class="fas fa-chair"></i>
                        <?= $count['posti_totali'] ?> posti
                    </span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

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
   AUTOCOMPLETE — Photon / OSM
════════════════════════════════════════════ */
const luogoInput  = document.getElementById('luogoInput');
const dropdown    = document.getElementById('autocompleteDropdown');
const userLatEl   = document.getElementById('userLat');
const userLonEl   = document.getElementById('userLon');
const raggioRow   = document.getElementById('raggioRow');

let debounceTimer = null;
let currentItems  = [];
let focusedIndex  = -1;

function formatLabel(props) {
    // Nominatim nests address info under props.address
    const a = props.address || props; // compatibile con entrambi i formati
    return [
        props.name       || props.display_name?.split(',')[0] || null,
        a.road           || a.street      || null,
        a.city           || a.town        || a.village || a.county || null,
        a.state          || null,
        a.country        || null,
    ].filter(v => v && v.trim() !== '').join(', ') || props.display_name || 'Luogo sconosciuto';
}

function formatSub(props) {
    const a = props.address || props;
    return [
        a.city   || a.town  || a.village || a.county || null,
        a.state  || null,
        a.country || null,
    ].filter(v => v && v.trim() !== '').join(' · ');
}

function closeDropdown() {
    dropdown.classList.remove('open');
    dropdown.innerHTML = '';
    focusedIndex = -1;
    currentItems = [];
}

function showStatus(msg) {
    dropdown.innerHTML = '';
    const el = document.createElement('div');
    el.className = 'autocomplete-loading';
    el.innerHTML = msg;
    dropdown.appendChild(el);
    dropdown.classList.add('open');
}

/* Costruisce i nodi DOM con textContent — nessun problema di encoding */
function renderItems(features) {
    dropdown.innerHTML = '';
    dropdown.classList.add('open');

    features.forEach((feature, i) => {
        const props = feature.properties;

        const item = document.createElement('div');
        item.className     = 'autocomplete-item';
        item.dataset.index = String(i);

        const iconSpan = document.createElement('span');
        iconSpan.className = 'autocomplete-item-icon';
        iconSpan.innerHTML = '<i class="fas fa-map-marker-alt"></i>';

        const textWrap = document.createElement('div');

        const mainDiv = document.createElement('div');
        mainDiv.className   = 'autocomplete-item-main';
        mainDiv.textContent = formatLabel(props);   // ← textContent, safe
        textWrap.appendChild(mainDiv);

        const sub = formatSub(props);
        if (sub) {
            const subDiv = document.createElement('div');
            subDiv.className   = 'autocomplete-item-sub';
            subDiv.textContent = sub;               // ← textContent, safe
            textWrap.appendChild(subDiv);
        }

        item.appendChild(iconSpan);
        item.appendChild(textWrap);

        item.addEventListener('mousedown', e => {
            e.preventDefault();
            const coords = feature.geometry.coordinates; // [lon, lat]
            setLocation(coords[1], coords[0], formatLabel(feature.properties));
        });

        dropdown.appendChild(item);
    });
}

function setLocation(lat, lon, label) {
    userLatEl.value  = lat;
    userLonEl.value  = lon;
    luogoInput.value = label;
    raggioRow.style.display = '';
    closeDropdown();
}

function clearLocation() {
    userLatEl.value = '';
    userLonEl.value = '';
    raggioRow.style.display = 'none';
}

async function fetchSuggestions(query) {
    if (query.length < 2) { closeDropdown(); return; }
    showStatus('<i class="fas fa-circle-notch fa-spin"></i> Ricerca…');

    try {
        const controller = new AbortController();
        const tid = setTimeout(() => controller.abort(), 8000);
        const res = await fetch(`geocode_proxy.php?q=${encodeURIComponent(query)}`, {
            signal: controller.signal
        });
        clearTimeout(tid);

        if (!res.ok) { showStatus('Servizio non disponibile. Riprova.'); return; }

        const data = await res.json();
        if (data.error)           { showStatus('Errore: ' + data.error); return; }
        if (!data.features?.length) { showStatus('Nessun risultato trovato.'); return; }

        currentItems = data.features;
        focusedIndex = -1;
        renderItems(currentItems);

    } catch (err) {
        if (err.name === 'AbortError') showStatus('Ricerca scaduta. Riprova.');
        else showStatus('Errore di connessione.');
    }
}

luogoInput.addEventListener('input', () => {
    clearLocation();
    clearTimeout(debounceTimer);
    const q = luogoInput.value.trim();
    if (q.length < 2) { closeDropdown(); return; }
    debounceTimer = setTimeout(() => fetchSuggestions(q), 350);
});

luogoInput.addEventListener('keydown', e => {
    const items = dropdown.querySelectorAll('.autocomplete-item');
    if (!items.length) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); focusedIndex = Math.min(focusedIndex + 1, items.length - 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); focusedIndex = Math.max(focusedIndex - 1, 0); }
    else if (e.key === 'Enter' && focusedIndex >= 0) {
        e.preventDefault();
        items[focusedIndex].dispatchEvent(new MouseEvent('mousedown'));
        return;
    } else if (e.key === 'Escape') { closeDropdown(); return; }
    items.forEach((el, i) => el.classList.toggle('focused', i === focusedIndex));
});

luogoInput.addEventListener('blur', () => setTimeout(closeDropdown, 200));

// Click / focus sul campo: seleziona tutto il testo e pulisce le coordinate
// così l'utente può subito sovrascrivere senza dover cancellare manualmente
luogoInput.addEventListener('focus', () => {
    luogoInput.select();
    clearLocation();
});

/* ════════════════════════════════════════════
   GPS — navigator.geolocation
════════════════════════════════════════════ */
const geoBtn     = document.getElementById('geoBtn');
const geoBtnIcon = document.getElementById('geoBtnIcon');

geoBtn.addEventListener('click', () => {
    if (!navigator.geolocation) { alert('Geolocalizzazione non supportata.'); return; }

    geoBtn.classList.add('loading');
    geoBtnIcon.className = 'fas fa-circle-notch';

    navigator.geolocation.getCurrentPosition(
        async pos => {
            const lat = pos.coords.latitude;
            const lon = pos.coords.longitude;

            try {
                const res  = await fetch(`geocode_proxy.php?lat=${lat}&lon=${lon}`);
                const data = await res.json();
                const p    = data.features?.[0]?.properties || {};
                const label = formatLabel(p) || 'La mia posizione';
                setLocation(lat, lon, label);
            } catch {
                setLocation(lat, lon, 'La mia posizione');
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
                alert('Impossibile rilevare la posizione GPS. Inserisci la città manualmente.');
            }
        },
        { timeout: 10000, maximumAge: 60000 }
    );
});
</script>

<?php if(isLoggedIn()): ?>
<a href="offri_passaggio.php" class="mobile-fab">
    <i class="fas fa-car"></i> Offri Passaggio
</a>
<?php endif; ?>
</body>
</html>
