"""
Hitster Camp - Quiz musicale live per campo estivo
Backend: Flask + Flask-SocketIO + Spotify + TOTP 2FA
"""

from flask import Flask, render_template, request, jsonify, redirect, url_for, session as flask_session
from flask_socketio import SocketIO, join_room, leave_room, emit
import random
import string
import time
import json
import os
import datetime
import spotipy
import pyotp
from spotipy.oauth2 import SpotifyClientCredentials

app = Flask(__name__)
app.config['SECRET_KEY'] = 'hitster-camp-secret-2024'

socketio = SocketIO(app, cors_allowed_origins="*", async_mode='threading')

CONFIG_PATH = 'config.json'
CURRENT_YEAR = datetime.datetime.now().year

def load_config():
    # NUOVO SET DI ISTRUZIONI DETTAGLIATE
    default_stands = [
        {
            "id": "1", 
            "name": "🎵 ROUND CLASSICO (Timeline)", 
            "desc": "🎯 COME FUNZIONA: Fai ascoltare la canzone. La squadra deve posizionarla correttamente nella timeline (PRIMA o DOPO le canzoni già indovinate).\n✅ PUNTI: +1 per la posizione corretta.\n⭐ BONUS: +3 se indovinano anche l'anno esatto (o titolo e artista al primo colpo)."
        },
        {
            "id": "2", 
            "name": "🕺 ROUND MOVIMENTO (Decadi)", 
            "desc": "🎯 COME FUNZIONA: Tutti ballano. Quando fermi la musica, devono correre nell'area della DECADE giusta (80s, 90s, 2000s...).\n✅ PUNTI: +1 alla squadra che arriva per prima nell'area corretta senza sbagliare.\n⭐ BONUS: +3 per il ballo più originale o coordinato."
        },
        {
            "id": "3", 
            "name": "🎤 ROUND CANTA TU (Stop)", 
            "desc": "🎯 COME FUNZIONA: Stoppa la musica all'improvviso. La squadra deve continuare a cantare le prossime 5-10 parole in modo esatto.\n✅ PUNTI: +1 se continuano correttamente.\n⭐ BONUS: +3 se sanno anche titolo e artista originale."
        },
        {
            "id": "4", 
            "name": "🧩 ROUND INDIZI (Quiz)", 
            "desc": "🎯 COME FUNZIONA: NON far sentire la musica. Dai 3 indizi (es: 'Hit estiva', 'Cantante bionda', 'Parla di una spiaggia'). Se non indovinano, fai sentire 2 secondi di intro.\n✅ PUNTI: +1 se indovinano dopo l'intro.\n⭐ BONUS: +3 se indovinano solo con gli indizi (senza audio)."
        },
        {
            "id": "5", 
            "name": "🏃 STAFFETTA MIMICA", 
            "desc": "🎯 COME FUNZIONA: Un giocatore corre da te, ascolta la canzone in cuffia e torna indietro. Deve farla indovinare mimando o canticchiando (senza parole).\n✅ PUNTI: +1 se indovinano entro 60 secondi.\n⭐ BONUS: +3 se indovinano in meno di 15 secondi."
        },
        {
            "id": "6", 
            "name": "🔥 FINALE EPICO (Duello)", 
            "desc": "🎯 COME FUNZIONA: Scontro diretto tra due squadre. Chi tocca prima un oggetto al centro ha diritto di risposta.\n✅ PUNTI: +1 per ogni risposta corretta al volo.\n⭐ BONUS: +3 per la canzone finale più difficile del mazzo."
        }
    ]
    
    default_age_groups = [
        {"id": "8-11", "label": "8-11 anni", "min_age": 8, "max_age": 11},
        {"id": "12-14", "label": "12-14 anni", "min_age": 12, "max_age": 14},
        {"id": "15-18", "label": "15-18 anni", "min_age": 15, "max_age": 18}
    ]

    if not os.path.exists(CONFIG_PATH):
        conf = {"admin_password": "hitster-admin", "age_groups": default_age_groups, "stands_info": default_stands, "spotify": { "client_id": "", "client_secret": "" }, "totp_secret": ""}
        save_config(conf)
        return conf
    
    with open(CONFIG_PATH, 'r', encoding='utf-8') as f:
        conf = json.load(f)
        # Aggiorno forzatamente gli stand per applicare le nuove descrizioni dettagliate
        conf["stands_info"] = default_stands
        return conf

def save_config(config):
    with open(CONFIG_PATH, 'w', encoding='utf-8') as f:
        json.dump(config, f, indent=2, ensure_ascii=False)

def get_spotify_client(config):
    client_id = os.environ.get('SPOTIPY_CLIENT_ID') or config['spotify'].get('client_id')
    client_secret = os.environ.get('SPOTIPY_CLIENT_SECRET') or config['spotify'].get('client_secret')
    if not client_id or not client_secret: return None
    try:
        auth_manager = SpotifyClientCredentials(client_id=client_id, client_secret=client_secret)
        return spotipy.Spotify(auth_manager=auth_manager)
    except: return None

# ─── Artisti italiani noti (per preferenza italiana) ──────────────────────────
ITALIAN_ARTISTS = {
    "tiziano ferro", "vasco rossi", "ligabue", "zucchero", "eros ramazzotti",
    "jovanotti", "lucio battisti", "fabrizio de andrè", "pino daniele",
    "claudio baglioni", "marco mengoni", "laura pausini", "elisa",
    "negramaro", "subsonica", "tiromancino", "zero assoluto",
    "fedez", "sfera ebbasta", "ghali", "marracash", "capo plaza",
    "lazza", "blanco", "sangiovanni", "irama", "ultimo", "anna",
    "annalisa", "elodie", "giorgia", "emma", "alessandra amoroso",
    "mahmood", "maneskin", "måneskin", "the kolors", "boomdabash", "j-ax",
    "baby k", "shiva", "geolier", "rose villain", "tropico", "madame",
    "mecna", "psicologi", "rkomi", "ariete", "noemi", "levante",
    "francesca michielin", "achille lauro", "coez", "frah quintale",
    "biagio antonacci", "nek", "max pezzali", "883", "lucio dalla",
    "gino paoli", "adriano celentano", "al bano", "riccardo cocciante",
    "mia martini", "diodato", "colapesce", "dimartino", "coma_cose",
    "willie peyote", "tony effe", "taxi b", "villabanks", "paky",
    "simba la rue", "artie 5ive", "sethu", "olly", "gazzelle", "venerus",
    "drillionaire", "rondodasosa", "baby gang", "luchè", "guè",
    "gemitaiz", "madman", "salmo", "noyz narcos", "fabri fibra",
    "clementino", "emis killa", "tormento", "calcutta", "liberato",
    "niccolò fabi", "brunori sas", "tommaso paradiso", "thegiornalisti",
    "cesare cremonini", "luca carboni", "la rappresentante di lista",
    "baustelle", "ministri", "dardust", "mobrici", "i cani",
}

# ─── Playlist Spotify IT ufficiali (id → bonus score) ─────────────────────────
PLAYLIST_CATALOG = {
    "37i9dQZEVXbIQnj7RRhdSX": {"name": "Top 50 Italia",   "bonus": 2},
    "37i9dQZEVXbKbvcwe5owJ1": {"name": "Viral 50 Italia",  "bonus": 3},
    "37i9dQZF1DX94qaYRnkufr": {"name": "Hits d'Italia",    "bonus": 2},
    "37i9dQZF1DWZrtRRNJ2MaW": {"name": "Canzoni italiane", "bonus": 1},
    "37i9dQZF1DX2L0iB23Enbq": {"name": "TikTok Italia",    "bonus": 3},
    "37i9dQZF1DX9aUQdLBk5mW": {"name": "Rap Italiano",     "bonus": 1},
    "37i9dQZF1DX3oM43U2or3c": {"name": "Indie Italia",     "bonus": 1},
}

def build_age_profile(min_age, max_age):
    """Restituisce la strategia di ricerca ottimale per la fascia d'età."""
    avg = (min_age + max_age) / 2
    if avg <= 11:      # 8-11 anni
        return {
            "year_range": (2018, CURRENT_YEAR), "pop_threshold": 50,
            "allow_explicit": False, "penalize_explicit": False,
            "playlist_ids": [
                "37i9dQZEVXbIQnj7RRhdSX", "37i9dQZF1DX94qaYRnkufr",
                "37i9dQZF1DWZrtRRNJ2MaW",
            ],
            "queries": [
                "pop italiano 2022 2023 2024 2025",
                "sanremo 2023 2024 2025", "top italia 2023 2024",
            ],
        }
    elif avg <= 14:    # 12-14 anni
        return {
            "year_range": (2019, CURRENT_YEAR), "pop_threshold": 55,
            "allow_explicit": False, "penalize_explicit": False,
            "playlist_ids": [
                "37i9dQZEVXbIQnj7RRhdSX", "37i9dQZEVXbKbvcwe5owJ1",
                "37i9dQZF1DX94qaYRnkufr", "37i9dQZF1DX2L0iB23Enbq",
            ],
            "queries": [
                "pop italiano hit 2022 2023 2024 2025",
                "sanremo 2023 2024 2025", "tiktok italia 2023 2024",
            ],
        }
    elif avg <= 17:    # 14-16 / 15-17 anni
        return {
            "year_range": (2019, CURRENT_YEAR), "pop_threshold": 55,
            "allow_explicit": True, "penalize_explicit": True,
            "playlist_ids": [
                "37i9dQZEVXbIQnj7RRhdSX", "37i9dQZEVXbKbvcwe5owJ1",
                "37i9dQZF1DX94qaYRnkufr", "37i9dQZF1DX2L0iB23Enbq",
                "37i9dQZF1DX9aUQdLBk5mW",
            ],
            "queries": [
                "pop italiano hit 2021 2022 2023 2024 2025",
                "trap italiana 2022 2023 2024 2025",
                "sanremo 2022 2023 2024 2025",
                "tiktok viral italia 2023 2024",
            ],
        }
    elif avg <= 22:    # 17-22 anni
        return {
            "year_range": (2015, CURRENT_YEAR), "pop_threshold": 50,
            "allow_explicit": True, "penalize_explicit": True,
            "playlist_ids": [
                "37i9dQZEVXbIQnj7RRhdSX", "37i9dQZEVXbKbvcwe5owJ1",
                "37i9dQZF1DX94qaYRnkufr", "37i9dQZF1DX9aUQdLBk5mW",
                "37i9dQZF1DX3oM43U2or3c",
            ],
            "queries": [
                "pop italiano hit 2018 2019 2020 2021 2022",
                "rap italiano 2019 2020 2021 2022",
                "sanremo 2019 2020 2021 2022 2023 2024 2025",
                "indie italiano 2017 2018 2019 2020",
            ],
        }
    else:              # 20+ anni
        return {
            "year_range": (2005, CURRENT_YEAR), "pop_threshold": 45,
            "allow_explicit": True, "penalize_explicit": False,
            "playlist_ids": [
                "37i9dQZEVXbIQnj7RRhdSX", "37i9dQZF1DX94qaYRnkufr",
                "37i9dQZF1DWZrtRRNJ2MaW", "37i9dQZF1DX3oM43U2or3c",
            ],
            "queries": [
                "pop italiano anni 2000 2010 classici",
                "cantautori italiani", "rock italiano",
                "sanremo classici hits", "hits italia 2005 2010 2015",
            ],
        }

def is_likely_italian(track):
    """True se l'artista è probabilmente italiano."""
    artist = track.get("artist", "").lower().strip()
    if artist in ITALIAN_ARTISTS:
        return True
    for known in ITALIAN_ARTISTS:
        if known in artist or artist in known:
            return True
    return False

def score_track(track, profile, source_bonus=0):
    """Calcola punteggio di rilevanza per un brano rispetto alla fascia d'età."""
    score = source_bonus
    pop = track.get("popularity", 0)
    if pop >= 80:   score += 4
    elif pop >= 70: score += 3
    elif pop >= 60: score += 2
    elif pop >= 50: score += 1
    try:
        age_of_song = CURRENT_YEAR - int(track.get("year", 0))
        if age_of_song <= 1:   score += 4
        elif age_of_song <= 2: score += 3
        elif age_of_song <= 3: score += 2
        elif age_of_song <= 5: score += 1
    except Exception:
        pass
    if is_likely_italian(track):
        score += 2
    if track.get("explicit") and profile.get("penalize_explicit"):
        score -= 3
    return score

def fetch_songs_smart(min_age, max_age, sp):
    """
    Selezione intelligente canzoni per fascia d'età.
    Priorità: playlist Viral/Top IT (trend TikTok) → query mirate → scoring.
    Preferisce canzoni italiane; penalizza explicit per fasce giovani.
    """
    if not sp:
        return []
    profile = build_age_profile(min_age, max_age)
    year_start, _ = profile["year_range"]
    all_tracks = {}  # id -> (track_dict, best_score)

    def _add_track(t, source_bonus):
        if not t or not t.get("id"):
            return
        if t.get("explicit") and not profile["allow_explicit"]:
            return
        try:
            if int(t["album"]["release_date"][:4]) < year_start:
                return
        except Exception:
            pass
        tid = t["id"]
        td = {
            "title": t["name"], "artist": t["artists"][0]["name"], "id": tid,
            "year": t["album"]["release_date"][:4] if "release_date" in t["album"] else "???",
            "preview_url": t.get("preview_url"),
            "spotify_url": t["external_urls"].get("spotify"),
            "explicit": t.get("explicit", False), "popularity": t.get("popularity", 0),
        }
        s = score_track(td, profile, source_bonus)
        if tid not in all_tracks or all_tracks[tid][1] < s:
            all_tracks[tid] = (td, s)

    # 1. Playlist Spotify IT ufficiali (sorgente principale)
    for pid in profile["playlist_ids"]:
        try:
            bonus = PLAYLIST_CATALOG.get(pid, {}).get("bonus", 1)
            res = sp.playlist_tracks(
                pid, limit=100, market='IT',
                fields="items(track(id,name,artists,album(release_date),explicit,popularity,preview_url,external_urls))"
            )
            for item in (res.get("items") or []):
                _add_track(item.get("track"), bonus)
        except Exception:
            pass

    # 2. Query mirate per anno/genere (integrano copertura)
    for q in profile["queries"]:
        try:
            res = sp.search(q=q, type='track', limit=50, market='IT')
            for t in (res.get('tracks', {}).get('items') or []):
                if t and t.get("popularity", 0) >= profile["pop_threshold"]:
                    _add_track(t, source_bonus=1)
        except Exception:
            pass

    # 3. Ordina per score, shuffle interno per varietà
    sorted_pairs = sorted(all_tracks.values(), key=lambda x: x[1], reverse=True)
    result, bucket, prev_score = [], [], None
    for td, sc in sorted_pairs:
        if prev_score is None:
            prev_score = sc
        if abs(sc - prev_score) > 2:
            random.shuffle(bucket)
            result.extend(bucket)
            bucket, prev_score = [], sc
        bucket.append(td)
    random.shuffle(bucket)
    result.extend(bucket)
    return result[:300]

sessions = {}
def generate_code(): return ''.join(random.choices(string.ascii_uppercase + string.digits, k=6))
def get_session(code): return sessions.get(code.upper())
def session_snapshot(session_obj):
    return {
        "code": session_obj["code"], "teams": session_obj["teams"], "stands": session_obj["stands"], 
        "age_label": session_obj.get("age_label"), "stands_info": session_obj.get("stands_info")
    }

@app.route('/api/admin/check')
def admin_check():
    config = load_config()
    return jsonify({"is_admin": flask_session.get('is_admin', False), "needs_setup": not bool(config.get('totp_secret'))})

@app.route('/api/admin/setup', methods=['POST'])
def admin_setup_req():
    data = request.get_json() or {}
    config = load_config()
    if config.get('totp_secret') and data.get('password') != config.get('admin_password'): return jsonify({"error": "Password errata"}), 403
    secret = config.get('totp_secret') or pyotp.random_base32()
    if not config.get('totp_secret'): 
        config['totp_secret'] = secret
        save_config(config)
    return jsonify({"uri": pyotp.TOTP(secret).provisioning_uri(name="HitsterCamp", issuer_name="HitsterCamp")})

@app.route('/api/admin/verify', methods=['POST'])
def admin_verify():
    data = request.get_json()
    config = load_config()
    if pyotp.TOTP(config.get('totp_secret')).verify(str(data.get('code')).replace(" ","")):
        flask_session['is_admin'] = True
        return jsonify({"ok": True})
    return jsonify({"error": "Codice errato"}), 401

@app.route('/api/admin/logout')
def admin_logout():
    flask_session.pop('is_admin', None)
    return redirect(url_for('home'))

@app.route('/admin', methods=['GET', 'POST'])
def admin_panel():
    if not flask_session.get('is_admin'): return redirect(url_for('home'))
    config = load_config()
    if request.method == 'POST':
        config['admin_password'] = request.form.get('admin_password')
        config['spotify']['client_id'] = request.form.get('spotify_id')
        config['spotify']['client_secret'] = request.form.get('spotify_secret')
        new_groups = []
        labels, mins, maxs = request.form.getlist('age_label[]'), request.form.getlist('age_min[]'), request.form.getlist('age_max[]')
        for i in range(len(labels)):
            if labels[i]: new_groups.append({"id": str(i), "label": labels[i], "min_age": int(mins[i]), "max_age": int(maxs[i])})
        config['age_groups'] = new_groups
        s_names, s_descs = request.form.getlist('stand_name[]'), request.form.getlist('stand_desc[]')
        new_stands = []
        for i in range(len(s_names)): new_stands.append({"id": str(i+1), "name": s_names[i], "desc": s_descs[i]})
        config['stands_info'] = new_stands
        save_config(config)
        return redirect(url_for('admin_panel'))
    return render_template('admin.html', config=config)

@app.route('/api/admin/reset_2fa', methods=['POST'])
def reset_2fa():
    if not flask_session.get('is_admin'): return jsonify({"error": "403"}), 403
    config = load_config()
    config['totp_secret'] = ""
    save_config(config)
    return jsonify({"ok": True})

@app.route('/')
def home():
    config = load_config()
    return render_template('home.html', age_groups=config['age_groups'])

@app.route('/create', methods=['POST'])
def create_session():
    age_id = request.form.get('age_group')
    config = load_config()
    age_info = next((g for g in config['age_groups'] if g['id'] == age_id), config['age_groups'][0])
    sp = get_spotify_client(config)
    songs = fetch_songs_smart(age_info.get('min_age', 10), age_info.get('max_age', 15), sp)
    random.shuffle(songs)
    code = generate_code()
    while code in sessions: code = generate_code()
    sessions[code] = {
        "code": code, "age_label": age_info['label'], "teams": {}, 
        "stands": {str(i):{"team1":None, "team2":None, "current_song": None, "history": []} for i in range(1, 7)}, 
        "stands_info": config['stands_info'], "songs_queue": songs, "used_songs": [],
        "used_ids": set()  # IDs già mostrati — mai riproposti nella stessa sessione
    }
    return redirect(url_for('master_view', code=code))

@app.route('/join', methods=['POST'])
def join_session():
    code = request.form.get('code', '').strip().upper()
    if not get_session(code): return render_template('home.html', error=f'Codice "{code}" non trovato.', age_groups=load_config()['age_groups'])
    return redirect(url_for('stand_view', code=code))

@app.route('/master/<code>')
def master_view(code):
    session_obj = get_session(code)
    if not session_obj: return redirect(url_for('home'))
    return render_template('master.html', session=session_obj)

@app.route('/stand/<code>')
def stand_view(code):
    session_obj = get_session(code)
    if not session_obj: return redirect(url_for('home'))
    return render_template('stand.html', session=session_obj)

@app.route('/api/session/<code>')
def api_session(code):
    session_obj = get_session(code)
    if not session_obj: return jsonify({"error": "404"}), 404
    return jsonify(session_snapshot(session_obj))

@app.route('/api/session/<code>/next_song', methods=['POST'])
def next_song(code):
    session_obj = get_session(code)
    stand_id = request.get_json().get('stand')
    if not session_obj or not stand_id or stand_id not in session_obj["stands"]:
        return jsonify({"error": "Invalid stand"}), 400

    # Raccoglie tutti gli ID già usati o attualmente in mostra su qualsiasi stand
    used_ids = session_obj.setdefault("used_ids", set())
    for stand in session_obj["stands"].values():
        if stand["current_song"]:
            used_ids.add(stand["current_song"]["id"])

    # Cerca la prima canzone non ancora mostrata nella coda
    song = None
    remaining = []
    for s in session_obj["songs_queue"]:
        if song is None and s["id"] not in used_ids:
            song = s
        else:
            remaining.append(s)
    session_obj["songs_queue"] = remaining

    if not song:
        return jsonify({"error": "No songs", "message": "Tutte le canzoni sono già state utilizzate in questa sessione."}), 404

    used_ids.add(song["id"])
    session_obj["used_songs"].append(song)
    session_obj["stands"][stand_id]["current_song"] = song
    socketio.emit('session_update', session_snapshot(session_obj), room=code)
    return jsonify({"ok": True, "song": song})

@app.route('/api/session/<code>/confirm_song', methods=['POST'])
def confirm_song(code):
    session_obj = get_session(code)
    data = request.get_json()
    sid = str(data.get('stand'))
    if session_obj and sid in session_obj["stands"]:
        song = session_obj["stands"][sid]["current_song"]
        if song:
            session_obj["stands"][sid]["history"].append(song)
            session_obj["stands"][sid]["history"].sort(key=lambda x: x['year'])
            session_obj["stands"][sid]["current_song"] = None
            socketio.emit('session_update', session_snapshot(session_obj), room=code)
    return jsonify({"ok": True})

@app.route('/api/session/<code>/add_team', methods=['POST'])
def add_team(code):
    session_obj = get_session(code)
    if not session_obj: return jsonify({"error": "404"}), 404
    name = request.get_json().get('name', '').strip()
    if not name or name in session_obj["teams"]: return jsonify({"error": "Bad"}), 400
    session_obj["teams"][name] = 0
    socketio.emit('session_update', session_snapshot(session_obj), room=code)
    return jsonify({"ok": True})

@app.route('/api/session/<code>/remove_team', methods=['POST'])
def remove_team(code):
    session_obj = get_session(code)
    if not session_obj: return jsonify({"error": "404"}), 404
    name = request.get_json().get('name')
    if name in session_obj["teams"]:
        del session_obj["teams"][name]
        socketio.emit('session_update', session_snapshot(session_obj), room=code)
    return jsonify({"ok": True})

@app.route('/api/session/<code>/set_stand', methods=['POST'])
def set_stand(code):
    session_obj = get_session(code)
    if not session_obj: return jsonify({"error": "404"}), 404
    data = request.get_json()
    sid, t1, t2 = str(data.get('stand')), data.get('team1'), data.get('team2')
    if sid in session_obj["stands"]:
        session_obj["stands"][sid]["team1"] = t1
        session_obj["stands"][sid]["team2"] = t2
        socketio.emit('session_update', session_snapshot(session_obj), room=code)
    return jsonify({"ok": True})

@app.route('/api/session/<code>/add_points', methods=['POST'])
def add_points(code):
    session_obj = get_session(code)
    if not session_obj: return jsonify({"error": "404"}), 404
    data = request.get_json()
    team, pts = data.get('team'), int(data.get('points', 0))
    if team in session_obj["teams"]:
        session_obj["teams"][team] += pts
        socketio.emit('session_update', session_snapshot(session_obj), room=code)
    return jsonify({"ok": True})

@app.route('/api/session/<code>/reset', methods=['POST'])
def reset_session(code):
    session_obj = get_session(code)
    if session_obj:
        for t in session_obj["teams"]: session_obj["teams"][t] = 0
        socketio.emit('session_update', session_snapshot(session_obj), room=code)
    return jsonify({"ok": True})

@app.route('/api/session/<code>/delete', methods=['POST'])
def delete_session(code):
    if code in sessions:
        socketio.emit('session_ended', {}, room=code)
        del sessions[code]
    return jsonify({"ok": True})

@socketio.on('join')
def on_join(data):
    code = data.get('code', '').upper()
    join_room(code)
    session_obj = get_session(code)
    if session_obj: emit('session_update', session_snapshot(session_obj))

if __name__ == '__main__':
    socketio.run(app, host='0.0.0.0', port=5000, debug=True, allow_unsafe_werkzeug=True)
