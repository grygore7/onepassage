<?php
require_once 'config.php';

$hasSearch = false;
$risultati = [];
$errore    = '';

// Parametri filtro
$q       = trim($_GET['q']       ?? '');
$luogo   = trim($_GET['luogo']   ?? '');
$data    = trim($_GET['data']    ?? '');
$raggio  = isset($_GET['raggio']) ? max(5, min(500, (int)$_GET['raggio'])) : 30;
$userLat = isset($_GET['ulat']) && $_GET['ulat'] !== '' ? (float)$_GET['ulat'] : null;
$userLon = isset($_GET['ulon']) && $_GET['ulon'] !== '' ? (float)$_GET['ulon'] : null;

// Esegui ricerca solo se l'utente ha inviato il form
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (
    $q !== '' || $luogo !== '' || $data !== '' || $userLat !== null
)) {
    $hasSearch = true;

    if ($userLat !== null && $userLon !== null) {
        // ── Ricerca geospaziale: Haversine in SQL ────────────────
        // Distanza dal punto di partenza dell'autista (lat/lon_partenza in ride_offers)
        // verso la posizione dell'utente passeggero.
        // Formula: 6371 * 2 * ASIN(SQRT(POW(SIN((lat2-lat1)*PI()/360),2)
        //          + COS(lat1*PI()/180)*COS(lat2*PI()/180)*POW(SIN((lon2-lon1)*PI()/360),2)))
        $sql = "
            SELECT
                e.id            AS event_id,
                e.nome_evento,
                e.luogo,
                e.data_evento,
                e.latitudine    AS lat_evento,
                e.longitudine   AS lon_evento,
                ro.id           AS offer_id,
                ro.user_id      AS driver_id,
                ro.punto_partenza,
                ro.latitudine_partenza,
                ro.longitudine_partenza,
                ro.posti_disponibili,
                ro.prezzo_per_posto,
                ro.note,
                CONCAT(u.nome, ' ', LEFT(u.cognome,1), '.') AS driver_nome,
                u.foto_profilo  AS driver_foto,
                ROUND(
                    6371 * 2 * ASIN(SQRT(
                        POW(SIN((ro.latitudine_partenza - :lat) * PI() / 360), 2)
                        + COS(:lat * PI()/180) * COS(ro.latitudine_partenza * PI()/180)
                        * POW(SIN((ro.longitudine_partenza - :lon) * PI() / 360), 2)
                    )), 1
                ) AS distanza_km
            FROM ride_offers ro
            JOIN events e  ON e.id  = ro.event_id
            JOIN users  u  ON u.id  = ro.user_id
            WHERE e.approvato = 1
              AND e.data_evento >= NOW()
              AND ro.posti_disponibili > 0
        ";
        $params = [':lat' => $userLat, ':lon' => $userLon];

        if ($q) {
            $sql .= " AND (e.nome_evento LIKE :q OR e.luogo LIKE :q2)";
            $params[':q']  = "%$q%";
            $params[':q2'] = "%$q%";
        }
        if ($data) {
            $sql .= " AND DATE(e.data_evento) = :data";
            $params[':data'] = $data;
        }

        $sql .= "
            HAVING distanza_km <= :raggio
            ORDER BY distanza_km ASC
        ";
        $params[':raggio'] = $raggio;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $risultati = $stmt->fetchAll();

    } else {
        // ── Ricerca testuale (senza coordinate) ──────────────────
        $sql = "
            SELECT
                e.id            AS event_id,
                e.nome_evento,
                e.luogo,
                e.data_evento,
                ro.id           AS offer_id,
                ro.user_id      AS driver_id,
                ro.punto_partenza,
                ro.posti_disponibili,
                ro.prezzo_per_posto,
                ro.note,
                CONCAT(u.nome, ' ', LEFT(u.cognome,1), '.') AS driver_nome,
                u.foto_profilo  AS driver_foto,
                NULL            AS distanza_km
            FROM ride_offers ro
            JOIN events e  ON e.id  = ro.event_id
            JOIN users  u  ON u.id  = ro.user_id
            WHERE e.approvato = 1
              AND e.data_evento >= NOW()
              AND ro.posti_disponibili > 0
        ";
        $params = [];

        if ($q) {
            $sql .= " AND (e.nome_evento LIKE ? OR e.luogo LIKE ?)";
            $params[] = "%$q%"; $params[] = "%$q%";
        }
        if ($luogo) {
            $sql .= " AND ro.punto_partenza LIKE ?";
            $params[] = "%$luogo%";
        }
        if ($data) {
            $sql .= " AND DATE(e.data_evento) = ?";
            $params[] = $data;
        }

        $sql .= " ORDER BY e.data_evento ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $risultati = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cerca Passaggio — OnePassage</title>
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/ricerca.css">
    <link rel="stylesheet" href="css/ricerca_extra.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

<main class="section-md">
<div class="container">

    <div class="page-intro">
        <h1><i class="fas fa-search" style="color:var(--color-accent);"></i> Trova un passaggio</h1>
        <p>Cerca un accompagnatore che parte vicino a te per lo stesso evento.</p>
    </div>

    <!-- ── Filtri ── -->
    <div class="card filters-card" style="margin-bottom:28px;">
        <form method="get" id="searchForm">
            <div class="filters-grid">
                <div class="form-group">
                    <label for="q"><i class="fas fa-music"></i> Nome evento</label>
                    <input type="text" id="q" name="q" value="<?= h($q) ?>"
                           placeholder="Es: Vasco Rossi, Milano...">
                </div>
                <div class="form-group">
                    <label for="luogo_input"><i class="fas fa-map-marker-alt"></i> Tua località di partenza</label>
                    <div class="autocomplete-wrapper">
                        <input type="text" id="luogo_input" name="luogo" value="<?= h($luogo) ?>"
                               placeholder="Inserisci il tuo comune..." autocomplete="off"
                               oninput="searchLuogo(this.value)">
                        <button type="button" class="geo-search-btn" onclick="gpsRicerca()" title="Usa GPS">
                            <i class="fas fa-location-arrow"></i>
                        </button>
                        <div class="autocomplete-dropdown" id="luogoDropdown" style="display:none;"></div>
                    </div>
                    <input type="hidden" name="ulat" id="ulat" value="<?= $userLat ?? '' ?>">
                    <input type="hidden" name="ulon" id="ulon" value="<?= $userLon ?? '' ?>">
                </div>
                <div class="form-group">
                    <label for="data"><i class="fas fa-calendar"></i> Data</label>
                    <input type="date" id="data" name="data" value="<?= h($data) ?>"
                           min="<?= date('Y-m-d') ?>">
                </div>
                <div class="form-group">
                    <label>
                        <i class="fas fa-ruler-horizontal"></i>
                        Raggio massimo: <strong id="raggioBadge"><?= $raggio ?> km</strong>
                    </label>
                    <input type="range" name="raggio" id="raggiSlider"
                           min="5" max="150" step="5" value="<?= $raggio ?>"
                           oninput="document.getElementById('raggioBadge').textContent=this.value+' km'">
                </div>
            </div>
            <div class="filter-actions">
                <button type="submit" class="btn-primary">
                    <i class="fas fa-search"></i> Cerca passaggi
                </button>
                <a href="ricerca.php" class="btn-secondary">
                    <i class="fas fa-times"></i> Azzera
                </a>
            </div>
        </form>
    </div>

    <!-- ── Risultati ── -->
    <?php if (!$hasSearch): ?>
        <div class="search-empty-state">
            <div class="search-empty-icon"><i class="fas fa-car"></i></div>
            <h3>Inserisci i filtri per trovare un passaggio</h3>
            <p>Cerca per nome evento, la tua città di partenza o usa il GPS per trovare i passaggi più vicini a te.</p>
        </div>

    <?php elseif (empty($risultati)): ?>
        <div class="search-empty-state">
            <div class="search-empty-icon"><i class="fas fa-map-marked-alt"></i></div>
            <h3>Nessun accompagnatore disponibile</h3>
            <p>Nessun accompagnatore disponibile nel raggio selezionato.<br>
               Prova ad aumentare il raggio o a modificare i filtri.</p>
            <?php if (isLoggedIn()): ?>
            <a href="offri_passaggio.php" class="btn-primary" style="margin-top:16px;">
                <i class="fas fa-car"></i> Offri tu il passaggio
            </a>
            <?php endif; ?>
        </div>

    <?php else: ?>
        <div class="results-header">
            <span class="results-count">
                <strong><?= count($risultati) ?></strong> passagg<?= count($risultati) == 1 ? 'io trovato' : 'i trovati' ?>
                <?php if ($userLat !== null): ?>
                — ordinati per distanza dal tuo punto di partenza
                <?php endif; ?>
            </span>
        </div>

        <div class="rides-list">
        <?php foreach ($risultati as $r):
            $dataFmt = $r['data_evento']
                ? (new DateTime($r['data_evento']))->format('d/m/Y H:i')
                : 'Data da definire';
            $prezzoFmt = $r['prezzo_per_posto'] > 0 ? '€ '.number_format($r['prezzo_per_posto'],2) : 'Gratuito';
            $iniziali  = strtoupper(substr($r['driver_nome'], 0, 1));
        ?>
        <div class="ride-card">
            <div class="ride-card-accent"></div>
            <div class="ride-card-body">
                <div class="ride-card-top">
                    <div class="ride-info">
                        <div class="ride-event-name"><?= h($r['nome_evento']) ?></div>
                        <div class="ride-meta">
                            <span><i class="fas fa-map-marker-alt"></i> <?= h($r['luogo'] ?? '') ?></span>
                            <span><i class="fas fa-calendar"></i> <?= $dataFmt ?></span>
                            <span><i class="fas fa-play-circle"></i> Parte da: <strong><?= h($r['punto_partenza']) ?></strong></span>
                        </div>
                    </div>
                    <div class="ride-driver">
                        <?php if ($r['driver_foto']): ?>
                            <img src="uploads/<?= h($r['driver_foto']) ?>" class="driver-avatar" alt="">
                        <?php else: ?>
                            <div class="driver-avatar driver-avatar-initial"><?= $iniziali ?></div>
                        <?php endif; ?>
                        <span class="driver-name"><?= h($r['driver_nome']) ?></span>
                    </div>
                </div>
                <div class="ride-card-bottom">
                    <div class="ride-chips">
                        <?php if ($r['distanza_km'] !== null): ?>
                        <span class="dash-chip dash-chip--green">
                            <i class="fas fa-route"></i> <?= $r['distanza_km'] ?> km da te
                        </span>
                        <?php endif; ?>
                        <span class="dash-chip dash-chip--<?= $r['posti_disponibili'] > 1 ? 'blue' : 'amber' ?>">
                            <i class="fas fa-user-friends"></i> <?= $r['posti_disponibili'] ?> posto<?= $r['posti_disponibili']>1?'i':'' ?>
                        </span>
                        <span class="dash-chip dash-chip--<?= $r['prezzo_per_posto'] > 0 ? 'amber' : 'green' ?>">
                            <i class="fas fa-euro-sign"></i> <?= $prezzoFmt ?>
                        </span>
                    </div>
                    <?php if (isLoggedIn()): ?>
                    <a href="richiedi_passaggio.php?offer=<?= $r['offer_id'] ?>" class="btn-primary btn-sm">
                        <i class="fas fa-hand-paper"></i> Richiedi
                    </a>
                    <?php else: ?>
                    <a href="auth.php" class="btn-secondary btn-sm">
                        <i class="fas fa-sign-in-alt"></i> Accedi per richiedere
                    </a>
                    <?php endif; ?>
                </div>
                <?php if ($r['note']): ?>
                <div class="ride-note"><i class="fas fa-sticky-note"></i> <?= h($r['note']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

</div>
</main>

<?php if (isLoggedIn()): ?>
<a href="offri_passaggio.php" class="mobile-fab">
    <i class="fas fa-car"></i> Offri Passaggio
</a>
<?php endif; ?>

<script>
// ── Geocoding luogo utente ────────────────────────────────────
var _lTimer = null;
function searchLuogo(q) {
    clearTimeout(_lTimer);
    if (q.length < 2) { document.getElementById('luogoDropdown').style.display='none'; return; }
    _lTimer = setTimeout(function() {
        fetch('geocode_proxy.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => showGeoDD(data.features||[]))
            .catch(()=>{});
    }, 350);
}

function showGeoDD(features) {
    var dd = document.getElementById('luogoDropdown');
    if (!features.length) { dd.style.display='none'; return; }
    dd.innerHTML = '';
    features.slice(0,6).forEach(function(f) {
        var d = document.createElement('div');
        d.className = 'autocomplete-item';
        var label = f.properties.label || f.properties.name || '';
        d.innerHTML = '<div class="autocomplete-item-main">' + escHtml(label) + '</div>';
        d.onclick = function() {
            document.getElementById('luogo_input').value = label;
            document.getElementById('ulat').value = f.geometry.coordinates[1];
            document.getElementById('ulon').value = f.geometry.coordinates[0];
            dd.style.display = 'none';
        };
        dd.appendChild(d);
    });
    dd.style.display = 'block';
}

function gpsRicerca() {
    if (!navigator.geolocation) return alert('GPS non disponibile.');
    navigator.geolocation.getCurrentPosition(function(pos) {
        document.getElementById('ulat').value = pos.coords.latitude;
        document.getElementById('ulon').value = pos.coords.longitude;
        fetch('geocode_proxy.php?lat='+pos.coords.latitude+'&lon='+pos.coords.longitude)
            .then(r=>r.json()).then(data=>{
                var f=(data.features||[])[0];
                if (f) document.getElementById('luogo_input').value = f.properties.label||f.properties.name||'';
            });
    }, function(){ alert('Impossibile ottenere la posizione GPS.'); });
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.autocomplete-wrapper'))
        document.querySelectorAll('.autocomplete-dropdown').forEach(d=>d.style.display='none');
});

function toggleTheme() {
    var t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',t);localStorage.setItem('theme',t);
}
</script>
</body>
</html>
