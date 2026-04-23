<?php
require_once 'config.php';
if (!isLoggedIn()) { header('Location: auth.php'); exit; }

$requestId = isset($_GET['request']) ? (int)$_GET['request'] : 0;
$userId    = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT rr.*,
           e.nome_evento, e.data_evento,
           ro.punto_partenza,
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

$isDriver  = ((int)$r['driver_id'] === (int)$userId);
$segnalatoId   = $isDriver ? $r['passenger_id'] : $r['driver_id'];
$segnalatoNome = $isDriver
    ? h($r['passenger_nome']).' '.strtoupper(substr($r['passenger_cognome'],0,1)).'.'
    : h($r['driver_nome'])   .' '.strtoupper(substr($r['driver_cognome'],   0,1)).'.';

$tipi = [
    'mancato_passaggio'       => ['icon'=>'fa-car-crash',    'label'=>'Mancato passaggio',        'desc'=>'Il conducente non si è presentato o il passaggio non è avvenuto.'],
    'comportamento_scorretto' => ['icon'=>'fa-user-times',   'label'=>'Comportamento scorretto',   'desc'=>'Atteggiamento irrispettoso, molesto o inappropriato.'],
    'pagamento'               => ['icon'=>'fa-euro-sign',    'label'=>'Problema di pagamento',     'desc'=>'Il contributo spese concordato non è stato rispettato.'],
    'sicurezza'               => ['icon'=>'fa-exclamation-triangle','label'=>'Problema di sicurezza','desc'=>'Guida pericolosa o situazione che ha messo a rischio la sicurezza.'],
    'altro'                   => ['icon'=>'fa-question-circle','label'=>'Altro',                   'desc'=>'Un problema non classificato nelle categorie precedenti.'],
];

$errore = ''; $successo = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo       = $_POST['tipo']        ?? '';
    $descrizione = trim($_POST['descrizione'] ?? '');

    if (!array_key_exists($tipo, $tipi))    { $errore = 'Seleziona un tipo di problema.'; }
    elseif (mb_strlen($descrizione) < 20)   { $errore = 'Descrivi il problema in almeno 20 caratteri.'; }
    else {
        try {
            $pdo->prepare("
                INSERT INTO segnalazioni (request_id, segnalante_id, segnalato_id, tipo, descrizione)
                VALUES (?, ?, ?, ?, ?)
            ")->execute([$requestId, $userId, $segnalatoId, $tipo, $descrizione]);
            $successo = true;
        } catch (PDOException $e) {
            $errore = 'Errore durante l\'invio. Riprova.';
        }
    }
}
?>
<!DOCTYPE html>

<html lang="it" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Segnala Problema - OnePassage</title>
<link rel="stylesheet" href="css/design-system.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if ($successo): ?><meta http-equiv="refresh" content="4;url=dashboard.php"><?php endif; ?>
<style>
.seg-wrap{max-width:640px;margin:48px auto;padding:0 16px 80px}
.page-title{font-size:28px;font-weight:800;margin-bottom:6px}
.page-sub{color:var(--color-text-secondary);margin-bottom:28px;font-size:15px}
/* Trip summary */
.trip-mini{display:flex;align-items:center;gap:14px;padding:18px 22px;margin-bottom:24px;border-left:4px solid #EF4444}
.trip-mini-avatar{width:46px;height:46px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#EF4444,#DC2626);color:#fff;font-size:18px;font-weight:700;display:flex;align-items:center;justify-content:center}
.trip-mini-name{font-size:16px;font-weight:700}.trip-mini-meta{font-size:12px;color:var(--color-text-secondary);margin-top:3px}
/* Tipo segnalazione grid */
.tipo-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:24px}
@media(max-width:480px){.tipo-grid{grid-template-columns:1fr}}
.tipo-option input{display:none}
.tipo-card{display:flex;flex-direction:column;gap:6px;padding:16px;border-radius:16px;border:2px solid var(--color-border);cursor:pointer;transition:all .2s ease;background:var(--color-card-bg)}
.tipo-card:hover{border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.04)}
.tipo-option input:checked+.tipo-card{border-color:#EF4444;background:rgba(239,68,68,.06)}
.tipo-card-icon{font-size:22px;color:#EF4444}
.tipo-card-label{font-size:14px;font-weight:700;color:var(--color-text-primary)}
.tipo-card-desc{font-size:12px;color:var(--color-text-secondary);line-height:1.4}
/* Alert */
.alert-error{padding:14px 18px;border-radius:14px;margin-bottom:20px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:#EF4444;font-size:14px;display:flex;align-items:center;gap:10px}
/* Success */
.success-box{text-align:center;padding:60px 20px}
.success-icon{font-size:64px;margin-bottom:20px}
.success-box h2{font-size:26px;font-weight:800;margin-bottom:10px}
.success-box p{color:var(--color-text-secondary)}
.success-bar{height:4px;background:var(--color-border);border-radius:4px;margin:30px auto 0;max-width:220px;overflow:hidden}
.success-bar-fill{height:100%;background:#EF4444;animation:fillBar 4s linear forwards;border-radius:4px}
@keyframes fillBar{from{width:0}to{width:100%}}
/* Submit btn red */
.btn-danger{display:inline-flex;align-items:center;gap:8px;padding:15px 24px;border-radius:16px;font-size:16px;font-weight:700;cursor:pointer;border:none;background:#EF4444;color:#fff;width:100%;justify-content:center;transition:background .2s}
.btn-danger:hover{background:#DC2626}
/* Nota legale */
.legal-note{margin-top:16px;padding:14px 18px;background:rgba(16,185,129,.05);border:1px solid rgba(16,185,129,.15);border-radius:14px;font-size:12px;color:var(--color-text-secondary);line-height:1.6}
.legal-note i{color:var(--color-accent)}
</style>
</head>
<body>
<?php include 'header_snippet.php'; ?>

<div class="seg-wrap">
<?php if ($successo): ?>
  <div class="card success-box">
    <div class="success-icon">🚩</div>
    <h2>Segnalazione inviata</h2>
    <p>Grazie per averci informato. Esamineremo la situazione il prima possibile.<br>Verrai reindirizzato alla dashboard tra pochi secondi.</p>
    <div class="success-bar"><div class="success-bar-fill"></div></div>
  </div>
<?php else: ?>
  <h1 class="page-title" style="color:#EF4444"><i class="fas fa-flag"></i> Segnala un Problema</h1>
  <p class="page-sub">Descrivi cosa è successo durante il passaggio verso <strong><?= h($r['nome_evento']) ?></strong>. La segnalazione è riservata.</p>

  <!-- Riepilogo utente segnalato -->
  <div class="card trip-mini">
    <div class="trip-mini-avatar"><?= strtoupper(substr($isDriver ? $r['passenger_nome'] : $r['driver_nome'], 0, 1)) ?></div>
    <div>
      <div class="trip-mini-name"><?= $segnalatoNome ?></div>
      <div class="trip-mini-meta">
        <?= $isDriver ? 'Passeggero' : 'Accompagnatore' ?> &middot;
        <?= h($r['nome_evento']) ?> &middot;
        <?= date('d/m/Y', strtotime($r['data_evento'])) ?>
      </div>
    </div>
  </div>

  <?php if ($errore): ?>
  <div class="alert-error"><i class="fas fa-exclamation-circle"></i> <?= h($errore) ?></div>
  <?php endif; ?>

  <div class="card" style="padding:28px">
    <form method="POST" action="">

      <!-- Tipo problema -->
      <div class="form-group" style="margin-bottom:8px">
        <label style="font-size:14px;font-weight:700"><i class="fas fa-list-ul"></i> Tipo di problema *</label>
      </div>
      <div class="tipo-grid">
        <?php foreach ($tipi as $key => $t): ?>
        <label class="tipo-option">
          <input type="radio" name="tipo" value="<?= $key ?>" <?= ($_POST['tipo'] ?? '') === $key ? 'checked' : '' ?> required>
          <div class="tipo-card">
            <div class="tipo-card-icon"><i class="fas <?= $t['icon'] ?>"></i></div>
            <div class="tipo-card-label"><?= $t['label'] ?></div>
            <div class="tipo-card-desc"><?= $t['desc'] ?></div>
          </div>
        </label>
        <?php endforeach; ?>
      </div>

      <!-- Descrizione -->
      <div class="form-group">
        <label><i class="fas fa-comment-dots"></i> Descrivi cosa è successo *</label>
        <textarea name="descrizione" rows="5" required minlength="20"
            placeholder="Fornisci più dettagli possibili: data, orario, luogo, cosa è successo esattamente…"><?= h($_POST['descrizione'] ?? '') ?></textarea>
        <small style="color:var(--color-text-secondary);font-size:12px">Minimo 20 caratteri. Più dettagli fornisci, più velocemente possiamo aiutarti.</small>
      </div>

      <button type="submit" class="btn-danger">
        <i class="fas fa-paper-plane"></i> Invia Segnalazione
      </button>
    </form>
  </div>

  <div class="legal-note">
    <i class="fas fa-shield-alt"></i>
    La segnalazione è riservata e visibile solo al team di OnePassage. Non verrà mostrata all'utente segnalato.
    Le segnalazioni false o abusive possono portare alla sospensione dell'account.
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
</script>
</body>
</html>