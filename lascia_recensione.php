<?php
require_once 'config.php';
if (!isLoggedIn()) { header('Location: auth.php'); exit; }

$requestId = isset($_GET['request']) ? (int)$_GET['request'] : 0;
$userId    = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT rr.*,
           e.nome_evento, e.data_evento,
           ro.punto_partenza, ro.prezzo_per_posto,
           ud.id AS driver_id, ud.nome AS driver_nome, ud.cognome AS driver_cognome,
           up.id AS passenger_id, up.nome AS passenger_nome, up.cognome AS passenger_cognome
    FROM ride_requests rr
    JOIN ride_offers ro ON rr.offer_id  = ro.id
    JOIN events e       ON ro.event_id  = e.id
    JOIN users ud       ON rr.driver_id = ud.id
    JOIN users up       ON rr.user_id   = up.id
    WHERE rr.id = ? AND (rr.driver_id = ? OR rr.user_id = ?)
      AND rr.stato IN ('accettato','concluso')
");
$stmt->execute([$requestId, $userId, $userId]);
$r = $stmt->fetch();
if (!$r) { header('Location: dashboard.php'); exit; }

$isDriver = ((int)$r['driver_id'] === (int)$userId);

$haGiaRecensito = $isDriver ? !empty($r['stelle_driver']) : !empty($r['stelle']);
if ($haGiaRecensito) { header('Location: dashboard.php'); exit; }

$targetNome = $isDriver
    ? h($r['passenger_nome']).' '.strtoupper(substr($r['passenger_cognome'],0,1)).'.'
    : h($r['driver_nome'])   .' '.strtoupper(substr($r['driver_cognome'],   0,1)).'.';
$targetId   = $isDriver ? $r['passenger_id'] : $r['driver_id'];
$targetRole = $isDriver ? 'Passeggero' : 'Accompagnatore';

$errore = ''; $successo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stelle = (int)($_POST['stelle'] ?? 0);
    $testo  = trim($_POST['recensione_testo'] ?? '');
    if ($stelle < 1 || $stelle > 5) { $errore = 'Seleziona un numero di stelle valido.'; }
    elseif (mb_strlen($testo) < 10) { $errore = 'La recensione deve essere di almeno 10 caratteri.'; }
    else {
        try {
            if ($isDriver) {
                $pdo->prepare("UPDATE ride_requests SET stelle_driver=?, recensione_driver=?, recensito_da_driver=1 WHERE id=?")
                    ->execute([$stelle, $testo, $requestId]);
            } else {
                $pdo->prepare("UPDATE ride_requests SET stelle=?, recensione_testo=?, recensito_da_passenger=1 WHERE id=?")
                    ->execute([$stelle, $testo, $requestId]);
            }
            $chk = $pdo->prepare("SELECT recensito_da_driver, recensito_da_passenger FROM ride_requests WHERE id=?");
            $chk->execute([$requestId]);
            $row = $chk->fetch();
            if ($row['recensito_da_driver'] && $row['recensito_da_passenger'])
                $pdo->prepare("UPDATE ride_requests SET stato='concluso' WHERE id=?")->execute([$requestId]);
            // Email notifica all'utente recensito
            $targetRow = $pdo->prepare("SELECT nome, email FROM users WHERE id=?");
            $targetRow->execute([$targetId]); $targetRow = $targetRow->fetch();
            $autoreNome = $isDriver
                ? $r['driver_nome']
                : $r['passenger_nome'];
            if ($targetRow) inviaEmail(
                $targetRow['email'], $targetRow['nome'],
                'Hai ricevuto una recensione per "'.$r['nome_evento'].'"',
                emailNuovaRecensione($targetRow['nome'], $autoreNome, $stelle, $r['nome_evento'])
            );
            $successo = true;
        } catch (PDOException $e) { $errore = 'Errore durante il salvataggio. Riprova.'; }
    }
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Lascia Recensione - OnePassage</title>
<link rel="stylesheet" href="css/design-system.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if ($successo): ?><meta http-equiv="refresh" content="3;url=dashboard.php"><?php endif; ?>
<style>
.review-wrap{max-width:640px;margin:48px auto;padding:0 16px 80px}
.page-title{font-size:28px;font-weight:800;margin-bottom:6px}
.page-sub{color:var(--color-text-secondary);margin-bottom:28px;font-size:15px}
.trip-card{display:flex;align-items:center;gap:16px;padding:20px 24px;margin-bottom:20px}
.trip-avatar{width:54px;height:54px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,var(--color-accent),var(--color-accent-dark));color:#fff;font-size:22px;font-weight:700;display:flex;align-items:center;justify-content:center}
.trip-name{font-size:17px;font-weight:700}.trip-role{font-size:13px;color:var(--color-text-secondary);margin-bottom:4px}
.trip-meta{display:flex;flex-wrap:wrap;gap:10px;font-size:12px;color:var(--color-text-secondary);margin-top:6px}
.trip-meta span{display:flex;align-items:center;gap:5px}
.trip-profile{margin-left:auto;font-size:13px;font-weight:600;color:var(--color-accent);text-decoration:none;white-space:nowrap}
.trip-profile:hover{text-decoration:underline}
.stars-interactive{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:6px;margin:14px 0 10px}
.stars-interactive input[type="radio"]{display:none}
.stars-interactive label{font-size:38px;color:var(--color-border);cursor:pointer;transition:color .15s,transform .15s}
.stars-interactive input:checked~label,.stars-interactive label:hover,.stars-interactive label:hover~label{color:#F59E0B}
.stars-interactive label:hover{transform:scale(1.15)}
.stars-caption{font-size:14px;font-weight:600;color:var(--color-accent);min-height:20px;margin-bottom:20px}
.report-link-row{margin-top:20px;padding:14px 18px;background:rgba(239,68,68,.05);border:1px solid rgba(239,68,68,.15);border-radius:14px;font-size:13px;color:var(--color-text-secondary);display:flex;align-items:center;gap:10px}
.report-link-row i{color:#EF4444;font-size:16px;flex-shrink:0}
.report-link-row a{color:#EF4444;font-weight:700;text-decoration:none}
.report-link-row a:hover{text-decoration:underline}
.success-box{text-align:center;padding:60px 20px}
.success-icon{font-size:64px;color:var(--color-accent);margin-bottom:20px}
.success-box h2{font-size:26px;font-weight:800;margin-bottom:10px}
.success-box p{color:var(--color-text-secondary)}
.success-bar{height:4px;background:var(--color-border);border-radius:4px;margin:30px auto 0;max-width:220px;overflow:hidden}
.success-bar-fill{height:100%;background:var(--color-accent);animation:fillBar 3s linear forwards;border-radius:4px}
@keyframes fillBar{from{width:0}to{width:100%}}
.alert-error{padding:14px 18px;border-radius:14px;margin-bottom:20px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#EF4444;font-size:14px;display:flex;align-items:center;gap:10px}
</style>
</head>
<body>
<header class="header">
  <div class="header-container">
    <a href="index.php" class="logo">OnePassage</a>
    <nav class="nav">
      <a href="ricerca.php" class="nav-link">Eventi</a>
      <a href="dashboard.php" class="nav-link">Dashboard</a>
      <a href="profilo.php?id=<?= $_SESSION['user_id'] ?>" class="btn-outline">Profilo</a>
      <button class="theme-toggle" onclick="toggleTheme()" aria-label="Toggle theme">
        <svg class="sun-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
        <svg class="moon-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
      </button>
    </nav>
  </div>
</header>

<div class="review-wrap">
<?php if ($successo): ?>
  <div class="card success-box">
    <div class="success-icon"><i class="fas fa-star"></i></div>
    <h2>Recensione inviata!</h2>
    <p>Grazie per il feedback. Verrai reindirizzato alla dashboard tra pochi secondi…</p>
    <div class="success-bar"><div class="success-bar-fill"></div></div>
  </div>
<?php else: ?>
  <h1 class="page-title"><i class="fas fa-star" style="color:var(--color-accent)"></i> Lascia una Recensione</h1>
  <p class="page-sub">Raccontaci com'è andato il viaggio verso <strong><?= h($r['nome_evento']) ?></strong></p>

  <div class="card trip-card">
    <div class="trip-avatar"><?= strtoupper(substr($isDriver ? $r['passenger_nome'] : $r['driver_nome'], 0, 1)) ?></div>
    <div style="flex:1">
      <div class="trip-role"><?= $targetRole ?></div>
      <div class="trip-name"><?= $targetNome ?></div>
      <div class="trip-meta">
        <span><i class="fas fa-calendar-alt"></i> <?= date('d/m/Y H:i', strtotime($r['data_evento'])) ?></span>
        <span><i class="fas fa-map-marker-alt"></i> <?= h($r['punto_partenza']) ?></span>
        <span><i class="fas fa-euro-sign"></i> €<?= number_format($r['prezzo_per_posto'], 2, ',', '.') ?></span>
      </div>
    </div>
    <a href="profilo.php?id=<?= $targetId ?>" class="trip-profile"><i class="fas fa-eye"></i> Profilo</a>
  </div>

  <?php if ($errore): ?>
  <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?= h($errore) ?></div>
  <?php endif; ?>

  <div class="card" style="padding:28px">
    <form method="POST" action="">
      <label style="font-size:14px;font-weight:700;display:block;margin-bottom:4px">
        <i class="fas fa-star" style="color:var(--color-accent)"></i>
        Com'è stato il viaggio con <?= $targetNome ?>?
      </label>
      <div class="stars-interactive" id="starsRow">
        <?php for ($i=5;$i>=1;$i--): ?>
        <input type="radio" name="stelle" id="star<?= $i ?>" value="<?= $i ?>" required>
        <label for="star<?= $i ?>"><i class="fas fa-star"></i></label>
        <?php endfor; ?>
      </div>
      <div class="stars-caption" id="starsCaption">Clicca per votare</div>

      <div class="form-group">
        <label><i class="fas fa-comment-alt"></i> La tua recensione</label>
        <textarea name="recensione_testo" rows="4" required minlength="10"
            placeholder="Racconta com'è andato — puntualità, cortesia, comodità…"><?= h($_POST['recensione_testo'] ?? '') ?></textarea>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%;padding:15px;font-size:16px">
        <i class="fas fa-paper-plane"></i> Invia Recensione
      </button>
    </form>
  </div>

  <div class="report-link-row">
    <i class="fas fa-flag"></i>
    <span>Hai avuto un problema durante il viaggio?
      <a href="segnalazione.php?request=<?= $requestId ?>">Invia una segnalazione</a>
      invece della recensione.</span>
  </div>
<?php endif; ?>
</div>

<footer class="footer"><div class="footer-content"><p>&copy; 2026 OnePassage.</p></div></footer>

<script>
(function(){ document.documentElement.setAttribute('data-theme', localStorage.getItem('theme')||'light'); })();
function toggleTheme() {
    const t = document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';
    document.documentElement.setAttribute('data-theme',t); localStorage.setItem('theme',t);
}
const labels  = ['','Pessimo','Scarso','Nella media','Buono','Eccellente'];
const caption = document.getElementById('starsCaption');
document.querySelectorAll('#starsRow input').forEach(inp =>
    inp.addEventListener('change', () => { caption.textContent = labels[+inp.value]; }));
</script>
</body>
</html>
