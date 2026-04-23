<?php
require_once 'config.php';

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$utente = $stmt->fetch();

if(!$utente) {
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) as totale_passaggi,
           SUM(CASE WHEN stato = 'concluso' THEN 1 ELSE 0 END) as completati
    FROM ride_requests WHERE driver_id = ?
");
$stmt->execute([$userId]);
$stats = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT AVG(stelle) as media_stelle, COUNT(*) as num_recensioni
    FROM ride_requests
    WHERE driver_id = ? AND stato = 'concluso' AND stelle IS NOT NULL
");
$stmt->execute([$userId]);
$recensioni_stats = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT rr.stelle, rr.recensione_testo, rr.created_at, u.nome, u.cognome
    FROM ride_requests rr
    JOIN users u ON rr.user_id = u.id
    WHERE rr.driver_id = ? AND rr.stato = 'concluso' AND rr.recensione_testo IS NOT NULL
    ORDER BY rr.created_at DESC LIMIT 20
");
$stmt->execute([$userId]);
$recensioni = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT ro.*, e.nome_evento, e.data_evento, e.luogo
    FROM ride_offers ro
    JOIN events e ON ro.event_id = e.id
    WHERE ro.user_id = ? AND e.data_evento >= NOW()
    ORDER BY e.data_evento ASC LIMIT 5
");
$stmt->execute([$userId]);
$prossimi_passaggi = $stmt->fetchAll();
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
    <title>Profilo <?= h($utente['nome']) ?> - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/profilo.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Header -->
    <?php include 'header_snippet.php'; ?>

    <div class="section-md">
        <div class="container">

            <!-- Profile Card -->
            <div class="card" style="margin-bottom: 32px;">
                <div class="profile-hero">
                    <div class="profile-avatar">
                        <?= strtoupper(substr($utente['nome'], 0, 1)) ?>
                    </div>
                    <div class="profile-info">
                        <h1 class="profile-name"><?= h($utente['nome']) ?> <?= h($utente['cognome']) ?></h1>

                        <?php if($recensioni_stats['num_recensioni'] > 0): ?>
                        <div class="profile-rating">
                            <span class="stars">
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star<?= $i <= round($recensioni_stats['media_stelle']) ? '' : '-o' ?>"></i>
                                <?php endfor; ?>
                            </span>
                            <strong><?= number_format($recensioni_stats['media_stelle'], 1) ?>/5</strong>
                            <span>· <?= $recensioni_stats['num_recensioni'] ?> recensioni</span>
                        </div>
                        <?php endif; ?>

                        <div class="profile-badges">
                            <span class="badge badge-success">
                                <i class="fas fa-check-circle"></i>
                                <?= $stats['completati'] ?> passaggi completati
                            </span>
                            <span class="badge badge-pending">
                                <i class="fas fa-calendar"></i>
                                Iscritto dal <?= date('m/Y', strtotime($utente['created_at'])) ?>
                            </span>
                        </div>
                    </div>

                    <?php if(isLoggedIn() && $_SESSION['user_id'] == $userId): ?>
                    <div class="profile-actions">
                        <a href="modifica_profilo.php" class="btn-secondary">
                            <i class="fas fa-edit"></i> Modifica Profilo
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if($utente['bio']): ?>
                <div class="profile-bio">
                    <h3><i class="fas fa-user"></i> Bio</h3>
                    <p><?= nl2br(h($utente['bio'])) ?></p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Prossimi Passaggi -->
            <?php if(!empty($prossimi_passaggi)): ?>
            <h2 class="section-heading">
                <i class="fas fa-calendar-alt"></i> Prossimi Passaggi Offerti
            </h2>
            <?php foreach($prossimi_passaggi as $passaggio): ?>
            <div class="card" style="margin-bottom: 16px;">
                <div class="ride-card-inner">
                    <div class="ride-info">
                        <div class="ride-title"><?= h($passaggio['nome_evento']) ?></div>
                        <div class="ride-meta">
                            <span class="ride-meta-item">
                                <i class="fas fa-map-marker-alt"></i><?= h($passaggio['luogo']) ?>
                            </span>
                            <span class="ride-meta-item">
                                <i class="fas fa-calendar"></i><?= date('d/m/Y H:i', strtotime($passaggio['data_evento'])) ?>
                            </span>
                        </div>
                        <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                            <span class="badge badge-success">
                                <i class="fas fa-chair"></i> <?= $passaggio['posti_disponibili'] ?> posti
                            </span>
                            <span class="badge badge-warning">
                                <i class="fas fa-euro-sign"></i> €<?= number_format($passaggio['prezzo_per_posto'], 2) ?>
                            </span>
                        </div>
                    </div>
                    <?php if(isLoggedIn() && $_SESSION['user_id'] != $userId): ?>
                    <a href="richiedi_passaggio.php?offer=<?= $passaggio['id'] ?>" class="btn-primary" style="padding: 10px 20px; font-size: 14px; white-space: nowrap;">
                        <i class="fas fa-hand-point-up"></i> Richiedi
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <!-- Recensioni -->
            <h2 class="section-heading" style="margin-top: 40px;">
                <i class="fas fa-star"></i> Recensioni
                <?php if(!empty($recensioni)): ?>
                <span style="font-size: 16px; color: var(--color-text-muted); font-weight: 400;">(<?= count($recensioni) ?>)</span>
                <?php endif; ?>
            </h2>

            <?php if(!empty($recensioni)): ?>
            <div class="card">
                <?php foreach($recensioni as $recensione): ?>
                <div class="review-item">
                    <div class="review-header">
                        <div>
                            <div class="review-author">
                                <?= h($recensione['nome']) ?> <?= h(substr($recensione['cognome'], 0, 1)) ?>.
                            </div>
                            <div class="review-stars">
                                <?php for($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star<?= $i <= $recensione['stelle'] ? '' : '-o' ?>"></i>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <span class="review-date"><?= date('d/m/Y', strtotime($recensione['created_at'])) ?></span>
                    </div>
                    <p class="review-text"><?= nl2br(h($recensione['recensione_testo'])) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="card empty-state">
                <div class="empty-state-icon"><i class="fas fa-comment-slash"></i></div>
                <h3>Nessuna recensione</h3>
                <p>
                    <?= isLoggedIn() && $_SESSION['user_id'] == $userId
                        ? 'Completa i tuoi primi passaggi per ricevere recensioni!'
                        : 'Questo utente non ha ancora ricevuto recensioni.' ?>
                </p>
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