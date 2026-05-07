<?php
require_once 'config.php';

// ── Logout ──
if (isset($_GET['logout'])) {
    session_unset();
    session_destroy();
    // Distrugge il cookie di sessione
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    header('Location: auth.php');
    exit;
}

$errore = '';
$successo = '';

// Gestione Login
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    if(empty($email) || empty($password)) {
        $errore = 'Inserisci email e password';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id']      = $user['id'];
            $_SESSION['user_nome']    = $user['nome'];
            $_SESSION['user_cognome'] = $user['cognome'];
            $_SESSION['user_email']   = $user['email'];
            header('Location: dashboard.php');
            exit;
        } else {
            $errore = 'Credenziali non valide';
        }
    }
}

// Gestione Registrazione
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    $nome = trim($_POST['nome']);
    $cognome = trim($_POST['cognome']);
    $email = trim($_POST['email_reg']);
    $password = $_POST['password_reg'];
    $password_confirm = $_POST['password_confirm'];
    
    if(empty($nome) || empty($cognome) || empty($email) || empty($password)) {
        $errore = 'Compila tutti i campi obbligatori';
    } elseif(!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errore = 'Email non valida';
    } elseif(strlen($password) < 6) {
        $errore = 'La password deve essere di almeno 6 caratteri';
    } elseif($password !== $password_confirm) {
        $errore = 'Le password non coincidono';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        
        if($stmt->fetch()) {
            $errore = 'Email giÃ  registrata';
        } else {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            try {
                $stmt = $pdo->prepare("INSERT INTO users (nome, cognome, email, password_hash) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nome, $cognome, $email, $password_hash]);
                $successo = 'Registrazione completata! Ora puoi accedere.';
            } catch(PDOException $e) {
                $errore = 'Errore durante la registrazione';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accedi - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/auth.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-container">
            <a href="index.php" class="logo">OnePassage</a>
            <nav class="nav">
                <a href="ricerca.php" class="nav-link">Eventi</a>
                <a href="come-funziona.php" class="nav-link">Come funziona</a>
                <a href="auth.php" class="btn-outline active">Accedi</a>
                <button class="nav-hamburger" id="navHamburger" onclick="openMobileNav()" aria-label="Apri menu">
                    <i class="fas fa-bars"></i>
                </button>
                <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle theme">
                    <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="5"/>
                        <line x1="12" y1="1" x2="12" y2="3"/>
                        <line x1="12" y1="21" x2="12" y2="23"/>
                        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
                        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                        <line x1="1" y1="12" x2="3" y2="12"/>
                        <line x1="21" y1="12" x2="23" y2="12"/>
                        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
                        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                    </svg>
                    <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                </button>
            </nav>
        </div>
    </header>

    <div class="section-md">
        <div class="auth-container container">
            
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

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('login')">
                    <i class="fas fa-sign-in-alt"></i> Accedi
                </button>
                <button class="tab" onclick="switchTab('register')">
                    <i class="fas fa-user-plus"></i> Registrati
                </button>
            </div>

            <!-- Login Form -->
            <div id="login-content" class="tab-content active">
                <div class="card">
                    <h2 class="section-title">Bentornato!</h2>
                    
                    <form method="POST" action="">
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" required placeholder="tua@email.com">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password</label>
                            <input type="password" name="password" required placeholder="****************">
                        </div>
                        
                        <button type="submit" name="login" class="btn-primary" style="width: 100%; margin-top: 8px;">
                            <i class="fas fa-sign-in-alt"></i> Accedi
                        </button>
                    </form>
                    
                    <div class="auth-separator">
                        Non hai un account? 
                        <a href="#" onclick="switchTab('register'); return false;">Registrati ora</a>
                    </div>
                </div>
            </div>

            <!-- Register Form -->
            <div id="register-content" class="tab-content">
                <div class="card">
                    <h2 class="section-title">Crea il tuo account</h2>
                    
                    <form method="POST" action="">
                        <div class="form-grid-2">
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Nome *</label>
                                <input type="text" name="nome" required placeholder="Mario">
                            </div>
                            
                            <div class="form-group">
                                <label><i class="fas fa-user"></i> Cognome *</label>
                                <input type="text" name="cognome" required placeholder="Rossi">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email *</label>
                            <input type="email" name="email_reg" required placeholder="tua@email.com">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Password * (min. 6 caratteri)</label>
                            <input type="password" name="password_reg" required minlength="6" placeholder="******">
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> Conferma Password *</label>
                            <input type="password" name="password_confirm" required minlength="6" placeholder="******">
                        </div>
                        
                        <div class="info-box">
                            <label>
                                <input type="checkbox" required>
                                <span>Accetto i <a href="#">Termini di Servizio</a> e la <a href="#">Privacy Policy</a></span>
                            </label>
                        </div>
                        
                        <button type="submit" name="register" class="btn-primary" style="width: 100%;">
                            <i class="fas fa-user-plus"></i> Registrati
                        </button>
                    </form>
                    
                    <div class="auth-separator">
                        Hai giÃ  un account? 
                        <a href="#" onclick="switchTab('login'); return false;">Accedi</a>
                    </div>
                </div>
            </div>

            <!-- Benefits -->
            <div class="benefits-section">
                <h3 class="benefits-title">PerchÃ© iscriversi a OnePassage?</h3>
                
                <div class="benefits-grid">
                    <div class="benefit-item card">
                        <div class="benefit-icon"><i class="fas fa-piggy-bank"></i></div>
                        <h4 class="benefit-title">Risparmia</h4>
                        <p class="benefit-text">Condividi le spese di viaggio verso i tuoi eventi</p>
                    </div>
                    
                    <div class="benefit-item card">
                        <div class="benefit-icon"><i class="fas fa-users"></i></div>
                        <h4 class="benefit-title">Socializza</h4>
                        <p class="benefit-text">Incontra persone con la tua stessa passione</p>
                    </div>
                    
                    <div class="benefit-item card">
                        <div class="benefit-icon"><i class="fas fa-shield-alt"></i></div>
                        <h4 class="benefit-title">Sicurezza</h4>
                        <p class="benefit-text">Sistema di recensioni per viaggi sicuri</p>
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
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            if(tab === 'login') {
                document.querySelector('.tab:nth-child(1)').classList.add('active');
                document.getElementById('login-content').classList.add('active');
            } else {
                document.querySelector('.tab:nth-child(2)').classList.add('active');
                document.getElementById('register-content').classList.add('active');
            }
        }

        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        }

        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();

        let ticking = false;
        function updateParallax() {
            const scrollY = window.scrollY;
            const offset = Math.min(scrollY * 0.5, 300);
            document.body.style.setProperty('--scroll-x', `${offset}px`);
            ticking = false;
        }

        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(updateParallax);
                ticking = true;
            }
        }, { passive: true });
    </script>
<!-- ── Mobile Navigation Drawer ── -->
<div class="mobile-nav-overlay" id="mobileNavOverlay" onclick="closeMobileNav()"></div>
<div class="mobile-nav-drawer" id="mobileNavDrawer">
    <div class="drawer-header">
        <span class="drawer-logo">OnePassage</span>
        <button class="drawer-close" onclick="closeMobileNav()"><i class="fas fa-times"></i></button>
    </div>
    <div class="drawer-links">
        <a href="ricerca.php" class="drawer-link"><i class="fas fa-search"></i> Eventi</a>
        <a href="come-funziona.php" class="drawer-link"><i class="fas fa-info-circle"></i> Come funziona</a>
        <div class="drawer-divider"></div>
        <a href="auth.php" class="drawer-link btn-primary" style="justify-content:center;color:#fff;background:var(--color-accent);">
            <i class="fas fa-user"></i> Accedi / Registrati
        </a>
    </div>
</div>
<script>
function openMobileNav() {
    document.getElementById('mobileNavOverlay').classList.add('open');
    document.getElementById('mobileNavDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeMobileNav() {
    document.getElementById('mobileNavOverlay').classList.remove('open');
    document.getElementById('mobileNavDrawer').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if(e.key==='Escape') closeMobileNav(); });
</script>
</body>
</html>
