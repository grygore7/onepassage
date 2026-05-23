<?php
session_start();

// ── Database ─────────────────────────────────────────────────
$host = getenv('MYSQLHOST') ?: '127.0.0.1'; 
$port = getenv('MYSQLPORT') ?: '3306';
$dbname = getenv('MYSQLDATABASE') ?: 'onepassage';
$user = getenv('MYSQLUSER') ?: 'root';
$password = getenv('MYSQLPASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $user, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES   => false]
    );
} catch (PDOException $e) {
    die('Connessione DB fallita: ' . $e->getMessage());
}

// ── Auth ─────────────────────────────────────────────────────
function isLoggedIn(): bool {
    return !empty($_SESSION['user_id']);
}

// ── XSS ──────────────────────────────────────────────────────
function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ── Geospaziale — Haversine ──────────────────────────────────
function calcolaDistanza(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R    = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a    = sin($dLat / 2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $R * asin(sqrt($a));
}

// ── Validazione password forte ───────────────────────────────
// Restituisce null se valida, altrimenti stringa con il motivo
function validaPassword(string $pwd): ?string {
    if (strlen($pwd) < 8)
        return 'La password deve contenere almeno 8 caratteri.';
    if (!preg_match('/[A-Z]/', $pwd))
        return 'La password deve contenere almeno una lettera maiuscola.';
    if (!preg_match('/[0-9]/', $pwd))
        return 'La password deve contenere almeno un numero.';
    if (!preg_match('/[\W_]/', $pwd))
        return 'La password deve contenere almeno un carattere speciale (es. !, @, #).';
    return null;
}

// ── OTP ──────────────────────────────────────────────────────
// Genera un codice OTP a 6 cifre e lo salva nel DB
function generaOTP(PDO $pdo, int $userId): string {
    $codice = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $scadenza = date('Y-m-d H:i:s', time() + 900); // 15 minuti
    // Invalida i vecchi OTP per questo utente
    $pdo->prepare("UPDATE otp_verifications SET usato=1 WHERE user_id=?")
        ->execute([$userId]);
    $pdo->prepare("INSERT INTO otp_verifications (user_id, codice, scadenza) VALUES (?,?,?)")
        ->execute([$userId, $codice, $scadenza]);
    return $codice;
}

// Verifica un OTP: restituisce true se valido, false altrimenti
function verificaOTP(PDO $pdo, int $userId, string $codice): bool {
    $stmt = $pdo->prepare("
        SELECT id FROM otp_verifications
        WHERE user_id = ? AND codice = ? AND usato = 0 AND scadenza > NOW()
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$userId, $codice]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->prepare("UPDATE otp_verifications SET usato=1 WHERE id=?")
            ->execute([$row['id']]);
        return true;
    }
    return false;
}

// ── Email transazionale (PHPMailer + Brevo SMTP) ─────────────
// Installa con: composer require phpmailer/phpmailer
// Oppure includi manualmente PHPMailer da vendor/
function inviaEmail(string $to, string $toName, string $subject, string $htmlBody): bool {
    // Verifica che PHPMailer sia disponibile
    $autoload = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoload)) return false;
    require_once $autoload;

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        // Configurazione SMTP Brevo (gratuito 300 email/giorno)
        $mail->isSMTP();
        $mail->Host       = 'smtp-relay.brevo.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'TUA_EMAIL_BREVO';          // ← da configurare
        $mail->Password   = 'TUA_CHIAVE_SMTP_BREVO';    // ← da configurare
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('noreply@onepassage.it', 'OnePassage');
        $mail->addAddress($to, $toName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[OnePassage Mail] ' . $e->getMessage());
        return false;
    }
}

// Template email OTP
function emailOTP(string $nome, string $codice): string {
    return "
    <div style='font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8fafb;border-radius:16px;'>
        <h2 style='color:#0f1419;margin-bottom:8px;'>Verifica il tuo account</h2>
        <p style='color:#64748b;'>Ciao <strong>{$nome}</strong>, usa il codice seguente per completare la registrazione.</p>
        <div style='background:#10B981;color:#fff;font-size:32px;font-weight:700;letter-spacing:10px;text-align:center;padding:20px;border-radius:12px;margin:24px 0;'>{$codice}</div>
        <p style='color:#64748b;font-size:13px;'>Il codice scade tra <strong>15 minuti</strong>. Se non hai richiesto questa email, ignorala.</p>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>
        <p style='color:#94a3b8;font-size:12px;'>OnePassage — Il passaggio intelligente per i tuoi eventi</p>
    </div>";
}

// Template email notifica passaggio richiesto (→ autista)
function emailNuovaRichiesta(string $nomeDriver, string $nomePasseggero, string $nomeEvento): string {
    return "
    <div style='font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8fafb;border-radius:16px;'>
        <h2 style='color:#0f1419;'>Nuova richiesta di passaggio!</h2>
        <p style='color:#64748b;'>Ciao <strong>{$nomeDriver}</strong>,</p>
        <p style='color:#64748b;'><strong>{$nomePasseggero}</strong> ha richiesto un passaggio per <strong>{$nomeEvento}</strong>.</p>
        <a href='https://onepassage.it/dashboard.php' style='display:inline-block;background:#10B981;color:#fff;padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:600;margin-top:16px;'>Vedi Dashboard →</a>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>
        <p style='color:#94a3b8;font-size:12px;'>OnePassage</p>
    </div>";
}

// Template email accettazione/rifiuto richiesta (→ passeggero)
function emailEsitoRichiesta(string $nomePasseggero, string $nomeEvento, bool $accettato): string {
    $stato = $accettato ? 'accettata ✅' : 'rifiutata ❌';
    $colore = $accettato ? '#10B981' : '#EF4444';
    $msg = $accettato
        ? 'Ottimo! Ora puoi chattare con il driver per coordinare i dettagli.'
        : 'Purtroppo l\'autista non è disponibile. Cercane un altro su OnePassage.';
    return "
    <div style='font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8fafb;border-radius:16px;'>
        <h2 style='color:{$colore};'>Richiesta {$stato}</h2>
        <p style='color:#64748b;'>Ciao <strong>{$nomePasseggero}</strong>,</p>
        <p style='color:#64748b;'>La tua richiesta per <strong>{$nomeEvento}</strong> è stata <strong>{$stato}</strong>.</p>
        <p style='color:#64748b;'>{$msg}</p>
        <a href='https://onepassage.it/dashboard.php' style='display:inline-block;background:{$colore};color:#fff;padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:600;margin-top:16px;'>Vai alla Dashboard →</a>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>
        <p style='color:#94a3b8;font-size:12px;'>OnePassage</p>
    </div>";
}

// Template email nuova recensione (→ utente recensito)
function emailNuovaRecensione(string $nomeRicevente, string $nomeAutore, int $stelle, string $nomeEvento): string {
    $starsHtml = str_repeat('★', $stelle) . str_repeat('☆', 5 - $stelle);
    return "
    <div style='font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8fafb;border-radius:16px;'>
        <h2 style='color:#0f1419;'>Hai ricevuto una recensione!</h2>
        <p style='color:#64748b;'>Ciao <strong>{$nomeRicevente}</strong>,</p>
        <p style='color:#64748b;'><strong>{$nomeAutore}</strong> ti ha lasciato una recensione per <strong>{$nomeEvento}</strong>:</p>
        <div style='font-size:28px;color:#F59E0B;margin:16px 0;'>{$starsHtml}</div>
        <a href='https://onepassage.it/profilo.php' style='display:inline-block;background:#10B981;color:#fff;padding:12px 24px;border-radius:12px;text-decoration:none;font-weight:600;'>Vedi il tuo profilo →</a>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:24px 0;'>
        <p style='color:#94a3b8;font-size:12px;'>OnePassage</p>
    </div>";
}
