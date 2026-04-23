-- OnePassage Database Schema
-- Database completo per la piattaforma di carpooling

CREATE DATABASE IF NOT EXISTS onepassage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE onepassage;

-- Tabella Utenti
CREATE TABLE users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    telefono VARCHAR(20),
    bio TEXT,
    foto_profilo VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella Eventi
CREATE TABLE events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    nome_evento VARCHAR(255) NOT NULL,
    descrizione TEXT,
    luogo VARCHAR(255) NOT NULL,
    latitudine DECIMAL(10,8) NOT NULL,
    longitudine DECIMAL(11,8) NOT NULL,
    data_evento DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_data_evento (data_evento),
    INDEX idx_luogo (luogo),
    INDEX idx_coords (latitudine, longitudine)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella Offerte Passaggi
CREATE TABLE ride_offers (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    event_id INT NOT NULL,
    punto_partenza VARCHAR(255) NOT NULL,
    latitudine_partenza DECIMAL(10,8) NOT NULL,
    longitudine_partenza DECIMAL(11,8) NOT NULL,
    posti_disponibili INT NOT NULL DEFAULT 1,
    prezzo_per_posto DECIMAL(10,2) NOT NULL,
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    INDEX idx_user_event (user_id, event_id),
    INDEX idx_event (event_id),
    INDEX idx_posti (posti_disponibili)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella Richieste Passaggi
CREATE TABLE ride_requests (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL COMMENT 'Passeggero che richiede',
    offer_id INT NOT NULL,
    driver_id INT NOT NULL COMMENT 'Conducente',
    stato ENUM('in_attesa', 'accettato', 'rifiutato', 'concluso') DEFAULT 'in_attesa',
    stelle INT CHECK (stelle >= 1 AND stelle <= 5),
    recensione_testo TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (offer_id) REFERENCES ride_offers(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_stato (user_id, stato),
    INDEX idx_driver_stato (driver_id, stato),
    INDEX idx_offer (offer_id),
    INDEX idx_recensioni (driver_id, stato, stelle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella Messaggi Chat
CREATE TABLE chat_messages (
    id INT PRIMARY KEY AUTO_INCREMENT,
    request_id INT NOT NULL,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    messaggio TEXT NOT NULL,
    letto BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES ride_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_request (request_id),
    INDEX idx_receiver_letto (receiver_id, letto),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- DATI DI ESEMPIO PER TESTING
-- =====================================================

-- Utenti di esempio
INSERT INTO users (nome, cognome, email, password_hash, telefono, bio) VALUES
('Marco', 'Rossi', 'marco.rossi@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '3331234567', 'Appassionato di musica rock e metal. Viaggio spesso per concerti in tutta Italia!'),
('Laura', 'Bianchi', 'laura.bianchi@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '3347654321', 'Amante degli eventi live. Sempre disponibile per condividere il viaggio!'),
('Giuseppe', 'Verdi', 'giuseppe.verdi@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '3389876543', 'DJ e producer. Mi piace andare ai festival elettronici.'),
('Sofia', 'Ferrari', 'sofia.ferrari@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '3392345678', 'Studentessa universitaria. Cerco sempre compagni di viaggio per risparmiare!'),
('Alessandro', 'Ricci', 'alessandro.ricci@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '3351234567', 'Musicista professionista. Organizzo spesso viaggi per concerti.');

-- Eventi di esempio (coordinate reali di città italiane)
INSERT INTO events (nome_evento, descrizione, luogo, latitudine, longitudine, data_evento) VALUES
('Concerto Måneskin - Tour 2026', 'Il tour italiano dei Måneskin fa tappa a Milano con uno spettacolo imperdibile al Forum di Assago. Rock italiano al massimo livello!', 'Mediolanum Forum - Assago (MI)', 45.41902600, 9.13626100, '2026-02-15 21:00:00'),
('Techno Festival Milano', 'Il più grande festival di musica elettronica del Nord Italia. 3 stage, 50+ artisti internazionali.', 'Ippodromo SNAI - Milano', 45.47851500, 9.15803900, '2026-03-20 18:00:00'),
('Rock in Roma 2026', 'Festival rock internazionale con headliner di fama mondiale. Location suggestiva all\'aperto.', 'Ippodromo Capannelle - Roma', 41.84183900, 12.53113800, '2026-04-10 19:00:00'),
('Jovanotti - Jova Beach Party', 'Il party estivo più atteso dell\'anno torna sulle spiagge italiane con una tappa speciale a Rimini!', 'Spiaggia di Rimini', 44.06761200, 12.56960300, '2026-06-25 17:00:00'),
('Vasco Rossi Live', 'Il Blasco torna dal vivo con uno show monumentale allo Stadio San Siro di Milano.', 'Stadio San Siro - Milano', 45.47827000, 9.12401500, '2026-05-30 20:30:00'),
('Hip Hop Headliners', 'Festival dedicato alla cultura hip hop con i migliori artisti italiani e internazionali.', 'Parco Nord - Bologna', 44.51435400, 11.36162300, '2026-07-12 19:00:00'),
('Jazz Festival Torino', 'Una settimana dedicata al jazz con concerti di artisti di fama mondiale nel centro storico.', 'Piazza Castello - Torino', 45.07225200, 7.68569900, '2026-09-05 21:00:00'),
('Indie Rock Night', 'Serata dedicata alle migliori band indie rock emergenti e consolidate della scena italiana.', 'Alcatraz - Milano', 45.49312800, 9.20811600, '2026-02-28 22:00:00');

-- Offerte passaggi di esempio
INSERT INTO ride_offers (user_id, event_id, punto_partenza, latitudine_partenza, longitudine_partenza, posti_disponibili, prezzo_per_posto, note) VALUES
(1, 1, 'Centro storico Milano', 45.46427000, 9.18951400, 3, 8.00, 'Parto dal centro verso le 19:30. Auto comoda, posso caricare anche strumenti piccoli.'),
(2, 1, 'Stazione Centrale Milano', 45.48640600, 9.20569800, 2, 5.00, 'Disponibile per tornare anche dopo il concerto. Auto piccola ma comoda.'),
(3, 2, 'Porta Garibaldi', 45.48453900, 9.18770700, 4, 10.00, 'Van spazioso, posso portare più persone. Partiamo alle 17:00 precise!'),
(4, 3, 'Bologna Centro', 44.49488300, 11.34261700, 3, 15.00, 'Viaggio in autostrada, auto nuova con aria condizionata. Preferisco non fumatori.'),
(5, 4, 'Ravenna', 44.41818300, 12.20350700, 2, 12.00, 'Parto dalla Romagna, passiamo vicino a Cesena e Forlì se serve.'),
(1, 5, 'Bergamo', 45.69826100, 9.67727400, 3, 10.00, 'Dalla provincia di Bergamo verso San Siro. Parto 2 ore prima.'),
(2, 6, 'Modena', 44.64601200, 10.92522700, 2, 8.00, 'Da Modena verso Bologna. Ritorno disponibile.'),
(3, 7, 'Asti', 44.89997000, 8.20635000, 3, 7.00, 'Dalla zona di Asti verso Torino centro. Auto spaziosa.'),
(4, 8, 'Monza', 45.58400700, 9.27350700, 2, 6.00, 'Da Monza centro verso Milano. Viaggio breve e conveniente.');

-- Richieste passaggi di esempio (alcuni con recensioni)
INSERT INTO ride_requests (user_id, offer_id, driver_id, stato, stelle, recensione_testo) VALUES
(4, 1, 1, 'concluso', 5, 'Esperienza fantastica! Marco è stato puntualissimo e molto cordiale. La conversazione durante il viaggio ha reso tutto più piacevole. Consigliatissimo!'),
(5, 1, 1, 'concluso', 5, 'Ottimo accompagnatore, viaggio confortevole e musica perfetta durante il tragitto. Tornerò a viaggiare con lui sicuramente!'),
(3, 2, 2, 'concluso', 4, 'Tutto ok, puntuale e disponibile. Auto pulita. Unico neo: un po\' di ritardo al ritorno ma giustificato dal traffico.'),
(1, 3, 3, 'accettato', NULL, NULL),
(2, 3, 3, 'accettato', NULL, NULL),
(4, 4, 4, 'in_attesa', NULL, NULL),
(5, 5, 5, 'accettato', NULL, NULL),
(1, 6, 1, 'concluso', 5, 'Viaggio perfetto, persona affidabile al 100%. Ha aspettato anche se eravamo leggermente in ritardo.'),
(3, 7, 2, 'rifiutato', NULL, NULL),
(2, 8, 3, 'in_attesa', NULL, NULL);

-- Messaggi di chat di esempio
INSERT INTO chat_messages (request_id, sender_id, receiver_id, messaggio, letto) VALUES
(1, 4, 1, 'Ciao! Confermi la partenza per le 19:30?', TRUE),
(1, 1, 4, 'Sì confermo! Ci troviamo in Piazza Duomo?', TRUE),
(1, 4, 1, 'Perfetto! A dopo allora 😊', TRUE),
(4, 1, 3, 'Ciao Giuseppe, ho visto la tua offerta per il festival. Ci sono ancora posti?', TRUE),
(4, 3, 1, 'Ciao! Sì, ho ancora 2 posti liberi. Sei interessato?', TRUE),
(4, 1, 3, 'Perfetto! Prenoto subito. A che ora parti esattamente?', FALSE),
(5, 2, 3, 'Hey! Hai spazio anche per uno zaino grande? Porto la tenda per il festival', TRUE),
(5, 3, 2, 'Certo, il van è grande! Nessun problema 👍', TRUE),
(7, 5, 1, 'Grazie mille per il passaggio! Ci vediamo sabato!', TRUE),
(7, 1, 5, 'Figurati! A presto', TRUE);

-- =====================================================
-- QUERY UTILI PER AMMINISTRAZIONE
-- =====================================================

-- Visualizza statistiche utenti
-- SELECT 
--     u.id,
--     u.nome,
--     u.cognome,
--     COUNT(DISTINCT ro.id) as passaggi_offerti,
--     COUNT(DISTINCT rr.id) as richieste_fatte,
--     AVG(rr2.stelle) as media_recensioni,
--     COUNT(rr2.id) as num_recensioni
-- FROM users u
-- LEFT JOIN ride_offers ro ON u.id = ro.user_id
-- LEFT JOIN ride_requests rr ON u.id = rr.user_id
-- LEFT JOIN ride_requests rr2 ON u.id = rr2.driver_id AND rr2.stelle IS NOT NULL
-- GROUP BY u.id;

-- Eventi più popolari
-- SELECT 
--     e.*,
--     COUNT(DISTINCT ro.id) as num_offerte,
--     SUM(ro.posti_disponibili) as posti_totali
-- FROM events e
-- LEFT JOIN ride_offers ro ON e.id = ro.event_id
-- GROUP BY e.id
-- ORDER BY num_offerte DESC;

-- Cleanup messaggi vecchi (opzionale)
-- DELETE FROM chat_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH);