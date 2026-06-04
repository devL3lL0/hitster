# 🎵 Hitster Camp

> **Quiz musicale live per campi estivi.** Un'app web multi-stand che usa l'API Spotify per generare sessioni di gioco musicale in tempo reale, pensata per animatori di camping e campi estivi.

---

## 📖 Cos'è Hitster Camp?

Hitster Camp è una web app **PHP + MySQL** che replica e potenzia il gioco da tavolo Hitster, adattandolo per l'uso in ambienti multi-stand (es. campi estivi con squadre che ruotano tra diverse postazioni). Ogni sessione di gioco genera automaticamente una playlist di canzoni italiane da Spotify, calibrata sulla fascia d'età dei partecipanti.

---

## 🏗️ Architettura

```
hitster-camp-php/
├── index.php              # Homepage: crea partita o unisciti con codice
├── master.php             # Dashboard del "master": classifica, stato stand, link animatori
├── stand.php              # Pannello dell'animatore: gioco, pesca canzone, punteggi
├── admin.php              # Pannello admin: configurazione Spotify, fasce d'età, 2FA
├── api.php                # API REST JSON (tutte le azioni di gioco)
├── health.php             # Endpoint di health-check
├── test_spotify.php       # Script di diagnostica connessione Spotify (debug)
├── setup.sql              # Script SQL per creare le tabelle
├── manifest.json          # Manifesto PWA (installabile come app)
├── sw.js                  # Service Worker (cache offline base)
├── robots.txt             # Regole crawler (delay per Bing/MSN)
├── .htaccess              # Apache: blocco /includes/, URL rewrite /master/CODE, /stand/CODE
├── _private/
│   ├── db_config.php      # Credenziali database (fuori dalla webroot)
│   └── .htaccess          # Blocca accesso diretto alla cartella
├── includes/
│   ├── db.php             # Singleton PDO (connessione MySQL)
│   ├── config.php         # Caricamento/salvataggio config da DB, stand e fasce d'età
│   ├── session_store.php  # CRUD sessioni di gioco su DB
│   ├── spotify.php        # Integrazione Spotify API (token, discovery dinamica, scoring)
│   └── totp.php           # Implementazione TOTP RFC 6238 (2FA admin)
└── assets/
    ├── css/style.css      # Design system dark mode (CSS puro)
    ├── js/base.js         # JS condiviso: toast, modal, PollingEngine, TOTP
    └── img/icon.png       # Icona app
```

### URL Rewrite (`.htaccess`)

Il server espone URL pulite tramite `mod_rewrite`:
- `/master/ABC123` → `master.php?code=ABC123`
- `/stand/ABC123` → `stand.php?code=ABC123`
- L'accesso diretto a `/includes/` è bloccato con `403 Forbidden`

---

## 🎮 Come Funziona

### Flusso di Gioco

1. **Il Master** apre `index.php`, sceglie la fascia d'età e crea una partita → riceve un **codice a 6 caratteri** (es. `ABC123`) e viene reindirizzato al suo pannello (`master.php`).
2. **Gli Animatori** aprono `index.php`, inseriscono il codice e vengono reindirizzati al loro pannello stand (`stand.php`).
3. Ogni animatore sceglie il suo **stand** (postazione di gioco) e le due **squadre** che si sfidano.
4. L'animatore pesca canzoni con il pulsante "Pesca Nuova" e usa il player Spotify integrato per farle ascoltare.
5. I punti vengono assegnati manualmente dall'animatore (+1 normale, +3 bonus).
6. Il master vede in tempo reale la classifica e lo stato di tutti gli stand.

### I 6 Stand (Modalità di Gioco)

| Stand | Nome | Meccanica |
|-------|------|-----------|
| 1 | 🎵 Round Classico (Timeline) | Posiziona la canzone nella timeline |
| 2 | 🕺 Round Movimento (Decadi) | Corri nell'area della decade giusta |
| 3 | 🎤 Round Canta Tu (Stop) | Continua a cantare dopo lo stop |
| 4 | 🧩 Round Indizi (Quiz) | Indovina con indizi senza sentire la musica |
| 5 | 🏃 Staffetta Mimica | Mimica o canticchia senza parole |
| 6 | 🔥 Finale Epico (Duello) | Scontro diretto, chi tocca prima risponde |

---

## 🚀 Installazione

### Requisiti

- PHP 8.x con estensioni: `pdo_mysql`, `curl`
- MySQL 5.7+ / MariaDB
- Web server Apache (con `mod_rewrite`) o Nginx

### Passaggi

1. **Carica i file** sul server (es. in `/var/www/html/hitster-camp/`)

2. **Crea il database** ed esegui lo script SQL:
   ```sql
   CREATE DATABASE hitster_camp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   USE hitster_camp;
   SOURCE setup.sql;
   ```

3. **Configura le credenziali DB** in `_private/db_config.php`:
   ```php
   <?php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'hitster_camp');
   define('DB_USER', 'tuo_utente');
   define('DB_PASS', 'tua_password');
   ```

4. **Crea un'app Spotify** su [Spotify Developer Dashboard](https://developer.spotify.com/dashboard) e prendi `Client ID` e `Client Secret`.

5. **Accedi al pannello admin** tramite il pulsante "⚙️ Admin Access" nella homepage:
   - Al primo accesso, inserisci la password master (`hitster-admin` di default)
   - Scansiona il QR con Google Authenticator per configurare il 2FA
   - Inserisci le credenziali Spotify nel pannello admin

6. **Installa come PWA** (opzionale): usa il pulsante "📱 Guida Installazione" sulla homepage per aggiungere l'app alla schermata home del dispositivo.

7. **Verifica la connessione Spotify** visitando `test_spotify.php` dal browser — mostra le playlist scoperte per ogni fascia d'età e i brani trovati.

---

## 🔌 API Endpoints

Tutte le chiamate passano per `api.php` (POST con body JSON o GET con query string).

| Action | Metodo | Descrizione |
|--------|--------|-------------|
| `create` | POST | Crea nuova sessione di gioco |
| `session` | GET | Legge lo stato di una sessione |
| `next_song` | POST | Pesca la prossima canzone per uno stand |
| `confirm_song` | POST | Aggiunge la canzone alla timeline (indovinata) |
| `add_team` | POST | Iscrive una nuova squadra |
| `remove_team` | POST | Rimuove una squadra |
| `set_stand` | POST | Assegna le squadre a uno stand |
| `add_points` | POST | Aggiunge/rimuove punti a una squadra |
| `reset_scores` | POST | Azzera tutti i punteggi |
| `delete` | POST | Termina e cancella la sessione |
| `admin_check` | GET | Controlla stato autenticazione admin |
| `admin_setup` | POST | Genera il secret TOTP (primo setup) |
| `admin_verify` | POST | Verifica il codice TOTP e crea la sessione admin |
| `admin_logout` | GET | Distrugge la sessione admin e reindirizza alla homepage |
| `reset_2fa` | POST | Azzera il secret TOTP (richiede re-setup) |

---

## 🎧 Integrazione Spotify

Il modulo `includes/spotify.php` gestisce tutta la logica musicale:

### Scoperta Dinamica (dalla v1.3.0)

Niente più playlist ID o artisti hardcoded. Il sistema usa **Spotify Search API** per scoprire automaticamente il contenuto rilevante:

1. **`build_age_profile()`** costruisce termini di ricerca a partire da `CURRENT_YEAR` (es. `"top 50 italia"`, `"rap italiano 2025"`, ecc.)
2. **`discover_playlists_for_profile()`** cerca su Spotify le playlist italiane che matchano quei termini → ~12-18 playlist uniche per sessione
3. I brani vengono raccolti da tutte le playlist scoperte + ricerche dirette di tracce
4. **Rilevamento artisti italiani automatico**: un artista è considerato "italiano" se appare 2+ volte nelle playlist italiane trovate — nessuna lista da aggiornare manualmente

### Scoring

Ogni brano riceve un punteggio composito:

| Fattore | Punti |
|---------|-------|
| Popolarità ≥ 80 | +4 |
| Popolarità ≥ 70 | +3 |
| Popolarità ≥ 60 | +2 |
| Popolarità ≥ 50 | +1 |
| Uscito nell'anno corrente | +4 |
| Uscito 2 anni fa | +3 |
| Uscito 3 anni fa | +2 |
| Uscito 4-5 anni fa | +1 |
| Artista italiano (dinamico) | +2 |
| Contenuto esplicito (fasce giovani) | -3 |
| Playlist di alta qualità (bonus sorgente) | +1/+2/+3 |

### Cache

- TTL: **30 minuti** per fascia d'età, salvato nella tabella `hitster_song_cache`
- Alla scadenza, il fetch completo (~20-28 chiamate API) viene rieseguito in background
- La cache viene invalidata automaticamente salvando una versione vuota su errore

---

## 🔐 Sicurezza

- **Accesso Admin**: Protetto da TOTP (Google Authenticator) con implementazione RFC 6238 nativa in PHP
- **`_private/`**: Cartella con credenziali DB bloccata da `.htaccess` (deny all)
- **Sessioni admin**: Tramite `$_SESSION['is_admin']` lato server
- **Sessioni di gioco**: Garbage collection automatica dopo 24 ore (`custom_session_gc()`)
- **XSS**: Tutti gli output HTML usano `htmlspecialchars()` / funzione `esc()` in JS
- **SQL Injection**: Tutte le query usano prepared statements PDO

---

## 🔄 Aggiornamenti in Tempo Reale

Il sistema usa un **polling engine** (`PollingEngine` in `base.js`) invece di WebSocket:
- Intervallo: ogni **2,5 secondi**
- Ottimizzato: l'update della UI avviene solo se il campo `updated_at` è cambiato
- Gestisce automaticamente il caso di sessione terminata (redirect a `index.php`)

---

## ⚙️ Pannello Admin

Accessibile solo dopo autenticazione 2FA. Permette di:
- Modificare la **password master**
- Inserire/aggiornare le credenziali **Spotify API**
- Aggiungere/modificare le **fasce d'età** (label, età minima, età massima)
- Resettare il **2FA** (es. cambio dispositivo)

---

## 🩺 Health Check

Endpoint pubblico `health.php` che restituisce:
```json
{
  "status": "ok",
  "uptime_seconds": -1,
  "active_sessions": 3
}
```

---

## 📦 Dipendenze Esterne (CDN)

| Libreria | Versione | Uso |
|----------|----------|-----|
| [SweetAlert2](https://sweetalert2.github.io/) | v11 | Dialoghi di conferma e input |
| [qrcodejs](https://github.com/davidshimjs/qrcodejs) | 1.0.0 | Generazione QR per setup 2FA |
| [Google Fonts - Outfit](https://fonts.google.com/specimen/Outfit) | — | Tipografia |

---

## 📱 PWA (Progressive Web App)

L'app è configurata per essere installata come app standalone:
- `manifest.json`: nome, icone, colori, modalità `standalone`
- `sw.js`: Service Worker per cache offline basilare
- Meta tag Apple per compatibilità iOS (status bar translucente, touch icon)

---

# 📋 Changelog

## [v1.3.0] – 2026-06-04

> Refactor completo del modulo Spotify: da lista hardcoded a scoperta dinamica.

### Modificato

- **`includes/spotify.php`** – Refactor architetturale completo:
  - **Rimossa** costante `ITALIAN_ARTISTS` (~80 artisti hardcoded)
  - **Rimossa** costante `PLAYLIST_CATALOG` (6 ID playlist fissi)
  - **Rimossa** funzione `is_likely_italian()` (dipendeva dalla lista hardcoded)
  - **Aggiunta** `discover_playlists_for_profile()`: cerca playlist italiane su Spotify in tempo reale tramite Search API, restituisce una mappa `playlist_id => source_bonus`
  - **Riscritta** `build_age_profile()`: tutte le query sono ora costruite dinamicamente da `CURRENT_YEAR` — nessun anno hardcoded, si aggiorna automaticamente ogni anno
  - **Riscritta** `fetch_songs_smart()`: flusso in due fasi — (1) raccolta brani e conteggio frequenza artisti, (2) scoring finale con set italiano determinato dinamicamente (artisti con 2+ apparizioni nelle playlist italiane scoperte)
  - **Modificata** `score_track()`: ora riceve `array $italian_set = []` come parametro invece di chiamare `is_likely_italian()`
  - **Invariate**: `spotify_get_token()`, `spotify_request()`, `get_cached_songs()`
- **`test_spotify.php`** – Aggiornato per testare il nuovo sistema: mostra le playlist scoperte (nome, followers, bonus) per ogni fascia d'età e i top 10 brani trovati

### Stima impatto

- ~20-28 chiamate API per fetch completo (vs ~14 precedenti), tutte cachate 30 min
- Le playlist si aggiornano automaticamente a ogni refresh della cache
- Gli artisti "italiani" vengono riconosciuti senza manutenzione manuale

---

## [v1.2.0] – 2026-06-04 (Versione Server)

> Aggiornamenti applicati direttamente sul server di produzione rispetto alla v1.1.0 locale.

### Aggiunto

- **`test_spotify.php`**: script di diagnostica per verificare la connettività Spotify — testa il token sia con SSL verification attiva che disabilitata, e stampa la risposta HTTP in plain text. Utile per debug su hosting condivisi
- **`robots.txt`**: aggiunto file con regole `Crawl-delay: 5` per MSNBot e Bingbot, per limitare la frequenza di scansione dei crawler Microsoft
- **`.htaccess` — URL rewrite rules**: aggiunte due regole per URL pulite: `/master/CODE` → `master.php?code=CODE` e `/stand/CODE` → `stand.php?code=CODE`. Aggiunto anche blocco `403` per accesso diretto a `/includes/`

### Modificato

- **`includes/spotify.php`**: aggiunto `CURLOPT_SSL_VERIFYPEER => false` su entrambe le funzioni `spotify_get_token()` e `spotify_request()` per evitare errori di certificati SSL tipici degli hosting condivisi
- **`includes/spotify.php` — `get_cached_songs()`**: aggiunto controllo `!empty($songs)` prima di scrivere in cache (evita di salvare array vuoti in caso di errore Spotify) e prima di fare shuffle sul risultato
- **`includes/session_store.php`**: rinominata `session_gc()` in `custom_session_gc()` per evitare conflitti con la funzione nativa PHP `session_gc()`
- **`api.php`**: aggiornata la chiamata da `session_gc()` a `custom_session_gc()`. Aggiunto controllo esplicito su `empty($songs)` nell'action `create` con risposta JSON di errore dettagliata invece di procedere silenziosamente con coda vuota
- **`index.php`**: l'alert di errore nella form di creazione usa ora `json.message` (se presente) al posto del generico "Errore durante la creazione."
- **`api.php` — `admin_logout`**: usa `unset($_SESSION['is_admin'])` invece di `session_destroy()` — approccio più corretto che preserva la sessione PHP rimuovendo solo il flag admin

### Note di Sicurezza

> [!WARNING]
> `CURLOPT_SSL_VERIFYPEER => false` disabilita la verifica del certificato SSL nelle chiamate a Spotify. Necessario su hosting condivisi con bundle CA mancante o obsoleto, ma idealmente da risolvere aggiornando il bundle `cacert.pem` sul server.

---

## [v1.1.0] – 2026-06-04

### Corretto

- **`manifest.json`**: percorso icona corretto da `/static/icon.png` a `assets/img/icon.png` (residuo del porting da Python/FastAPI). Aggiornati anche `start_url` (`./index.php`), `background_color` e `theme_color` per coerenza con il design system
- **`sw.js`**: percorsi asset corretti da `/static/...` ai percorsi reali (`assets/img/icon.png`, `assets/css/style.css`, `assets/js/base.js`). Aggiunto listener `activate` per pulizia automatica delle cache obsolete. Versione cache bumped a `v2`
- **`api.php`**: aggiunta action `admin_logout` mancante — il pulsante Logout in `admin.php` puntava a `api.php?action=admin_logout` ma il case non esisteva, lasciando la sessione admin attiva. Ora distrugge correttamente la sessione e reindirizza a `index.php`
- **`includes/config.php`**: rimossa la sovrascrittura forzata di `stands_info` con i valori di default ad ogni caricamento. La configurazione degli stand salvata sul DB viene ora rispettata; i valori di default si applicano solo al primo avvio o se `stands_info` è mancante (compatibilità con installazioni precedenti)

---

## [v1.0.0] – 2026-06-04

> Versione iniziale – Porting completo da Python/FastAPI a PHP puro + MySQL

### Aggiunto

- **Homepage** (`index.php`): selezione fascia d'età, creazione partita, join con codice
- **Master Dashboard** (`master.php`): classifica live, stato stand, link per animatori
- **Stand Panel** (`stand.php`): configurazione stand, player Spotify, timeline, punteggi
- **Pannello Admin** (`admin.php`): configurazione Spotify, fasce d'età, gestione 2FA
- **API REST** (`api.php`): 15 endpoint per tutte le operazioni di gioco
- **Integrazione Spotify**: token OAuth2, fetch intelligente per fascia d'età, scoring, cache DB
- **TOTP 2FA** (`includes/totp.php`): implementazione RFC 6238 nativa, compatibile con Google Authenticator
- **Polling Engine** (`base.js`): aggiornamenti in tempo reale ogni 2,5s senza WebSocket
- **Macchina degli Indizi** (stand 4): generatore di indizi contestuali sul titolo/artista/anno
- **Visualizzatore Timeline** (stand 1): mostra le canzoni indovinate in ordine cronologico con suggerimento posizione
- **Design System** (`style.css`): dark mode ispirata a iOS, glassmorphism, palette coerente
- **PWA**: manifest + service worker per installazione su mobile
- **Health Check** (`health.php`): endpoint per monitoraggio sessioni attive
- **Garbage Collection**: pulizia automatica sessioni scadute (> 24h)
- **Guida Installazione**: modal contestuale per iOS e Android
- **Sicurezza `_private/`**: credenziali DB isolate fuori dalla webroot

---

*Progetto sviluppato per uso interno nei campi estivi. © 2026 Hitster Camp.*
