<?php
require_once 'config.php';

if (!isLoggedIn()) { header('Location: auth.php'); exit; }

$userId  = $_SESSION['user_id'];
$errore  = '';
$successo = '';
$offertaId = null;

// ── POST: salva l'offerta ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tmId        = trim($_POST['ticketmaster_id'] ?? '');
    $nomeEvento  = trim($_POST['nome_evento']     ?? '');
    $dataEvento  = trim($_POST['data_evento']     ?? '');
    $luogoEvento = trim($_POST['luogo_evento']    ?? '');
    $latEvento   = (float)($_POST['lat_evento']   ?? 0);
    $lonEvento   = (float)($_POST['lon_evento']   ?? 0);
    $eventIdPost = (int)($_POST['event_id']       ?? 0);

    $puntoPartenza = trim($_POST['punto_partenza']        ?? '');
    $latPartenza   = (float)($_POST['lat_partenza']       ?? 0);
    $lonPartenza   = (float)($_POST['lon_partenza']       ?? 0);
    $posti         = (int)($_POST['posti_disponibili']    ?? 0);
    $prezzo        = (float)($_POST['prezzo_per_posto']   ?? 0);
    $note          = trim($_POST['note']                  ?? '');

    // Validazione
    if (empty($puntoPartenza) || $latPartenza == 0 || $lonPartenza == 0) {
        $errore = 'Inserisci un punto di partenza valido con coordinate.';
    } elseif ($posti < 1 || $posti > 8) {
        $errore = 'I posti devono essere tra 1 e 8.';
    } elseif ($prezzo < 0 || $prezzo > 200) {
        $errore = 'Prezzo non valido.';
    } elseif (empty($nomeEvento)) {
        $errore = 'Seleziona o inserisci un evento.';
    } else {
        try {
            // ── Importazione automatica evento da Ticketmaster ──
            if (!$eventIdPost && !empty($tmId)) {
                // Cerca se esiste già nel DB
                $ex = $pdo->prepare("SELECT id FROM events WHERE ticketmaster_id = ?");
                $ex->execute([$tmId]);
                $existing = $ex->fetch();
                if ($existing) {
                    $eventIdPost = $existing['id'];
                } else {
                    // Inserisci nuovo evento importato
                    $ins = $pdo->prepare("
                        INSERT INTO events
                            (nome_evento, luogo, latitudine, longitudine,
                             data_evento, approvato, fonte, ticketmaster_id, creato_da)
                        VALUES (?, ?, ?, ?, ?, 1, 'ticketmaster', ?, ?)
                    ");
                    $ins->execute([
                        $nomeEvento, $luogoEvento, $latEvento, $lonEvento,
                        $dataEvento ?: null, $tmId, $userId
                    ]);
                    $eventIdPost = (int)$pdo->lastInsertId();
                }
            }

            // ── Evento manuale (senza Ticketmaster) ────────────
            if (!$eventIdPost && empty($tmId)) {
                if (empty($dataEvento) || $latEvento == 0) {
                    $errore = 'Per un evento manuale inserisci tutti i campi evento.';
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO events
                            (nome_evento, luogo, latitudine, longitudine,
                             data_evento, approvato, fonte, creato_da)
                        VALUES (?, ?, ?, ?, ?, 1, 'manuale', ?)
                    ");
                    $ins->execute([
                        $nomeEvento, $luogoEvento, $latEvento, $lonEvento,
                        $dataEvento, $userId
                    ]);
                    $eventIdPost = (int)$pdo->lastInsertId();
                }
            }

            if (!$errore && $eventIdPost) {
                // Controlla duplicato offerta
                $dup = $pdo->prepare("SELECT id FROM ride_offers WHERE user_id=? AND event_id=?");
                $dup->execute([$userId, $eventIdPost]);
                if ($dup->fetch()) {
                    $errore = 'Hai già un\'offerta attiva per questo evento.';
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO ride_offers
                            (user_id, event_id, punto_partenza,
                             latitudine_partenza, longitudine_partenza,
                             posti_disponibili, prezzo_per_posto, note)
                        VALUES (?,?,?,?,?,?,?,?)
                    ");
                    $ins->execute([
                        $userId, $eventIdPost, $puntoPartenza,
                        $latPartenza, $lonPartenza,
                        $posti, $prezzo, $note
                    ]);
                    $offertaId = (int)$pdo->lastInsertId();
                    $successo  = 'Passaggio pubblicato! I passeggeri possono ora trovarlo.';
                }
            }
        } catch (PDOException $e) {
            $errore = 'Errore durante il salvataggio.';
            error_log($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offri Passaggio — OnePassage</title>
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/offri_passaggio.css">
    <link rel="stylesheet" href="css/ricerca_extra.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

<main class="section-md">
<div class="container" style="max-width:680px;margin:0 auto;">

    <div class="page-intro">
        <h1><i class="fas fa-car" style="color:var(--color-accent);"></i> Offri un passaggio</h1>
        <p>Condividi la tua auto e aiuta altri fan a raggiungere l'evento.</p>
    </div>

    <?php if ($errore): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= h($errore) ?></div>
    <?php endif; ?>

    <?php if ($successo): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= h($successo) ?></div>
    <?php endif; ?>

    <?php if (!$offertaId): ?>
    <form method="post" id="offerForm">
        <div class="card" style="margin-bottom:20px;">
            <h3 class="form-section-title"><i class="fas fa-calendar-alt"></i> Evento di destinazione</h3>

            <!-- Ricerca Ticketmaster -->
            <div class="form-group">
                <label for="tmSearch">Cerca evento <span style="color:var(--color-text-muted);font-weight:400;">(Ticketmaster — musica e sport in Italia)</span></label>
                <div class="autocomplete-wrapper">
                    <input type="text" id="tmSearch" placeholder="Es: Vasco Rossi, San Siro..."
                           autocomplete="off" oninput="searchTM(this.value)">
                    <div class="autocomplete-dropdown" id="tmDropdown" style="display:none;"></div>
                </div>
            </div>

            <!-- Evento selezionato / preview -->
            <div id="eventoSelezionato" class="event-info-box" style="display:none;">
                <div id="eventoPreview"></div>
                <button type="button" class="btn-secondary btn-sm" onclick="clearEvento()" style="margin-top:10px;">
                    <i class="fas fa-times"></i> Cambia evento
                </button>
            </div>

            <!-- Inserimento manuale -->
            <div id="manualBlock" style="display:none;">
                <div class="info-box" style="margin-bottom:16px;">
                    <i class="fas fa-info-circle"></i>
                    Inserimento manuale — per sagre, eventi privati o non trovati su Ticketmaster.
                </div>
                <div class="form-group">
                    <label for="nome_evento_manual">Nome evento *</label>
                    <input type="text" id="nome_evento_manual" placeholder="Es: Sagra del Paese 2025">
                </div>
                <div class="form-group">
                    <label for="data_evento_manual">Data e ora *</label>
                    <input type="datetime-local" id="data_evento_manual">
                </div>
                <div class="form-group">
                    <label>Luogo evento *</label>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="luogo_evento_manual" placeholder="Cerca venue o indirizzo..."
                               autocomplete="off" oninput="searchLuogoEvento(this.value)">
                        <button type="button" class="geo-search-btn" onclick="gpsLuogoEvento()" title="Usa GPS">
                            <i class="fas fa-location-arrow"></i>
                        </button>
                        <div class="autocomplete-dropdown" id="luogoEventoDropdown" style="display:none;"></div>
                    </div>
                    <div id="coordsEventoHint" class="coords-badge" style="display:none;"></div>
                </div>
            </div>

            <!-- Campi nascosti evento -->
            <input type="hidden" name="ticketmaster_id" id="h_tm_id">
            <input type="hidden" name="nome_evento"     id="h_nome_evento">
            <input type="hidden" name="data_evento"     id="h_data_evento">
            <input type="hidden" name="luogo_evento"    id="h_luogo_evento">
            <input type="hidden" name="lat_evento"      id="h_lat_evento" value="0">
            <input type="hidden" name="lon_evento"      id="h_lon_evento" value="0">
            <input type="hidden" name="event_id"        id="h_event_id"   value="0">

            <button type="button" class="btn-secondary" style="width:100%;margin-top:4px;"
                    onclick="toggleManual()">
                <i class="fas fa-pen"></i> Aggiungi evento manualmente
            </button>
        </div>

        <div class="card" style="margin-bottom:20px;">
            <h3 class="form-section-title"><i class="fas fa-map-marker-alt"></i> Punto di partenza</h3>
            <div class="form-group">
                <label for="punto_partenza">Da dove parti? *</label>
                <div class="autocomplete-wrapper">
                    <input type="text" id="punto_partenza" name="punto_partenza"
                           placeholder="Es: Bareggio, Via Roma 1..."
                           autocomplete="off" oninput="searchPartenza(this.value)" required>
                    <button type="button" class="geo-search-btn" onclick="gpsPartenza()" title="Usa GPS">
                        <i class="fas fa-location-arrow"></i>
                    </button>
                    <div class="autocomplete-dropdown" id="partenzaDropdown" style="display:none;"></div>
                </div>
                <input type="hidden" name="lat_partenza" id="lat_partenza" value="0">
                <input type="hidden" name="lon_partenza" id="lon_partenza" value="0">
                <div id="coordsPartenzaHint" class="coords-badge" style="display:none;"></div>
            </div>
        </div>

        <div class="card" style="margin-bottom:20px;">
            <h3 class="form-section-title"><i class="fas fa-users"></i> Dettagli del passaggio</h3>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="posti_disponibili">Posti disponibili *</label>
                    <input type="number" id="posti_disponibili" name="posti_disponibili"
                           min="1" max="8" value="2" required>
                </div>
                <div class="form-group">
                    <label for="prezzo_per_posto">Prezzo per posto (€)</label>
                    <input type="number" id="prezzo_per_posto" name="prezzo_per_posto"
                           min="0" max="200" step="0.50" value="0.00">
                    <small style="color:var(--color-text-muted);font-size:12px;">0 = gratuito</small>
                </div>
            </div>
            <div class="form-group">
                <label for="note">Note aggiuntive</label>
                <textarea id="note" name="note" rows="3"
                          placeholder="Es: Parcheggio gratuito, animali ammessi, ritiro a casa..."></textarea>
            </div>
        </div>

        <button type="submit" class="btn-primary" style="width:100%;padding:15px;">
            <i class="fas fa-car"></i> Pubblica passaggio
        </button>
    </form>
    <?php else: ?>
    <div class="card" style="text-align:center;padding:48px 24px;">
        <div style="font-size:56px;margin-bottom:16px;">🎉</div>
        <h2>Passaggio pubblicato!</h2>
        <p style="color:var(--color-text-muted);margin-bottom:28px;">
            I passeggeri che cercano in quella zona potranno trovarlo.
        </p>
        <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap;">
            <a href="dashboard.php" class="btn-primary"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="offri_passaggio.php" class="btn-secondary"><i class="fas fa-plus"></i> Altro passaggio</a>
        </div>
    </div>
    <?php endif; ?>

</div>
</main>

<script>
var _manualMode = false;

// ── Ricerca Ticketmaster ──────────────────────────────────────
var _tmTimer = null;
function searchTM(q) {
    clearTimeout(_tmTimer);
    if (q.length < 2) { document.getElementById('tmDropdown').style.display = 'none'; return; }
    _tmTimer = setTimeout(function() {
        fetch('ticketmaster_proxy.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => showTMResults(data))
            .catch(() => {});
    }, 350);
}

function showTMResults(events) {
    var dd = document.getElementById('tmDropdown');
    if (!events || !events.length) { dd.style.display='none'; return; }
    dd.innerHTML = '';
    events.forEach(function(ev) {
        var d = document.createElement('div');
        d.className = 'autocomplete-item';
        var data = ev.data ? new Date(ev.data).toLocaleDateString('it-IT',{day:'2-digit',month:'short',year:'numeric'}) : '';
        d.innerHTML = '<div class="autocomplete-item-main">' + escHtml(ev.nome) + '</div>'
                    + '<div class="autocomplete-item-sub"><i class="fas fa-map-marker-alt"></i> ' + escHtml(ev.luogo)
                    + (data ? ' &nbsp;·&nbsp; <i class="fas fa-calendar"></i> ' + data : '') + '</div>';
        d.onclick = function() { selectTMEvent(ev); };
        dd.appendChild(d);
    });
    dd.style.display = 'block';
}

function selectTMEvent(ev) {
    document.getElementById('h_tm_id').value       = ev.id;
    document.getElementById('h_nome_evento').value  = ev.nome;
    document.getElementById('h_data_evento').value  = ev.data  || '';
    document.getElementById('h_luogo_evento').value = ev.luogo || '';
    document.getElementById('h_lat_evento').value   = ev.lat   || 0;
    document.getElementById('h_lon_evento').value   = ev.lon   || 0;
    document.getElementById('h_event_id').value     = 0; // sarà inserito lato server

    var data = ev.data ? new Date(ev.data).toLocaleDateString('it-IT',{weekday:'long',day:'2-digit',month:'long',year:'numeric',hour:'2-digit',minute:'2-digit'}) : 'Data TBD';
    document.getElementById('eventoPreview').innerHTML =
        '<strong>' + escHtml(ev.nome) + '</strong><br>'
        + '<i class="fas fa-map-marker-alt"></i> ' + escHtml(ev.luogo) + '<br>'
        + '<i class="fas fa-calendar"></i> ' + data
        + (ev.immagine ? '<br><img src="'+ev.immagine+'" style="margin-top:10px;border-radius:8px;width:100%;max-height:120px;object-fit:cover;">' : '');

    document.getElementById('eventoSelezionato').style.display = 'block';
    document.getElementById('tmDropdown').style.display = 'none';
    document.getElementById('tmSearch').value = ev.nome;
    if (_manualMode) toggleManual();
}

function clearEvento() {
    ['h_tm_id','h_nome_evento','h_data_evento','h_luogo_evento','h_event_id'].forEach(function(id){
        document.getElementById(id).value = '';
    });
    document.getElementById('h_lat_evento').value = 0;
    document.getElementById('h_lon_evento').value = 0;
    document.getElementById('eventoSelezionato').style.display = 'none';
    document.getElementById('tmSearch').value = '';
}

function toggleManual() {
    _manualMode = !_manualMode;
    document.getElementById('manualBlock').style.display = _manualMode ? 'block' : 'none';
    if (_manualMode) clearEvento();
}

// ── Geocoding partenza ────────────────────────────────────────
var _pTimer = null;
function searchPartenza(q) {
    clearTimeout(_pTimer);
    if (q.length < 3) { document.getElementById('partenzaDropdown').style.display='none'; return; }
    _pTimer = setTimeout(function() {
        fetch('geocode_proxy.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => showGeoResults(data.features||[], 'partenzaDropdown', setPartenza))
            .catch(()=>{});
    }, 350);
}
function setPartenza(feat) {
    var coords = feat.geometry.coordinates;
    document.getElementById('lat_partenza').value = coords[1];
    document.getElementById('lon_partenza').value = coords[0];
    document.getElementById('punto_partenza').value = feat.properties.label || feat.properties.name || '';
    document.getElementById('partenzaDropdown').style.display = 'none';
    showCoords('coordsPartenzaHint', coords[1], coords[0]);
}
function gpsPartenza() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(function(pos) {
        document.getElementById('lat_partenza').value = pos.coords.latitude;
        document.getElementById('lon_partenza').value = pos.coords.longitude;
        fetch('geocode_proxy.php?lat='+pos.coords.latitude+'&lon='+pos.coords.longitude)
            .then(r=>r.json()).then(data=>{
                var feat = (data.features||[])[0];
                if (feat) document.getElementById('punto_partenza').value = feat.properties.label || feat.properties.name || '';
            });
        showCoords('coordsPartenzaHint', pos.coords.latitude, pos.coords.longitude);
    });
}

// ── Geocoding luogo evento manuale ────────────────────────────
var _leTimer = null;
function searchLuogoEvento(q) {
    clearTimeout(_leTimer);
    if (q.length < 3) { document.getElementById('luogoEventoDropdown').style.display='none'; return; }
    _leTimer = setTimeout(function() {
        fetch('geocode_proxy.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => showGeoResults(data.features||[], 'luogoEventoDropdown', setLuogoEvento))
            .catch(()=>{});
    }, 350);
}
function setLuogoEvento(feat) {
    var coords = feat.geometry.coordinates;
    document.getElementById('h_lat_evento').value   = coords[1];
    document.getElementById('h_lon_evento').value   = coords[0];
    document.getElementById('h_luogo_evento').value = feat.properties.label || feat.properties.name || '';
    document.getElementById('luogo_evento_manual').value = feat.properties.label || feat.properties.name || '';
    document.getElementById('luogoEventoDropdown').style.display = 'none';
    showCoords('coordsEventoHint', coords[1], coords[0]);
    // Sync con hidden
    document.getElementById('h_nome_evento').value = document.getElementById('nome_evento_manual').value;
    document.getElementById('h_data_evento').value = document.getElementById('data_evento_manual').value;
}
function gpsLuogoEvento() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(function(pos) {
        document.getElementById('h_lat_evento').value = pos.coords.latitude;
        document.getElementById('h_lon_evento').value = pos.coords.longitude;
        showCoords('coordsEventoHint', pos.coords.latitude, pos.coords.longitude);
    });
}

// ── Helpers ───────────────────────────────────────────────────
function showGeoResults(features, dropdownId, cb) {
    var dd = document.getElementById(dropdownId);
    if (!features.length) { dd.style.display='none'; return; }
    dd.innerHTML = '';
    features.slice(0,6).forEach(function(f) {
        var d = document.createElement('div');
        d.className = 'autocomplete-item';
        var label = f.properties.label || f.properties.name || '';
        var country = f.properties.country || '';
        d.innerHTML = '<div class="autocomplete-item-main">' + escHtml(label) + '</div>'
                    + (country ? '<div class="autocomplete-item-sub">' + escHtml(country) + '</div>' : '');
        d.onclick = function() { cb(f); };
        dd.appendChild(d);
    });
    dd.style.display = 'block';
}

function showCoords(id, lat, lon) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = '📍 ' + lat.toFixed(5) + ', ' + lon.toFixed(5);
    el.style.display = 'block';
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Sync campi manuali prima del submit
document.getElementById('offerForm') && document.getElementById('offerForm').addEventListener('submit', function() {
    if (_manualMode) {
        document.getElementById('h_nome_evento').value = document.getElementById('nome_evento_manual').value;
        document.getElementById('h_data_evento').value = document.getElementById('data_evento_manual').value;
    }
});

function toggleTheme() {
    var t = document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',t); localStorage.setItem('theme',t);
}

// Chiudi dropdown cliccando fuori
document.addEventListener('click', function(e) {
    if (!e.target.closest('.autocomplete-wrapper')) {
        document.querySelectorAll('.autocomplete-dropdown').forEach(d => d.style.display='none');
    }
});
</script>
</body>
</html>
