# 📋 Changelog — Hitster Camp

Tutte le modifiche significative al progetto sono documentate qui.  
Formato basato su [Keep a Changelog](https://keepachangelog.com/it/1.0.0/).

---

## [1.2.2] — 2026-05-13

### ✨ Aggiunto
- **Endpoint `/health`** per monitoraggio keepalive (UptimeRobot / cron ping)
  - Risponde sempre `200 OK` con JSON: `{ status, uptime_seconds, active_sessions }`
  - Non richiede autenticazione — sicuro da esporre pubblicamente
  - Costante `APP_START_TIME` aggiunta per calcolo uptime dal riavvio del processo

---

## [1.2.1] — 2026-05-13

### 🐛 Fix
- **Canzoni duplicate nella stessa sessione** — Una canzone già mostrata poteva riapparire in questi casi:
  1. La coda si svuotava e le canzoni venivano riciclate (*reshuffle* da `used_songs`)
  2. Una canzone in mostra su uno stand veniva comunque estratta per un altro stand
- Introdotto `used_ids` (set Python) aggiunto all'oggetto sessione: tiene traccia di tutti gli ID già mostrati e **non li ripropone mai** per tutta la durata della sessione
- `next_song` ora scorre la coda saltando i brani con ID già in `used_ids` (incluse le canzoni attualmente visualizzate su qualsiasi stand)
- Rimosso il meccanismo di riciclo del mazzo (`used_songs → songs_queue`): quando tutte le canzoni sono esaurite, l'API risponde con messaggio esplicito

---

## [1.2.0] — 2026-05-13

### ✨ Aggiunto
- **Sistema di selezione canzoni intelligente (`fetch_songs_smart`)**  
  Sostituisce completamente la vecchia funzione `fetch_songs_auto` con una logica multi-layer:
  - Lettura diretta delle **playlist Spotify italiane ufficiali**: Top 50 Italia, Viral 50 Italia, TikTok Italia, Hits d'Italia, Rap Italiano, Indie Italia, Canzoni italiane
  - Integrazione automatica con i **trend TikTok/social** tramite la playlist "Viral 50 Italia" (aggiornata settimanalmente da Spotify)
  - Query mirate per anno e genere come sorgente secondaria di copertura

- **`build_age_profile(min_age, max_age)`**  
  Restituisce la strategia di ricerca ottimale per fascia d'età. Ogni fascia ha: range anni musicali corretto, soglia popolarità, playlist da usare, query mirate, policy explicit.

  | Fascia | Anni musica | Playlist principali | Explicit |
  |--------|-------------|---------------------|----------|
  | 8–11   | 2018–oggi   | Top50 IT, Hits IT   | Escluso  |
  | 12–14  | 2019–oggi   | Top50 IT, Viral50, TikTok IT | Escluso |
  | 14–17  | 2019–oggi   | Top50 IT, Viral50, TikTok IT, Rap IT | Penalizzato |
  | 17–22  | 2015–oggi   | Top50 IT, Viral50, Rap IT, Indie IT | Penalizzato |
  | 20+    | 2005–oggi   | Top50 IT, Hits IT, Indie IT | Consentito |

- **`score_track(track, profile, source_bonus)`**  
  Sistema di scoring multi-fattore per ogni brano:
  - Popolarità Spotify (max +4 per pop ≥ 80)
  - Recency: brani recenti premiati (max +4 per uscita nell'ultimo anno)
  - Bonus artista italiano (+2)
  - Penalità explicit (-3 per fasce 14+)
  - Source bonus: Viral50/TikTok (+3), Top50/Hits (+2), query (+1)

- **`is_likely_italian(track)`**  
  Rilevamento automatico artisti italiani tramite un set di ~80 nomi noti (classici e contemporanei: Blanco, Lazza, Annalisa, Mahmood, Vasco Rossi, Fedez, ecc.)

- **`ITALIAN_ARTISTS`** — Set centralizzato di artisti italiani per il bonus nazionalità

- **`PLAYLIST_CATALOG`** — Dizionario con ID e bonus delle 7 playlist Spotify IT ufficiali

- Ordinamento finale con **shuffle per fasce di score** (evita liste monotone mantenendo la rilevanza)

### 🔧 Modificato
- `create_session`: aggiornata la chiamata da `fetch_songs_auto` a `fetch_songs_smart`
- Il pool di canzoni passa da 250 a **300 brani** per maggiore varietà

### 🗑️ Rimosso
- `fetch_songs_auto`: rimossa la logica errata basata su `CURRENT_YEAR - max_age` come range anni (che per un 14enne cercava canzoni del 2010!)
- Query Spotify generiche `year:X-Y summer/hits/radio` sostituite da sorgenti più precise

### 🐛 Fix
- La fascia 14-16 anni ora riceve canzoni degli ultimi 5 anni (2019-oggi) invece di brani del periodo 2010-2026 che i ragazzi non conoscono

---

## [1.1.0] — 2026-05-07

### ✨ Aggiunto
- Autenticazione admin tramite **TOTP 2FA** (Google Authenticator / Authy)
- Pannello admin con configurazione Spotify, fasce d'età e stand
- API `/api/admin/reset_2fa` per reset del segreto 2FA
- Sessione admin persistente via Flask session

### 🔧 Modificato
- Le fasce d'età sono ora configurabili dall'admin panel (non più hardcoded)
- Le descrizioni degli stand sono aggiornabili dall'admin

---

## [1.0.0] — 2026-05 (rilascio iniziale)

### ✨ Aggiunto
- App Flask + Flask-SocketIO per quiz musicale live in campo estivo
- Creazione sessione con codice univoco a 6 caratteri
- Gestione squadre in tempo reale (aggiunta, rimozione, punti)
- 6 stand di gioco con round differenti (Timeline, Movimento, Canta Tu, Indizi, Mimica, Finale)
- Ricerca canzoni Spotify tramite API (Spotipy)
- Filtro anti-explicit e soglia popolarità minima
- Sincronizzazione real-time via WebSocket tra master e animatori
- Pannello Master e pannello Animatore separati
- Deploy su Render con `gunicorn + eventlet`
- File `render.yaml` per deploy automatico
