<?php
require_once 'config.php';

// ── 6 eventi in evidenza (maggior numero di autisti disponibili) ──
$stmt = $pdo->prepare("
    SELECT
        e.id,
        e.nome_evento,
        e.luogo,
        e.data_evento,
        e.latitudine,
        e.longitudine,
        COUNT(ro.id)   AS num_autisti,
        SUM(ro.posti_disponibili) AS posti_totali,
        MIN(ro.prezzo_per_posto)  AS prezzo_min
    FROM events e
    JOIN ride_offers ro ON ro.event_id = e.id
    WHERE e.approvato  = 1
      AND e.data_evento >= NOW()
      AND ro.posti_disponibili > 0
    GROUP BY e.id
    ORDER BY num_autisti DESC, e.data_evento ASC
    LIMIT 6
");
$stmt->execute();
$eventiEvidenza = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OnePassage — Il passaggio intelligente per i tuoi eventi</title>
    <meta name="description" content="Trova o offri un passaggio per concerti, festival ed eventi sportivi. Connettiti con chi parte dalla tua zona.">
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/style-home.css">
    <link rel="stylesheet" href="css/index.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

<!-- ══════════════════════════════════════════════
     HERO — parallax + search bar centrata
══════════════════════════════════════════════ -->
<section class="hero" id="hero">
    <div class="hero-content">
        <div class="hero-eyebrow">🎵 Concerti · Festival · Sport</div>
        <h1 class="hero-title" id="heroTitle">Dove vuoi andare?</h1>

        <!-- Search bar orizzontale centrata -->
        <form class="search-bar" id="searchBar" method="get" action="ricerca.php">
            <div class="search-field">
                <input type="text" class="search-input" name="q"
                       placeholder="Evento, artista, squadra..."
                       autocomplete="off">
            </div>
            <div class="search-separator"></div>
            <div class="search-field">
                <input type="text" class="search-input" name="luogo"
                       id="heroLuogo" placeholder="Città di partenza"
                       autocomplete="off">
                <input type="hidden" name="ulat" id="heroUlat">
                <input type="hidden" name="ulon" id="heroUlon">
            </div>
            <div class="search-separator"></div>
            <div class="search-field">
                <input type="date" class="search-input" name="data"
                       min="<?= date('Y-m-d') ?>">
            </div>
            <button type="submit" class="search-button">
                <i class="fas fa-search"></i> Cerca
            </button>
        </form>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     BLOCCO UNICO: EVENTI + COME FUNZIONA + TRUST
     Sfondo continuo, zero divisioni visibili
══════════════════════════════════════════════ -->
<div class="home-content-block">

<section class="section-md">
    <div class="container">
        <div class="section-header">
            <div>
                <h2 class="section-title">Eventi in evidenza</h2>
                <p class="section-sub">I 6 eventi con più autisti disponibili al momento</p>
            </div>
            <a href="ricerca.php" class="btn-secondary">
                <i class="fas fa-th-list"></i> Vedi tutti
            </a>
        </div>

        <?php if (empty($eventiEvidenza)): ?>
        <div class="home-empty-events">
            <i class="fas fa-calendar-times"></i>
            <p>Nessun evento disponibile al momento.<br>
               <a href="<?= isLoggedIn() ? 'offri_passaggio.php' : 'auth.php' ?>">Sii il primo a offrire un passaggio!</a>
            </p>
        </div>
        <?php else: ?>
        <div class="events-home-grid">
            <?php foreach ($eventiEvidenza as $ev):
                $giorno = $ev['data_evento'] ? (new DateTime($ev['data_evento']))->format('d') : '--';
                $mese   = $ev['data_evento'] ? strtoupper((new DateTime($ev['data_evento']))->format('M')) : '---';
                $prezzoLabel = $ev['prezzo_min'] > 0 ? 'da €'.number_format($ev['prezzo_min'],0) : 'Gratuito';
            ?>
            <a href="ricerca.php?q=<?= urlencode($ev['nome_evento']) ?>" class="event-home-card">
                <div class="event-home-card-top">
                    <div class="event-home-title"><?= h($ev['nome_evento']) ?></div>
                    <div class="event-home-date-badge">
                        <span class="event-home-day"><?= $giorno ?></span>
                        <span class="event-home-month"><?= $mese ?></span>
                    </div>
                </div>
                <div class="event-home-venue"><?= h($ev['luogo']) ?></div>
                <div class="event-home-footer">
                    <span class="event-home-drivers">
                        <?= $ev['num_autisti'] ?> autist<?= $ev['num_autisti']==1?'a':'i' ?> disponibil<?= $ev['num_autisti']==1?'e':'i' ?>
                    </span>
                    <span class="event-home-price"><?= $prezzoLabel ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     COME FUNZIONA
══════════════════════════════════════════════ -->
<section class="section-md how-section">
    <div class="container">
        <div class="section-header" style="margin-bottom:48px;">
            <div>
                <h2 class="section-title">Come funziona</h2>
                <p class="section-sub">Tre passi per non perdere mai più un evento per colpa della logistica</p>
            </div>
        </div>

        <div class="how-grid">
            <div class="how-step">
                <div class="how-step-num">01</div>
                <div class="how-step-icon"><i class="fas fa-search"></i></div>
                <h3>Cerca il tuo evento</h3>
                <p>Inserisci il nome del concerto o festival. Filtra per la tua città di partenza e imposta il raggio di ricerca in km.</p>
            </div>
            <div class="how-step">
                <div class="how-step-num">02</div>
                <div class="how-step-icon"><i class="fas fa-comments"></i></div>
                <h3>Connettiti e chatta</h3>
                <p>Richiedi un posto libero. La chat cifrata end-to-end ti permette di coordinare orari, punti di ritrovo e dettagli.</p>
            </div>
            <div class="how-step">
                <div class="how-step-num">03</div>
                <div class="how-step-icon"><i class="fas fa-star"></i></div>
                <h3>Vai e lascia una recensione</h3>
                <p>Confermate il passaggio quando siete d'accordo. Dopo l'evento lasciate una valutazione reciproca per costruire fiducia.</p>
            </div>
        </div>

        <!-- Per gli autisti -->
        <div class="how-driver-cta">
            <div class="how-driver-text">
                <h3>Hai un posto libero in macchina?</h3>
                <p>Pubblica il tuo passaggio in 2 minuti. Indica da dove parti e per quale evento — i passeggeri ti troveranno.</p>
            </div>
            <a href="<?= isLoggedIn() ? 'offri_passaggio.php' : 'auth.php' ?>" class="btn-primary">
                <i class="fas fa-car"></i> Offri un passaggio
            </a>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     TRUST BAR
══════════════════════════════════════════════ -->
<section class="trust-section">
    <div class="container trust-grid">
        <div class="trust-item">
            <i class="fas fa-lock"></i>
            <span>Chat cifrata end-to-end</span>
        </div>
        <div class="trust-item">
            <i class="fas fa-star"></i>
            <span>Recensioni bidirezionali</span>
        </div>
        <div class="trust-item">
            <i class="fas fa-shield-alt"></i>
            <span>Sistema di segnalazioni</span>
        </div>
        <div class="trust-item">
            <i class="fas fa-mobile-alt"></i>
            <span>100% mobile-friendly</span>
        </div>
    </div>
</section>

</div><!-- /home-content-block -->

<?php if (isLoggedIn()): ?>
<a href="offri_passaggio.php" class="mobile-fab">
    <i class="fas fa-car"></i> Offri Passaggio
</a>
<?php endif; ?>

<footer class="footer">
    <div class="container">
        <div class="footer-inner">
            <span class="logo">OnePassage</span>
            <span style="color:var(--color-text-muted);font-size:13px;">
                Il passaggio intelligente per i tuoi eventi
            </span>
        </div>
    </div>
</footer>

<script>
// ── Tema ─────────────────────────────────────────────────────
function toggleTheme() {
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
}

// ── Hero: entry animation ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        var title = document.getElementById('heroTitle');
        var bar   = document.getElementById('searchBar');
        if (title) title.classList.add('animate');
        if (bar)   bar.classList.add('animate');
    }, 80);
});



// ── Geocoding campo città nella search bar ────────────────────
var _heroLuogoTimer = null;
var heroLuogoInput  = document.getElementById('heroLuogo');
var heroLuogoDD     = null;

if (heroLuogoInput) {
    // Crea dropdown autocomplete
    heroLuogoDD = document.createElement('div');
    heroLuogoDD.className = 'hero-search-dropdown';
    heroLuogoInput.parentNode.style.position = 'relative';
    heroLuogoInput.parentNode.appendChild(heroLuogoDD);

    heroLuogoInput.addEventListener('input', function () {
        var q = this.value.trim();
        clearTimeout(_heroLuogoTimer);
        if (q.length < 2) { heroLuogoDD.style.display = 'none'; return; }
        _heroLuogoTimer = setTimeout(function () {
            fetch('geocode_proxy.php?q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) { showHeroGeoDD(data.features || []); })
                .catch(function () {});
        }, 320);
    });

    // GPS button
    heroLuogoInput.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') heroLuogoDD.style.display = 'none';
    });
}

function showHeroGeoDD(features) {
    if (!heroLuogoDD) return;
    if (!features.length) { heroLuogoDD.style.display = 'none'; return; }
    heroLuogoDD.innerHTML = '';
    features.slice(0, 5).forEach(function (f) {
        var label = f.properties.label || f.properties.name || '';
        var item  = document.createElement('div');
        item.className   = 'hero-search-dd-item';
        item.textContent = label;
        item.addEventListener('click', function () {
            heroLuogoInput.value = label;
            document.getElementById('heroUlat').value = f.geometry.coordinates[1];
            document.getElementById('heroUlon').value = f.geometry.coordinates[0];
            heroLuogoDD.style.display = 'none';
        });
        heroLuogoDD.appendChild(item);
    });
    heroLuogoDD.style.display = 'block';
}

document.addEventListener('click', function (e) {
    if (heroLuogoDD && !e.target.closest('#heroLuogo') && !e.target.closest('.hero-search-dropdown')) {
        heroLuogoDD.style.display = 'none';
    }
});
</script>
</body>
</html>
