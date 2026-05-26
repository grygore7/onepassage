<?php
date_default_timezone_set('Europe/Rome');
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

    $romeOffset = (new DateTime('now', new DateTimeZone('Europe/Rome')))->format('P');
    $pdo->exec('SET time_zone = ' . $pdo->quote($romeOffset));
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

define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');
define('RESEND_FROM',    getenv('RESEND_FROM')    ?: 'OnePassage <noreply@onepassage.cloud>');
 
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
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
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
function _op_email_wrap(string $bodyContent, string $accentColor = '#10B981'): string
{
    return '<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>OnePassage</title>
</head>
<body style="
  margin: 0;
  padding: 0;
  background-color: #0B0F12;
  font-family: \'Inter\', system-ui, -apple-system, BlinkMacSystemFont, \'Segoe UI\', sans-serif;
  -webkit-font-smoothing: antialiased;
  mso-line-height-rule: exactly;
">

  <!-- Outer wrapper -->
  <table width="100%" cellpadding="0" cellspacing="0" border="0"
    style="background-color: #0B0F12; min-height: 100vh;">
    <tr>
      <td align="center" style="padding: 40px 16px;">

        <!-- Container -->
        <table width="100%" cellpadding="0" cellspacing="0" border="0"
          style="max-width: 560px; width: 100%;">

          <!-- Logo / Brand Header -->
          <tr>
            <td align="center" style="padding-bottom: 28px;">
              <table cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="vertical-align: middle;">
                    <img src="favicon.ico"
                      alt="OnePassage"
                      width="40"
                      height="40"
                      style="display: block; border-radius: 8px; border: 0;"
                    >
                  </td>
                  <td style="padding-left: 10px; vertical-align: middle;">
                    <span style="
                      font-size: 17px;
                      font-weight: 700;
                      color: #FFFFFF;
                      letter-spacing: -0.3px;
                    ">OnePassage</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Main Card -->
          <tr>
            <td style="
              background-color: #151E24;
              border-radius: 16px;
              padding: 40px 40px 36px 40px;
              border: 1px solid #1E2D38;
            ">
              ' . $bodyContent . '
            </td>
          </tr>

          <!-- Divider -->
          <tr>
            <td style="padding: 28px 0 16px 0;">
              <table width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="height: 1px; background-color: #1A2630;"></td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td align="center" style="padding-bottom: 8px;">
              <p style="
                margin: 0;
                font-size: 12px;
                color: #334155;
                letter-spacing: 0.2px;
                line-height: 1.6;
              ">
                <span style="color: #475569; font-weight: 600;">OnePassage</span>
                <span style="color: #334155;"> — Il passaggio intelligente per i tuoi eventi</span>
              </p>
            </td>
          </tr>
          <tr>
            <td align="center">
              <p style="
                margin: 6px 0 0 0;
                font-size: 11px;
                color: #253040;
                line-height: 1.5;
              ">
                Hai ricevuto questa email perché sei registrato su OnePassage.<br>
                Per assistenza scrivici a <span style="color: #334155;">support@onepassage.app</span>
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>';
}


// ─────────────────────────────────────────────────────────────────────────────
// 1. EMAIL OTP
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Genera l'email di verifica OTP per OnePassage.
 *
 * @param string $nome   Nome dell'utente destinatario
 * @param string $codice Codice OTP a 6 cifre
 * @return string        HTML completo dell'email
 */
function emailOTP(string $nome, string $codice): string
{
    $body = '
      <!-- Icona -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 28px;">
            <div style="
              display: inline-block;
              width: 56px;
              height: 56px;
              background-color: #0D2B20;
              border-radius: 14px;
              text-align: center;
              line-height: 56px;
              font-size: 24px;
            ">&#128274;</div>
          </td>
        </tr>
      </table>

      <!-- Titolo -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 10px;">
            <h1 style="
              margin: 0;
              font-size: 24px;
              font-weight: 700;
              color: #FFFFFF;
              letter-spacing: -0.5px;
              line-height: 1.2;
            ">Verifica il tuo accesso</h1>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom: 32px;">
            <p style="
              margin: 0;
              font-size: 15px;
              color: #64748B;
              line-height: 1.6;
            ">Ciao <span style="color: #94A3B8; font-weight: 500;">' . htmlspecialchars($nome) . '</span>, usa il codice qui sotto per completare la verifica.</p>
          </td>
        </tr>
      </table>

      <!-- OTP Box -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 32px;">
            <div style="
              display: inline-block;
              background-color: #0D2B20;
              border: 1px solid #10B981;
              border-radius: 12px;
              padding: 20px 40px;
              text-align: center;
            ">
              <span style="
                font-size: 38px;
                font-weight: 800;
                color: #10B981;
                letter-spacing: 12px;
                font-variant-numeric: tabular-nums;
                font-family: \'Courier New\', Courier, monospace;
              ">' . htmlspecialchars($codice) . '</span>
            </div>
          </td>
        </tr>
      </table>

      <!-- Note di sicurezza -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background-color: #0F1A22; border-radius: 10px; border: 1px solid #1E2D38;">
        <tr>
          <td style="padding: 16px 20px;">
            <p style="
              margin: 0 0 6px 0;
              font-size: 12px;
              font-weight: 600;
              color: #475569;
              text-transform: uppercase;
              letter-spacing: 0.8px;
            ">Note di sicurezza</p>
            <p style="
              margin: 0;
              font-size: 13px;
              color: #475569;
              line-height: 1.6;
            ">Questo codice scade tra <span style="color: #94A3B8;">10 minuti</span>. Non condividerlo con nessuno. OnePassage non ti chiederà mai questo codice via telefono o chat.</p>
          </td>
        </tr>
      </table>
    ';

    return _op_email_wrap($body, '#10B981');
}


// ─────────────────────────────────────────────────────────────────────────────
// 2. EMAIL NUOVA RICHIESTA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Notifica al guidatore una nuova richiesta di passaggio.
 *
 * @param string $nomeDriver     Nome del guidatore destinatario
 * @param string $nomePasseggero Nome del passeggero richiedente
 * @param string $nomeEvento     Nome dell'evento musicale
 * @return string                HTML completo dell'email
 */
function emailNuovaRichiesta(string $nomeDriver, string $nomePasseggero, string $nomeEvento): string
{
    $body = '
      <!-- Icona -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 28px;">
            <div style="
              display: inline-block;
              width: 56px;
              height: 56px;
              background-color: #0D2B20;
              border-radius: 14px;
              text-align: center;
              line-height: 56px;
              font-size: 24px;
            ">&#127939;</div>
          </td>
        </tr>
      </table>

      <!-- Titolo e intro -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 8px;">
            <h1 style="
              margin: 0;
              font-size: 24px;
              font-weight: 700;
              color: #FFFFFF;
              letter-spacing: -0.5px;
              line-height: 1.2;
            ">Nuova richiesta di passaggio</h1>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom: 32px;">
            <p style="
              margin: 0;
              font-size: 15px;
              color: #64748B;
              line-height: 1.6;
            ">Ciao <span style="color: #94A3B8; font-weight: 500;">' . htmlspecialchars($nomeDriver) . '</span>, hai ricevuto una nuova richiesta.</p>
          </td>
        </tr>
      </table>

      <!-- Info card passeggero + evento -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background-color: #0F1A22; border-radius: 12px; border: 1px solid #1E2D38; margin-bottom: 24px;">
        <tr>
          <td style="padding: 20px 24px;">

            <!-- Riga passeggero -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 16px;">
              <tr>
                <td style="width: 32px; vertical-align: top; padding-top: 2px;">
                  <span style="
                    display: inline-block;
                    width: 28px;
                    height: 28px;
                    background-color: #0D2B20;
                    border-radius: 8px;
                    text-align: center;
                    line-height: 28px;
                    font-size: 14px;
                  ">&#128100;</span>
                </td>
                <td style="padding-left: 12px; vertical-align: top;">
                  <p style="margin: 0 0 2px 0; font-size: 11px; color: #475569; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600;">Passeggero</p>
                  <p style="margin: 0; font-size: 17px; font-weight: 700; color: #FFFFFF;">' . htmlspecialchars($nomePasseggero) . '</p>
                </td>
              </tr>
            </table>

            <!-- Divisore -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 16px;">
              <tr><td style="height: 1px; background-color: #1E2D38;"></td></tr>
            </table>

            <!-- Riga evento -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="width: 32px; vertical-align: top; padding-top: 2px;">
                  <span style="
                    display: inline-block;
                    width: 28px;
                    height: 28px;
                    background-color: #0D2B20;
                    border-radius: 8px;
                    text-align: center;
                    line-height: 28px;
                    font-size: 14px;
                  ">&#127925;</span>
                </td>
                <td style="padding-left: 12px; vertical-align: top;">
                  <p style="margin: 0 0 2px 0; font-size: 11px; color: #475569; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600;">Evento</p>
                  <p style="margin: 0; font-size: 17px; font-weight: 700; color: #10B981;">' . htmlspecialchars($nomeEvento) . '</p>
                </td>
              </tr>
            </table>

          </td>
        </tr>
      </table>

      <!-- Testo secondario -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 28px;">
            <p style="
              margin: 0;
              font-size: 14px;
              color: #64748B;
              line-height: 1.7;
            ">Accedi all\'app per vedere il profilo di <span style="color: #94A3B8;">' . htmlspecialchars($nomePasseggero) . '</span> e decidere se accettare o rifiutare la richiesta.</p>
          </td>
        </tr>
      </table>

      <!-- CTA Button -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="text-align: center;">
            <table cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;">
              <tr>
                <td align="center" style="border-radius: 10px;">
                  <a href="#"
                    style="
                display: inline-block;
                background-color: #10B981;
                color: #FFFFFF;
                text-decoration: none;
                font-size: 15px;
                font-weight: 700;
                letter-spacing: 0.2px;
                padding: 14px 40px;
                border-radius: 10px;
                font-family: \'Inter\', system-ui, sans-serif;
              ">
                    Gestisci la richiesta &rarr;
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    ';

    return _op_email_wrap($body, '#10B981');
}


// ─────────────────────────────────────────────────────────────────────────────
// 3. EMAIL ESITO RICHIESTA
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Notifica al passeggero l'esito della sua richiesta di passaggio.
 *
 * @param string $nomePasseggero Nome del passeggero destinatario
 * @param string $nomeEvento     Nome dell'evento musicale
 * @param bool   $accettato      true = accettato, false = rifiutato
 * @return string                HTML completo dell'email
 */
function emailEsitoRichiesta(string $nomePasseggero, string $nomeEvento, bool $accettato): string
{
    if ($accettato) {
        $accentColor   = '#10B981';
        $bgAccent      = '#0D2B20';
        $borderAccent  = '#10B981';
        $iconEsito     = '&#9989;';
        $titoloEsito   = 'Richiesta accettata!';
        $sottotitoloEsito = 'Ottima notizia! Il tuo passaggio per';
        $sottotitoloPost  = 'è stato confermato.';
        $messaggioCorpo   = 'Trovi tutti i dettagli del viaggio all\'interno dell\'app: orario di pickup, punto di incontro e informazioni sul guidatore.';
        $ctaLabel         = 'Vedi dettagli del viaggio &rarr;';
        $badgeText        = 'CONFERMATO';
        $badgeBg          = '#0D2B20';
        $badgeColor       = '#10B981';
    } else {
        $accentColor   = '#EF4444';
        $bgAccent      = '#2A1010';
        $borderAccent  = '#EF4444';
        $iconEsito     = '&#10060;';
        $titoloEsito   = 'Richiesta non accettata';
        $sottotitoloEsito = 'Purtroppo la tua richiesta per';
        $sottotitoloPost  = 'non è stata accettata questa volta.';
        $messaggioCorpo   = 'Non scoraggiarti: ci sono altri guidatori disponibili per questo evento. Torna sull\'app e trova il passaggio che fa per te.';
        $ctaLabel         = 'Cerca altri passaggi &rarr;';
        $badgeText        = 'NON ACCETTATO';
        $badgeBg          = '#2A1010';
        $badgeColor       = '#EF4444';
    }

    $body = '
      <!-- Icona esito -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 28px;">
            <div style="
              display: inline-block;
              width: 56px;
              height: 56px;
              background-color: ' . $bgAccent . ';
              border-radius: 14px;
              text-align: center;
              line-height: 56px;
              font-size: 26px;
            ">' . $iconEsito . '</div>
          </td>
        </tr>
      </table>

      <!-- Badge stato -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 14px;">
            <span style="
              display: inline-block;
              background-color: ' . $badgeBg . ';
              color: ' . $badgeColor . ';
              font-size: 10px;
              font-weight: 700;
              letter-spacing: 1.5px;
              padding: 5px 14px;
              border-radius: 20px;
              border: 1px solid ' . $borderAccent . ';
              text-transform: uppercase;
            ">' . $badgeText . '</span>
          </td>
        </tr>
      </table>

      <!-- Titolo -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 10px;">
            <h1 style="
              margin: 0;
              font-size: 24px;
              font-weight: 700;
              color: ' . $accentColor . ';
              letter-spacing: -0.5px;
              line-height: 1.2;
            ">' . $titoloEsito . '</h1>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom: 32px;">
            <p style="
              margin: 0;
              font-size: 15px;
              color: #64748B;
              line-height: 1.6;
            ">Ciao <span style="color: #94A3B8; font-weight: 500;">' . htmlspecialchars($nomePasseggero) . '</span>, ' . $sottotitoloEsito . ' <span style="color: #FFFFFF; font-weight: 600;">' . htmlspecialchars($nomeEvento) . '</span> ' . $sottotitoloPost . '</p>
          </td>
        </tr>
      </table>

      <!-- Card evento -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background-color: #0F1A22; border-radius: 12px; border: 1px solid #1E2D38; margin-bottom: 24px;">
        <tr>
          <td style="padding: 18px 24px;">
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="width: 32px; vertical-align: middle;">
                  <span style="
                    display: inline-block;
                    width: 28px;
                    height: 28px;
                    background-color: ' . $bgAccent . ';
                    border-radius: 8px;
                    text-align: center;
                    line-height: 28px;
                    font-size: 14px;
                  ">&#127925;</span>
                </td>
                <td style="padding-left: 12px; vertical-align: middle;">
                  <p style="margin: 0 0 2px 0; font-size: 11px; color: #475569; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600;">Evento</p>
                  <p style="margin: 0; font-size: 16px; font-weight: 700; color: #FFFFFF;">' . htmlspecialchars($nomeEvento) . '</p>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>

      <!-- Testo secondario -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 28px;">
            <p style="
              margin: 0;
              font-size: 14px;
              color: #64748B;
              line-height: 1.7;
            ">' . $messaggioCorpo . '</p>
          </td>
        </tr>
      </table>

      <!-- CTA Button -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="text-align: center;">
            <table cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;">
              <tr>
                <td align="center" style="border-radius: 10px;">
                  <a href="#"
                    style="
                display: inline-block;
                background-color: ' . $accentColor . ';
                color: #FFFFFF;
                text-decoration: none;
                font-size: 15px;
                font-weight: 700;
                letter-spacing: 0.2px;
                padding: 14px 40px;
                border-radius: 10px;
                font-family: \'Inter\', system-ui, sans-serif;
              ">
                    ' . $ctaLabel . '
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    ';

    return _op_email_wrap($body, $accentColor);
}


// ─────────────────────────────────────────────────────────────────────────────
// 4. EMAIL NUOVA RECENSIONE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Notifica all'utente che ha ricevuto una nuova recensione.
 *
 * @param string $nomeRicevente Nome dell'utente che riceve la recensione
 * @param string $nomeAutore    Nome di chi ha scritto la recensione
 * @param int    $stelle        Numero di stelle (1–5)
 * @param string $nomeEvento    Nome dell'evento a cui si riferisce la recensione
 * @return string               HTML completo dell'email
 */
function emailNuovaRecensione(string $nomeRicevente, string $nomeAutore, int $stelle, string $nomeEvento): string
{
    // Stelle HTML: ★ piene ambra, ☆ vuote grigio scuro
    $stelle = max(1, min(5, $stelle));
    $starsHtml = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $stelle) {
            $starsHtml .= '<span style="color: #F59E0B; font-size: 28px; letter-spacing: 4px;">&#9733;</span>';
        } else {
            $starsHtml .= '<span style="color: #1E2D38; font-size: 28px; letter-spacing: 4px;">&#9733;</span>';
        }
    }

    // Messaggio dinamico in base al punteggio
    if ($stelle >= 5) {
        $headline = 'Valutazione perfetta!';
        $subline  = 'Hai conquistato il massimo dei voti. Complimenti!';
    } elseif ($stelle >= 4) {
        $headline = 'Ottima valutazione!';
        $subline  = 'Continua così, la community ti apprezza.';
    } elseif ($stelle >= 3) {
        $headline = 'Nuova valutazione ricevuta';
        $subline  = 'Ogni viaggio è un\'opportunità per migliorare.';
    } else {
        $headline = 'Nuova valutazione ricevuta';
        $subline  = 'Leggi il feedback e migliora la tua esperienza.';
    }

    $body = '
      <!-- Icona -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 28px;">
            <div style="
              display: inline-block;
              width: 56px;
              height: 56px;
              background-color: #2A1E08;
              border-radius: 14px;
              text-align: center;
              line-height: 56px;
              font-size: 26px;
            ">&#11088;</div>
          </td>
        </tr>
      </table>

      <!-- Titolo -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 6px;">
            <h1 style="
              margin: 0;
              font-size: 24px;
              font-weight: 700;
              color: #FFFFFF;
              letter-spacing: -0.5px;
              line-height: 1.2;
            ">' . $headline . '</h1>
          </td>
        </tr>
        <tr>
          <td align="center" style="padding-bottom: 28px;">
            <p style="
              margin: 0;
              font-size: 15px;
              color: #64748B;
              line-height: 1.6;
            ">Ciao <span style="color: #94A3B8; font-weight: 500;">' . htmlspecialchars($nomeRicevente) . '</span>, ' . $subline . '</p>
          </td>
        </tr>
      </table>

      <!-- Stars box -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="padding-bottom: 28px;">
            <div style="
              display: inline-block;
              background-color: #0F1A22;
              border: 1px solid #2A1E08;
              border-radius: 12px;
              padding: 18px 32px;
              text-align: center;
            ">
              ' . $starsHtml . '
              <br>
              <span style="
                font-size: 13px;
                color: #F59E0B;
                font-weight: 600;
                letter-spacing: 0.3px;
                display: block;
                margin-top: 8px;
              ">' . $stelle . ' su 5 stelle</span>
            </div>
          </td>
        </tr>
      </table>

      <!-- Info card autore + evento -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0"
        style="background-color: #0F1A22; border-radius: 12px; border: 1px solid #1E2D38; margin-bottom: 24px;">
        <tr>
          <td style="padding: 20px 24px;">

            <!-- Riga autore -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 16px;">
              <tr>
                <td style="width: 32px; vertical-align: top; padding-top: 2px;">
                  <span style="
                    display: inline-block;
                    width: 28px;
                    height: 28px;
                    background-color: #2A1E08;
                    border-radius: 8px;
                    text-align: center;
                    line-height: 28px;
                    font-size: 14px;
                  ">&#128393;</span>
                </td>
                <td style="padding-left: 12px; vertical-align: top;">
                  <p style="margin: 0 0 2px 0; font-size: 11px; color: #475569; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600;">Recensione di</p>
                  <p style="margin: 0; font-size: 17px; font-weight: 700; color: #FFFFFF;">' . htmlspecialchars($nomeAutore) . '</p>
                </td>
              </tr>
            </table>

            <!-- Divisore -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom: 16px;">
              <tr><td style="height: 1px; background-color: #1E2D38;"></td></tr>
            </table>

            <!-- Riga evento -->
            <table width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td style="width: 32px; vertical-align: top; padding-top: 2px;">
                  <span style="
                    display: inline-block;
                    width: 28px;
                    height: 28px;
                    background-color: #2A1E08;
                    border-radius: 8px;
                    text-align: center;
                    line-height: 28px;
                    font-size: 14px;
                  ">&#127925;</span>
                </td>
                <td style="padding-left: 12px; vertical-align: top;">
                  <p style="margin: 0 0 2px 0; font-size: 11px; color: #475569; text-transform: uppercase; letter-spacing: 0.8px; font-weight: 600;">Evento</p>
                  <p style="margin: 0; font-size: 17px; font-weight: 700; color: #F59E0B;">' . htmlspecialchars($nomeEvento) . '</p>
                </td>
              </tr>
            </table>

          </td>
        </tr>
      </table>

      <!-- CTA Button -->
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td align="center" style="text-align: center;">
            <table cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;">
              <tr>
                <td align="center" style="border-radius: 10px;">
                  <a href="#"
                    style="
                display: inline-block;
                background-color: #F59E0B;
                color: #0B0F12;
                text-decoration: none;
                font-size: 15px;
                font-weight: 700;
                letter-spacing: 0.2px;
                padding: 14px 40px;
                border-radius: 10px;
                font-family: \'Inter\', system-ui, sans-serif;
              ">
                    Visualizza il tuo profilo &rarr;
                  </a>
                </td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
    ';

    return _op_email_wrap($body, '#F59E0B');
}
