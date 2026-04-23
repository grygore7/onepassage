<?php
require_once 'config.php';

if(!isLoggedIn()) {
    header('Location: auth.php');
    exit;
}

$userId  = $_SESSION['user_id'];
$errore  = '';
$successo = '';

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$utente = $stmt->fetch();

if(!$utente) { header('Location: index.php'); exit; }

if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nome             = trim($_POST['nome']);
    $cognome          = trim($_POST['cognome']);
    $email            = trim($_POST['email']);
    $telefono         = trim($_POST['telefono']);
    $bio              = trim($_POST['bio']);
    $password_nuova   = $_POST['password_nuova'] ?? '';
    $password_conferma = $_POST['password_conferma'] ?? '';

    if(empty($nome) || empty($cognome) || empty($email)) {
        $errore = 'Nome, cognome ed email sono obbligatori';
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Email non valida';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $userId]);
        if($check->fetch()) {
            $errore = 'Email già utilizzata da un altro utente';
        } else {
            try {
                $pdo->prepare("UPDATE users SET nome=?, cognome=?, email=?, telefono=?, bio=? WHERE id=?")
                    ->execute([$nome, $cognome, $email, $telefono, $bio, $userId]);

                if(!empty($password_nuova)) {
                    if(strlen($password_nuova) < 6) {
                        $errore = 'La password deve essere di almeno 6 caratteri';
                    } elseif($password_nuova !== $password_conferma) {
                        $errore = 'Le password non coincidono';
                    } else {
                        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                            ->execute([password_hash($password_nuova, PASSWORD_DEFAULT), $userId]);
                        $successo = 'Profilo e password aggiornati con successo!';
                    }
                } else {
                    $successo = 'Profilo aggiornato con successo!';
                }

                if($successo) {
                    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $utente = $stmt->fetch();
                }
            } catch(PDOException $e) {
                $errore = 'Errore durante l\'aggiornamento';
            }
        }
    }
}

// Statistiche
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT ro.id) as passaggi_offerti,
           COUNT(DISTINCT rr.id) as richieste_fatte,
           COUNT(DISTINCT rr2.id) as viaggi_completati
    FROM users u
    LEFT JOIN ride_offers ro ON u.id = ro.user_id
    LEFT JOIN ride_requests rr ON u.id = rr.user_id
    LEFT JOIN ride_requests rr2 ON u.id = rr2.driver_id AND rr2.stato = 'concluso'
    WHERE u.id = ?
    GROUP BY u.id
");
$stmt->execute([$userId]);
$stats = $stmt->fetch();
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
    <title>Modifica Profilo - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/modifica_profilo.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <?php include 'header_snippet.php'; ?>

    <div class="section-md">
        <div class="container">
            <div class="page-container">

                <div class="page-header">
                    <h1><i class="fas fa-user-edit"></i> Modifica Profilo</h1>
                    <a href="profilo.php?id=<?= $userId ?>" class="btn-secondary" style="padding: 10px 20px; font-size: 14px;">
                        <i class="fas fa-arrow-left"></i> Torna al Profilo
                    </a>
                </div>

                <?php if($errore): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= h($errore) ?></span>
                </div>
                <?php endif; ?>

                <?php if($successo): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?= h($successo) ?></span>
                </div>
                <?php endif; ?>

                <!-- Edit Form -->
                <div class="card" style="margin-bottom: 24px;">
                    <form method="POST" action="">
                        <div class="form-section-title">
                            <i class="fas fa-user"></i> Informazioni Personali
                        </div>

                        <div class="form-grid-2">
                            <div class="form-group">
                                <label>Nome *</label>
                                <input type="text" name="nome" value="<?= h($utente['nome']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label>Cognome *</label>
                                <input type="text" name="cognome" value="<?= h($utente['cognome']) ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email *</label>
                            <input type="email" name="email" value="<?= h($utente['email']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-phone"></i> Telefono</label>
                            <input type="tel" name="telefono" value="<?= h($utente['telefono'] ?? '') ?>" placeholder="+39 333 1234567">
                            <small style="color: var(--color-text-muted); display: block; margin-top: 6px; font-size: 12px;">Opzionale — Utile per organizzare i viaggi</small>
                        </div>

                        <div class="form-group">
                            <label><i class="fas fa-align-left"></i> Biografia</label>
                            <textarea name="bio" rows="5" placeholder="Parlaci di te, delle tue passioni musicali, dei tuoi viaggi..."><?= h($utente['bio'] ?? '') ?></textarea>
                            <small style="color: var(--color-text-muted); display: block; margin-top: 6px; font-size: 12px;">Aiuta gli altri utenti a conoscerti meglio</small>
                        </div>

                        <!-- Password Section -->
                        <div class="form-section-divider">
                            <div class="form-section-title">
                                <i class="fas fa-lock"></i> Modifica Password
                            </div>
                            <div class="password-hint">
                                <i class="fas fa-info-circle" style="color: var(--color-accent);"></i>
                                Lascia vuoti questi campi se non vuoi modificare la password.
                            </div>

                            <div class="form-group">
                                <label>Nuova Password</label>
                                <input type="password" name="password_nuova" minlength="6" placeholder="Minimo 6 caratteri">
                            </div>
                            <div class="form-group">
                                <label>Conferma Nuova Password</label>
                                <input type="password" name="password_conferma" minlength="6" placeholder="Ripeti la password">
                            </div>
                        </div>

                        <button type="submit" class="btn-primary" style="width: 100%; margin-top: 8px;">
                            <i class="fas fa-save"></i> Salva Modifiche
                        </button>
                    </form>
                </div>

                <!-- Stats Card -->
                <div class="card">
                    <div class="form-section-title">
                        <i class="fas fa-chart-line"></i> Statistiche Account
                    </div>
                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="stat-box-number accent"><?= $stats['passaggi_offerti'] ?? 0 ?></div>
                            <div class="stat-box-label">Passaggi Offerti</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box-number blue"><?= $stats['richieste_fatte'] ?? 0 ?></div>
                            <div class="stat-box-label">Richieste Inviate</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-box-number amber"><?= $stats['viaggi_completati'] ?? 0 ?></div>
                            <div class="stat-box-label">Viaggi Completati</div>
                        </div>
                    </div>
                    <div class="stats-footer">
                        <i class="fas fa-calendar"></i>
                        Iscritto dal <?= date('d/m/Y', strtotime($utente['created_at'])) ?>
                    </div>
                </div>

            </div>
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