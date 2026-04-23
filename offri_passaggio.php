<?php
require_once 'config.php';

if(!isLoggedIn()) {
    header('Location: auth.php');
    exit;
}

$userId = $_SESSION['user_id'];
$eventId = isset($_GET['event']) ? (int)$_GET['event'] : 0;
$errore = '';
$successo = '';

// Carica lista eventi futuri
$stmt = $pdo->prepare("
    SELECT id, nome_evento, luogo, data_evento
    FROM events
    WHERE data_evento >= NOW()
    ORDER BY data_evento ASC
");
$stmt->execute();
$eventi = $stmt->fetchAll();

// Gestione form
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $event_id = (int)$_POST['event_id'];
    $punto_partenza = trim($_POST['punto_partenza']);
    $lat_partenza = (float)$_POST['lat_partenza'];
    $lon_partenza = (float)$_POST['lon_partenza'];
    $posti = (int)$_POST['posti_disponibili'];
    $prezzo = (float)$_POST['prezzo_per_posto'];
    $note = trim($_POST['note']);
    
    // Validazione
    if(empty($punto_partenza) || $lat_partenza == 0 || $lon_partenza == 0) {
        $errore = 'Inserisci un punto di partenza valido';
    } elseif($posti < 1 || $posti > 8) {
        $errore = 'I posti devono essere tra 1 e 8';
    } elseif($prezzo < 0 || $prezzo > 100) {
        $errore = 'Prezzo non valido';
    } else {
        // Verifica se esiste già un'offerta per questo evento
        $check = $pdo->prepare("
            SELECT id FROM ride_offers 
            WHERE user_id = ? AND event_id = ?
        ");
        $check->execute([$userId, $event_id]);
        
        if($check->fetch()) {
            $errore = 'Hai già pubblicato un\'offerta per questo evento';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ride_offers 
                    (user_id, event_id, punto_partenza, latitudine_partenza, 
                     longitudine_partenza, posti_disponibili, prezzo_per_posto, note)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $userId, $event_id, $punto_partenza, $lat_partenza,
                    $lon_partenza, $posti, $prezzo, $note
                ]);
                
                $successo = 'Offerta pubblicata con successo!';
                $eventId = 0; // Reset selezione
            } catch(PDOException $e) {
                $errore = 'Errore durante la pubblicazione';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offri un Passaggio - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/ricerca_extra.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
<?php include 'header_snippet.php'; ?>

    <div class="container">
        <div style="max-width: 700px; margin: 40px auto;">
            <h1 style="font-size: 32px; font-weight: 800; margin-bottom: 12px;">
                <i class="fas fa-car"></i> Offri un Passaggio
            </h1>
            <p style="color: var(--text-secondary); margin-bottom: 32px;">
                Condividi il tuo viaggio verso un evento e aiuta altri appassionati a raggiungerlo!
            </p>

            <?php if($errore): ?>
            <div class="card" style="background-color: rgba(239, 68, 68, 0.1); border-color: #EF4444; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 12px; color: #EF4444;">
                    <i class="fas fa-exclamation-circle" style="font-size: 24px;"></i>
                    <span style="font-weight: 600;"><?= h($errore) ?></span>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if($successo): ?>
            <div class="card" style="background-color: rgba(16, 185, 129, 0.1); border-color: var(--accent-primary); margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 12px; color: var(--accent-primary);">
                    <i class="fas fa-check-circle" style="font-size: 24px;"></i>
                    <div style="flex: 1;">
                        <span style="font-weight: 600;"><?= h($successo) ?></span>
                        <div style="margin-top: 8px;">
                            <a href="dashboard.php" class="btn btn-primary btn-small">
                                Vai alla Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card">
                <form method="POST" action="" id="offerForm">
                    <div class="form-group">
                        <label>
                            <i class="fas fa-calendar-alt"></i> Evento *
                        </label>
                        <select name="event_id" required onchange="updateEventInfo(this)">
                            <option value="">Seleziona un evento</option>
                            <?php foreach($eventi as $evento): ?>
                            <option value="<?= $evento['id'] ?>" 
                                    data-nome="<?= h($evento['nome_evento']) ?>"
                                    data-luogo="<?= h($evento['luogo']) ?>"
                                    data-data="<?= date('d/m/Y H:i', strtotime($evento['data_evento'])) ?>"
                                    <?= $eventId == $evento['id'] ? 'selected' : '' ?>>
                                <?= h($evento['nome_evento']) ?> - 
                                <?= date('d/m/Y', strtotime($evento['data_evento'])) ?> - 
                                <?= h($evento['luogo']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="eventInfo" style="margin-top: 12px; padding: 12px; background: var(--bg-tertiary); border-radius: 8px; display: none;">
                            <div style="font-size: 14px; color: var(--text-secondary);"></div>
                        </div>
                        <div style="margin-top: 10px; font-size: 13px; color: var(--color-text-secondary);">
                            Non trovi l'evento?
                            <a href="crea_evento.php?redirect=offri_passaggio"
                               style="color: var(--color-accent); font-weight: 600; text-decoration: none;">
                                <i class="fas fa-plus-circle"></i> Crealo adesso
                            </a>
                            — dopo la creazione tornerai automaticamente qui.
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="fas fa-map-marker-alt"></i> Punto di Partenza *
                        </label>
                        <div class="autocomplete-wrapper" id="partenzaWrapper">
                            <input type="text" name="punto_partenza" id="puntoPartenza"
                                   placeholder="Cerca una città o un indirizzo…"
                                   autocomplete="off" required>
                            <button type="button" class="geo-search-btn" id="geoBtn"
                                    title="Usa GPS per rilevare la tua posizione">
                                <i class="fas fa-crosshairs" id="geoBtnIcon"></i>
                            </button>
                            <div class="autocomplete-dropdown" id="partenzaDropdown"></div>
                        </div>
                        <!-- Badge coordinate selezionate -->
                        <div id="coordsBadge" style="display:none; margin-top:8px; font-size:12px;
                             color:var(--color-accent); display:none; align-items:center; gap:6px;">
                            <i class="fas fa-check-circle"></i>
                            <span id="coordsText"></span>
                        </div>
                        <!-- Campi hidden per lat/lon -->
                        <input type="hidden" name="lat_partenza" id="latPartenza">
                        <input type="hidden" name="lon_partenza" id="lonPartenza">
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                        <div class="form-group">
                            <label>
                                <i class="fas fa-users"></i> Posti Disponibili *
                            </label>
                            <input type="number" name="posti_disponibili" min="1" max="8" 
                                   value="2" required>
                            <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                                Quanti passeggeri puoi portare?
                            </small>
                        </div>
                        
                        <div class="form-group">
                            <label>
                                <i class="fas fa-euro-sign"></i> Prezzo per Posto *
                            </label>
                            <input type="number" name="prezzo_per_posto" min="0" max="100" 
                                   step="0.50" value="10.00" required>
                            <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                                Contributo spese in €
                            </small>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>
                            <i class="fas fa-sticky-note"></i> Note Aggiuntive
                        </label>
                        <textarea name="note" rows="4" 
                                  placeholder="Es: Parto alle 19:30, auto spaziosa, posso caricare strumenti..."></textarea>
                        <small style="color: var(--text-muted); display: block; margin-top: 4px;">
                            Informazioni utili per i passeggeri (orario partenza, tipo auto, ecc.)
                        </small>
                    </div>

                    <div style="background: var(--bg-tertiary); padding: 16px; border-radius: 12px; margin: 24px 0;">
                        <h4 style="margin-bottom: 12px; font-weight: 700;">
                            <i class="fas fa-shield-alt"></i> Consigli per la Sicurezza
                        </h4>
                        <ul style="color: var(--text-secondary); font-size: 14px; margin-left: 20px; line-height: 1.8;">
                            <li>Comunica sempre tramite la chat interna</li>
                            <li>Verifica il profilo e le recensioni dei passeggeri</li>
                            <li>Parti solo se ti senti sicuro/a</li>
                            <li>Chiedi un contributo equo per le spese</li>
                        </ul>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%; font-size: 18px; padding: 16px;">
                        <i class="fas fa-check"></i> Pubblica Offerta
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function updateEventInfo(select) {
            const option = select.options[select.selectedIndex];
            const infoDiv = document.getElementById('eventInfo');
            
            if(option.value) {
                const nome = option.dataset.nome;
                const luogo = option.dataset.luogo;
                const data = option.dataset.data;
                
                infoDiv.innerHTML = `
                    <div style="font-size: 14px; color: var(--text-secondary);">
                        <strong style="color: var(--text-primary);">${nome}</strong><br>
                        <i class="fas fa-map-marker-alt"></i> ${luogo}<br>
                        <i class="fas fa-calendar"></i> ${data}
                    </div>
                `;
                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
            }
        }

        function toggleTheme() {
            const html = document.documentElement;
            const t = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', t);
            localStorage.setItem('theme', t);
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            const eventSelect = document.querySelector('select[name="event_id"]');
            if(eventSelect.value) updateEventInfo(eventSelect);
        });

        /* ════════════════════════════════════
           AUTOCOMPLETE PUNTO PARTENZA + GPS
        ════════════════════════════════════ */
        const puntoInput  = document.getElementById('puntoPartenza');
        const dropdown    = document.getElementById('partenzaDropdown');
        const latEl       = document.getElementById('latPartenza');
        const lonEl       = document.getElementById('lonPartenza');
        const coordsBadge = document.getElementById('coordsBadge');
        const coordsText  = document.getElementById('coordsText');

        let debounceTimer = null;
        let currentItems  = [];
        let focusedIndex  = -1;

        function formatLabel(props) {
            return [
                props.name || null,
                props.road || props.street || null,
                props.city || props.town || props.village || props.county || null,
                props.state || null,
                props.country || null,
            ].filter(v => v && v.trim() !== '').join(', ') || 'Luogo sconosciuto';
        }

        function closeDropdown() {
            dropdown.classList.remove('open');
            dropdown.innerHTML = '';
            focusedIndex = -1;
        }

        function showStatus(msg) {
            dropdown.innerHTML = '';
            const el = document.createElement('div');
            el.className = 'autocomplete-loading';
            el.innerHTML = msg;
            dropdown.appendChild(el);
            dropdown.classList.add('open');
        }

        function renderItems(features) {
            dropdown.innerHTML = '';
            dropdown.classList.add('open');
            features.forEach((feature, i) => {
                const props = feature.properties;
                const item  = document.createElement('div');
                item.className = 'autocomplete-item';

                const icon = document.createElement('span');
                icon.className = 'autocomplete-item-icon';
                icon.innerHTML = '<i class="fas fa-map-marker-alt"></i>';

                const text = document.createElement('div');
                const main = document.createElement('div');
                main.className   = 'autocomplete-item-main';
                main.textContent = formatLabel(props);

                const address = props.address || {};
                const sub = [
                    address.city || address.town || address.village || null,
                    address.state || null,
                    address.country || null,
                ].filter(Boolean).join(' · ');

                if (sub) {
                    const subDiv = document.createElement('div');
                    subDiv.className   = 'autocomplete-item-sub';
                    subDiv.textContent = sub;
                    text.appendChild(subDiv);
                }

                text.insertBefore(main, text.firstChild);
                item.appendChild(icon);
                item.appendChild(text);

                item.addEventListener('mousedown', e => {
                    e.preventDefault();
                    const coords = feature.geometry.coordinates; // [lon, lat]
                    setLocation(coords[1], coords[0], formatLabel(props));
                });

                dropdown.appendChild(item);
            });
        }

        function setLocation(lat, lon, label) {
            latEl.value       = lat;
            lonEl.value       = lon;
            puntoInput.value  = label;
            coordsText.textContent = `${parseFloat(lat).toFixed(5)}, ${parseFloat(lon).toFixed(5)}`;
            coordsBadge.style.display = 'flex';
            closeDropdown();
        }

        async function fetchSuggestions(query) {
            if (query.length < 2) { closeDropdown(); return; }
            showStatus('<i class="fas fa-circle-notch fa-spin"></i> Ricerca…');
            try {
                const controller = new AbortController();
                const tid = setTimeout(() => controller.abort(), 8000);
                const res = await fetch(`geocode_proxy.php?q=${encodeURIComponent(query)}`, { signal: controller.signal });
                clearTimeout(tid);
                if (!res.ok) { showStatus('Servizio non disponibile. Riprova.'); return; }
                const data = await res.json();
                if (data.error)            { showStatus('Errore: ' + data.error); return; }
                if (!data.features?.length){ showStatus('Nessun risultato trovato.'); return; }
                currentItems = data.features;
                focusedIndex = -1;
                renderItems(currentItems);
            } catch (err) {
                if (err.name === 'AbortError') showStatus('Ricerca scaduta. Riprova.');
                else showStatus('Errore di connessione.');
            }
        }

        puntoInput.addEventListener('input', () => {
            latEl.value = '';
            lonEl.value = '';
            coordsBadge.style.display = 'none';
            clearTimeout(debounceTimer);
            const q = puntoInput.value.trim();
            if (q.length < 2) { closeDropdown(); return; }
            debounceTimer = setTimeout(() => fetchSuggestions(q), 350);
        });

        puntoInput.addEventListener('keydown', e => {
            const items = dropdown.querySelectorAll('.autocomplete-item');
            if (!items.length) return;
            if (e.key === 'ArrowDown')  { e.preventDefault(); focusedIndex = Math.min(focusedIndex + 1, items.length - 1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); focusedIndex = Math.max(focusedIndex - 1, 0); }
            else if (e.key === 'Enter' && focusedIndex >= 0) { e.preventDefault(); items[focusedIndex].dispatchEvent(new MouseEvent('mousedown')); return; }
            else if (e.key === 'Escape') { closeDropdown(); return; }
            items.forEach((el, i) => el.classList.toggle('focused', i === focusedIndex));
        });

        puntoInput.addEventListener('blur', () => setTimeout(closeDropdown, 200));

puntoInput.addEventListener('focus', () => {
    puntoInput.select();
    latEl.value = '';
    lonEl.value = '';
    coordsBadge.style.display = 'none';
});

        // Valida che lat/lon siano stati selezionati prima di submit
        document.getElementById('offerForm').addEventListener('submit', function(e) {
            if (!latEl.value || !lonEl.value || latEl.value == '0' || lonEl.value == '0') {
                e.preventDefault();
                puntoInput.focus();
                showStatus('<i class="fas fa-exclamation-triangle"></i> Seleziona un luogo dai suggerimenti o usa il GPS.');
                puntoInput.style.borderColor = '#EF4444';
                setTimeout(() => puntoInput.style.borderColor = '', 3000);
            }
        });

        /* ── GPS ── */
        const geoBtn     = document.getElementById('geoBtn');
        const geoBtnIcon = document.getElementById('geoBtnIcon');

        geoBtn.addEventListener('click', () => {
            if (!navigator.geolocation) { alert('Geolocalizzazione non supportata dal browser.'); return; }
            geoBtn.classList.add('loading');
            geoBtnIcon.className = 'fas fa-circle-notch';

            navigator.geolocation.getCurrentPosition(
                async pos => {
                    const lat = pos.coords.latitude;
                    const lon = pos.coords.longitude;
                    try {
                        const res  = await fetch(`geocode_proxy.php?lat=${lat}&lon=${lon}`);
                        const data = await res.json();
                        const p    = data.features?.[0]?.properties || {};
                        setLocation(lat, lon, formatLabel(p) || 'La mia posizione');
                    } catch {
                        setLocation(lat, lon, 'La mia posizione');
                    }
                    geoBtn.classList.remove('loading');
                    geoBtnIcon.className = 'fas fa-crosshairs';
                },
                err => {
                    geoBtn.classList.remove('loading');
                    geoBtnIcon.className = 'fas fa-crosshairs';
                    const msg = err.code === err.PERMISSION_DENIED
                        ? 'Permesso GPS negato. Abilita la posizione nelle impostazioni del browser.'
                        : 'Impossibile rilevare la posizione. Inserisci l\'indirizzo manualmente.';
                    alert(msg);
                },
                { timeout: 10000, maximumAge: 60000 }
            );
        });
    </script>
</body>
</html>