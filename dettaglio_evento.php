<?php
require_once 'config.php';

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
$stmt->execute([$eventId]);
$evento = $stmt->fetch();

if(!$evento) { header('Location: ricerca.php'); exit; }

$raggioKm = isset($_GET['raggio']) ? (int)$_GET['raggio']   : 50;
$minStelle = isset($_GET['stelle']) ? (float)$_GET['stelle'] : 0;

$stmt = $pdo->prepare("
    SELECT ro.id as offer_id, ro.user_id, ro.punto_partenza,
           ro.latitudine_partenza, ro.longitudine_partenza,
           ro.posti_disponibili, ro.prezzo_per_posto,
           u.nome, u.cognome, u.bio,
           COALESCE(AVG(r.stelle), 0) as media_stelle,
           COUNT(r.stelle) as num_recensioni
    FROM ride_offers ro
    JOIN users u ON ro.user_id = u.id
    LEFT JOIN ride_requests r ON u.id = r.driver_id AND r.stato = 'concluso' AND r.stelle IS NOT NULL
    WHERE ro.event_id = ? AND ro.posti_disponibili > 0
    GROUP BY ro.id, u.id, ro.punto_partenza, ro.latitudine_partenza, ro.longitudine_partenza, ro.posti_disponibili, ro.prezzo_per_posto, u.nome, u.cognome, u.bio
");
$stmt->execute([$eventId]);
$offerte = $stmt->fetchAll();

$offerte_filtrate = [];
foreach($offerte as $offerta) {
    $distanza = calcolaDistanza(
        $evento['latitudine'], $evento['longitudine'],
        $offerta['latitudine_partenza'], $offerta['longitudine_partenza']
    );
    if($distanza <= $raggioKm && $offerta['media_stelle'] >= $minStelle) {
        $offerta['distanza_km'] = round($distanza, 1);
        $offerte_filtrate[] = $offerta;
    }
}
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
    <title><?= h($evento['nome_evento']) ?> - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/dettaglio_evento.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'header_snippet.php'; ?>

    <div class="section-md">
        <div class="container">

            <!-- Event Hero -->
            <div class="card event-hero">
                <div class="event-hero-icon">
                    <i class="fas fa-music"></i>
                </div>
                <div class="event-hero-content">
                    <h1 class="event-hero-title"><?= h($evento['nome_evento']) ?></h1>
                    <div class="event-hero-meta">
                        <span class="event-hero-meta-item">
                            <i class="fas fa-map-marker-alt"></i><?= h($evento['luogo']) ?>
                        </span>
                        <span class="event-hero-meta-item">
                            <i class="fas fa-calendar"></i><?= date('d/m/Y', strtotime($evento['data_evento'])) ?>
                        </span>
                        <span class="event-hero-meta-item">
                            <i class="fas fa-clock"></i><?= date('H:i', strtotime($evento['data_evento'])) ?>
                        </span>
                    </div>
                    <?php if($evento['descrizione']): ?>
                    <p class="event-description"><?= h($evento['descrizione']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filters -->
            <div class="card filters-card">
                <div class="filters-title"><i class="fas fa-filter"></i> Filtra Accompagnatori</div>
                <form method="GET" action="">
                    <input type="hidden" name="id" value="<?= $eventId ?>">
                    <div class="filter-row">
                        <div class="filter-item">
                            <label>
                                Raggio massimo: <strong id="raggioValue"><?= $raggioKm ?> km</strong>
                            </label>
                            <input type="range" name="raggio" class="slider" min="5" max="100"
                                   value="<?= $raggioKm ?>" step="5"
                                   oninput="document.getElementById('raggioValue').textContent = this.value + ' km'">
                        </div>
                        <div class="filter-item">
                            <label>
                                Minimo stelle: <strong id="stelleValue"><?= $minStelle ?></strong>
                            </label>
                            <input type="range" name="stelle" class="slider" min="0" max="5"
                                   value="<?= $minStelle ?>" step="0.5"
                                   oninput="document.getElementById('stelleValue').textContent = this.value">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary" style="padding: 12px 28px; font-size: 15px;">
                        <i class="fas fa-check"></i> Applica Filtri
                    </button>
                </form>
            </div>

            <!-- Drivers -->
            <div class="section-heading">
                <i class="fas fa-users"></i>
                Accompagnatori Disponibili
                <span style="font-size: 16px; color: var(--color-text-muted); font-weight: 400;">(<?= count($offerte_filtrate) ?>)</span>
            </div>

            <?php if(empty($offerte_filtrate)): ?>
            <div class="card empty-state">
                <div class="empty-state-icon"><i class="fas fa-user-slash"></i></div>
                <h3>Nessun accompagnatore disponibile</h3>
                <p>Prova a modificare i filtri o torna più tardi.</p>
            </div>
            <?php else: ?>

            <?php foreach($offerte_filtrate as $offerta): ?>
            <div class="card driver-card">
                <a href="profilo.php?id=<?= $offerta['user_id'] ?>" class="driver-card-avatar">
                    <?= strtoupper(substr($offerta['nome'], 0, 1)) ?>
                </a>
                <div class="driver-card-info">
                    <a href="profilo.php?id=<?= $offerta['user_id'] ?>" class="driver-card-name">
                        <?= h($offerta['nome']) ?> <?= h(substr($offerta['cognome'], 0, 1)) ?>.
                    </a>
                    <div class="driver-card-rating">
                        <span class="stars">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                                <i class="fas fa-star<?= $i <= round($offerta['media_stelle']) ? '' : '-o' ?>"></i>
                            <?php endfor; ?>
                        </span>
                        <strong><?= number_format($offerta['media_stelle'], 1) ?>/5</strong>
                        <span>· <?= $offerta['num_recensioni'] ?> recensioni</span>
                    </div>
                    <div class="driver-card-details">
                        <span><i class="fas fa-map-marker-alt"></i> Parte da: <?= h($offerta['punto_partenza']) ?></span>
                        <span><i class="fas fa-route"></i> <?= $offerta['distanza_km'] ?> km dall'evento</span>
                    </div>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <span class="badge badge-success">
                            <i class="fas fa-chair"></i> <?= $offerta['posti_disponibili'] ?> posti
                        </span>
                        <span class="badge badge-warning">
                            <i class="fas fa-euro-sign"></i> €<?= number_format($offerta['prezzo_per_posto'], 2) ?>/posto
                        </span>
                    </div>
                </div>
                <div class="driver-card-action">
                    <?php if(isLoggedIn()): ?>
                    <a href="richiedi_passaggio.php?offer=<?= $offerta['offer_id'] ?>" class="btn-primary" style="padding: 12px 24px; font-size: 14px; white-space: nowrap;">
                        <i class="fas fa-hand-point-up"></i> Richiedi
                    </a>
                    <?php else: ?>
                    <a href="auth.php" class="btn-secondary" style="padding: 12px 24px; font-size: 14px; white-space: nowrap;">
                        Accedi per richiedere
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php endif; ?>

            <!-- Offer CTA -->
            <?php if(isLoggedIn()): ?>
            <div class="card offer-cta">
                <h3>Stai andando a questo evento?</h3>
                <a href="offri_passaggio.php?event=<?= $eventId ?>" class="btn-primary" style="font-size: 17px; padding: 16px 40px;">
                    <i class="fas fa-car"></i> Offri un Passaggio
                </a>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <footer class="footer">
        <div class="footer-content">
            <p>&copy; 2026 OnePassage. Viaggia insieme, risparmia e socializza.</p>
        </div>
    </footer>

    <script>
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
</body>
</html>