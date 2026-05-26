<?php
$_hLogged   = isset($_SESSION['user_id']);
$_hNome     = htmlspecialchars($_SESSION['user_nome']    ?? '', ENT_QUOTES, 'UTF-8');
$_hCognome  = htmlspecialchars($_SESSION['user_cognome'] ?? '', ENT_QUOTES, 'UTF-8');
$_hEmail    = htmlspecialchars($_SESSION['user_email']   ?? '', ENT_QUOTES, 'UTF-8');
$_hFoto = $_SESSION['user_foto_profilo'] ?? '';
$_hId       = (int)($_SESSION['user_id'] ?? 0);
$_hInitials = '';
if ($_hLogged) {
    $_hInitials  = strtoupper(substr($_SESSION['user_nome']    ?? 'U', 0, 1));
    $_hInitials .= strtoupper(substr($_SESSION['user_cognome'] ?? '',  0, 1));
}
?>
<header class="header">
    <div class="header-container">
        <a href="index.php" class="logo">OnePassage</a>
        <nav class="nav">
            <!-- Lente — link alla ricerca -->
            <a href="ricerca.php" class="nav-icon-btn" aria-label="Cerca eventi" title="Cerca passaggi">
                <i class="fas fa-search"></i>
            </a>

            <?php if ($_hLogged): ?>
            <!-- Dashboard link -->
            <a href="dashboard.php" class="nav-link">Dashboard</a>

            <!-- Avatar dropdown -->
            <div class="header-avatar-wrap">
                <button class="header-avatar" id="avatarBtn"
                        onclick="toggleAvatarMenu(event)" aria-label="Menu utente"
                        aria-expanded="false">
                    <?php if (!empty($_hFoto)): ?>
    <img src="uploads/<?php echo h(basename($_hFoto)); ?>" alt="Profilo">
<?php else: ?>
    <?php echo $_hInitials; ?>
<?php endif; ?>
                </button>
                <div class="header-avatar-menu" id="avatarMenu">
                    <div class="avatar-menu-user">
                        <div class="avatar-menu-name"><?php echo $_hNome . ($_hCognome ? ' ' . $_hCognome : ''); ?></div>
                        <?php if ($_hEmail): ?>
                        <div class="avatar-menu-email"><?php echo $_hEmail; ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="avatar-menu-divider"></div>
                    <a href="profilo.php?id=<?php echo $_hId; ?>" class="avatar-menu-item">
                        <i class="fas fa-user"></i> Vedi profilo
                    </a>
                    <a href="modifica_profilo.php" class="avatar-menu-item">
                        <i class="fas fa-pen"></i> Modifica profilo
                    </a>

                    
                    <a href="offri_passaggio.php" class="avatar-menu-item">
                        <i class="fas fa-car"></i> Offri passaggio
                    </a>
                    <div class="avatar-menu-divider"></div>
                    <a href="auth.php?logout=1" class="avatar-menu-item avatar-menu-item--danger">
                        <i class="fas fa-sign-out-alt"></i> Logout
                    </a>
                </div>
            </div>

            <?php else: ?>
            <!-- Non loggato -->
            <a href="auth.php" class="header-auth-btn">
                <i class="fas fa-user"></i> Accedi
            </a>
            <?php endif; ?>

            <!-- Hamburger mobile -->
            <button class="nav-hamburger" id="navHamburger"
                    onclick="openMobileNav()" aria-label="Apri menu">
                <i class="fas fa-bars"></i>
            </button>

            <!-- Theme toggle -->
            <button class="theme-toggle" onclick="toggleTheme()" aria-label="Cambia tema">
                <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
                <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
            </button>
        </nav>
    </div>
</header>

<!-- ── Mobile Drawer ── -->
<div class="mobile-nav-overlay" id="mobileNavOverlay" onclick="closeMobileNav()"></div>
<div class="mobile-nav-drawer" id="mobileNavDrawer">
    <div class="drawer-header">
        <span class="drawer-logo">OnePassage</span>
        <button class="drawer-close" onclick="closeMobileNav()" aria-label="Chiudi">
            <i class="fas fa-times"></i>
        </button>
    </div>
    <div class="drawer-links">
        <a href="ricerca.php" class="drawer-link">
            <i class="fas fa-search"></i> Cerca passaggi
        </a>
        <?php if ($_hLogged): ?>
        <a href="dashboard.php" class="drawer-link">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="offri_passaggio.php" class="drawer-link">
            <i class="fas fa-car"></i> Offri passaggio
        </a>
        <div class="drawer-divider"></div>
        <a href="profilo.php?id=<?php echo $_hId; ?>" class="drawer-link">
            <i class="fas fa-user"></i> Il mio profilo
        </a>
        <a href="modifica_profilo.php" class="drawer-link">
            <i class="fas fa-pen"></i> Modifica profilo
        </a>
        <div class="drawer-divider"></div>
        <div class="drawer-footer">
            <a href="auth.php?logout=1" class="drawer-link drawer-link--danger">
                <i class="fas fa-sign-out-alt" style="color:#EF4444;"></i> Logout
            </a>
        </div>
        <?php else: ?>
        <div class="drawer-divider"></div>
<div class="drawer-footer">
    <button type="button" class="drawer-link drawer-theme-toggle" onclick="toggleTheme()">
        <i class="fas fa-adjust"></i> Cambia tema
    </button>
    <a href="auth.php?logout=1" class="drawer-link drawer-link--danger">
        <i class="fas fa-sign-out-alt"></i> Logout
    </a>
</div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAvatarMenu(e) {
    e.stopPropagation();
    var menu = document.getElementById('avatarMenu');
    if (!menu) return;
    var open = menu.classList.toggle('open');
    document.getElementById('avatarBtn').setAttribute('aria-expanded', String(open));
}
document.addEventListener('click', function() {
    var m = document.getElementById('avatarMenu');
    if (m) m.classList.remove('open');
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var m = document.getElementById('avatarMenu');
        if (m) m.classList.remove('open');
        closeMobileNav();
    }
});
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
function toggleTheme() {
    var t = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('theme', t);
    var icon = document.querySelector('.theme-toggle i');
    if (icon) icon.className = t === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
}
</script>
