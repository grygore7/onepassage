<?php
require_once 'config.php';

if (!isLoggedIn()) {
    header('Location: auth.php');
    exit;
}

function adminTableExists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([$table]);
    return $cache[$table] = (bool)$stmt->fetchColumn();
}

function adminColumnExists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!adminTableExists($pdo, $table)) {
        return $cache[$key] = false;
    }

    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return $cache[$key] = (bool)$stmt->fetchColumn();
}

function adminUserLabel(?array $user): string
{
    if (!$user) {
        return 'Utente non disponibile';
    }

    $name = trim(($user['nome'] ?? '') . ' ' . ($user['cognome'] ?? ''));
    return $name !== '' ? $name : ($user['email'] ?? 'Utente #' . ($user['id'] ?? ''));
}

function adminStatusBadge(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['risolta', 'resolved', 'chiusa'], true)) {
        return 'badge-success';
    }
    if (in_array($status, ['bannato', 'banned', 'sospeso'], true)) {
        return 'badge-danger';
    }
    return 'badge-warning';
}

function adminBanColumn(PDO $pdo): ?array
{
    $columns = [
        'ban_status' => 'bannato',
        'is_banned' => 1,
        'banned' => 1,
        'stato' => 'bannato',
    ];

    foreach ($columns as $column => $value) {
        if (adminColumnExists($pdo, 'users', $column)) {
            return [$column, $value];
        }
    }

    return null;
}

function adminOffersTable(PDO $pdo): ?string
{
    if (adminTableExists($pdo, 'ride_offers')) {
        return 'ride_offers';
    }
    if (adminTableExists($pdo, 'offers')) {
        return 'offers';
    }
    return null;
}

$currentUserId = (int)$_SESSION['user_id'];
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$currentUserId]);
$currentUser = $stmt->fetch();

$isAdmin = false;
if ($currentUser) {
    if (adminColumnExists($pdo, 'users', 'ruolo')) {
        $isAdmin = in_array(strtolower((string)($currentUser['ruolo'] ?? '')), ['admin', 'amministratore'], true);
    }
    if (!$isAdmin && adminColumnExists($pdo, 'users', 'is_admin')) {
        $isAdmin = (int)($currentUser['is_admin'] ?? 0) === 1;
    }
}

if (!$isAdmin) {
    header('Location: auth.php');
    exit;
}

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

$feedback = '';
$feedbackType = 'success';
$activeTab = $_GET['tab'] ?? 'reports';

function adminRedirect(string $tab, string $type, string $message): void
{
    header('Location: admin_dashboard.php?tab=' . urlencode($tab) . '&' . $type . '=' . urlencode($message));
    exit;
}

if (isset($_GET['success'])) {
    $feedback = (string)$_GET['success'];
    $feedbackType = 'success';
} elseif (isset($_GET['error'])) {
    $feedback = (string)$_GET['error'];
    $feedbackType = 'error';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    $activeTab = $_POST['active_tab'] ?? 'reports';

    if (!hash_equals($_SESSION['admin_csrf'], $postedToken)) {
        adminRedirect($activeTab, 'error', 'Sessione scaduta. Riprova.');
    }

    try {
        if ($action === 'resolve_report') {
            $reportId = (int)($_POST['report_id'] ?? 0);
            if ($reportId <= 0 || !adminTableExists($pdo, 'reports')) {
                throw new RuntimeException('Segnalazione non valida.');
            }

            $stmt = $pdo->prepare("UPDATE reports SET stato = 'risolta' WHERE id = ?");
            $stmt->execute([$reportId]);
            adminRedirect('reports', 'success', 'Segnalazione risolta.');
        }

        if ($action === 'ban_user') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            if ($targetUserId <= 0) {
                throw new RuntimeException('Utente non valido.');
            }
            if ($targetUserId === $currentUserId) {
                throw new RuntimeException('Non puoi bannare il tuo account amministratore.');
            }

            $banColumn = adminBanColumn($pdo);
            if (!$banColumn) {
                throw new RuntimeException('Nessuna colonna ban trovata in users.');
            }

            [$column, $value] = $banColumn;
            $stmt = $pdo->prepare("UPDATE users SET `$column` = ? WHERE id = ?");
            $stmt->execute([$value, $targetUserId]);
            adminRedirect($activeTab, 'success', 'Utente bannato.');
        }

        if ($action === 'toggle_admin') {
            $targetUserId = (int)($_POST['user_id'] ?? 0);
            $makeAdmin = (int)($_POST['make_admin'] ?? 0) === 1;
            if ($targetUserId <= 0) {
                throw new RuntimeException('Utente non valido.');
            }

            if (adminColumnExists($pdo, 'users', 'ruolo')) {
                $newRole = $makeAdmin ? 'admin' : 'user';
                $stmt = $pdo->prepare('UPDATE users SET ruolo = ? WHERE id = ?');
                $stmt->execute([$newRole, $targetUserId]);
            } elseif (adminColumnExists($pdo, 'users', 'is_admin')) {
                $stmt = $pdo->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
                $stmt->execute([$makeAdmin ? 1 : 0, $targetUserId]);
            } else {
                throw new RuntimeException('Nessuna colonna admin trovata in users.');
            }

            adminRedirect('users', 'success', $makeAdmin ? 'Permessi admin concessi.' : 'Permessi admin revocati.');
        }

        if ($action === 'delete_offer') {
            $offerId = (int)($_POST['offer_id'] ?? 0);
            $offersTable = adminOffersTable($pdo);
            if ($offerId <= 0 || !$offersTable) {
                throw new RuntimeException('Corsa non valida.');
            }

            $stmt = $pdo->prepare("DELETE FROM `$offersTable` WHERE id = ?");
            $stmt->execute([$offerId]);
            adminRedirect($activeTab, 'success', 'Corsa eliminata.');
        }

        throw new RuntimeException('Azione non riconosciuta.');
    } catch (Throwable $e) {
        adminRedirect($activeTab, 'error', $e->getMessage());
    }
}

$reports = [];
if (adminTableExists($pdo, 'reports')) {
    $reportDateColumn = adminColumnExists($pdo, 'reports', 'data_invio') ? 'r.data_invio DESC,' : '';
    $reportsSql = "
        SELECT
            r.*,
            reporter.nome AS reporter_nome,
            reporter.cognome AS reporter_cognome,
            reporter.email AS reporter_email,
            reported.nome AS reported_nome,
            reported.cognome AS reported_cognome,
            reported.email AS reported_email
        FROM reports r
        LEFT JOIN users reporter ON reporter.id = r.segnalatore_id
        LEFT JOIN users reported ON reported.id = r.segnalato_id
        ORDER BY
            CASE WHEN r.stato = 'aperta' THEN 0 ELSE 1 END,
            $reportDateColumn
            r.id DESC
    ";
    $reports = $pdo->query($reportsSql)->fetchAll();
}

$search = trim($_GET['q'] ?? '');
$usersSql = 'SELECT * FROM users';
$usersParams = [];
if ($search !== '') {
    $usersSql .= ' WHERE email LIKE ? OR nome LIKE ? OR cognome LIKE ?';
    $term = '%' . $search . '%';
    $usersParams = [$term, $term, $term];
}
$usersSql .= ' ORDER BY id DESC LIMIT 100';
$stmt = $pdo->prepare($usersSql);
$stmt->execute($usersParams);
$users = $stmt->fetchAll();

$totalUsers = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$openReports = adminTableExists($pdo, 'reports')
    ? (int)$pdo->query("SELECT COUNT(*) FROM reports WHERE stato = 'aperta'")->fetchColumn()
    : 0;

$offers = [];
$offersTable = adminOffersTable($pdo);
if ($offersTable === 'ride_offers') {
    if (adminTableExists($pdo, 'events')) {
        $dateColumn = adminColumnExists($pdo, 'events', 'data_evento') ? 'e.data_evento' : 'ro.id';
        $offersSql = "
            SELECT
                ro.id,
                ro.user_id AS driver_id,
                ro.posti_disponibili,
                ro.punto_partenza,
                ro.prezzo_per_posto,
                e.nome_evento,
                e.luogo,
                e.data_evento,
                u.nome,
                u.cognome,
                u.email
            FROM ride_offers ro
            LEFT JOIN events e ON e.id = ro.event_id
            LEFT JOIN users u ON u.id = ro.user_id
            ORDER BY $dateColumn DESC
            LIMIT 100
        ";
    } else {
        $offersSql = "
            SELECT
                ro.id,
                ro.user_id AS driver_id,
                ro.posti_disponibili,
                ro.punto_partenza,
                ro.prezzo_per_posto,
                NULL AS nome_evento,
                NULL AS luogo,
                NULL AS data_evento,
                u.nome,
                u.cognome,
                u.email
            FROM ride_offers ro
            LEFT JOIN users u ON u.id = ro.user_id
            ORDER BY ro.id DESC
            LIMIT 100
        ";
    }
    $offers = $pdo->query($offersSql)->fetchAll();
} elseif ($offersTable === 'offers') {
    $offersSql = "
        SELECT
            o.*,
            u.nome,
            u.cognome,
            u.email
        FROM offers o
        LEFT JOIN users u ON u.id = o.driver_id
        ORDER BY o.data_viaggio DESC, o.id DESC
        LIMIT 100
    ";
    $offers = $pdo->query($offersSql)->fetchAll();
}

$totalOffers = $offersTable
    ? (int)$pdo->query("SELECT COUNT(*) FROM `$offersTable`")->fetchColumn()
    : 0;

$banColumn = adminBanColumn($pdo);
$canEditAdmin = adminColumnExists($pdo, 'users', 'ruolo') || adminColumnExists($pdo, 'users', 'is_admin');
$csrf = $_SESSION['admin_csrf'];
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <script>(function(){var t=localStorage.getItem('theme')||'light';document.documentElement.setAttribute('data-theme',t);})();</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - OnePassage</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .admin-page { padding: 56px 0 96px; }
        .admin-hero { display: flex; justify-content: space-between; gap: 24px; align-items: flex-end; margin-bottom: 28px; }
        .admin-eyebrow { color: var(--color-accent); font-weight: 700; font-size: 13px; text-transform: uppercase; letter-spacing: .08em; margin-bottom: 8px; }
        .admin-title { font-size: 42px; line-height: 1.08; letter-spacing: -.02em; margin: 0 0 10px; }
        .admin-subtitle { color: var(--color-text-muted); font-size: 16px; line-height: 1.6; max-width: 680px; }
        .admin-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 24px; }
        .admin-stat { border: 1px solid var(--color-border); background: var(--color-card-bg); border-radius: 18px; padding: 20px; backdrop-filter: blur(16px); }
        .admin-stat strong { display: block; font-size: 30px; line-height: 1; margin-bottom: 8px; }
        .admin-stat span { color: var(--color-text-muted); font-size: 13px; font-weight: 600; }
        .admin-panel { background: var(--color-card-bg); border: 1px solid var(--color-border); border-radius: 22px; box-shadow: var(--shadow-sm); backdrop-filter: blur(18px); overflow: hidden; }
        .admin-tabs { display: flex; gap: 8px; padding: 16px; border-bottom: 1px solid var(--color-border); overflow-x: auto; }
        .admin-tab { border: 0; background: transparent; color: var(--color-text-secondary); border-radius: 100px; padding: 12px 18px; font: 700 14px/1 Inter, sans-serif; cursor: pointer; white-space: nowrap; display: inline-flex; align-items: center; gap: 8px; }
        .admin-tab.active { background: var(--color-accent); color: #fff; }
        .admin-content { display: none; padding: 24px; }
        .admin-content.active { display: block; }
        .admin-toolbar { display: flex; justify-content: space-between; gap: 16px; align-items: center; margin-bottom: 18px; }
        .admin-toolbar h2 { font-size: 22px; margin: 0; }
        .admin-search { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
        .admin-search .form-group { margin: 0; min-width: min(360px, 100%); }
        .admin-alert { display: flex; align-items: center; gap: 10px; padding: 14px 16px; border-radius: 16px; margin-bottom: 18px; font-weight: 600; }
        .admin-alert.success { background: rgba(16, 185, 129, .12); color: var(--color-accent); }
        .admin-alert.error { background: rgba(239, 68, 68, .12); color: #EF4444; }
        .admin-table-wrap { overflow-x: auto; border: 1px solid var(--color-border); border-radius: 18px; background: rgba(255,255,255,.34); }
        [data-theme="dark"] .admin-table-wrap { background: rgba(15,20,25,.24); }
        .admin-table { width: 100%; border-collapse: collapse; min-width: 860px; }
        .admin-table th, .admin-table td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--color-border); vertical-align: top; font-size: 14px; }
        .admin-table th { color: var(--color-text-muted); font-size: 12px; text-transform: uppercase; letter-spacing: .06em; }
        .admin-table tr:last-child td { border-bottom: 0; }
        .admin-primary { font-weight: 700; color: var(--color-text-primary); }
        .admin-muted { color: var(--color-text-muted); font-size: 12px; margin-top: 4px; }
        .admin-actions { display: flex; flex-wrap: wrap; gap: 8px; }
        .admin-action { border: 1px solid var(--color-border); border-radius: 100px; padding: 9px 12px; background: transparent; color: var(--color-text-primary); font: 700 12px/1 Inter, sans-serif; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; }
        .admin-action:hover { border-color: var(--color-accent); color: var(--color-accent); }
        .admin-action.danger { color: #EF4444; }
        .admin-action.danger:hover { border-color: #EF4444; background: rgba(239,68,68,.08); }
        .admin-empty { text-align: center; padding: 44px 20px; color: var(--color-text-muted); }
        .admin-empty i { display: block; font-size: 28px; color: var(--color-accent); margin-bottom: 12px; }
        .admin-description { max-width: 340px; color: var(--color-text-secondary); line-height: 1.45; }
        .admin-description small { color: var(--color-text-muted); display: block; margin-top: 6px; }
        @media (max-width: 768px) {
            .admin-page { padding: 34px 0 64px; }
            .admin-hero { display: block; }
            .admin-title { font-size: 30px; }
            .admin-stats { grid-template-columns: 1fr; }
            .admin-toolbar { display: block; }
            .admin-toolbar h2 { margin-bottom: 14px; }
            .admin-content { padding: 16px; }
        }
    </style>
</head>
<body>
<?php include 'header_snippet.php'; ?>

<main class="admin-page">
    <div class="container">
        <section class="admin-hero">
            <div>
                <p class="admin-eyebrow">Area amministrazione</p>
                <h1 class="admin-title">Moderazione OnePassage</h1>
                <p class="admin-subtitle">Controlla segnalazioni, utenti e corse da un unico pannello operativo, con azioni rapide e conferme esplicite.</p>
            </div>
            <a class="btn-outline" href="dashboard.php"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </section>

        <section class="admin-stats" aria-label="Riepilogo amministrazione">
            <div class="admin-stat"><strong><?= $openReports ?></strong><span>Segnalazioni aperte</span></div>
            <div class="admin-stat"><strong><?= $totalUsers ?></strong><span>Utenti registrati</span></div>
            <div class="admin-stat"><strong><?= $totalOffers ?></strong><span>Corse presenti</span></div>
        </section>

        <?php if ($feedback): ?>
            <div class="admin-alert <?= $feedbackType ?>">
                <i class="fas fa-<?= $feedbackType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                <?= h($feedback) ?>
            </div>
        <?php endif; ?>

        <section class="admin-panel">
            <div class="admin-tabs" role="tablist" aria-label="Sezioni admin">
                <button class="admin-tab <?= $activeTab === 'reports' ? 'active' : '' ?>" data-tab="reports" type="button"><i class="fas fa-flag"></i> Segnalazioni</button>
                <button class="admin-tab <?= $activeTab === 'users' ? 'active' : '' ?>" data-tab="users" type="button"><i class="fas fa-users"></i> Utenti</button>
                <button class="admin-tab <?= $activeTab === 'offers' ? 'active' : '' ?>" data-tab="offers" type="button"><i class="fas fa-car"></i> Corse</button>
            </div>

            <div id="reports-content" class="admin-content <?= $activeTab === 'reports' ? 'active' : '' ?>">
                <div class="admin-toolbar">
                    <h2>Pannello segnalazioni</h2>
                    <span class="badge badge-warning"><?= count($reports) ?> totali</span>
                </div>

                <?php if (!adminTableExists($pdo, 'reports')): ?>
                    <div class="admin-empty"><i class="fas fa-database"></i>La tabella reports non e' ancora presente nel database.</div>
                <?php elseif (!$reports): ?>
                    <div class="admin-empty"><i class="fas fa-check-circle"></i>Nessuna segnalazione da gestire.</div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Chi segnala</th>
                                    <th>Segnalato</th>
                                    <th>Motivo</th>
                                    <th>Stato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($reports as $report): ?>
                                <?php
                                    $reporter = adminUserLabel([
                                        'nome' => $report['reporter_nome'] ?? '',
                                        'cognome' => $report['reporter_cognome'] ?? '',
                                        'email' => $report['reporter_email'] ?? '',
                                    ]);
                                    $reported = adminUserLabel([
                                        'nome' => $report['reported_nome'] ?? '',
                                        'cognome' => $report['reported_cognome'] ?? '',
                                        'email' => $report['reported_email'] ?? '',
                                    ]);
                                    $offerId = (int)($report['offerta_id'] ?? 0);
                                ?>
                                <tr>
                                    <td>
                                        <div class="admin-primary"><?= h($reporter) ?></div>
                                        <div class="admin-muted"><?= h($report['reporter_email'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div class="admin-primary"><?= h($reported) ?></div>
                                        <div class="admin-muted"><?= h($report['reported_email'] ?? '') ?></div>
                                    </td>
                                    <td>
                                        <div class="admin-description">
                                            <strong><?= h($report['motivo'] ?? 'Segnalazione') ?></strong>
                                            <small><?= h($report['descrizione'] ?? '') ?></small>
                                        </div>
                                    </td>
                                    <td><span class="badge <?= adminStatusBadge((string)($report['stato'] ?? 'aperta')) ?>"><?= h($report['stato'] ?? 'aperta') ?></span></td>
                                    <td>
                                        <div class="admin-actions">
                                            <form method="post">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="active_tab" value="reports">
                                                <input type="hidden" name="action" value="resolve_report">
                                                <input type="hidden" name="report_id" value="<?= (int)$report['id'] ?>">
                                                <button class="admin-action" type="submit"><i class="fas fa-check"></i> Risolvi</button>
                                            </form>
                                            <form method="post" onsubmit="return confirm('Bannare questo utente?');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="active_tab" value="reports">
                                                <input type="hidden" name="action" value="ban_user">
                                                <input type="hidden" name="user_id" value="<?= (int)($report['segnalato_id'] ?? 0) ?>">
                                                <button class="admin-action danger" type="submit"><i class="fas fa-ban"></i> Ban utente</button>
                                            </form>
                                            <?php if ($offerId > 0 && $offersTable): ?>
                                            <form method="post" onsubmit="return confirm('Eliminare questa corsa?');">
                                                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                                <input type="hidden" name="active_tab" value="reports">
                                                <input type="hidden" name="action" value="delete_offer">
                                                <input type="hidden" name="offer_id" value="<?= $offerId ?>">
                                                <button class="admin-action danger" type="submit"><i class="fas fa-trash"></i> Elimina corsa</button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div id="users-content" class="admin-content <?= $activeTab === 'users' ? 'active' : '' ?>">
                <div class="admin-toolbar">
                    <h2>Gestione utenti</h2>
                    <form class="admin-search" method="get">
                        <input type="hidden" name="tab" value="users">
                        <div class="form-group">
                            <label for="q">Cerca per nome o email</label>
                            <input id="q" name="q" type="search" value="<?= h($search) ?>" placeholder="nome@email.com">
                        </div>
                        <button class="btn-primary" type="submit"><i class="fas fa-search"></i> Cerca</button>
                    </form>
                </div>

                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Utente</th>
                                <th>Registrazione</th>
                                <th>Ruolo</th>
                                <th>Stato</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($users as $user): ?>
                            <?php
                                $userIsAdmin = adminColumnExists($pdo, 'users', 'ruolo')
                                    ? in_array(strtolower((string)($user['ruolo'] ?? '')), ['admin', 'amministratore'], true)
                                    : ((int)($user['is_admin'] ?? 0) === 1);
                                $banStatus = $banColumn ? (string)($user[$banColumn[0]] ?? 'attivo') : 'non configurato';
                            ?>
                            <tr>
                                <td>
                                    <div class="admin-primary"><?= h(adminUserLabel($user)) ?></div>
                                    <div class="admin-muted"><?= h($user['email'] ?? '') ?></div>
                                </td>
                                <td><?= h($user['data_registrazione'] ?? $user['created_at'] ?? '-') ?></td>
                                <td><span class="badge <?= $userIsAdmin ? 'badge-success' : 'badge-pending' ?>"><?= $userIsAdmin ? 'Admin' : 'Utente' ?></span></td>
                                <td><span class="badge <?= adminStatusBadge($banStatus) ?>"><?= h($banStatus) ?></span></td>
                                <td>
                                    <div class="admin-actions">
                                        <?php if ($canEditAdmin): ?>
                                        <form method="post" onsubmit="return confirm('Aggiornare i permessi di questo utente?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="active_tab" value="users">
                                            <input type="hidden" name="action" value="toggle_admin">
                                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                            <input type="hidden" name="make_admin" value="<?= $userIsAdmin ? 0 : 1 ?>">
                                            <button class="admin-action" type="submit"><i class="fas fa-user-shield"></i> <?= $userIsAdmin ? 'Revoca admin' : 'Rendi admin' ?></button>
                                        </form>
                                        <?php endif; ?>
                                        <?php if ($banColumn && (int)$user['id'] !== $currentUserId): ?>
                                        <form method="post" onsubmit="return confirm('Bannare questo utente?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="active_tab" value="users">
                                            <input type="hidden" name="action" value="ban_user">
                                            <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                                            <button class="admin-action danger" type="submit"><i class="fas fa-ban"></i> Ban</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="offers-content" class="admin-content <?= $activeTab === 'offers' ? 'active' : '' ?>">
                <div class="admin-toolbar">
                    <h2>Monitoraggio corse</h2>
                    <span class="badge badge-pending"><?= count($offers) ?> visualizzate</span>
                </div>

                <?php if (!$offersTable): ?>
                    <div class="admin-empty"><i class="fas fa-database"></i>Nessuna tabella corse trovata.</div>
                <?php elseif (!$offers): ?>
                    <div class="admin-empty"><i class="fas fa-car"></i>Nessuna corsa presente.</div>
                <?php else: ?>
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th>Corsa</th>
                                    <th>Autista</th>
                                    <th>Data</th>
                                    <th>Posti</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($offers as $offer): ?>
                                <?php
                                    $title = $offersTable === 'ride_offers'
                                        ? ($offer['nome_evento'] ?? 'Passaggio')
                                        : (($offer['citta_partenza'] ?? 'Partenza') . ' - ' . ($offer['destinazione'] ?? 'Destinazione'));
                                    $place = $offersTable === 'ride_offers'
                                        ? ($offer['punto_partenza'] ?? $offer['luogo'] ?? '')
                                        : ($offer['citta_partenza'] ?? '') . ' - ' . ($offer['destinazione'] ?? '');
                                    $date = $offersTable === 'ride_offers'
                                        ? ($offer['data_evento'] ?? '-')
                                        : ($offer['data_viaggio'] ?? '-');
                                ?>
                                <tr>
                                    <td>
                                        <div class="admin-primary"><?= h($title) ?></div>
                                        <div class="admin-muted"><?= h($place) ?></div>
                                    </td>
                                    <td>
                                        <div class="admin-primary"><?= h(trim(($offer['nome'] ?? '') . ' ' . ($offer['cognome'] ?? '')) ?: 'Autista') ?></div>
                                        <div class="admin-muted"><?= h($offer['email'] ?? '') ?></div>
                                    </td>
                                    <td><?= h($date) ?></td>
                                    <td><?= h((string)($offer['posti_disponibili'] ?? '-')) ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Eliminare questa corsa?');">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                            <input type="hidden" name="active_tab" value="offers">
                                            <input type="hidden" name="action" value="delete_offer">
                                            <input type="hidden" name="offer_id" value="<?= (int)$offer['id'] ?>">
                                            <button class="admin-action danger" type="submit"><i class="fas fa-trash"></i> Elimina</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<script>
document.querySelectorAll('.admin-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        var name = tab.dataset.tab;
        document.querySelectorAll('.admin-tab').forEach(function(item) {
            item.classList.toggle('active', item.dataset.tab === name);
        });
        document.querySelectorAll('.admin-content').forEach(function(panel) {
            panel.classList.toggle('active', panel.id === name + '-content');
        });
        var url = new URL(window.location.href);
        url.searchParams.set('tab', name);
        window.history.replaceState({}, '', url.toString());
    });
});
</script>
</body>
</html>
