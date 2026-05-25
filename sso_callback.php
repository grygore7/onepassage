<?php
/**

 * ── SETUP GOOGLE ─────────────────────────────────────────────
 * 1. Vai su https://console.cloud.google.com
 * 2. Crea un progetto → API & Services → Credentials
 * 3. Crea "OAuth 2.0 Client ID" (tipo: Web application)
 * 4. Aggiungi in "Authorized redirect URIs":
 *      https://tuodominio.it/sso_callback.php?provider=google
 * 5. Su Railway → Settings → Variables aggiungi:
 *      GOOGLE_CLIENT_ID=xxxx.apps.googleusercontent.com
 *      GOOGLE_CLIENT_SECRET=xxxx
 */

require_once 'config.php';

$provider = $_GET['provider'] ?? '';

// ═══════════════════════════════════════════════════════════════
// GOOGLE
// ═══════════════════════════════════════════════════════════════
if ($provider === 'google') {

    $clientId     = getenv('GOOGLE_CLIENT_ID')     ?: 'TUO_GOOGLE_CLIENT_ID';
    $clientSecret = getenv('GOOGLE_CLIENT_SECRET') ?: 'TUO_GOOGLE_CLIENT_SECRET';
    
    // Rimuove gli slash finali da dirname per evitare il doppio slash //
    $folder = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    
    $redirectUri  = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST']
                    . $folder
                    . '/sso_callback.php?provider=google';

    // Step 1: redirect verso Google se non c'è ?code
    if (!isset($_GET['code'])) {
        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'prompt'        => 'select_account',
        ]);
        header('Location: https://accounts.google.com/o/oauth2/auth?' . $params);
        exit;
    }

    // Step 2: scambia il code per un access_token
    $tokenResp = httpPost('https://oauth2.googleapis.com/token', [
        'code'          => $_GET['code'],
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri'  => $redirectUri,
        'grant_type'    => 'authorization_code',
    ]);
    $tokenData = json_decode($tokenResp, true);
    if (empty($tokenData['id_token'])) {
        die('Errore Google SSO: token non ricevuto.');
    }

    // Step 3: decodifica il JWT id_token (senza verifica firma — affidabile perché arriva da Google TLS)
    $payload = jwtDecode($tokenData['id_token']);
    if (!$payload || empty($payload['sub'])) {
        die('Errore Google SSO: payload non valido.');
    }

    $googleId = $payload['sub'];
    $email    = $payload['email']      ?? '';
    $nome     = $payload['given_name'] ?? '';
    $cognome  = $payload['family_name'] ?? '';

    gestisciSSOLogin($pdo, 'google', $googleId, $email, $nome, $cognome);
}

// ═══════════════════════════════════════════════════════════════
// APPLE
// ═══════════════════════════════════════════════════════════════
elseif ($provider === 'apple') {

    $clientId = getenv('APPLE_CLIENT_ID') ?: 'TUO_APPLE_SERVICE_ID';
    $redirectUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST']
                    . dirname($_SERVER['SCRIPT_NAME'])
                    . '/sso_callback.php?provider=apple';

    // Step 1: redirect verso Apple se non c'è ?code
    if (!isset($_POST['code'])) {
        $params = http_build_query([
            'client_id'     => $clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code id_token',
            'scope'         => 'name email',
            'response_mode' => 'form_post',
        ]);
        header('Location: https://appleid.apple.com/auth/authorize?' . $params);
        exit;
    }

    // Apple invia il codice via POST
    $code = $_POST['code'] ?? '';
    if (!$code) die('Errore Apple SSO: codice mancante.');

    $clientSecret = appleClientSecret();
    $tokenResp    = httpPost('https://appleid.apple.com/auth/token', [
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'code'          => $code,
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => $redirectUri,
    ]);
    $tokenData = json_decode($tokenResp, true);
    if (empty($tokenData['id_token'])) {
        die('Errore Apple SSO: token non ricevuto.');
    }

    $payload = jwtDecode($tokenData['id_token']);
    if (!$payload || empty($payload['sub'])) {
        die('Errore Apple SSO: payload non valido.');
    }

    $appleId = $payload['sub'];
    $email   = $payload['email'] ?? '';
    // Apple invia il nome solo al primo login via POST
    $nameObj = json_decode($_POST['user'] ?? '{}', true);
    $nome    = $nameObj['name']['firstName'] ?? '';
    $cognome = $nameObj['name']['lastName']  ?? '';

    gestisciSSOLogin($pdo, 'apple', $appleId, $email, $nome, $cognome);
}

else {
    header('Location: auth.php');
    exit;
}

// ═══════════════════════════════════════════════════════════════
// LOGICA CONDIVISA
// ═══════════════════════════════════════════════════════════════

/**
 * Login o registrazione tramite SSO.
 * - Se esiste già per provider_id → login diretto
 * - Se esiste per email → collega il provider e fa login
 * - Altrimenti → crea nuovo utente con email_verificata=1 (niente OTP)
 */
function gestisciSSOLogin(PDO $pdo, string $provider, string $providerId,
                           string $email, string $nome, string $cognome): void
{
    $col = $provider === 'google' ? 'google_id' : 'apple_id';

    // 1. Cerca per provider ID
    $stmt = $pdo->prepare("SELECT * FROM users WHERE $col = ?");
    $stmt->execute([$providerId]);
    $user = $stmt->fetch();

    if (!$user && $email) {
        // 2. Cerca per email (collega il provider)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $pdo->prepare("UPDATE users SET $col = ? WHERE id = ?")
                ->execute([$providerId, $user['id']]);
        }
    }

    if (!$user) {
        // 3. Crea nuovo utente SSO (email già verificata)
        if (!$email) {
            // Apple può non fornire l'email dopo il primo accesso
            $email = $provider . '_' . substr($providerId, 0, 8) . '@onepassage.local';
        }
        $pdo->prepare("INSERT INTO users (nome, cognome, email, password_hash, email_verificata, $col)
                        VALUES (?, ?, ?, '', 1, ?)")
            ->execute([
                $nome    ?: ucfirst($provider) . 'User',
                $cognome ?: '',
                $email,
                $providerId,
            ]);
        $userId = (int)$pdo->lastInsertId();
        $user   = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $user->execute([$userId]);
        $user = $user->fetch();
    }

    // Avvia sessione
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['user_nome']    = $user['nome'];
    $_SESSION['user_cognome'] = $user['cognome'];
    $_SESSION['user_email']   = $user['email'];
    header('Location: dashboard.php');
    exit;
}

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
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ?: '';
}

/** Decode JWT payload (no signature verification — solo per uso interno) */
function jwtDecode(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) < 2) return null;
    $payload = $parts[1];
    $payload = str_replace(['-', '_'], ['+', '/'], $payload);
    $payload = base64_decode($payload . str_repeat('=', 4 - strlen($payload) % 4));
    return json_decode($payload, true) ?: null;
}

/**
 * Genera il client_secret JWT per Apple (ES256, valido 6 mesi).
 * Richiede: APPLE_TEAM_ID, APPLE_KEY_ID, APPLE_CLIENT_ID, APPLE_PRIVATE_KEY
 */
function appleClientSecret(): string {
    $teamId    = getenv('APPLE_TEAM_ID')    ?: '';
    $keyId     = getenv('APPLE_KEY_ID')     ?: '';
    $clientId  = getenv('APPLE_CLIENT_ID')  ?: '';
    $privateKey= getenv('APPLE_PRIVATE_KEY') ?: '';

    $header  = base64UrlEncode(json_encode(['alg'=>'ES256','kid'=>$keyId]));
    $now     = time();
    $payload = base64UrlEncode(json_encode([
        'iss' => $teamId,
        'iat' => $now,
        'exp' => $now + 15776999, // ~6 mesi
        'aud' => 'https://appleid.apple.com',
        'sub' => $clientId,
    ]));
    $data = $header . '.' . $payload;

    $key = openssl_pkey_get_private(str_replace('\\n', "\n", $privateKey));
    openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256);
    return $data . '.' . base64UrlEncode($signature);
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
