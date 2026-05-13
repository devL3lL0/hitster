# 🎵 Hitster Camp

> Quiz musicale live per campo estivo — selezione canzoni intelligente per fascia d'età, real-time via WebSocket.

**Stack:** Flask · Flask-SocketIO · Spotify API · TOTP 2FA · Render

---

## 📖 Indice

- [Avvio in locale](#-avvio-in-locale)
- [Come usarlo](#-come-usarlo)
- [Deploy su Render (step by step)](#-deploy-su-render-step-by-step)
- [Variabili d'ambiente](#%EF%B8%8F-variabili-dambiente)
- [Struttura progetto](#-struttura-progetto)
- [Stack tecnico](#-stack-tecnico)
- [Regole del gioco](#-regole-del-gioco)
- [Changelog](#-changelog)

---

## ▶️ Avvio in locale

```bash
# 1. Clona il repository
git clone https://github.com/TUO_USERNAME/hitster-camp.git
cd hitster-camp

# 2. (Opzionale) Crea un ambiente virtuale
python -m venv venv
venv\Scripts\activate        # Windows
# source venv/bin/activate   # macOS/Linux

# 3. Installa le dipendenze
pip install -r requirements.txt

# 4. Avvia il server
python app.py
```

Apri il browser su → **http://localhost:5000**

---

## 📱 Come usarlo

### 1. Master (coordinatore)
- Vai su `http://localhost:5000`
- Seleziona la **fascia d'età** del gruppo
- Clicca **Crea partita** — il sistema scarica automaticamente le canzoni più adatte
- Aggiungi le squadre e condividi il **codice sessione** agli animatori

### 2. Animatori Stand
- Vai su `http://localhost:5000` (o ricevi il link diretto)
- Inserisci il codice sessione
- Scegli il tuo **stand** (1–6) e le due squadre che si sfidano
- Usa **Prossima canzone** per pescare dal mazzo
- Assegna punti con i bottoni (+1, +3, -1)

### 3. Admin Panel
- Vai su `/admin` e autenticati con TOTP 2FA (Google Authenticator)
- Configura le credenziali Spotify, le fasce d'età e le descrizioni degli stand

---

## 🚀 Deploy su Render (step by step)

### Prerequisiti
- Account [GitHub](https://github.com) con il codice pushato su un repository
- Account [Render](https://render.com) (gratuito)
- Credenziali Spotify API ([vedi sotto](#spotify-api))

---

### Step 1 — Prepara il repository GitHub

```bash
# Se non hai ancora un repo remoto:
git init
git add .
git commit -m "first commit"
git branch -M main
git remote add origin https://github.com/TUO_USERNAME/hitster-camp.git
git push -u origin main
```

> Se hai già un repo, assicurati che `app.py`, `requirements.txt`, `Procfile` e `render.yaml` siano tutti committati e pushati.

---

### Step 2 — Ottieni le credenziali Spotify API

1. Vai su [developer.spotify.com/dashboard](https://developer.spotify.com/dashboard)
2. Clicca **Create App**
3. Compila:
   - **App name:** Hitster Camp
   - **App description:** Quiz musicale
   - **Redirect URI:** `http://localhost` (non serve realmente, ma è obbligatorio)
   - **API/SDKs:** spunta *Web API*
4. Salva e vai in **Settings** → copia **Client ID** e **Client Secret**

---

### Step 3 — Crea il Web Service su Render

1. Accedi a [dashboard.render.com](https://dashboard.render.com)
2. Clicca **New +** → **Web Service**
3. Seleziona **Build and deploy from a Git repository** → clicca **Next**
4. Autorizza Render ad accedere al tuo GitHub e seleziona il repository `hitster-camp`
5. Clicca **Connect**

---

### Step 4 — Configura il Web Service

Nella pagina di configurazione imposta:

| Campo | Valore |
|---|---|
| **Name** | `hitster-camp` (o come preferisci) |
| **Region** | Frankfurt (EU Central) — più vicino all'Italia |
| **Branch** | `main` |
| **Runtime** | `Python 3` |
| **Build Command** | `pip install -r requirements.txt` |
| **Start Command** | `gunicorn --worker-class eventlet -w 1 app:app` |
| **Instance Type** | Free (o Starter per più stabilità) |

> ⚠️ **Importante:** WebSocket richiede `eventlet` e **un solo worker** (`-w 1`). Non aumentare il numero di worker.

---

### Step 5 — Aggiungi le variabili d'ambiente

Vai nella sezione **Environment** del servizio e aggiungi:

| Variabile | Valore | Note |
|---|---|---|
| `SPOTIPY_CLIENT_ID` | Il tuo Client ID Spotify | Obbligatorio |
| `SPOTIPY_CLIENT_SECRET` | Il tuo Client Secret Spotify | Obbligatorio |
| `SECRET_KEY` | Una stringa casuale lunga | Generata auto da `render.yaml` |

> 💡 In alternativa le credenziali Spotify possono essere inserite anche dal pannello Admin dell'app dopo il deploy.

---

### Step 6 — Avvia il deploy

1. Clicca **Create Web Service**
2. Render esegue automaticamente il build (`pip install -r requirements.txt`)
3. Aspetta che lo stato diventi **Live** (1–3 minuti)
4. L'app sarà disponibile su `https://hitster-camp.onrender.com` (o il nome che hai scelto)

---

### Step 7 — Configurazione iniziale (primo avvio)

1. Vai su `https://hitster-camp.onrender.com/admin`
2. Completa il setup **2FA**:
   - Clicca "Configura 2FA"
   - Scansiona il QR code con **Google Authenticator** o **Authy**
   - Inserisci il codice a 6 cifre per verificare
3. Accedi al pannello admin e inserisci le credenziali Spotify (se non le hai messe come env var)
4. Verifica le fasce d'età e gli stand → **Salva**

---

### Step 8 — Aggiornamenti futuri

Per aggiornare l'app dopo modifiche al codice:

```bash
git add .
git commit -m "descrizione delle modifiche"
git push origin main
```

Render rileva automaticamente il push e avvia un **re-deploy** senza downtime.

---

### 🔧 Risoluzione problemi comuni

| Problema | Causa | Soluzione |
|---|---|---|
| App non si avvia | Worker multipli con SocketIO | Usa `gunicorn -w 1` |
| WebSocket disconnesso | Eventlet mancante | Verifica `eventlet` in `requirements.txt` |
| Nessuna canzone trovata | Credenziali Spotify mancanti o errate | Controlla le env var |
| 2FA non funziona | Orologio di sistema non sincronizzato | Sincronizza l'orario sul tuo dispositivo |
| App in "sleep" (piano Free) | Render mette in standby dopo 15 min di inattività | Aggiorna al piano Starter o usa un ping service |

---

## ⚙️ Variabili d'ambiente

| Variabile | Descrizione | Obbligatorio |
|---|---|---|
| `SPOTIPY_CLIENT_ID` | Client ID dell'app Spotify | Sì (o via admin panel) |
| `SPOTIPY_CLIENT_SECRET` | Client Secret dell'app Spotify | Sì (o via admin panel) |
| `SECRET_KEY` | Chiave segreta Flask per le sessioni | Sì (auto da render.yaml) |

---

## 📁 Struttura progetto

```
hitster-camp/
├── app.py              ← Backend Flask + SocketIO + logica canzoni
├── requirements.txt    ← Dipendenze Python
├── Procfile            ← Comando di avvio per Render/Heroku
├── render.yaml         ← Config automatica Render
├── config.json         ← Configurazione persistente (generato al primo avvio)
├── CHANGELOG.md        ← Storico delle modifiche
├── README.md
└── templates/
    ├── base.html       ← Layout + stili condivisi
    ├── home.html       ← Pagina principale
    ├── admin.html      ← Pannello Admin
    ├── master.html     ← Pannello Master (coordinatore)
    └── stand.html      ← Pannello Animatore
```

---

## ⚙️ Stack tecnico

| Layer | Tecnologia |
|---|---|
| Backend | Flask + Flask-SocketIO + Eventlet |
| Real-time | WebSocket via Socket.IO |
| Autenticazione | TOTP 2FA (pyotp) |
| Musica | Spotify Web API (Spotipy) |
| Frontend | HTML + CSS + JS vanilla |
| Storage | In memoria (nessun database) |
| Deploy | Render (gunicorn + eventlet) |

---

## 🎮 Regole del gioco

- I ragazzi sono divisi in **squadre**
- Il gioco si svolge su **6 stand** contemporanei, ognuno con un round diverso:
  - 🎵 **Timeline** — Posiziona la canzone nella timeline cronologica
  - 🕺 **Decadi** — Corri nell'area della decade giusta quando la musica si ferma
  - 🎤 **Canta Tu** — Continua a cantare dopo lo stop improvviso
  - 🧩 **Indizi** — Indovina senza ascoltare, solo con gli indizi
  - 🏃 **Staffetta Mimica** — Fai indovinare mimando (no parole)
  - 🔥 **Finale Epico** — Duello diretto tra due squadre
- La **classifica è visibile in tempo reale** su tutti i dispositivi connessi

### Sistema punti
| Evento | Punti |
|---|---|
| Risposta corretta | +1 |
| Bonus (anno esatto / 1° colpo / meno di 15s) | +3 |
| Risposta sbagliata | -1 |

---

## 📋 Changelog

Vedi [CHANGELOG.md](./CHANGELOG.md) per lo storico completo delle modifiche.
