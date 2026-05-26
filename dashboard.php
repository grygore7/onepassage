<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: auth.php');
    exit;
}

$userId = $_SESSION['user_id'];

// Query richieste inviate dall'utente
$stmt = $pdo->prepare("
    SELECT
        rr.*,
        e.nome_evento,
        e.data_evento,
        e.luogo,
        u.nome AS driver_nome,
        u.cognome AS driver_cognome,
        ro.prezzo_per_posto
    FROM ride_requests rr
    JOIN ride_offers ro ON rr.offer_id = ro.id
    JOIN events e ON ro.event_id = e.id
    JOIN users u ON rr.driver_id = u.id
    WHERE rr.user_id = ?
    ORDER BY rr.created_at DESC
");
$stmt->execute([$userId]);
$richieste_inviate = $stmt->fetchAll();

// Query offerte gestite (richieste ricevute dall'utente come driver)
$stmt = $pdo->prepare("
    SELECT
        rr.*,
        e.nome_evento,
        e.data_evento,
        e.luogo,
        u.nome AS passenger_nome,
        u.cognome AS passenger_cognome,
        ro.prezzo_per_posto,
        ro.posti_disponibili
    FROM ride_requests rr
    JOIN ride_offers ro ON rr.offer_id = ro.id
    JOIN events e ON ro.event_id = e.id
    JOIN users u ON rr.user_id = u.id
    WHERE rr.driver_id = ?
    ORDER BY rr.created_at DESC
");
$stmt->execute([$userId]);
$richieste_ricevute_raw = $stmt->fetchAll();

// Query passaggi offerti (le mie offerte come autista)
$stmt = $pdo->prepare("
    SELECT
        ro.*,
        e.nome_evento,
        e.data_evento,
        e.luogo,
        (SELECT COUNT(*) FROM ride_requests rr WHERE rr.offer_id = ro.id AND rr.stato = 'accettato') AS posti_occupati,
        (SELECT COUNT(*) FROM ride_requests rr WHERE rr.offer_id = ro.id AND rr.stato = 'in_attesa') AS richieste_pendenti
    FROM ride_offers ro
    JOIN events e ON ro.event_id = e.id
    WHERE ro.user_id = ?
    ORDER BY e.data_evento ASC
");
$stmt->execute([$userId]);
$mie_offerte = $stmt->fetchAll();

// Separa richieste in attesa, passaggi confermati e storico
$now = time();
$richieste_in_attesa = array_filter(
    $richieste_inviate,
    fn($r) => $r['stato'] === 'in_attesa' && strtotime($r['data_evento']) >= $now
);
$mie_passaggi = array_filter(
    $richieste_inviate,
    fn($r) => $r['stato'] === 'accettato' && strtotime($r['data_evento']) >= $now
);
$richieste_inviate_passate = array_filter(
    $richieste_inviate,
    fn($r) => strtotime($r['data_evento']) < $now || in_array($r['stato'], ['rifiutato', 'concluso'], true)
);
$richieste_ricevute = array_filter(
    $richieste_ricevute_raw,
    fn($r) => strtotime($r['data_evento']) >= $now || in_array($r['stato'], ['in_attesa', 'accettato'], true)
);
$richieste_ricevute_passate = array_filter(
    $richieste_ricevute_raw,
    fn($r) => strtotime($r['data_evento']) < $now && !in_array($r['stato'], ['in_attesa', 'accettato'], true)
);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - OnePassage</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

<div class="dash-page">
    <div class="dash-hero">
        <div class="dash-hero-inner container">
            <div class="dash-hero-text">
                <p class="dash-greeting">Bentornato,</p>
                <h1 class="dash-name"><?= h($_SESSION['user_nome'] ?? '') ?></h1>
            </div>
            <div class="dash-stats">
                <div class="dash-stat">
                    <span class="dash-stat-num"><?= count($richieste_in_attesa) ?></span>
                    <span class="dash-stat-label">Richieste attive</span>
                </div>
                <div class="dash-stat-divider"></div>
                <div class="dash-stat">
                    <span class="dash-stat-num"><?= count($mie_passaggi) ?></span>
                    <span class="dash-stat-label">Passaggi confermati</span>
                </div>
                <div class="dash-stat-divider"></div>
                <div class="dash-stat">
                    <span class="dash-stat-num"><?= count($richieste_ricevute) ?></span>
                    <span class="dash-stat-label">Offerte gestite</span>
                </div>
            </div>
            <a href="offri_passaggio.php" class="dash-hero-cta">
                <i class="fas fa-plus"></i> Offri Passaggio
            </a>
        </div>
    </div>

    <div class="container dash-body">
        <div class="dash-panel">
            <div class="tabs" id="mainTabs">
                <button class="tab active" data-tab="richieste" onclick="switchTab('richieste')">
                    <i class="fas fa-paper-plane"></i> Richieste Inviate
                    <?php $nFut = count($richieste_in_attesa); if ($nFut > 0): ?>
                    <span class="badge badge-pending"><?= $nFut ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab" data-tab="passaggi" onclick="switchTab('passaggi')">
                    <i class="fas fa-ticket-alt"></i> I miei passaggi
                    <?php $nPassaggi = count($mie_passaggi); if ($nPassaggi > 0): ?>
                    <span class="badge badge-success"><?= $nPassaggi ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab" data-tab="offerte" onclick="switchTab('offerte')">
                    <i class="fas fa-car"></i> Offerte Gestite
                    <?php $nOff = count($richieste_ricevute); if ($nOff > 0): ?>
                    <span class="badge badge-success"><?= $nOff ?></span>
                    <?php endif; ?>
                </button>
                <button class="tab" data-tab="passati" onclick="switchTab('passati')">
                    <i class="fas fa-history"></i> Storico
                    <?php $nPast = count($richieste_inviate_passate) + count($richieste_ricevute_passate); if ($nPast > 0): ?>
                    <span class="badge badge-pending"><?= $nPast ?></span>
                    <?php endif; ?>
                </button>
            </div>
            <div class="dash-panel-divider"></div>

            <div class="dash-tab-stage">
                <div class="dash-toolbar">
                    <div class="dash-search-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" id="dashSearch" class="dash-search"
                               placeholder="Cerca nelle tue richieste e offerte..."
                               oninput="filterCards(this.value)">
                    </div>
                </div>

                <div class="tab-content-grid">
                    <div id="richieste-content" class="tab-content active">
                        <?php if (empty($richieste_in_attesa)): ?>
                        <div class="dash-empty">
                            <div class="dash-empty-icon"><i class="fas fa-paper-plane"></i></div>
                            <h3>Nessuna richiesta attiva</h3>
                            <p>Sfoglia gli eventi e richiedi un passaggio per iniziare!</p>
                            <a href="ricerca.php" class="dash-btn dash-btn--primary" style="margin-top:8px">
                                <i class="fas fa-search"></i> Trova evento
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="dash-cards">
                        <?php foreach ($richieste_in_attesa as $richiesta): ?>
                            <div class="dash-card" data-search="<?= strtolower(h($richiesta['nome_evento']) . ' ' . h($richiesta['driver_nome']) . ' ' . h($richiesta['luogo'])) ?>">
                                <div class="dash-card-inner">
                                    <div class="dash-card-accent dash-card-accent--blue"></div>
                                    <div class="dash-card-body">
                                        <div class="dash-card-info">
                                            <div class="dash-card-top">
                                                <span class="dash-card-title"><?= h($richiesta['nome_evento']) ?></span>
                                                <span class="dash-chip dash-chip--blue">
                                                    <i class="fas fa-clock"></i> In Attesa
                                                </span>
                                            </div>
                                            <div class="dash-card-meta">
                                                <span class="dash-meta-item"><i class="fas fa-user"></i> <?= h($richiesta['driver_nome']) ?> <?= h(substr($richiesta['driver_cognome'], 0, 1)) ?>.</span>
                                                <span class="dash-meta-item"><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($richiesta['data_evento'])) ?></span>
                                                <span class="dash-meta-item"><i class="fas fa-map-marker-alt"></i> <?= h($richiesta['luogo']) ?></span>
                                            </div>
                                            <div class="dash-card-chips">
                                                <span class="dash-chip dash-chip--amber"><i class="fas fa-euro-sign"></i> &euro;<?= number_format((float)$richiesta['prezzo_per_posto'], 2) ?></span>
                                            </div>
                                        </div>
                                        <div class="dash-card-actions">
                                            <a href="profilo.php?id=<?= (int)$richiesta['driver_id'] ?>" class="dash-btn dash-btn--secondary">
                                                <i class="fas fa-user"></i> Profilo
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="passaggi-content" class="tab-content">
                        <?php if (empty($mie_passaggi)): ?>
                        <div class="dash-empty">
                            <div class="dash-empty-icon"><i class="fas fa-ticket-alt"></i></div>
                            <h3>Nessun passaggio confermato</h3>
                            <p>Quando un autista accetta una tua richiesta, il viaggio apparira' qui.</p>
                            <a href="ricerca.php" class="dash-btn dash-btn--primary" style="margin-top:8px">
                                <i class="fas fa-search"></i> Trova passaggio
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="dash-cards">
                        <?php foreach ($mie_passaggi as $richiesta): ?>
                            <div class="dash-card" data-search="<?= strtolower(h($richiesta['nome_evento']) . ' ' . h($richiesta['driver_nome']) . ' ' . h($richiesta['luogo'])) ?>">
                                <div class="dash-card-inner">
                                    <div class="dash-card-accent dash-card-accent--green"></div>
                                    <div class="dash-card-body">
                                        <div class="dash-card-info">
                                            <div class="dash-card-top">
                                                <span class="dash-card-title"><?= h($richiesta['nome_evento']) ?></span>
                                                <span class="dash-chip dash-chip--green">
                                                    <i class="fas fa-check-circle"></i> Confermato
                                                </span>
                                            </div>
                                            <div class="dash-card-meta">
                                                <span class="dash-meta-item"><i class="fas fa-user"></i> <?= h($richiesta['driver_nome']) ?> <?= h(substr($richiesta['driver_cognome'], 0, 1)) ?>.</span>
                                                <span class="dash-meta-item"><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($richiesta['data_evento'])) ?></span>
                                                <span class="dash-meta-item"><i class="fas fa-map-marker-alt"></i> <?= h($richiesta['luogo']) ?></span>
                                            </div>
                                            <div class="dash-card-chips">
                                                <span class="dash-chip dash-chip--amber"><i class="fas fa-euro-sign"></i> &euro;<?= number_format((float)$richiesta['prezzo_per_posto'], 2) ?></span>
                                            </div>
                                        </div>
                                        <div class="dash-card-actions">
                                            <a href="chat.php?request=<?= (int)$richiesta['id'] ?>" class="dash-btn dash-btn--primary">
                                                <i class="fas fa-comments"></i> Chat
                                            </a>
                                            <a href="profilo.php?id=<?= (int)$richiesta['driver_id'] ?>" class="dash-btn dash-btn--secondary">
                                                <i class="fas fa-user"></i> Profilo
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="offerte-content" class="tab-content">
                        <?php if (empty($richieste_ricevute)): ?>
                        <div class="dash-empty">
                            <div class="dash-empty-icon"><i class="fas fa-car"></i></div>
                            <h3>Nessuna offerta attiva</h3>
                            <p>Offri un passaggio per un evento e inizia a ricevere richieste!</p>
                            <a href="offri_passaggio.php" class="dash-btn dash-btn--primary" style="margin-top:8px">
                                <i class="fas fa-plus"></i> Offri Passaggio
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="dash-cards">
                        <?php foreach ($richieste_ricevute as $richiesta): ?>
                            <?php
                            switch ($richiesta['stato']) {
                                case 'in_attesa': $accentCol = 'blue'; $chipCol = 'blue'; $badgeIcon = 'clock'; $statoText = 'In Attesa'; break;
                                case 'accettato': $accentCol = 'green'; $chipCol = 'green'; $badgeIcon = 'check-circle'; $statoText = 'Accettato'; break;
                                case 'rifiutato': $accentCol = 'red'; $chipCol = 'red'; $badgeIcon = 'times-circle'; $statoText = 'Rifiutato'; break;
                                case 'concluso': $accentCol = 'gray'; $chipCol = 'gray'; $badgeIcon = 'flag-checkered'; $statoText = 'Concluso'; break;
                                default: $accentCol = 'blue'; $chipCol = 'blue'; $badgeIcon = 'circle'; $statoText = ucfirst($richiesta['stato']);
                            }
                            ?>
                            <div class="dash-card" data-search="<?= strtolower(h($richiesta['nome_evento']) . ' ' . h($richiesta['passenger_nome']) . ' ' . h($richiesta['luogo'])) ?>">
                                <div class="dash-card-inner">
                                    <div class="dash-card-accent dash-card-accent--<?= $accentCol ?>"></div>
                                    <div class="dash-card-body">
                                        <div class="dash-card-info">
                                            <div class="dash-card-top">
                                                <span class="dash-card-title"><?= h($richiesta['nome_evento']) ?></span>
                                                <span class="dash-chip dash-chip--<?= $chipCol ?>">
                                                    <i class="fas fa-<?= $badgeIcon ?>"></i> <?= $statoText ?>
                                                </span>
                                            </div>
                                            <div class="dash-card-meta">
                                                <span class="dash-meta-item"><i class="fas fa-user"></i> <?= h($richiesta['passenger_nome']) ?> <?= h(substr($richiesta['passenger_cognome'], 0, 1)) ?>.</span>
                                                <span class="dash-meta-item"><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($richiesta['data_evento'])) ?></span>
                                                <span class="dash-meta-item"><i class="fas fa-map-marker-alt"></i> <?= h($richiesta['luogo']) ?></span>
                                            </div>
                                            <div class="dash-card-chips">
                                                <span class="dash-chip dash-chip--amber"><i class="fas fa-euro-sign"></i> &euro;<?= number_format((float)$richiesta['prezzo_per_posto'], 2) ?></span>
                                                <span class="dash-chip dash-chip--blue"><i class="fas fa-chair"></i> <?= (int)$richiesta['posti_disponibili'] ?> posti liberi</span>
                                            </div>
                                        </div>
                                        <div class="dash-card-actions">
                                            <?php if ($richiesta['stato'] === 'in_attesa'): ?>
                                            <a href="gestisci_richiesta.php?id=<?= (int)$richiesta['id'] ?>&action=accetta" class="dash-btn dash-btn--primary" onclick="return confirm('Accettare?')">
                                                <i class="fas fa-check"></i> Accetta
                                            </a>
                                            <a href="gestisci_richiesta.php?id=<?= (int)$richiesta['id'] ?>&action=rifiuta" class="dash-btn dash-btn--secondary" onclick="return confirm('Rifiutare?')">
                                                <i class="fas fa-times"></i> Rifiuta
                                            </a>
                                            <?php elseif (in_array($richiesta['stato'], ['accettato', 'concluso'], true)): ?>
                                            <a href="chat.php?request=<?= (int)$richiesta['id'] ?>" class="dash-btn dash-btn--primary">
                                                <i class="fas fa-comments"></i> Chat
                                            </a>
                                            <?php endif; ?>
                                            <a href="profilo.php?id=<?= (int)$richiesta['user_id'] ?>" class="dash-btn dash-btn--secondary">
                                                <i class="fas fa-user"></i> Profilo
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div id="passati-content" class="tab-content">
                        <?php
                        $tuttiPassati = array_merge(
                            array_map(fn($r) => $r + ['_tipo' => 'inviata'], $richieste_inviate_passate),
                            array_map(fn($r) => $r + ['_tipo' => 'ricevuta'], $richieste_ricevute_passate)
                        );
                        usort($tuttiPassati, fn($a, $b) => strtotime($b['data_evento']) - strtotime($a['data_evento']));
                        ?>
                        <?php if (empty($tuttiPassati)): ?>
                        <div class="dash-empty">
                            <div class="dash-empty-icon"><i class="fas fa-history"></i></div>
                            <h3>Nessun evento passato</h3>
                            <p>Le corse completate appariranno qui nel tempo.</p>
                        </div>
                        <?php else: ?>
                        <div class="dash-cards">
                        <?php foreach ($tuttiPassati as $richiesta): ?>
                            <?php
                            $isSent = $richiesta['_tipo'] === 'inviata';
                            switch ($richiesta['stato']) {
                                case 'concluso': $accentCol = 'gray'; $chipCol = 'gray'; $badgeIcon = 'flag-checkered'; $statoText = 'Concluso'; break;
                                case 'rifiutato': $accentCol = 'red'; $chipCol = 'red'; $badgeIcon = 'times-circle'; $statoText = 'Rifiutato'; break;
                                default: $accentCol = 'blue'; $chipCol = 'blue'; $badgeIcon = 'check-circle'; $statoText = ucfirst($richiesta['stato']);
                            }
                            $altroNome = $isSent
                                ? h($richiesta['driver_nome']) . ' ' . h(substr($richiesta['driver_cognome'], 0, 1)) . '.'
                                : h($richiesta['passenger_nome']) . ' ' . h(substr($richiesta['passenger_cognome'], 0, 1)) . '.';
                            ?>
                            <div class="dash-card dash-card--past" data-search="<?= strtolower(h($richiesta['nome_evento']) . ' ' . h($richiesta['luogo'])) ?>">
                                <div class="dash-card-inner">
                                    <div class="dash-card-accent dash-card-accent--<?= $accentCol ?>"></div>
                                    <div class="dash-card-body">
                                        <div class="dash-card-info">
                                            <div class="dash-card-top">
                                                <span class="dash-card-title"><?= h($richiesta['nome_evento']) ?></span>
                                                <span class="dash-chip dash-chip--<?= $chipCol ?>">
                                                    <i class="fas fa-<?= $badgeIcon ?>"></i> <?= $statoText ?>
                                                </span>
                                                <span class="dash-chip dash-chip--gray" style="font-size:11px">
                                                    <?= $isSent ? 'Inviata' : 'Ricevuta' ?>
                                                </span>
                                            </div>
                                            <div class="dash-card-meta">
                                                <span class="dash-meta-item"><i class="fas fa-user"></i> <?= $altroNome ?></span>
                                                <span class="dash-meta-item"><i class="fas fa-calendar"></i> <?= date('d/m/Y', strtotime($richiesta['data_evento'])) ?></span>
                                                <span class="dash-meta-item"><i class="fas fa-map-marker-alt"></i> <?= h($richiesta['luogo']) ?></span>
                                            </div>
                                        </div>
                                        <div class="dash-card-actions">
                                            <?php if (in_array($richiesta['stato'], ['accettato', 'concluso'], true)): ?>
                                            <a href="chat.php?request=<?= (int)$richiesta['id'] ?>" class="dash-btn dash-btn--secondary">
                                                <i class="fas fa-comments"></i> Chat
                                            </a>
                                            <?php endif; ?>
                                            <?php
                                            $haRec = $isSent ? empty($richiesta['stelle']) : empty($richiesta['stelle_driver']);
                                            if ($richiesta['stato'] === 'concluso' && $haRec):
                                            ?>
                                            <a href="lascia_recensione.php?request=<?= (int)$richiesta['id'] ?>" class="dash-btn dash-btn--primary">
                                                <i class="fas fa-star"></i> Recensisci
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    var button = document.querySelector('.tab[data-tab="' + tab + '"]');
    var panel = document.getElementById(tab + '-content');

    if (!button || !panel) {
        console.warn('Tab non trovato:', tab);
        return;
    }

    document.querySelectorAll('.tab').forEach(function(t) {
        t.classList.remove('active');
    });
    document.querySelectorAll('.tab-content').forEach(function(c) {
        c.classList.remove('active');
    });

    button.classList.add('active');
    panel.classList.add('active');

    var s = document.getElementById('dashSearch');
    if (s) {
        s.value = '';
        filterCards('');
    }
}

function filterCards(query) {
    var q = query.toLowerCase().trim();
    var activeTab = document.querySelector('.tab-content.active');
    if (!activeTab) return;

    activeTab.querySelectorAll('.dash-card').forEach(function(card) {
        var text = card.dataset.search || '';
        card.style.display = (q === '' || text.includes(q)) ? '' : 'none';
    });
}
</script>

<a href="offri_passaggio.php" class="mobile-fab">
    <i class="fas fa-car"></i> Offri Passaggio
</a>
</body>
</html>
