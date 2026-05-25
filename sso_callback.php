<?php
/**
 * sso_callback.php — Google Sign-In OAuth2
 *
 * ── SETUP (una volta sola) ───────────────────────────────────
 * 1. Vai su console.cloud.google.com → APIs & Services → Credentials
 * 2. Crea "OAuth 2.0 Client ID" (tipo: Web application)
 * 3. In "Authorized redirect URIs" aggiungi ESATTAMENTE:
 *      https://onepassage.cloud/sso_callback.php?provider=google
 * 4. Su Render → Environment → aggiungi:
 *      GOOGLE_CLIENT_ID     = xxxx.apps.googleusercontent.com
 *      GOOGLE_CLIENT_SECRET = xxxx
 *      GOOGLE_REDIRECT_URI  = https://onepassage.cloud/sso_callback.php?provider=google
 *
 * NOTA: il redirect_uri deve essere identico carattere per carattere
 * a quello registrato su Google — anche una / finale in più causa 400.
 */

require_once 'config.php';

$provider = $_GET['provider'] ?? '';

if ($provider !== 'google') {
    header('Location: auth.php'); exit;
}

// ── Configurazione ────────────────────────────────────────────
$clientId     = getenv('GOOGLE_CLIENT_ID')     ?: '';
$clientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: '';
// URI deve corrispondere ESATTAMENTE a quello su Google Console
$redirectUri  = getenv('GOOGLE_REDIRECT_URI')
    ?: 'https://onepassage.cloud/sso_callback.php?provider=google';

if (!$clientId || !$clientSecret) {
    die('SSO non configurato. Imposta GOOGLE_CLIENT_ID e GOOGLE_CLIENT_SECRET su Render.');
}

// ── Step 1: nessun ?code → redirect a Google ─────────────────
if (!isset($_GET['code'])) {
    $url = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'prompt'        => 'select_account',
    ]);
    header('Location: ' . $url); exit;
}

// ── Step 2: scambia code per token ───────────────────────────
$tokenResp = httpPost('https://oauth2.googleapis.com/token', [
    'code'          => $_GET['code'],
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri'  => $redirectUri,
    'grant_type'    => 'authorization_code',
]);
$tokenData = json_decode($tokenResp, true);

if (empty($tokenData['id_token'])) {
    error_log('[SSO] Token error: ' . $tokenResp);
    header('Location: auth.php?sso_error=1'); exit;
}

// ── Step 3: decodifica il JWT id_token ───────────────────────
$payload = jwtDecode($tokenData['id_token']);
if (!$payload || empty($payload['sub'])) {
    header('Location: auth.php?sso_error=1'); exit;
}

$googleId = $payload['sub'];
$email    = $payload['email']       ?? '';
$nome     = $payload['given_name']  ?? 'Utente';
$cognome  = $payload['family_name'] ?? '';

// ── Step 4: login o registrazione ────────────────────────────
// 1. Cerca per google_id (già registrato con Google)
$stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
$stmt->execute([$googleId]);
$user = $stmt->fetch();

if (!$user && $email) {
    // 2. Cerca per email (account preesistente — collega Google)
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user) {
        $pdo->prepare('UPDATE users SET google_id = ? WHERE id = ?')->execute([$googleId, $user['id']]);
    }
}

if (!$user) {
    // 3. Crea nuovo account (email già verificata tramite Google)
    $pdo->prepare('INSERT INTO users (nome, cognome, email, password_hash, email_verificata, google_id) VALUES (?, ?, ?, \'\', 1, ?)')
        ->execute([$nome, $cognome, $email ?: $googleId . '@google.onepassage.cloud', $googleId]);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE google_id = ? LIMIT 1');
    $stmt->execute([$googleId]);
    $user = $stmt->fetch();
}

if (!$user) {
    header('Location: auth.php?sso_error=1'); exit;
}

$_SESSION['user_id']      = $user['id'];
$_SESSION['user_nome']    = $user['nome'];
$_SESSION['user_cognome'] = $user['cognome'];
$_SESSION['user_email']   = $user['email'];
header('Location: dashboard.php'); exit;

// ── Helpers ───────────────────────────────────────────────────
function httpPost(string $url, array $data): string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $r = curl_exec($ch);
    if ($r === false) error_log('[SSO] cURL: ' . curl_error($ch));
    curl_close($ch);
    return $r ?: '';
}

function jwtDecode(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;
    $b64 = str_replace(['-','_'],['+','/'], $parts[1]);
    $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
    $json = base64_decode($b64);
    return $json ? json_decode($json, true) : null;
}
