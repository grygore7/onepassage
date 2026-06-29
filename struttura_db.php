<?php
// Pagina didattica stand-alone basata sul dump reale del DB OnePassage
// bfufsahwe4hqu2sm9ccb.sql - esportato il 29/06/2026.

$tables = [
    [
        'name' => 'USERS',
        'label' => 'Entita principale',
        'description' => 'Contiene gli account degli utenti, i dati profilo, lo stato email, il ruolo admin e gli identificativi SSO.',
        'fields' => [
            ['id', 'INT AUTO_INCREMENT', 'PK'],
            ['nome', 'VARCHAR(100)', ''],
            ['cognome', 'VARCHAR(100)', ''],
            ['email', 'VARCHAR(255)', 'UNIQUE'],
            ['password_hash', 'VARCHAR(255)', ''],
            ['telefono', 'VARCHAR(20)', ''],
            ['bio', 'TEXT', ''],
            ['foto_profilo', 'VARCHAR(255)', ''],
            ['created_at', 'TIMESTAMP', ''],
            ['updated_at', 'TIMESTAMP', ''],
            ['public_key', 'TEXT', ''],
            ['email_verificata', 'TINYINT(1)', ''],
            ['is_admin', 'TINYINT(1)', ''],
            ['ban_status', "ENUM('attivo','bannato')", ''],
            ['google_id', 'VARCHAR(255)', ''],
            ['apple_id', 'VARCHAR(255)', ''],
        ],
        'relations' => [
            ['PK', '`id` identifica univocamente ogni utente.'],
            ['1:N', '`users(id)` -> `ride_offers.user_id`: un utente puo pubblicare molti passaggi.'],
            ['1:N', '`users(id)` -> `ride_requests.user_id`: un utente puo richiedere molti passaggi come passeggero.'],
            ['1:N', '`users(id)` -> `chat_messages.sender_id` e `receiver_id`: un utente puo inviare e ricevere molti messaggi.'],
        ],
    ],
    [
        'name' => 'EVENTS',
        'label' => 'Entita evento',
        'description' => 'Rappresenta concerti, festival o eventi sportivi, manuali o importati da Ticketmaster.',
        'fields' => [
            ['id', 'INT AUTO_INCREMENT', 'PK'],
            ['nome_evento', 'VARCHAR(255)', ''],
            ['descrizione', 'TEXT', ''],
            ['luogo', 'VARCHAR(255)', ''],
            ['latitudine', 'DECIMAL(10,8)', ''],
            ['longitudine', 'DECIMAL(11,8)', ''],
            ['data_evento', 'DATETIME', ''],
            ['created_at', 'TIMESTAMP', ''],
            ['updated_at', 'TIMESTAMP', ''],
            ['creato_da', 'INT', 'IDX'],
            ['approvato', 'TINYINT(1)', ''],
            ['ticketmaster_id', 'VARCHAR(64)', 'UNIQUE'],
            ['fonte', "ENUM('manuale','ticketmaster')", ''],
        ],
        'relations' => [
            ['PK', '`id` identifica ogni evento.'],
            ['1:N', '`events(id)` -> `ride_offers.event_id`: un evento puo avere molti passaggi offerti.'],
        ],
    ],
    [
        'name' => 'RIDE_OFFERS',
        'label' => 'Passaggi offerti',
        'description' => 'Associa un guidatore a un evento e descrive partenza, posti, prezzo e coordinate.',
        'fields' => [
            ['id', 'INT AUTO_INCREMENT', 'PK'],
            ['user_id', 'INT', 'FK'],
            ['event_id', 'INT', 'FK'],
            ['punto_partenza', 'VARCHAR(255)', ''],
            ['latitudine_partenza', 'DECIMAL(10,8)', ''],
            ['longitudine_partenza', 'DECIMAL(11,8)', ''],
            ['posti_disponibili', 'INT', ''],
            ['prezzo_per_posto', 'DECIMAL(10,2)', ''],
            ['note', 'TEXT', ''],
            ['created_at', 'TIMESTAMP', ''],
            ['updated_at', 'TIMESTAMP', ''],
        ],
        'relations' => [
            ['FK', '`user_id` -> `users(id)` con `ON DELETE CASCADE`.'],
            ['FK', '`event_id` -> `events(id)` con `ON DELETE CASCADE`.'],
            ['N:1', 'Molti passaggi possono appartenere allo stesso utente e allo stesso evento.'],
            ['1:N', '`ride_offers(id)` -> `ride_requests.offer_id`: un passaggio puo ricevere molte richieste.'],
        ],
    ],
    [
        'name' => 'RIDE_REQUESTS',
        'label' => 'Richieste prenotazione',
        'description' => 'Registra le richieste dei passeggeri, lo stato del viaggio, conferme e recensioni reciproche.',
        'fields' => [
            ['id', 'INT AUTO_INCREMENT', 'PK'],
            ['user_id', 'INT', 'FK'],
            ['offer_id', 'INT', 'FK'],
            ['driver_id', 'INT', 'FK'],
            ['stato', "ENUM('in_attesa','accettato','rifiutato','concluso')", ''],
            ['stelle', 'INT', ''],
            ['recensione_testo', 'TEXT', ''],
            ['created_at', 'TIMESTAMP', ''],
            ['updated_at', 'TIMESTAMP', ''],
            ['confermato_driver', 'TINYINT(1)', ''],
            ['confermato_passenger', 'TINYINT(1)', ''],
            ['passaggio_confermato', 'TINYINT(1)', ''],
            ['stelle_driver', 'TINYINT(1)', ''],
            ['recensione_driver', 'TEXT', ''],
            ['recensito_da_driver', 'TINYINT(1)', ''],
            ['recensito_da_passenger', 'TINYINT(1)', ''],
        ],
        'relations' => [
            ['FK', '`user_id` -> `users(id)`: passeggero che richiede.'],
            ['FK', '`offer_id` -> `ride_offers(id)`: passaggio richiesto.'],
            ['FK', '`driver_id` -> `users(id)`: conducente del passaggio.'],
            ['1:N', '`ride_requests(id)` -> `chat_messages.request_id`, `chat_keys.request_id` e `segnalazioni.request_id`.'],
        ],
    ],
    [
        'name' => 'CHAT_MESSAGES',
        'label' => 'Messaggistica',
        'description' => 'Contiene i messaggi della chat collegata a una richiesta, anche in forma cifrata AES-GCM.',
        'fields' => [
            ['id', 'INT AUTO_INCREMENT', 'PK'],
            ['request_id', 'INT', 'FK'],
            ['sender_id', 'INT', 'FK'],
            ['receiver_id', 'INT', 'FK'],
            ['messaggio', 'TEXT', ''],
            ['letto', 'TINYINT(1)', ''],
            ['created_at', 'TIMESTAMP', ''],
            ['encrypted', 'TINYINT(1)', ''],
        ],
        'relations' => [
            ['FK', '`request_id` -> `ride_requests(id)` con `ON DELETE CASCADE`.'],
            ['FK', '`sender_id` -> `users(id)` con `ON DELETE CASCADE`.'],
            ['FK', '`receiver_id` -> `users(id)` con `ON DELETE CASCADE`.'],
            ['N:1', 'Molti messaggi appartengono alla stessa richiesta di passaggio.'],
        ],
    ],
    [
        'name' => 'CHAT_KEYS',
        'label' => 'Cifratura chat',
        'description' => 'Memorizza salt e dati legacy delle chiavi per la chat cifrata collegata a una richiesta.',
        'fields' => [
            ['id', 'INT AUTO_INCREMENT', 'PK'],
            ['request_id', 'INT', 'FK'],
            ['user_id', 'INT', 'IDX'],
            ['chat_salt', 'VARCHAR(64)', ''],
            ['encrypted_key', 'TEXT', ''],
            ['created_at', 'TIMESTAMP', ''],
        ],
        'relations' => [
            ['FK', '`request_id` -> `ride_requests(id)` con `ON DELETE CASCADE`.'],
            ['1:N', 'Una richiesta puo avere piu record tecnici di chiave/salt.'],
        ],
    ],
    [
        'name' => 'OTP_VERIFICATIONS',
        'label' => 'Verifica email',
        'description' => 'Gestisce i codici OTP necessari per confermare l email dell utente durante la registrazione.',
        'fields' => [
            ['id', 'INT AUTO_INCREMENT', 'PK'],
            ['user_id', 'INT', 'FK'],
            ['codice', 'CHAR(6)', ''],
            ['scadenza', 'DATETIME', ''],
            ['usato', 'TINYINT(1)', ''],
            ['created_at', 'TIMESTAMP', ''],
        ],
        'relations' => [
            ['FK', '`user_id` -> `users(id)` con `ON DELETE CASCADE`.'],
            ['1:N', 'Un utente puo avere molti OTP nel tempo; il campo `usato` evita riutilizzi.'],
        ],
    ],
    [
        'name' => 'SEGNALAZIONI',
        'label' => 'Moderazione',
        'description' => 'Raccoglie le segnalazioni degli utenti relative a una specifica richiesta di passaggio.',
        'fields' => [
            ['id', 'INT AUTO_INCREMENT', 'PK'],
            ['request_id', 'INT', 'FK'],
            ['segnalante_id', 'INT', 'FK'],
            ['segnalato_id', 'INT', 'FK'],
            ['tipo', "ENUM('mancato_passaggio','comportamento_scorretto','pagamento','sicurezza','altro')", ''],
            ['descrizione', 'TEXT', ''],
            ['stato', "ENUM('aperta','in_revisione','chiusa')", ''],
            ['created_at', 'TIMESTAMP', ''],
        ],
        'relations' => [
            ['FK', '`request_id` -> `ride_requests(id)` con `ON DELETE CASCADE`.'],
            ['FK', '`segnalante_id` -> `users(id)`: utente che segnala.'],
            ['FK', '`segnalato_id` -> `users(id)`: utente segnalato.'],
            ['N:1', 'Molte segnalazioni possono riferirsi alla stessa richiesta o allo stesso utente.'],
        ],
    ],
];

function badgeClass(string $kind): string
{
    return match ($kind) {
        'PK' => 'badge-pk',
        'FK' => 'badge-fk',
        'UNIQUE' => 'badge-unique',
        'IDX' => 'badge-index',
        'NOTE' => 'badge-note',
        default => 'badge-cardinality',
    };
}
?>
<!DOCTYPE html>
<html lang="it" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Struttura Database - OnePassage</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #070b10;
            --bg-soft: #0d131b;
            --card: #121a24;
            --card-strong: #162130;
            --border: rgba(255, 255, 255, .09);
            --border-strong: rgba(16, 185, 129, .62);
            --text: #f4f7fb;
            --muted: #9ca8b8;
            --emerald: #10b981;
            --emerald-soft: rgba(16, 185, 129, .14);
            --amber: #f59e0b;
            --amber-soft: rgba(245, 158, 11, .14);
            --cyan: #22d3ee;
            --cyan-soft: rgba(34, 211, 238, .13);
            --violet: #a78bfa;
            --violet-soft: rgba(167, 139, 250, .13);
            --slate-soft: rgba(148, 163, 184, .12);
            --shadow: 0 24px 70px rgba(0, 0, 0, .42);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            color: var(--text);
            background:
                radial-gradient(circle at 18% 8%, rgba(16, 185, 129, .16), transparent 34%),
                radial-gradient(circle at 82% 12%, rgba(34, 211, 238, .10), transparent 32%),
                linear-gradient(135deg, #070b10 0%, #0d131b 52%, #070b10 100%);
            line-height: 1.5;
        }

        a { color: inherit; text-decoration: none; }

        .page {
            width: min(1600px, 100%);
            margin: 0 auto;
            padding: 32px 28px 64px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 600;
            padding: 10px 0;
            transition: color .18s ease, transform .18s ease;
        }

        .back-link:hover { color: var(--emerald); transform: translateX(-2px); }

        .hero {
            margin: 42px 0 34px;
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(280px, .85fr);
            gap: 28px;
            align-items: end;
        }

        .eyebrow {
            display: inline-flex;
            width: fit-content;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: rgba(255, 255, 255, .04);
            color: var(--emerald);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 18px;
        }

        h1 {
            max-width: 940px;
            font-size: clamp(40px, 6vw, 76px);
            line-height: .98;
            letter-spacing: -.04em;
            font-weight: 800;
        }

        .subtitle {
            max-width: 780px;
            margin-top: 20px;
            color: var(--muted);
            font-size: clamp(16px, 2vw, 20px);
        }

        .legend {
            padding: 22px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: rgba(18, 26, 36, .78);
            box-shadow: var(--shadow);
        }

        .legend h2 { font-size: 15px; margin-bottom: 14px; }
        .legend-list { display: grid; gap: 10px; }
        .legend-item { display: flex; align-items: center; gap: 8px; color: var(--muted); font-size: 13px; }

        .schema-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(260px, 1fr));
            gap: 18px;
        }

        .table-card {
            min-height: 100%;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: linear-gradient(180deg, rgba(22, 33, 48, .94), rgba(18, 26, 36, .94));
            box-shadow: 0 14px 38px rgba(0, 0, 0, .25);
            overflow: hidden;
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
        }

        .table-card:hover {
            border-color: var(--border-strong);
            box-shadow: 0 20px 58px rgba(16, 185, 129, .12), 0 18px 50px rgba(0, 0, 0, .32);
            transform: translateY(-3px);
        }

        .table-head {
            padding: 18px 18px 15px;
            border-bottom: 1px solid var(--border);
            background: rgba(255, 255, 255, .025);
        }

        .entity-label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .table-name {
            margin-top: 5px;
            font-size: 22px;
            font-weight: 800;
            letter-spacing: -.02em;
        }

        .table-desc {
            margin-top: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .fields { list-style: none; padding: 12px 14px 4px; }

        .field {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: 9px;
            align-items: center;
            padding: 10px 6px;
            border-bottom: 1px solid rgba(255, 255, 255, .055);
        }

        .field:last-child { border-bottom: 0; }

        .field-name {
            min-width: 0;
            font-size: 14px;
            font-weight: 650;
            color: #eef3f8;
            overflow-wrap: anywhere;
        }

        .field-type {
            justify-self: end;
            max-width: 190px;
            color: var(--muted);
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 11px;
            text-align: right;
            overflow-wrap: anywhere;
        }

        .key, .badge {
            display: inline-grid;
            place-items: center;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 800;
        }

        .key {
            width: 28px;
            height: 26px;
        }

        .key-pk, .badge-pk {
            color: var(--amber);
            background: var(--amber-soft);
            border: 1px solid rgba(245, 158, 11, .26);
        }

        .key-fk, .badge-fk {
            color: var(--cyan);
            background: var(--cyan-soft);
            border: 1px solid rgba(34, 211, 238, .24);
        }

        .key-unique, .badge-unique {
            color: var(--violet);
            background: var(--violet-soft);
            border: 1px solid rgba(167, 139, 250, .24);
        }

        .key-index, .badge-index, .badge-note {
            color: var(--muted);
            background: var(--slate-soft);
            border: 1px solid rgba(148, 163, 184, .20);
        }

        .key-empty {
            color: rgba(255, 255, 255, .18);
            background: rgba(255, 255, 255, .035);
            border: 1px solid rgba(255, 255, 255, .055);
        }

        .constraints {
            margin-top: auto;
            padding: 16px 18px 18px;
            border-top: 1px solid var(--border);
            background: rgba(0, 0, 0, .12);
        }

        .constraints h3 {
            font-size: 13px;
            color: #dfe7ef;
            margin-bottom: 10px;
        }

        .relation { display: grid; gap: 9px; color: var(--muted); font-size: 13px; }
        .relation-row { display: grid; grid-template-columns: auto 1fr; gap: 8px; align-items: start; }

        .badge {
            width: fit-content;
            padding: 5px 9px;
            border-radius: 999px;
            letter-spacing: .03em;
            text-transform: uppercase;
        }

        .badge-cardinality {
            color: var(--emerald);
            background: var(--emerald-soft);
            border: 1px solid rgba(16, 185, 129, .25);
        }

        code {
            color: #d9f99d;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .95em;
        }

        .footer-note {
            margin-top: 34px;
            padding: 18px 20px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: rgba(255, 255, 255, .035);
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 1280px) {
            .schema-grid { grid-template-columns: repeat(3, minmax(260px, 1fr)); }
        }

        @media (max-width: 920px) {
            .page { padding: 24px 18px 46px; }
            .hero { grid-template-columns: 1fr; margin-top: 28px; }
            .schema-grid { grid-template-columns: repeat(2, minmax(240px, 1fr)); }
        }

        @media (max-width: 620px) {
            .schema-grid { grid-template-columns: 1fr; }
            .field { grid-template-columns: auto 1fr; }
            .field-type { grid-column: 2; justify-self: start; text-align: left; max-width: none; }
        }
    </style>
</head>
<body>
    <main class="page">
        <a class="back-link" href="index.php" aria-label="Torna alla Home">← Torna alla Home</a>

        <section class="hero">
            <div>
                <span class="eyebrow">Area Didattica · Dump reale 29/06/2026</span>
                <h1>Struttura Database OnePassage</h1>
                <p class="subtitle">
                    Schema logico relazionale basato sul database attuale: tabelle, attributi SQL,
                    chiavi primarie, chiavi esterne, indici e cardinalita principali.
                </p>
            </div>

            <aside class="legend" aria-label="Legenda dello schema">
                <h2>Legenda</h2>
                <div class="legend-list">
                    <span class="legend-item"><span class="key key-pk">PK</span> Chiave primaria: identifica un record.</span>
                    <span class="legend-item"><span class="key key-fk">FK</span> Chiave esterna: collega due tabelle.</span>
                    <span class="legend-item"><span class="key key-unique">UQ</span> Valore unico, utile contro duplicati.</span>
                    <span class="legend-item"><span class="badge badge-cardinality">1:N</span> Un record puo collegarsi a molti record.</span>
                </div>
            </aside>
        </section>

        <section class="schema-grid" aria-label="Schema logico del database">
            <?php foreach ($tables as $table): ?>
                <article class="table-card">
                    <header class="table-head">
                        <span class="entity-label"><?= htmlspecialchars($table['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        <h2 class="table-name"><?= htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <p class="table-desc"><?= htmlspecialchars($table['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </header>

                    <ul class="fields">
                        <?php foreach ($table['fields'] as [$field, $type, $kind]): ?>
                            <?php
                                $keyText = match ($kind) {
                                    'PK' => 'PK',
                                    'FK' => 'FK',
                                    'UNIQUE' => 'UQ',
                                    'IDX' => 'IX',
                                    default => '·',
                                };
                                $keyClass = match ($kind) {
                                    'PK' => 'key-pk',
                                    'FK' => 'key-fk',
                                    'UNIQUE' => 'key-unique',
                                    'IDX' => 'key-index',
                                    default => 'key-empty',
                                };
                            ?>
                            <li class="field">
                                <span class="key <?= $keyClass ?>"><?= $keyText ?></span>
                                <span class="field-name"><?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="field-type"><?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <section class="constraints">
                        <h3>Vincoli di Integrità e Cardinalità</h3>
                        <div class="relation">
                            <?php foreach ($table['relations'] as [$kind, $text]): ?>
                                <p class="relation-row">
                                    <span class="badge <?= badgeClass($kind) ?>"><?= htmlspecialchars($kind, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span><?= preg_replace('/`([^`]+)`/', '<code>$1</code>', htmlspecialchars($text, ENT_QUOTES, 'UTF-8')) ?></span>
                                </p>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </article>
            <?php endforeach; ?>
        </section>
    </main>
</body>
</html>
