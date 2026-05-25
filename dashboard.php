<?php
require_once 'config.php';

if(!isLoggedIn()) {
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
        u.nome as driver_nome,
        u.cognome as driver_cognome,
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
        u.nome as passenger_nome,
        u.cognome as passenger_cognome,
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

// Separa future (evento non ancora avvenuto o accettato/in_attesa) da passate
$now = time();
$richieste_inviate_future  = array_filter($richieste_inviate,  fn($r) => strtotime($r['data_evento']) >= $now || in_array($r['stato'], ['in_attesa','accettato']));
$richieste_inviate_passate = array_filter($richieste_inviate,  fn($r) => strtotime($r['data_evento']) <  $now && !in_array($r['stato'], ['in_attesa','accettato']));
$richieste_ricevute        = array_filter($richieste_ricevute_raw, fn($r) => strtotime($r['data_evento']) >= $now || in_array($r['stato'], ['in_attesa','accettato']));
$richieste_ricevute_passate= array_filter($richieste_ricevute_raw, fn($r) => strtotime($r['data_evento']) <  $now && !in_array($r['stato'], ['in_attesa','accettato']));
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

        <!-- ══ HERO HEADER ══ -->
        <div class="dash-hero">
            <div class="dash-hero-inner container">
                <div class="dash-hero-text">
                    <p class="dash-greeting">Bentornato,</p>
                    <h1 class="dash-name"><?php echo htmlspecialchars($_SESSION['user_nome'] ?? ''); ?></h1>
                </div>
                <div class="dash-stats">
                    <div class="dash-stat">
                        <span class="dash-stat-num"><?= count($richieste_inviate_future) ?></span>
                        <span class="dash-stat-label">Richieste attive</span>
                    </div>
                    <div class="dash-stat-divider"></div>
                    <div class="dash-stat">
                        <span class="dash-stat-num"><?= count($richieste_ricevute) ?></span>
                        <span class="dash-stat-label">Offerte gestite</span>
                    </div>
                    <div class="dash-stat-divider"></div>
                    <div class="dash-stat">
                        <span class="dash-stat-num"><?= count($richieste_inviate_passate) + count($richieste_ricevute_passate) ?></span>
                        <span class="dash-stat-label">Corse completate</span>
                    </div>
                </div>
                <a href="offri_passaggio.php" class="dash-hero-cta">
                    <i class="fas fa-plus"></i> Offri Passaggio
                </a>
            </div>
        </div>

        <div class="container dash-body">

        <!-- ── Panel principale (glass card) ── -->
        <div class="dash-panel">

        <!-- Tabs -->
        <div class="tabs" id="mainTabs">
            <button class="tab active" data-tab="richieste" onclick="switchTab('richieste')">
                <i class="fas fa-paper-plane"></i> Richieste Inviate
                <?php $nFut = count($richieste_inviate_future); if($nFut > 0): ?>
                <span class="badge badge-pending" style="margin-left:8px"><?= $nFut ?></span>
                <?php endif; ?>
            </button>
            <button class="tab" data-tab="offerte" onclick="switchTab('offerte')">
                <i class="fas fa-car"></i> Offerte Gestite
                <?php $nOff = count($richieste_ricevute); if($nOff > 0): ?>
                <span class="badge badge-success" style="margin-left:8px"><?= $nOff ?></span>
                <?php endif; ?>
            </button>
            <button class="tab" data-tab="passati" onclick="switchTab('passati')">
                <i class="fas fa-history"></i> Storico
                <?php $nPast = count($richieste_inviate_passate) + count($richieste_ricevute_passate); if($nPast > 0): ?>
                <span class="badge badge-pending" style="margin-left:8px"><?= $nPast ?></span>
                <?php endif; ?>
            </button>
        </div><!-- /tabs -->
        <div class="dash-panel-divider"></div>

        <!-- ── Contenuto tab ── -->
        <div class="dash-tab-stage">

        <!-- ── Barra azioni + ricerca interna ── -->
        <div class="dash-toolbar">
            <div class="dash-search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" id="dashSearch" class="dash-search"
                    placeholder="Cerca nelle tue richieste e offerte…"
                    oninput="filterCards(this.value)">
            </div>
        </div>

        <div class="tab-content-grid">
        <!-- ── TAB: Richieste Inviate ── -->
        <div id="richieste-content" class="tab-content active">
            <?php if(empty($richieste_inviate_future)): ?>
            <div class="dash-empty">
                <div class="dash-empty-icon"><i class="fas fa-paper-plane"></i></div>
                <h3>Nessuna richiesta attiva</h3>
                <p>Sfoglia gli eventi e richiedi un passaggio per iniziare!</p>
                <a href="ricerca.php" class="dash-btn dash-btn--primary" style="margin-top:8px"><i class="fas fa-search"></i> Trova evento</a>
            </div>
            <?php else: ?>
            <div class="dash-cards">
            <?php foreach($richieste_inviate_future as $richiesta): ?>
            <?php
                switch($richiesta['stato']) {
                    case 'in_attesa': $accentCol='blue';  $chipCol='blue';  $badgeIcon='clock';          $statoText='In Attesa'; break;
                    case 'accettato': $accentCol='green'; $chipCol='green'; $badgeIcon='check-circle';   $statoText='Accettato'; break;
                    case 'rifiutato': $accentCol='red';   $chipCol='red';   $badgeIcon='times-circle';   $statoText='Rifiutato'; break;
                    case 'concluso':  $accentCol='gray';  $chipCol='gray';  $badgeIcon='flag-checkered'; $statoText='Concluso'; break;
                    default:          $accentCol='blue';  $chipCol='blue';  $badgeIcon='circle';         $statoText=ucfirst($richiesta['stato']);
                }
            ?>
            <div class="dash-card" data-search="<?= strtolower(h($richiesta['nome_evento']).' '.$richiesta['driver_nome'].' '.$richiesta['luogo']) ?>">
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
                                <span class="dash-meta-item"><i class="fas fa-user"></i> <?= h($richiesta['driver_nome']) ?> <?= h(substr($richiesta['driver_cognome'],0,1)) ?>.</span>
                                <span class="dash-meta-item"><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($richiesta['data_evento'])) ?></span>
                                <span class="dash-meta-item"><i class="fas fa-map-marker-alt"></i> <?= h($richiesta['luogo']) ?></span>
                            </div>
                            <div class="dash-card-chips">
                                <span class="dash-chip dash-chip--amber"><i class="fas fa-euro-sign"></i> €<?= number_format($richiesta['prezzo_per_posto'],2) ?></span>
                            </div>
                        </div>
                        <div class="dash-card-actions">
                            <?php if(in_array($richiesta['stato'],['accettato','concluso'])): ?>
                            <a href="chat.php?request=<?= $richiesta['id'] ?>" class="dash-btn dash-btn--primary"><i class="fas fa-comments"></i> Chat</a>
                            <?php endif; ?>
                            <a href="profilo.php?id=<?= $richiesta['driver_id'] ?>" class="dash-btn dash-btn--secondary"><i class="fas fa-user"></i> Profilo</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── TAB: Offerte Gestite ── -->
        <div id="offerte-content" class="tab-content">
            <?php if(empty($richieste_ricevute)): ?>
            <div class="dash-empty">
                <div class="dash-empty-icon"><i class="fas fa-car"></i></div>
                <h3>Nessuna offerta attiva</h3>
                <p>Offri un passaggio per un evento e inizia a ricevere richieste!</p>
                <a href="offri_passaggio.php" class="dash-btn dash-btn--primary" style="margin-top:8px"><i class="fas fa-plus"></i> Offri Passaggio</a>
            </div>
            <?php else: ?>
            <div class="dash-cards">
            <?php foreach($richieste_ricevute as $richiesta): ?>
            <?php
                switch($richiesta['stato']) {
                    case 'in_attesa': $accentCol='blue';  $chipCol='blue';  $badgeIcon='clock';          $statoText='In Attesa'; break;
                    case 'accettato': $accentCol='green'; $chipCol='green'; $badgeIcon='check-circle';   $statoText='Accettato'; break;
                    case 'rifiutato': $accentCol='red';   $chipCol='red';   $badgeIcon='times-circle';   $statoText='Rifiutato'; break;
                    case 'concluso':  $accentCol='gray';  $chipCol='gray';  $badgeIcon='flag-checkered'; $statoText='Concluso'; break;
                    default:          $accentCol='blue';  $chipCol='blue';  $badgeIcon='circle';         $statoText=ucfirst($richiesta['stato']);
                }
            ?>
            <div class="dash-card" data-search="<?= strtolower(h($richiesta['nome_evento']).' '.$richiesta['passenger_nome'].' '.$richiesta['luogo']) ?>">
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
                                <span class="dash-meta-item"><i class="fas fa-user"></i> <?= h($richiesta['passenger_nome']) ?> <?= h(substr($richiesta['passenger_cognome'],0,1)) ?>.</span>
                                <span class="dash-meta-item"><i class="fas fa-calendar"></i> <?= date('d/m/Y H:i', strtotime($richiesta['data_evento'])) ?></span>
                                <span class="dash-meta-item"><i class="fas fa-map-marker-alt"></i> <?= h($richiesta['luogo']) ?></span>
                            </div>
                            <div class="dash-card-chips">
                                <span class="dash-chip dash-chip--amber"><i class="fas fa-euro-sign"></i> €<?= number_format($richiesta['prezzo_per_posto'],2) ?></span>
                                <span class="dash-chip dash-chip--blue"><i class="fas fa-chair"></i> <?= $richiesta['posti_disponibili'] ?> posti liberi</span>
                            </div>
                        </div>
                        <div class="dash-card-actions">
                            <?php if($richiesta['stato'] === 'in_attesa'): ?>
                            <a href="gestisci_richiesta.php?id=<?= $richiesta['id'] ?>&action=accetta" class="dash-btn dash-btn--primary" onclick="return confirm('Accettare?')"><i class="fas fa-check"></i> Accetta</a>
                            <a href="gestisci_richiesta.php?id=<?= $richiesta['id'] ?>&action=rifiuta" class="dash-btn dash-btn--secondary" onclick="return confirm('Rifiutare?')"><i class="fas fa-times"></i> Rifiuta</a>
                            <?php elseif(in_array($richiesta['stato'],['accettato','concluso'])): ?>
                            <a href="chat.php?request=<?= $richiesta['id'] ?>" class="dash-btn dash-btn--primary"><i class="fas fa-comments"></i> Chat</a>
                            <?php endif; ?>
                            <a href="profilo.php?id=<?= $richiesta['user_id'] ?>" class="dash-btn dash-btn--secondary"><i class="fas fa-user"></i> Profilo</a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── TAB: I miei Passaggi (offerte create) ── -->
        <div id="mie-offerte-content" class="tab-content">
            <?php if (empty($mie_offerte)): ?>
            <div class="dash-empty">
                <div class="dash-empty-icon"><i class="fas fa-route"></i></div>
                <p>Non stai ancora offrendo nessun passaggio.</p>
                <a href="offri_passaggio.php" class="btn-primary" style="margin-top:14px;">
                    <i class="fas fa-plus"></i> Offri Passaggio
                </a>
            </div>
            <?php else: ?>
            <?php foreach ($mie_offerte as $o):
                $dt = $o['data_evento'] ? new DateTime($o['data_evento']) : null;
                $dataFmt = $dt ? $dt->format('d/m/Y H:i') : '—';
                $isFuture = $dt && $dt->getTimestamp() >= time();
                $accentCol = $isFuture ? 'green' : 'gray';
            ?>
            <div class="dash-card" data-search="<?= h(strtolower($o['nome_evento'].' '.$o['luogo'])) ?>">
                <div class="dash-card-inner">
                    <div class="dash-card-accent dash-card-accent--<?= $accentCol ?>"></div>
                    <div class="dash-card-body">
                        <div class="dash-card-top">
                            <div>
                                <div class="dash-card-title"><?= h($o['nome_evento']) ?></div>
                                <div class="dash-card-meta">
                                    <span><?= h($o['luogo']) ?></span>
                                    <span><?= $dataFmt ?></span>
                                    <span>Partenza: <?= h($o['punto_partenza']) ?></span>
                                </div>
                            </div>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:flex-start;">
                                <?php if ($o['richieste_pendenti'] > 0): ?>
                                <span class="dash-chip dash-chip--amber">
                                    <?= $o['richieste_pendenti'] ?> in attesa
                                </span>
                                <?php endif; ?>
                                <span class="dash-chip dash-chip--blue">
                                    <?= (int)$o['posti_disponibili'] ?> posti liberi
                                </span>
                                <span class="dash-chip dash-chip--<?= $o['prezzo_per_posto'] > 0 ? 'amber' : 'green' ?>">
                                    <?= $o['prezzo_per_posto'] > 0 ? '€'.number_format((float)$o['prezzo_per_posto'],2) : 'Gratuito' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="dash-card-actions">
                        <a href="ricerca.php?q=<?= urlencode($o['nome_evento']) ?>" class="dash-btn dash-btn--secondary">
                            <i class="fas fa-eye"></i> Vedi
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ── TAB: Storico ── -->
        <div id="passati-content" class="tab-content">
            <?php
            $tuttiPassati = array_merge(
                array_map(fn($r) => $r + ['_tipo'=>'inviata'], iterator_to_array((function($a){ foreach($a as $v) yield $v; })($richieste_inviate_passate))),
                array_map(fn($r) => $r + ['_tipo'=>'ricevuta'], iterator_to_array((function($a){ foreach($a as $v) yield $v; })($richieste_ricevute_passate)))
            );
            usort($tuttiPassati, fn($a,$b) => strtotime($b['data_evento']) - strtotime($a['data_evento']));
            ?>
            <?php if(empty($tuttiPassati)): ?>
            <div class="dash-empty">
                <div class="dash-empty-icon"><i class="fas fa-history"></i></div>
                <h3>Nessun evento passato</h3>
                <p>Le corse completate appariranno qui nel tempo.</p>
            </div>
            <?php else: ?>
            <div class="dash-cards">
            <?php foreach($tuttiPassati as $richiesta): ?>
            <?php
                $isSent = $richiesta['_tipo'] === 'inviata';
                switch($richiesta['stato']) {
                    case 'concluso':  $accentCol='gray';  $chipCol='gray';  $badgeIcon='flag-checkered'; $statoText='Concluso'; break;
                    case 'rifiutato': $accentCol='red';   $chipCol='red';   $badgeIcon='times-circle';   $statoText='Rifiutato'; break;
                    default:          $accentCol='blue';  $chipCol='blue';  $badgeIcon='check-circle';   $statoText=ucfirst($richiesta['stato']);
                }
                $altroNome = $isSent
                    ? h($richiesta['driver_nome']).' '.h(substr($richiesta['driver_cognome'],0,1)).'.'
                    : h($richiesta['passenger_nome']).' '.h(substr($richiesta['passenger_cognome'],0,1)).'.';
                $altroId = $isSent ? $richiesta['driver_id'] : $richiesta['user_id'];
            ?>
            <div class="dash-card dash-card--past" data-search="<?= strtolower(h($richiesta['nome_evento']).' '.$richiesta['luogo']) ?>">
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
                            <?php if(in_array($richiesta['stato'],['accettato','concluso'])): ?>
                            <a href="chat.php?request=<?= $richiesta['id'] ?>" class="dash-btn dash-btn--secondary"><i class="fas fa-comments"></i> Chat</a>
                            <?php endif; ?>
                            <?php
                                $haRec = $isSent ? empty($richiesta['stelle']) : empty($richiesta['stelle_driver']);
                                if($richiesta['stato']==='concluso' && $haRec):
                            ?>
                            <a href="lascia_recensione.php?request=<?= $richiesta['id'] ?>" class="dash-btn dash-btn--primary"><i class="fas fa-star"></i> Recensisci</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        </div><!-- /tab-content-grid -->

        </div><!-- /dash-tab-stage -->
        </div><!-- /dash-panel -->

    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.querySelector('.tab[data-tab="' + tab + '"]').classList.add('active');
            document.getElementById(tab + '-content').classList.add('active');
            // Resetta la ricerca quando si cambia tab
            var s = document.getElementById('dashSearch');
            if(s) { s.value = ''; filterCards(''); }
        }

        function filterCards(query) {
            const q = query.toLowerCase().trim();
            // Cerca nelle card del tab attivo
            const activeTab = document.querySelector('.tab-content.active');
            if (!activeTab) return;
            activeTab.querySelectorAll('.dash-card').forEach(card => {
                const text = card.dataset.search || '';
                card.style.display = (q === '' || text.includes(q)) ? '' : 'none';
            });
        }

        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            
            const icon = document.querySelector('.theme-toggle i');
            icon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            
            const icon = document.querySelector('.theme-toggle i');
            icon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        });
    </script>
<a href="offri_passaggio.php" class="mobile-fab">
    <i class="fas fa-car"></i> Offri Passaggio
</a>
</body>
</html>
