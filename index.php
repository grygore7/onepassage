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
    <link rel="stylesheet" href="css/index.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

<!-- ══════════════════════════════════════════════
     HERO
══════════════════════════════════════════════ -->
<section class="hero-section">
    <div class="hero-bg-shapes">
        <div class="hero-shape hero-shape--1"></div>
        <div class="hero-shape hero-shape--2"></div>
    </div>
    <div class="container hero-inner">
        <div class="hero-text">
            <div class="hero-eyebrow">🎵 Concerti · Festival · Sport</div>
            <h1 class="hero-title">
                Il passaggio intelligente<br>
                <span class="hero-accent">per i tuoi eventi</span>
            </h1>
            <p class="hero-subtitle">
                Trova chi parte dalla tua zona o condividi la tua auto.<br>
                Zero stress, più risparmio, nuove amicizie.
            </p>
            <div class="hero-ctas">
                <a href="ricerca.php" class="btn-primary hero-cta-main">
                    <i class="fas fa-search"></i> Cerca un passaggio
                </a>
                <?php if (isLoggedIn()): ?>
                <a href="offri_passaggio.php" class="btn-secondary">
                    <i class="fas fa-car"></i> Offri passaggio
                </a>
                <?php else: ?>
                <a href="auth.php" class="btn-secondary">
                    <i class="fas fa-user-plus"></i> Registrati gratis
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-stats">
            <div class="hero-stat-card">
                <div class="hero-stat-icon"><i class="fas fa-users"></i></div>
                <div class="hero-stat-num">+1.200</div>
                <div class="hero-stat-label">Utenti attivi</div>
            </div>
            <div class="hero-stat-card">
                <div class="hero-stat-icon"><i class="fas fa-car"></i></div>
                <div class="hero-stat-num">+800</div>
                <div class="hero-stat-label">Passaggi offerti</div>
            </div>
            <div class="hero-stat-card">
                <div class="hero-stat-icon"><i class="fas fa-music"></i></div>
                <div class="hero-stat-num">+250</div>
                <div class="hero-stat-label">Eventi coperti</div>
            </div>
        </div>
    </div>
</section>

<!-- ══════════════════════════════════════════════
     EVENTI IN EVIDENZA
══════════════════════════════════════════════ -->
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
                $dataFmt = $ev['data_evento']
                    ? (new DateTime($ev['data_evento']))->format('d M Y')
                    : 'Data TBD';
                $giorno  = $ev['data_evento']
                    ? (new DateTime($ev['data_evento']))->format('d')
                    : '--';
                $mese    = $ev['data_evento']
                    ? strtoupper((new DateTime($ev['data_evento']))->format('M'))
                    : '---';
                $prezzoLabel = $ev['prezzo_min'] > 0
                    ? 'da €'.number_format($ev['prezzo_min'],0)
                    : 'Gratuito';
            ?>
            <a href="ricerca.php?q=<?= urlencode($ev['nome_evento']) ?>" class="event-home-card">
                <div class="event-home-date">
                    <span class="event-home-day"><?= $giorno ?></span>
                    <span class="event-home-month"><?= $mese ?></span>
                </div>
                <div class="event-home-body">
                    <div class="event-home-title"><?= h($ev['nome_evento']) ?></div>
                    <div class="event-home-meta">
                        <span><i class="fas fa-map-marker-alt"></i> <?= h($ev['luogo']) ?></span>
                    </div>
                    <div class="event-home-chips">
                        <span class="dash-chip dash-chip--green">
                            <i class="fas fa-car"></i> <?= $ev['num_autisti'] ?> autist<?= $ev['num_autisti']==1?'a':'i' ?>
                        </span>
                        <span class="dash-chip dash-chip--<?= $ev['prezzo_min'] > 0 ? 'amber' : 'green' ?>">
                            <i class="fas fa-euro-sign"></i> <?= $prezzoLabel ?>
                        </span>
                    </div>
                </div>
                <div class="event-home-arrow"><i class="fas fa-chevron-right"></i></div>
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
            <div class="how-connector"><i class="fas fa-arrow-right"></i></div>
            <div class="how-step">
                <div class="how-step-num">02</div>
                <div class="how-step-icon"><i class="fas fa-comments"></i></div>
                <h3>Connettiti e chatta</h3>
                <p>Richiedi un posto libero. La chat cifrata end-to-end ti permette di coordinare orari, punti di ritrovo e dettagli.</p>
            </div>
            <div class="how-connector"><i class="fas fa-arrow-right"></i></div>
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
function toggleTheme() {
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
}
</script>
</body>
</html>
