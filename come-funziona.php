<?php
require_once 'config.php';
?>
<!DOCTYPE html>
<html lang="it" data-theme="light">
<head>
    <script>
    (function() {
        var t = localStorage.getItem('theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
</script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Come Funziona - OnePassage</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/style-comefunziona.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Header -->
    <?php include 'header_snippet.php'; ?>

    <!-- Hero Section -->
    <section class="hero-section">
        <div class="hero-content">
            <h1 class="hero-title">Come Funziona</h1>
            <p class="hero-subtitle">Condividi il viaggio verso i tuoi eventi preferiti in 3 semplici step</p>
        </div>
    </section>

    <!-- Steps Section -->
    <section class="steps-section">
        <div class="container">
            <div class="step">
                <div class="step-number">01</div>
                <div class="step-content">
                    <h2 class="step-title">Cerca l'Evento</h2>
                    <p class="step-description">
                        Utilizza la barra di ricerca per trovare il concerto, festival o evento a cui vuoi partecipare. 
                        Filtra per città, data e tipo di evento per trovare esattamente quello che cerchi.
                    </p>
                </div>
                <div class="step-visual">
                    <div class="visual-card">
                        <div class="search-mockup">
                            <div class="search-field-mock"></div>
                            <div class="search-field-mock"></div>
                            <div class="search-button-mock"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="step reverse">
                <div class="step-number">02</div>
                <div class="step-content">
                    <h2 class="step-title">Scegli il Tuo Accompagnatore</h2>
                    <p class="step-description">
                        Esplora i profili degli accompagnatori disponibili. Consulta le recensioni, verifica la distanza 
                        dal punto di partenza e scegli la persona con cui condividere il viaggio. Oppure, se hai un'auto, 
                        offri tu stesso un passaggio!
                    </p>
                </div>
                <div class="step-visual">
                    <div class="visual-card">
                        <div class="profile-mockup">
                            <div class="avatar-mock"></div>
                            <div class="stars-mock">★★★★★</div>
                            <div class="info-line"></div>
                            <div class="info-line short"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="step">
                <div class="step-number">03</div>
                <div class="step-content">
                    <h2 class="step-title">Viaggia e Recensisci</h2>
                    <p class="step-description">
                        Organizza i dettagli tramite chat, condividi il viaggio e le spese. Dopo l'evento, lascia una 
                        recensione per aiutare la community a crescere in sicurezza e fiducia.
                    </p>
                </div>
                <div class="step-visual">
                    <div class="visual-card">
                        <div class="chat-mockup">
                            <div class="message-bubble left"></div>
                            <div class="message-bubble right"></div>
                            <div class="message-bubble left"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section class="features-section">
        <div class="container">
            <h2 class="section-title">Perché Scegliere OnePassage</h2>
            
            <div class="features-grid">
                <div class="feature-card card">
                    <div class="feature-icon">💰</div>
                    <h3 class="feature-title">Risparmia</h3>
                    <p class="feature-text">
                        Condividi le spese di viaggio e risparmia fino al 70% sui tuoi spostamenti verso gli eventi.
                    </p>
                </div>

                <div class="feature-card card">
                    <div class="feature-icon">🤝</div>
                    <h3 class="feature-title">Socializza</h3>
                    <p class="feature-text">
                        Incontra nuove persone che condividono la tua passione per la musica e gli eventi live.
                    </p>
                </div>

                <div class="feature-card card">
                    <div class="feature-icon">🌍</div>
                    <h3 class="feature-title">Sostenibile</h3>
                    <p class="feature-text">
                        Riduci le emissioni di CO2 viaggiando insieme e contribuisci a un futuro più verde.
                    </p>
                </div>

                <div class="feature-card card">
                    <div class="feature-icon">🛡️</div>
                    <h3 class="feature-title">Sicuro</h3>
                    <p class="feature-text">
                        Sistema di recensioni verificate e profili autenticati per viaggiare in totale sicurezza.
                    </p>
                </div>

                <div class="feature-card card">
                    <div class="feature-icon">💬</div>
                    <h3 class="feature-title">Chat Integrata</h3>
                    <p class="feature-text">
                        Organizza tutti i dettagli del viaggio direttamente nella piattaforma, senza scambiare numeri.
                    </p>
                </div>

                <div class="feature-card card">
                    <div class="feature-icon">⚡</div>
                    <h3 class="feature-title">Veloce</h3>
                    <p class="feature-text">
                        Trova un passaggio in pochi click grazie ai filtri intelligenti e alla ricerca avanzata.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section">
        <div class="cta-content">
            <h2 class="cta-title">Pronto a Partire?</h2>
            <p class="cta-subtitle">Unisciti a migliaia di appassionati che viaggiano insieme</p>
            <div class="cta-buttons">
                <a href="ricerca.php" class="btn-primary-large">Cerca un Evento</a>
                <?php if(!isLoggedIn()): ?>
                <a href="auth.php" class="btn-secondary-large">Registrati Gratis</a>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <p>&copy; 2026 OnePassage. Viaggia insieme, risparmia e socializza.</p>
        </div>
    </footer>

    <script>
        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme');
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
        }

        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();

        let ticking = false;
        function updateParallax() {
            const scrollY = window.scrollY;
            const offset = Math.min(scrollY * 0.5, 300);
            document.body.style.setProperty('--scroll-x', `${offset}px`);
            ticking = false;
        }

        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(updateParallax);
                ticking = true;
            }
        }, { passive: true });
    </script>
</body>
</html>