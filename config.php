<?php
session_start();

// ── Database ─────────────────────────────────────────────────
$host = getenv('DB_HOST') ?: '127.0.0.1'; 
$port = getenv('MYSQLPORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'onepassage';
$username = getenv('DB_USER') ?: 'root';
$password = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username, $password,
        [PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
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
 
// ── Validazione password forte ────────────────────────────────
function validaPassword(string $pwd): ?string {
    if (strlen($pwd) < 8)
        return 'La password deve contenere almeno 8 caratteri.';
    if (!preg_match('/[A-Z]/', $pwd))
        return 'La password deve contenere almeno una letter maiuscola.';
    if (!preg_match('/[0-9]/', $pwd))
        return 'La password deve contenere almeno un numero.';
    if (!preg_match('/[\W_]/', $pwd))
        return 'La password deve contenere almeno un carattere speciale (es. !, @, #).';
    return null;
}
 
// ── OTP ──────────────────────────────────────────────────────
function generaOTP(PDO $pdo, int $userId): string {
    $codice   = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $scadenza = date('Y-m-d H:i:s', time() + 900); // 15 minuti
    $pdo->prepare("UPDATE otp_verifications SET usato=1 WHERE user_id=?")
        ->execute([$userId]);
    $pdo->prepare("INSERT INTO otp_verifications (user_id, codice, scadenza) VALUES (?,?,?)")
        ->execute([$userId, $codice, $scadenza]);
    return $codice;
}
 
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
 
// ── Email transazionale — Resend API ─────────────────────────
// Nessuna dipendenza esterna, usa solo cURL nativo.
//
// Setup (una volta sola):
//   1. Registrati su resend.com
//   2. Vai su API Keys → Create API Key → copia la chiave
//   3. Su Railway → Variables → aggiungi:
//       RESEND_API_KEY = re_xxxxxxxxxxxxxxxxxxxx
//   4. In locale: sostituisci il fallback '' con la tua chiave
//       getenv('RESEND_API_KEY') ?: 're_xxxx...'
//
// Mittente aggiornato per l'utilizzo del terzo livello validato su Aruba
define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');
define('RESEND_FROM',    getenv('RESEND_FROM')    ?: 'OnePassage <noreply@send.onepassage.cloud>');
 
function inviaEmail(string $to, string $toName, string $subject, string $htmlBody): bool {
    $apiKey = RESEND_API_KEY;
 
    // Se la chiave non è configurata logga e ritorna false senza crashare
    if (!$apiKey) {
        error_log('[OnePassage Mail] RESEND_API_KEY non configurata — email non inviata.');
        return false;
    }
 
    $payload = json_encode([
        'from'    => RESEND_FROM,
        'to'      => [$toName ? "$toName <$to>" : $to],
        'subject' => $subject,
        'html'    => $htmlBody,
        'text'    => strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)),
    ], JSON_UNESCAPED_UNICODE);
 
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
 
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
 
    if ($curlErr) {
        error_log('[OnePassage Mail] cURL error: ' . $curlErr);
        return false;
    }
 
    // Resend restituisce 200 o 201 in caso di successo
    if ($httpCode !== 200 && $httpCode !== 201) {
        error_log('[OnePassage Mail] Resend HTTP ' . $httpCode . ': ' . $response);
        return false;
    }
 
    return true;
}
 
// ── Template email OTP ────────────────────────────────────────
function emailOTP(string $nome, string $codice): string {
    return "
    <div style='font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8fafb;border-radius:16px;'>
        <div style='text-align:center;margin-bottom:24px;'>
            <div style='display:inline-block;background:#10B981;color:#fff;width:52px;height:52px;border-radius:14px;font-size:24px;line-height:52px;text-align:center;'>✉</div>
        </div>
        <h2 style='color:#0f1419;margin:0 0 8px;text-align:center;'>Verifica il tuo account</h2>
        <p style='color:#64748b;text-align:center;margin:0 0 24px;'>
            Ciao <strong>{$nome}</strong>, usa il codice seguente per completare la registrazione.
        </p>
        <div style='background:#10B981;color:#fff;font-size:34px;font-weight:700;
                    letter-spacing:12px;text-align:center;padding:22px 16px;
                    border-radius:14px;margin:0 0 24px;'>
            {$codice}
        </div>
        <p style='color:#94a3b8;font-size:13px;text-align:center;margin:0 0 24px;'>
            Il codice scade tra <strong style='color:#64748b;'>15 minuti</strong>.<br>
            Se non hai richiesto questa email, ignorala.
        </p>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:0 0 16px;'>
        <p style='color:#cbd5e1;font-size:11px;text-align:center;margin:0;'>
            OnePassage — Il passaggio intelligente per i tuoi eventi
        </p>
    </div>";
}
 
// ── Template: nuova richiesta passaggio (→ autista) ───────────
function emailNuovaRichiesta(string $nomeDriver, string $nomePasseggero, string $nomeEvento): string {
    return "
    <div style='font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8fafb;border-radius:16px;'>
        <h2 style='color:#0f1419;margin:0 0 16px;'>🚗 Nuova richiesta di passaggio!</h2>
        <p style='color:#64748b;margin:0 0 8px;'>Ciao <strong>{$nomeDriver}</strong>,</p>
        <p style='color:#64748b;margin:0 0 24px;'>
            <strong>{$nomePasseggero}</strong> ha richiesto un posto per
            <strong>{$nomeEvento}</strong>.
        </p>
        <a href='https://www.onepassage.cloud/dashboard.php'
           style='display:block;background:#10B981;color:#fff;padding:14px 24px;
                  border-radius:12px;text-decoration:none;font-weight:600;
                  text-align:center;margin:0 0 24px;'>
            Vedi la richiesta →
        </a>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:0 0 16px;'>
        <p style='color:#cbd5e1;font-size:11px;text-align:center;margin:0;'>OnePassage</p>
    </div>";
}
 
// ── Template: esito richiesta (→ passeggero) ──────────────────
function emailEsitoRichiesta(string $nomePasseggero, string $nomeEvento, bool $accettato): string {
    $emoji  = $accettato ? '✅' : '❌';
    $stato  = $accettato ? 'accettata' : 'rifiutata';
    $colore = $accettato ? '#10B981' : '#EF4444';
    $msg    = $accettato
        ? 'Vai in chat per coordinare orario e punto di incontro con il driver.'
        : 'Purtroppo il driver non è disponibile. Cerca un altro passaggio su OnePassage.';
    return "
    <div style='font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8fafb;border-radius:16px;'>
        <h2 style='color:{$colore};margin:0 0 16px;'>{$emoji} Richiesta {$stato}</h2>
        <p style='color:#64748b;margin:0 0 8px;'>Ciao <strong>{$nomePasseggero}</strong>,</p>
        <p style='color:#64748b;margin:0 0 8px;'>
            La tua richiesta per <strong>{$nomeEvento}</strong> è stata <strong>{$stato}</strong>.
        </p>
        <p style='color:#64748b;margin:0 0 24px;'>{$msg}</p>
        <a href='https://www.onepassage.cloud/dashboard.php'
           style='display:block;background:{$colore};color:#fff;padding:14px 24px;
                  border-radius:12px;text-decoration:none;font-weight:600;
                  text-align:center;margin:0 0 24px;'>
            Vai alla Dashboard →
        </a>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:0 0 16px;'>
        <p style='color:#cbd5e1;font-size:11px;text-align:center;margin:0;'>OnePassage</p>
    </div>";
}
 
// ── Template: nuova recensione (→ utente recensito) ───────────
function emailNuovaRecensione(string $nomeRicevente, string $nomeAutore, int $stelle, string $nomeEvento): string {
    $starsHtml = str_repeat('★', $stelle) . str_repeat('☆', 5 - $stelle);
    return "
    <div style='font-family:Inter,sans-serif;max-width:480px;margin:0 auto;padding:32px 24px;background:#f8fafb;border-radius:16px;'>
        <h2 style='color:#0f1419;margin:0 0 16px;'>⭐ Hai ricevuto una recensione!</h2>
        <p style='color:#64748b;margin:0 0 8px;'>Ciao <strong>{$nomeRicevente}</strong>,</p>
        <p style='color:#64748b;margin:0 0 16px;'>
            <strong>{$nomeAutore}</strong> ti ha lasciato una recensione
            per <strong>{$nomeEvento}</strong>:
        </p>
        <div style='font-size:32px;color:#F59E0B;margin:0 0 24px;letter-spacing:4px;'>
            {$starsHtml}
        </div>
        <a href='https://www.onepassage.cloud/profilo.php'
           style='display:block;background:#10B981;color:#fff;padding:14px 24px;
                  border-radius:12px;text-decoration:none;font-weight:600;
                  text-align:center;margin:0 0 24px;'>
            Vedi il tuo profilo →
        </a>
        <hr style='border:none;border-top:1px solid #e2e8f0;margin:0 0 16px;'>
        <p style='color:#cbd5e1;font-size:11px;text-align:center;margin:0;'>OnePassage</p>
    </div>";
}
