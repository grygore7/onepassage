<?php
require_once 'config.php';

// ── Logout ────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_unset(); session_destroy();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    header('Location: auth.php'); exit;
}

// Redirect se già loggato
if (isLoggedIn()) { header('Location: dashboard.php'); exit; }

$errore  = '';
$successo = '';
$stepOTP = false;
$otpUserId = null;

// ── Step 2: Verifica OTP ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['verify_otp']) || isset($_POST['otp_codice']))) {
    $userId = (int)($_POST['otp_user_id'] ?? 0);
    $codice = trim($_POST['otp_codice'] ?? '');
    if ($userId && $codice) {
        if (verificaOTP($pdo, $userId, $codice)) {
            $pdo->prepare("UPDATE users SET email_verificata=1 WHERE id=?")->execute([$userId]);
            $u = $pdo->prepare("SELECT * FROM users WHERE id=?");
            $u->execute([$userId]);
            $user = $u->fetch();
            $_SESSION['user_id']      = $user['id'];
            $_SESSION['user_nome']    = $user['nome'];
            $_SESSION['user_cognome'] = $user['cognome'];
            $_SESSION['user_email']   = $user['email'];
            $_SESSION['user_foto_profilo']  = $user['foto_profilo'] ?? '';
            
            header('Location: dashboard.php'); exit;
        } else {
            $errore    = 'Codice non valido o scaduto.';
            $stepOTP   = true;
            $otpUserId = $userId;
        }
    }
}

// ── Reinvia OTP ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_otp'])) {
    $userId = (int)($_POST['otp_user_id'] ?? 0);
    if ($userId) {
        $uRow = $pdo->prepare("SELECT nome, email FROM users WHERE id=? AND email_verificata=0");
        $uRow->execute([$userId]);
        $uRow = $uRow->fetch();
        if ($uRow) {
            $codice = generaOTP($pdo, $userId);
            inviaEmail($uRow['email'], $uRow['nome'],
                'Nuovo codice verifica OnePassage', emailOTP($uRow['nome'], $codice));
            $successo  = 'Nuovo codice inviato a ' . h($uRow['email']) . '.';
        }
        $stepOTP   = true;
        $otpUserId = $userId;
    }
}

// ── Login ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $errore = 'Inserisci email e password.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            if (($user['ban_status'] ?? 'attivo') === 'bannato') {
                $errore = 'Account sospeso. Contatta il supporto.';
            } elseif (!$user['email_verificata']) {
                $codice = generaOTP($pdo, $user['id']);
                inviaEmail(
                    $user['email'],
                    $user['nome'],
                    'Verifica il tuo account OnePassage',
                    emailOTP($user['nome'], $codice)
                );

                $stepOTP   = true;
                $otpUserId = $user['id'];
                $successo  = 'Abbiamo inviato un nuovo codice a ' . h($email) . '.';
            } else {
                $_SESSION['user_id']      = $user['id'];
                $_SESSION['user_nome']    = $user['nome'];
                $_SESSION['user_cognome'] = $user['cognome'];
                $_SESSION['user_email']   = $user['email'];

                header('Location: dashboard.php');
                exit;
            }
        } else {
            $errore = 'Credenziali non valide.';
        }
    }
}

// ── Registrazione ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $nome     = trim($_POST['nome']    ?? '');
    $cognome  = trim($_POST['cognome'] ?? '');
    $email    = trim($_POST['email_reg'] ?? '');
    $pwd      = $_POST['password_reg']     ?? '';
    $pwdConf  = $_POST['password_confirm'] ?? '';

    if (empty($nome) || empty($cognome) || empty($email) || empty($pwd)) {
        $errore = 'Compila tutti i campi obbligatori.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Email non valida.';
    } elseif ($pwd !== $pwdConf) {
        $errore = 'Le password non coincidono.';
    } elseif (($pwdErr = validaPassword($pwd)) !== null) {
        $errore = $pwdErr;
    } else {
        $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $check->execute([$email]);
        if ($check->fetch()) {
            $errore = 'Email già registrata.';
        } else {
            $pdo->beginTransaction();
            try {
                $hash = password_hash($pwd, PASSWORD_DEFAULT);
                $ins  = $pdo->prepare(
                    "INSERT INTO users (nome, cognome, email, password_hash, email_verificata)
                     VALUES (?, ?, ?, ?, 0)"
                );
                $ins->execute([$nome, $cognome, $email, $hash]);
                $newId = (int)$pdo->lastInsertId();

                // Genera OTP e salva nel DB (dentro la transazione)
                $codice   = generaOTP($pdo, $newId);
                $pdo->commit(); // commit prima di inviare email

                // Invia email — se fallisce l'utente esiste già ma può
                // richiedere un nuovo codice al prossimo login
                $mailInviata = inviaEmail($email, $nome,
                    'Verifica il tuo account OnePassage',
                    emailOTP($nome, $codice));

                $stepOTP   = true;
                $otpUserId = $newId;
                $successo  = $mailInviata
                    ? 'Account creato! Controlla la tua email per il codice di verifica.'
                    : 'Account creato! Purtroppo l\'email non è stata inviata — usa "Invia di nuovo" per ricevere il codice.';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errore = 'Errore durante la registrazione: ' . $e->getMessage();
                error_log('[OnePassage Reg] ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accedi — OnePassage</title>
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/auth.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

<main class="section-md">
<div class="auth-container container">

<?php if ($stepOTP): ?>
    <!-- ── STEP OTP ── -->
    <div class="card auth-otp-card">
        <div class="otp-icon"><i class="fas fa-envelope-open-text"></i></div>
        <h2>Controlla la tua email</h2>
        <p class="otp-subtitle">Abbiamo inviato un codice a 6 cifre al tuo indirizzo email.<br>Inseriscilo qui sotto — scade tra <strong>15 minuti</strong>.</p>

        <?php if ($errore): ?><div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= h($errore) ?></div><?php endif; ?>
        <?php if ($successo): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= h($successo) ?></div><?php endif; ?>

        <form method="post">
            <input type="hidden" name="otp_user_id" value="<?= $otpUserId ?>">
            <div class="otp-inputs">
                <input type="text" id="otp_codice" name="otp_codice" maxlength="6"
                       placeholder="000000" class="otp-field" autocomplete="one-time-code"
                       inputmode="numeric" pattern="[0-9]{6}" required autofocus>
            </div>
            <button type="submit" name="verify_otp" class="btn-primary" style="width:100%;justify-content:center;">
                <i class="fas fa-check"></i> Verifica
            </button>
        </form>
        <p class="otp-resend">
            Non hai ricevuto il codice?
            <form method="post" style="display:inline;">
                <input type="hidden" name="otp_user_id" value="<?= $otpUserId ?>">
                <button type="submit" name="resend_otp" style="background:none;border:none;color:var(--color-accent);font-weight:600;cursor:pointer;padding:0;font-size:inherit;">Invia di nuovo</button>
            </form>
        </p>
    </div>

<?php else: ?>
    <!-- ── TABS LOGIN / REGISTRAZIONE ── -->
    <div class="tabs" id="authTabs">
        <button class="tab active" data-tab="login" onclick="switchAuthTab('login')">
            <i class="fas fa-sign-in-alt"></i> Accedi
        </button>
        <button class="tab" data-tab="register" onclick="switchAuthTab('register')">
            <i class="fas fa-user-plus"></i> Registrati
        </button>
    </div>

    <?php if ($errore): ?><div class="alert alert-error" style="margin-top:12px;"><i class="fas fa-exclamation-circle"></i> <?= h($errore) ?></div><?php endif; ?>
    <?php if ($successo): ?><div class="alert alert-success" style="margin-top:12px;"><i class="fas fa-check-circle"></i> <?= h($successo) ?></div><?php endif; ?>

    <!-- LOGIN -->
    <div id="login-content" class="tab-content card active">
        <!-- SSO -->
        <div class="sso-buttons">
            <a href="sso_callback.php?provider=google" class="sso-btn sso-btn--google">
                <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v8.51h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.14z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/></svg>
                Continua con Google
            </a>
    <!--        <a href="sso_callback.php?provider=apple" class="sso-btn sso-btn--apple">
                <svg width="18" height="18" viewBox="0 0 814 1000" fill="currentColor"><path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.3-164-39.3c-76 0-103.7 40.8-165.9 40.8s-105-57.8-155.5-127.4C46 376.8 37 285.3 37 226.8c0-112.4 73.5-171.8 145.9-171.8 38.4 0 70.5 25.4 94.8 25.4 23.1 0 59.2-27.1 104.2-27.1 37.3 0 134.4 6.4 202.3 95.3zm-257-130.6c-5.1-32.5-17.9-72.8-45.7-102.2-23.8-25.6-60.8-44.5-96.8-44.5-1.9 0-3.8.1-5.7.4 1.3 33.8 13.5 73.5 41.3 103.4 24.5 27.1 62.5 47.3 107 47.3 1.9 0 3.8-.1 5.7-.2 0-1.4-.1-2.9-.1-4.2z"/></svg>
                Continua con Apple
            </a> -->
        </div>
        <div class="sso-divider"><span>oppure</span></div>

        <form method="post">
            <div class="form-group">
                <label for="email"><i class="fas fa-envelope"></i> Email</label>
                <input type="email" id="email" name="email" placeholder="tua@email.com"
                       required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password"><i class="fas fa-lock"></i> Password</label>
                <div class="input-password-wrap">
                    <input type="password" id="password" name="password" placeholder="••••••••"
                           required autocomplete="current-password">
                    <button type="button" class="toggle-pwd" onclick="togglePwd('password')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <button type="submit" name="login" class="btn-primary auth-submit">
                <i class="fas fa-sign-in-alt"></i> Accedi
            </button>
        </form>
    </div>

    <!-- REGISTRAZIONE -->
    <div id="register-content" class="tab-content card">
        <!-- SSO -->
        <div class="sso-buttons">
            <a href="sso_callback.php?provider=google" class="sso-btn sso-btn--google">
                <svg width="18" height="18" viewBox="0 0 48 48"><path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v8.51h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.14z"/><path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/><path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/><path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/></svg>
                Registrati con Google
            </a>
        <!--    <a href="sso_callback.php?provider=apple" class="sso-btn sso-btn--apple">
                <svg width="18" height="18" viewBox="0 0 814 1000" fill="currentColor"><path d="M788.1 340.9c-5.8 4.5-108.2 62.2-108.2 190.5 0 148.4 130.3 200.9 134.2 202.2-.6 3.2-20.7 71.9-68.7 141.9-42.8 61.6-87.5 123.1-155.5 123.1s-85.5-39.3-164-39.3c-76 0-103.7 40.8-165.9 40.8s-105-57.8-155.5-127.4C46 376.8 37 285.3 37 226.8c0-112.4 73.5-171.8 145.9-171.8 38.4 0 70.5 25.4 94.8 25.4 23.1 0 59.2-27.1 104.2-27.1 37.3 0 134.4 6.4 202.3 95.3zm-257-130.6c-5.1-32.5-17.9-72.8-45.7-102.2-23.8-25.6-60.8-44.5-96.8-44.5-1.9 0-3.8.1-5.7.4 1.3 33.8 13.5 73.5 41.3 103.4 24.5 27.1 62.5 47.3 107 47.3 1.9 0 3.8-.1 5.7-.2 0-1.4-.1-2.9-.1-4.2z"/></svg>
                Registrati con Apple
            </a> -->
        </div>
        <div class="sso-divider"><span>oppure</span></div>

        <form method="post">
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="nome"><i class="fas fa-user"></i> Nome</label>
                    <input type="text" id="nome" name="nome" placeholder="Mario" required autocomplete="given-name">
                </div>
                <div class="form-group">
                    <label for="cognome"><i class="fas fa-user"></i> Cognome</label>
                    <input type="text" id="cognome" name="cognome" placeholder="Rossi" required autocomplete="family-name">
                </div>
            </div>
            <div class="form-group">
                <label for="email_reg"><i class="fas fa-envelope"></i> Email</label>
                <input type="email" id="email_reg" name="email_reg" placeholder="tua@email.com" required autocomplete="email">
            </div>
            <div class="form-group">
                <label for="password_reg"><i class="fas fa-lock"></i> Password</label>
                <div class="input-password-wrap">
                    <input type="password" id="password_reg" name="password_reg"
                           placeholder="Min. 8 car., 1 maiusc., 1 numero, 1 speciale"
                           required autocomplete="new-password"
                           oninput="checkPwdStrength(this.value)">
                    <button type="button" class="toggle-pwd" onclick="togglePwd('password_reg')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <!-- Indicatore forza password -->
                <div class="pwd-strength-bar" id="pwdBar">
                    <div class="pwd-strength-fill" id="pwdFill"></div>
                </div>
                <div class="pwd-rules" id="pwdRules">
                    <span id="r-len"><i class="fas fa-times-circle"></i> 8+ caratteri</span>
                    <span id="r-upper"><i class="fas fa-times-circle"></i> Maiuscola</span>
                    <span id="r-num"><i class="fas fa-times-circle"></i> Numero</span>
                    <span id="r-spec"><i class="fas fa-times-circle"></i> Speciale</span>
                </div>
            </div>
            <div class="form-group">
                <label for="password_confirm"><i class="fas fa-lock"></i> Conferma password</label>
                <div class="input-password-wrap">
                    <input type="password" id="password_confirm" name="password_confirm"
                           placeholder="Ripeti la password" required autocomplete="new-password">
                    <button type="button" class="toggle-pwd" onclick="togglePwd('password_confirm')">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            <button type="submit" name="register" class="btn-primary auth-submit">
                <i class="fas fa-user-plus"></i> Crea account
            </button>
        </form>
    </div>
<?php endif; ?>

</div>
</main>

<script>
// ── Tabs ──────────────────────────────────────────────────────
function switchAuthTab(tab) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.querySelector('.tab[data-tab="' + tab + '"]').classList.add('active');
    document.getElementById(tab + '-content').classList.add('active');
}

// ── Mostra/nascondi password ──────────────────────────────────
function togglePwd(id) {
    var inp = document.getElementById(id);
    var btn = inp.nextElementSibling;
    if (inp.type === 'password') {
        inp.type = 'text';
        btn.innerHTML = '<i class="fas fa-eye-slash"></i>';
    } else {
        inp.type = 'password';
        btn.innerHTML = '<i class="fas fa-eye"></i>';
    }
}

// ── Forza password ────────────────────────────────────────────
function checkPwdStrength(v) {
    var rules = {
        'r-len':   v.length >= 8,
        'r-upper': /[A-Z]/.test(v),
        'r-num':   /[0-9]/.test(v),
        'r-spec':  /[\W_]/.test(v),
    };
    var score = Object.values(rules).filter(Boolean).length;
    var colors = ['#EF4444','#F59E0B','#F59E0B','#10B981','#10B981'];
    var fill = document.getElementById('pwdFill');
    if (fill) {
        fill.style.width  = (score * 25) + '%';
        fill.style.background = colors[score];
    }
    Object.entries(rules).forEach(function([id, ok]) {
        var el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = (ok ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-times-circle"></i>') + ' ' + el.textContent.replace(/^[^ ]+ /, '');
        el.classList.toggle('ok', ok);
    });
}

// ── OTP auto-submit a 6 cifre ─────────────────────────────────
var otpField = document.getElementById('otp_codice');
if (otpField) {
    otpField.addEventListener('input', function() {
        if (this.value.replace(/\D/g,'').length === 6) {
            this.value = this.value.replace(/\D/g,'');
            this.closest('form').submit();
        }
    });
}

// ── Tema ──────────────────────────────────────────────────────
function toggleTheme() {
    var html = document.documentElement;
    var t = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    html.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
}
</script>
</body>
</html>
