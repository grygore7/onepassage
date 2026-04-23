<?php
require_once 'config.php';

// Query eventi in evidenza
$stmt = $pdo->prepare("
    SELECT id, nome_evento, luogo, data_evento, descrizione
    FROM events 
    WHERE data_evento >= CURDATE() 
    ORDER BY data_evento ASC 
    LIMIT 6
");
$stmt->execute();
$eventi = $stmt->fetchAll();
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
    <title>OnePassage - Condividi il viaggio verso i tuoi eventi</title>
    <link rel="stylesheet" href="css/design-system.css">
    <link rel="stylesheet" href="css/style-home.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <!-- Header Absolute -->
    <?php include 'header_snippet.php'; ?>

    <!-- Hero Section Unificata -->
    <section class="hero">
        <div class="hero-content">
            <h1 class="hero-title">Dove vuoi andare?</h1>
            
            <form action="ricerca.php" method="GET" class="search-bar">
                <div class="search-field">
                    <input type="text" name="q" placeholder="Nome evento" class="search-input">
                </div>
                <div class="search-separator"></div>
                <div class="search-field">
                    <input type="text" name="luogo" placeholder="Città" class="search-input">
                </div>
                <div class="search-separator"></div>
                <div class="search-field">
                    <input type="date" name="data_inizio" placeholder="Data" class="search-input">
                </div>
                <button type="submit" class="search-button">Cerca</button>
            </form>
        </div>
    </section>

    <!-- Eventi in Evidenza -->
    <section class="section-lg featured-section">
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 48px;">
                <h2 class="section-title">Eventi in evidenza</h2>
                <a href="ricerca.php" style="color: var(--color-text-secondary); font-weight: 500; display: flex; align-items: center; gap: 8px; transition: color 0.3s;">
                    Vedi tutti
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M6 12L10 8L6 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
            </div>

            <?php if(empty($eventi)): ?>
            <div class="card" style="text-align: center; padding: 60px 20px;">
                <p style="font-size: 18px; color: var(--color-text-muted);">Nessun evento disponibile al momento</p>
            </div>
            <?php else: ?>
            <div class="home-events-grid">
                <?php foreach($eventi as $evento): ?>
                <a href="dettaglio_evento.php?id=<?= $evento['id'] ?>" class="home-event-card card">
                    <h3 class="event-title"><?= h($evento['nome_evento']) ?></h3>
                    <p class="event-location"><?= h($evento['luogo']) ?></p>
                    <p class="event-date"><?= date('d M Y', strtotime($evento['data_evento'])) ?></p>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </section>

    <script>
        // Theme Toggle
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
            window.addEventListener('load', () => {
                document.querySelector('.hero-title').classList.add('animate');
                document.querySelector('.search-bar').classList.add('animate');
            });
        })();

        // Parallax unificato
        let ticking = false;
        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(() => {
                    const scrollY = window.scrollY;
                    const offset = Math.min(scrollY * 0.4, 400);
                    document.body.style.setProperty('--scroll-x', `${offset}px`);
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    </script>
</body>
</html>