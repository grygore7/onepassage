<?php
require_once 'config.php';

$hasSearch = false;
$risultati = [];
$errore    = '';

// ── Parametri ─────────────────────────────────────────────────
$q       = trim($_GET['q']    ?? '');
$data    = trim($_GET['data'] ?? '');
$raggio  = isset($_GET['raggio']) ? max(5, min(300, (int)$_GET['raggio'])) : 30;
$userLat = (isset($_GET['ulat']) && $_GET['ulat'] !== '') ? (float)$_GET['ulat'] : null;
$userLon = (isset($_GET['ulon']) && $_GET['ulon'] !== '') ? (float)$_GET['ulon'] : null;
$luogoNome = trim($_GET['luogo'] ?? '');

// ── Esegui ricerca solo se il form è stato inviato ────────────
if (isset($_GET['cerca'])) {

    // Validazione: la località di partenza è obbligatoria
    // (serve per il calcolo della distanza — senza di essa la ricerca non ha senso)
    if ($userLat === null || $userLon === null) {
        $errore = 'Inserisci la tua città di partenza per trovare passaggi vicini a te.';
    } else {
        $hasSearch = true;

        // ── Query con Haversine in SQL ────────────────────────
        // IMPORTANTE: tutti i parametri sono named (:nome) per evitare
        // il conflitto HY093 tra named e positional params nello stesso statement.
        $where  = "WHERE e.approvato = 1 AND e.data_evento >= NOW() AND ro.posti_disponibili > 0";
        $params = [
            ':lat'    => $userLat,
            ':lat2'   => $userLat,   // PDO non permette di riusare lo stesso named param
            ':lon'    => $userLon,
            ':raggio' => $raggio,
        ];
        if (isLoggedIn()) {
    $where .= " AND ro.user_id <> :self_id";
    $params[':self_id'] = $_SESSION['user_id'];
}

        if ($q !== '') {
            $where .= " AND e.nome_evento LIKE :q";
            $params[':q'] = "%$q%";
        }
        if ($data !== '') {
            $where .= " AND DATE(e.data_evento) = :data";
            $params[':data'] = $data;
        }

        $sql = "
            SELECT
                e.id                AS event_id,
                e.nome_evento,
                e.luogo,
                e.data_evento,
                ro.id               AS offer_id,
                ro.punto_partenza,
                ro.latitudine_partenza,
                ro.longitudine_partenza,
                ro.posti_disponibili,
                ro.prezzo_per_posto,
                ro.note,
                CONCAT(u.nome, ' ', LEFT(u.cognome,1), '.') AS driver_nome,
                u.foto_profilo      AS driver_foto,
                u.id                AS driver_id,
                ROUND(
                    6371 * 2 * ASIN(SQRT(
                        POW(SIN((ro.latitudine_partenza - :lat) * PI() / 360), 2)
                        + COS(:lat2 * PI()/180)
                        * COS(ro.latitudine_partenza * PI()/180)
                        * POW(SIN((ro.longitudine_partenza - :lon) * PI() / 360), 2)
                    )), 1
                ) AS distanza_km
            FROM ride_offers ro
            JOIN events e ON e.id  = ro.event_id
            JOIN users  u ON u.id  = ro.user_id
            $where
            HAVING distanza_km <= :raggio
            ORDER BY distanza_km ASC
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $risultati = $stmt->fetchAll();
        } catch (PDOException $e) {
            $errore = 'Errore nella ricerca. Riprova tra qualche momento.';
            error_log('[OnePassage Ricerca] ' . $e->getMessage());
        }
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

<main class="section-md">
<div class="container" style="max-width:900px;">

    <div class="search-page-header">
        <h1>Cerca un passaggio</h1>
        <p>Trova chi parte dalla tua zona per lo stesso evento.</p>
    </div>

    <!-- ── Filtri ── -->
    <div class="search-filters">
        <form method="get" id="searchForm">
            <input type="hidden" name="cerca" value="1">

            <div class="search-filters-grid">
                <!-- Evento -->
                <div class="search-filter-field">
                    <label for="q">Evento</label>
                    <input type="text" id="q" name="q"
                           value="<?= h($q) ?>"
                           placeholder="Nome evento, artista...">
                </div>

                <!-- Città di partenza — OBBLIGATORIO -->
                <div class="search-filter-field">
                    <label for="luogo_input">
                        La tua città di partenza
                        <span class="required-mark">*</span>
                    </label>
                    <div class="geo-field-wrap">
                        <input type="text" id="luogo_input" name="luogo"
                               value="<?= h($luogoNome) ?>"
                               placeholder="Es. Milano, Torino..."
                               autocomplete="off"
                               oninput="searchLuogo(this.value)"
                               required>
                        <button type="button" class="geo-btn" onclick="gpsRicerca()"
                                title="Usa la mia posizione">
                            <i class="fas fa-location-crosshairs"></i>
                        </button>
                        <div class="geo-dropdown" id="luogoDropdown"></div>
                    </div>
                    <input type="hidden" name="ulat" id="ulat" value="<?= h((string)($userLat ?? '')) ?>">
                    <input type="hidden" name="ulon" id="ulon" value="<?= h((string)($userLon ?? '')) ?>">
                </div>

                <!-- Data -->
                <div class="search-filter-field">
                    <label for="data">Data</label>
                    <input type="date" id="data" name="data"
                           value="<?= h($data) ?>"
                           min="<?= date('Y-m-d') ?>">
                </div>

                <!-- Raggio -->
                <div class="search-filter-field">
                    <label>
                        Raggio di ricerca
                        <strong id="raggioBadge"><?= $raggio ?> km</strong>
                    </label>
                    <input type="range" name="raggio" id="raggioSlider"
                           min="5" max="150" step="5" value="<?= $raggio ?>"
                           oninput="document.getElementById('raggioBadge').textContent=this.value+' km'">
                </div>
            </div>

            <?php if ($errore): ?>
            <div class="search-error">
                <i class="fas fa-exclamation-circle"></i> <?= h($errore) ?>
            </div>
            <?php endif; ?>

            <div class="search-filters-actions">
                <button type="submit" class="btn-primary">
                    Cerca passaggi
                </button>
                <a href="ricerca.php" class="btn-secondary">Azzera filtri</a>
            </div>
        </form>
    </div>

    <!-- ── Risultati ── -->
    <?php if (!$hasSearch && !$errore): ?>
        <div class="search-prompt">
            <p>Inserisci la tua città di partenza per trovare passaggi disponibili.</p>
        </div>

    <?php elseif ($hasSearch && empty($risultati)): ?>
        <div class="search-no-results">
            <strong>Nessun passaggio trovato</strong>
            <p>Nessun accompagnatore disponibile nel raggio di <?= $raggio ?> km da <?= h($luogoNome ?: 'te') ?>.<br>
               Prova ad aumentare il raggio o a modificare i filtri.</p>
            <?php if (isLoggedIn()): ?>
            <a href="offri_passaggio.php" class="btn-primary">Offri tu il passaggio</a>
            <?php endif; ?>
        </div>

    <?php elseif ($hasSearch): ?>
        <div class="search-results-header">
            <span><?= count($risultati) ?> passagg<?= count($risultati) == 1 ? 'io trovato' : 'i trovati' ?>
                  entro <?= $raggio ?> km<?= $luogoNome ? ' da ' . h($luogoNome) : '' ?></span>
        </div>

        <div class="rides-grid">
        <?php foreach ($risultati as $r):
            $dt      = $r['data_evento'] ? new DateTime($r['data_evento']) : null;
            $dataFmt = $dt ? $dt->format('d/m/Y') : '—';
            $oraFmt  = $dt ? $dt->format('H:i')   : '';
            $prezzo  = (float)$r['prezzo_per_posto'];
            $initials = strtoupper(substr($r['driver_nome'], 0, 1));
        ?>
        <div class="ride-result-card">
            <div class="rrc-left">
                <div class="rrc-event"><?= h($r['nome_evento']) ?></div>
                <div class="rrc-details">
                    <span><?= h($r['luogo'] ?? '') ?></span>
                    <span><?= $dataFmt ?><?= $oraFmt ? ' · ' . $oraFmt : '' ?></span>
                </div>
                <div class="rrc-departure">
                    Partenza: <strong><?= h($r['punto_partenza']) ?></strong>
                </div>
                <?php if ($r['note']): ?>
                <div class="rrc-note"><?= h($r['note']) ?></div>
                <?php endif; ?>
            </div>
            <div class="rrc-right">
                <div class="rrc-driver">
                    <?php if ($r['driver_foto']): ?>
                        <img src="uploads/<?= h($r['driver_foto']) ?>" class="rrc-avatar" alt="">
                    <?php else: ?>
                        <div class="rrc-avatar rrc-avatar-init"><?= $initials ?></div>
                    <?php endif; ?>
                    <span class="rrc-driver-name"><?= h($r['driver_nome']) ?></span>
                </div>
                <div class="rrc-meta">
                    <div class="rrc-stat">
                        <span class="rrc-stat-val"><?= $r['distanza_km'] ?></span>
                        <span class="rrc-stat-lbl">km da te</span>
                    </div>
                    <div class="rrc-stat">
                        <span class="rrc-stat-val"><?= $r['posti_disponibili'] ?></span>
                        <span class="rrc-stat-lbl">post<?= $r['posti_disponibili']==1?'o':'i' ?></span>
                    </div>
                    <div class="rrc-stat">
                        <span class="rrc-stat-val"><?= $prezzo > 0 ? '€'.number_format($prezzo,0) : 'Free' ?></span>
                        <span class="rrc-stat-lbl">a posto</span>
                    </div>
                </div>
                <?php if (isLoggedIn()): ?>
                <a href="richiedi_passaggio.php?offer=<?= $r['offer_id'] ?>" class="btn-primary rrc-cta">
                    Richiedi posto
                </a>
                <?php else: ?>
                <a href="auth.php" class="btn-secondary rrc-cta">Accedi per richiedere</a>
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
// ── Geocoding città utente ────────────────────────────────────
var _lt = null;
function searchLuogo(q) {
    clearTimeout(_lt);
    var dd = document.getElementById('luogoDropdown');
    if (q.length < 2) { dd.innerHTML=''; dd.classList.remove('open'); return; }
    _lt = setTimeout(function() {
        fetch('geocode_proxy.php?q=' + encodeURIComponent(q))
            .then(function(r){ return r.json(); })
            .then(function(d){ showGeoDD(d.features||[]); })
            .catch(function(){});
    }, 320);
}

function showGeoDD(features) {
    var dd = document.getElementById('luogoDropdown');
    dd.innerHTML = '';
    if (!features.length) { dd.classList.remove('open'); return; }
    features.slice(0,6).forEach(function(f) {
        var label = f.properties.label || f.properties.name || '';
        var item  = document.createElement('div');
        item.className   = 'geo-dd-item';
        item.textContent = label;
        item.addEventListener('click', function() {
            document.getElementById('luogo_input').value = label;
            document.getElementById('ulat').value = f.geometry.coordinates[1];
            document.getElementById('ulon').value = f.geometry.coordinates[0];
            dd.classList.remove('open');
        });
        dd.appendChild(item);
    });
    dd.classList.add('open');
}

function gpsRicerca() {
    var btn = document.querySelector('.geo-btn');
    if (!navigator.geolocation) return;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    navigator.geolocation.getCurrentPosition(function(pos) {
        document.getElementById('ulat').value = pos.coords.latitude;
        document.getElementById('ulon').value = pos.coords.longitude;
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i>';
        fetch('geocode_proxy.php?lat='+pos.coords.latitude+'&lon='+pos.coords.longitude)
            .then(function(r){ return r.json(); })
            .then(function(d){
                var f=(d.features||[])[0];
                if(f) document.getElementById('luogo_input').value = f.properties.label||f.properties.name||'';
            });
    }, function(){
        btn.innerHTML = '<i class="fas fa-location-crosshairs"></i>';
    });
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.geo-field-wrap'))
        document.getElementById('luogoDropdown').classList.remove('open');
});

// Valida che la città sia stata selezionata prima di inviare
document.getElementById('searchForm').addEventListener('submit', function(e) {
    var lat = document.getElementById('ulat').value;
    var lon = document.getElementById('ulon').value;
    if (!lat || !lon) {
        e.preventDefault();
        document.getElementById('luogo_input').focus();
        document.getElementById('luogo_input').classList.add('input-error');
        // Mostra hint
        var hint = document.getElementById('luogo_hint');
        if (!hint) {
            hint = document.createElement('div');
            hint.id = 'luogo_hint';
            hint.className = 'field-error-hint';
            hint.textContent = 'Seleziona una città dall\'elenco o usa il GPS';
            document.getElementById('luogo_input').parentNode.appendChild(hint);
        }
    }
});
document.getElementById('luogo_input').addEventListener('input', function() {
    // Resetta errore appena l'utente ridigita
    document.getElementById('ulat').value = '';
    document.getElementById('ulon').value = '';
    this.classList.remove('input-error');
    var hint = document.getElementById('luogo_hint');
    if (hint) hint.remove();
});

function toggleTheme() {
    var t=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',t);
    localStorage.setItem('theme',t);
}
</script>
</body>
</html>
