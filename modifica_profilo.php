<?php
require_once 'config.php';

if (!isLoggedIn()) { header('Location: auth.php'); exit; }

$userId  = $_SESSION['user_id'];
$errore  = '';
$successo = '';

// Carica dati utente
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$userId]);
$utente = $stmt->fetch();
if (!$utente) { header('Location: index.php'); exit; }

// ── Gestione POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome      = trim($_POST['nome']    ?? '');
    $cognome   = trim($_POST['cognome'] ?? '');
    $email     = trim($_POST['email']   ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $bio       = trim($_POST['bio']     ?? '');

    if (empty($nome) || empty($cognome) || empty($email)) {
        $errore = 'Nome, cognome ed email sono obbligatori.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Email non valida.';
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $userId]);
        if ($check->fetch()) {
            $errore = 'Email già utilizzata da un altro account.';
        } else {
            try {
                $pdo->prepare("UPDATE users SET nome=?, cognome=?, email=?, telefono=?, bio=? WHERE id=?")
                    ->execute([$nome, $cognome, $email, $telefono, $bio, $userId]);
                $_SESSION['user_nome']    = $nome;
                $_SESSION['user_cognome'] = $cognome;
                $_SESSION['user_email']   = $email;
                $successo = 'Profilo aggiornato con successo!';
            } catch (PDOException $e) {
                $errore = 'Errore durante l\'aggiornamento.';
            }
        }
    }

    // ── Cambio password (separato, solo se compilato) ──────────
    $pwdAttuale  = $_POST['password_attuale']  ?? '';
    $pwdNuova    = $_POST['password_nuova']    ?? '';
    $pwdConferma = $_POST['password_conferma'] ?? '';

    if (!empty($pwdNuova) && empty($errore)) {
        $hashRow = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
        $hashRow->execute([$userId]);
        $hashRow = $hashRow->fetch();

        if (empty($pwdAttuale) || !password_verify($pwdAttuale, $hashRow['password_hash'])) {
            $errore = 'La password attuale inserita non è corretta.';
        } elseif (($pwdErr = validaPassword($pwdNuova)) !== null) {
            $errore = $pwdErr;
        } elseif ($pwdNuova !== $pwdConferma) {
            $errore = 'Le nuove password non coincidono.';
        } else {
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")
                ->execute([password_hash($pwdNuova, PASSWORD_DEFAULT), $userId]);
            $successo = 'Profilo e password aggiornati!';
        }
    }

    // ── Upload foto ────────────────────────────────────────────
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
        $mime     = mime_content_type($_FILES['foto']['tmp_name']);
        $allowed  = ['image/jpeg','image/png','image/webp'];
        if (!in_array($mime, $allowed)) {
            $errore = 'Formato immagine non supportato (usa JPG, PNG o WebP).';
        } else {
            $ext      = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime];
            $filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
            $dest     = __DIR__ . '/uploads/' . $filename;
            if (!is_dir(__DIR__ . '/uploads')) mkdir(__DIR__ . '/uploads', 0755, true);
            if (move_uploaded_file($_FILES['foto']['tmp_name'], $dest)) {
                $pdo->prepare("UPDATE users SET foto_profilo=? WHERE id=?")->execute([$filename, $userId]);
            }
        }
    }

    // Ricarica dati aggiornati
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $utente = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifica Profilo — OnePassage</title>
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/modifica_profilo.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

<main class="section-md">
<div class="container" style="max-width:740px;margin:0 auto;">

    <div class="page-header">
        <div>
            <h1 class="section-title">Modifica profilo</h1>
            <p style="color:var(--color-text-muted);font-size:14px;">Aggiorna le tue informazioni personali</p>
        </div>
        <a href="profilo.php?id=<?= $userId ?>" class="btn-secondary">
            <i class="fas fa-eye"></i> Vedi profilo
        </a>
    </div>

    <?php if ($errore): ?>
    <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= h($errore) ?></div>
    <?php endif; ?>
    <?php if ($successo): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= h($successo) ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">

        <!-- ── Informazioni personali ── -->
        <div class="card" style="margin-bottom:20px;">
            <h3 class="form-section-title"><i class="fas fa-user"></i> Informazioni personali</h3>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="nome">Nome *</label>
                    <input type="text" id="nome" name="nome" required
                           value="<?= h($utente['nome']) ?>" autocomplete="given-name">
                </div>
                <div class="form-group">
                    <label for="cognome">Cognome *</label>
                    <input type="text" id="cognome" name="cognome" required
                           value="<?= h($utente['cognome']) ?>" autocomplete="family-name">
                </div>
            </div>
            <div class="form-group">
                <label for="email">Email *</label>
                <input type="email" id="email" name="email" required
                       value="<?= h($utente['email']) ?>" autocomplete="email">
            </div>
            <div class="form-group">
                <label for="telefono">Telefono <span style="color:var(--color-text-muted);font-weight:400;">(opzionale)</span></label>
                <input type="tel" id="telefono" name="telefono"
                       value="<?= h($utente['telefono'] ?? '') ?>" autocomplete="tel"
                       placeholder="+39 333 000 0000">
            </div>
            <div class="form-group">
                <label for="bio">Bio</label>
                <textarea id="bio" name="bio" rows="4"
                          placeholder="Parlaci di te, dei tuoi gusti musicali..."><?= h($utente['bio'] ?? '') ?></textarea>
            </div>

            <!-- Foto profilo -->
            <div class="form-group">
                <label>Foto profilo</label>
                <div class="avatar-upload-row">
                    <?php if (!empty($utente['foto_profilo'])): ?>
                        <img src="uploads/<?= h($utente['foto_profilo']) ?>" class="avatar-preview" alt="Foto attuale">
                    <?php else: ?>
                        <div class="avatar-preview avatar-placeholder">
                            <?= strtoupper(substr($utente['nome'],0,1) . substr($utente['cognome'],0,1)) ?>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label class="avatar-upload-btn btn-secondary" for="foto">
                            <i class="fas fa-camera"></i> Cambia foto
                        </label>
                        <input type="file" id="foto" name="foto" accept="image/jpeg,image/png,image/webp"
                               style="display:none;" onchange="previewAvatar(this)">
                        <p style="font-size:12px;color:var(--color-text-muted);margin-top:6px;">JPG, PNG o WebP — max 5MB</p>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary" style="margin-top:4px;">
                <i class="fas fa-save"></i> Salva modifiche
            </button>
        </div>

        <!-- ── Cambio password ── -->
        <div class="card">
            <h3 class="form-section-title"><i class="fas fa-lock"></i> Cambia password</h3>
            <p style="font-size:13px;color:var(--color-text-muted);margin-bottom:20px;">
                Lascia i campi vuoti se non vuoi modificare la password.
            </p>

            <div class="form-group">
                <label for="password_attuale">Password attuale</label>
                <div class="input-password-wrap">
                    <input type="password" id="password_attuale" name="password_attuale"
                           placeholder="Inserisci la tua password attuale" autocomplete="current-password">
                    <button type="button" class="toggle-pwd" onclick="togglePwd('password_attuale')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <div class="form-group">
                <label for="password_nuova">Nuova password</label>
                <div class="input-password-wrap">
                    <input type="password" id="password_nuova" name="password_nuova"
                           placeholder="Min. 8 car., 1 maiusc., 1 numero, 1 speciale"
                           autocomplete="new-password"
                           oninput="checkPwdStrength(this.value)">
                    <button type="button" class="toggle-pwd" onclick="togglePwd('password_nuova')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <div class="pwd-strength-bar"><div class="pwd-strength-fill" id="pwdFill"></div></div>
                <div class="pwd-rules" id="pwdRules">
                    <span id="r-len"><i class="fas fa-times-circle"></i> 8+ caratteri</span>
                    <span id="r-upper"><i class="fas fa-times-circle"></i> Maiuscola</span>
                    <span id="r-num"><i class="fas fa-times-circle"></i> Numero</span>
                    <span id="r-spec"><i class="fas fa-times-circle"></i> Speciale</span>
                </div>
            </div>
            <div class="form-group">
                <label for="password_conferma">Conferma nuova password</label>
                <div class="input-password-wrap">
                    <input type="password" id="password_conferma" name="password_conferma"
                           placeholder="Ripeti la nuova password" autocomplete="new-password">
                    <button type="button" class="toggle-pwd" onclick="togglePwd('password_conferma')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-primary">
                <i class="fas fa-key"></i> Aggiorna password
            </button>
        </div>

    </form>
</div>
</main>

<script>
function togglePwd(id) {
    var inp = document.getElementById(id);
    var btn = inp.nextElementSibling;
    if (inp.type === 'password') { inp.type = 'text'; btn.innerHTML = '<i class="fas fa-eye-slash"></i>'; }
    else { inp.type = 'password'; btn.innerHTML = '<i class="fas fa-eye"></i>'; }
}
function checkPwdStrength(v) {
    var rules = { 'r-len': v.length>=8, 'r-upper': /[A-Z]/.test(v), 'r-num': /[0-9]/.test(v), 'r-spec': /[\W_]/.test(v) };
    var score = Object.values(rules).filter(Boolean).length;
    var fill  = document.getElementById('pwdFill');
    if (fill) { fill.style.width=(score*25)+'%'; fill.style.background=['#EF4444','#EF4444','#F59E0B','#10B981','#10B981'][score]; }
    Object.entries(rules).forEach(function([id,ok]){
        var el=document.getElementById(id); if(!el) return;
        el.innerHTML=(ok?'<i class="fas fa-check-circle"></i>':'<i class="fas fa-times-circle"></i>')+' '+el.textContent.replace(/^\S+\s/,'');
        el.classList.toggle('ok',ok);
    });
}
function previewAvatar(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            var prev = document.querySelector('.avatar-preview');
            if (prev) { prev.style.backgroundImage = 'url('+e.target.result+')'; }
        };
        reader.readAsDataURL(input.files[0]);
    }
}
function toggleTheme() {
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
}
</script>
</body>
</html>
