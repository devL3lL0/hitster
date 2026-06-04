<?php
require_once __DIR__ . '/includes/session_store.php';
$code = strtoupper($_GET['code'] ?? '');
$session = session_get($code);
if (!$session) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <title>Stand – <?php echo htmlspecialchars($session['code']); ?></title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
</head>
<body>
  <header class="app-header">
    <a class="app-logo" href="index.php">
      <img src="assets/img/icon.png" alt="Logo" onerror="this.style.display='none'">
      Hitster<span>Camp</span>
    </a>
    <span class="badge-code" style="background: var(--accent); border:none;"><?php echo htmlspecialchars($session['code']); ?></span>
  </header>

  <div class="container">
    <div style="margin-bottom: 2rem;">
      <h1 style="font-family: var(--font-head); font-size: 1.8rem; font-weight: 800; color: var(--cyan); letter-spacing: -1px;">
        🎤 Animatore <span style="font-weight: 300; color: var(--text-muted);">Stand</span>
      </h1>
    </div>

    <!-- SELEZIONE STAND -->
    <div class="card" id="setup-card">
      <h2>📍 Configura lo Stand</h2>
      <p class="muted" style="margin-bottom: 1.5rem;">Scegli la tua postazione e le squadre.</p>
      
      <div style="margin-bottom: 1.25rem;">
        <select id="stand-select" onchange="updateStandPreview()">
          <option value="">--- Scegli lo stand ---</option>
          <?php foreach ($session['stands_info'] as $stand): ?>
          <option value="<?php echo htmlspecialchars($stand['id']); ?>"><?php echo htmlspecialchars($stand['id'] . ' - ' . $stand['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div id="stand-preview-card" style="display:none; padding: 1.25rem; background: rgba(100, 210, 255, 0.05); border-radius: 20px; border: 1px dashed var(--cyan); margin-bottom: 1.5rem;">
        <h3 id="preview-name" style="font-size: 1rem; color: var(--cyan); margin-bottom: 0.4rem;"></h3>
        <p id="preview-desc" class="muted" style="font-size: 0.9rem;"></p>
      </div>

      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin-bottom: 1.5rem;">
        <select id="team1-select"><option value="">Squadra 1...</option></select>
        <select id="team2-select"><option value="">Squadra 2...</option></select>
      </div>
      
      <button class="btn btn-primary btn-full" onclick="confirmSetup()">✅ Inizia Sfida</button>
    </div>

    <!-- PANNELLO GIOCO -->
    <div id="game-panel" style="display:none;">
      
      <!-- REGOLE -->
      <div class="card" style="padding: 1.25rem; background: rgba(255,255,255,0.02); border-style: dashed; border-color: var(--cyan);">
        <h2 id="active-stand-name" style="margin:0; font-size:1rem; color: var(--cyan); text-transform: uppercase;"></h2>
        <p id="active-stand-desc" class="muted" style="font-size:0.85rem; margin-top:0.5rem; white-space: pre-wrap;"></p>
      </div>

      <!-- PLAYER CARD -->
      <div class="card" style="border-color: var(--accent); background: rgba(99, 102, 241, 0.05); padding: 1.5rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
          <h2 style="margin: 0; font-size: 1rem; color: var(--accent);">🎵 Brano Attivo</h2>
          <button class="btn btn-primary btn-sm" onclick="nextSong()" id="next-song-btn">Pesca Nuova</button>
        </div>
        
        <div id="song-info-display" style="text-align: center; margin-bottom: 1.5rem;">
          <p class="muted">Pesca una canzone...</p>
        </div>

        <!-- MACCHINA DEGLI INDIZI (SOLO STAND 4) -->
        <div id="clue-machine" class="card" style="display:none; background: rgba(0,0,0,0.4); border-color: var(--cyan); margin-bottom: 1.5rem; padding: 1rem;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem;">
                <span style="font-weight:800; font-size:0.8rem; color:var(--cyan);">🔍 MACCHINA INDIZI</span>
                <button class="btn btn-ghost btn-sm" onclick="generateClue()" style="font-size:0.7rem;">NUOVO INDIZIO</button>
            </div>
            <div id="clue-display" style="min-height: 40px; display:flex; align-items:center; justify-content:center; text-align:center; font-style:italic; color:white; font-size:1.1rem; font-weight:600;">
                Clicca per generare un indizio...
            </div>
        </div>

        <!-- SPOTIFY PLAYER -->
        <div id="player-container" style="display:none; animation: fadeIn 0.5s ease;">
            <div style="border-radius: 20px; overflow: hidden; background: #000; border: 1px solid rgba(255,255,255,0.1); margin-bottom: 1rem;">
                <iframe id="spotify-widget" src="" width="100%" height="80" frameborder="0" allowtransparency="true" allow="encrypted-media"></iframe>
            </div>
            <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                <button class="btn btn-emerald btn-full" onclick="confirmAndAdd()" style="background: #32d74b; color: black; font-weight: 800; padding: 1rem;">
                    ✅ Indovinata (Timeline)
                </button>
                <a id="full-spotify-link" href="#" target="_blank" class="btn btn-ghost btn-full btn-sm" style="color: #1DB954; border-color: rgba(29, 185, 84, 0.3);">
                     Apri in Spotify (App)
                </a>
            </div>
        </div>
      </div>

      <!-- TIMELINE VISUALIZER -->
      <div id="timeline-card" class="card" style="display:none; padding: 1.5rem; background: rgba(255,255,255,0.02); border-color: var(--gold);">
        <h2 style="font-size: 1rem; color: var(--gold); margin-bottom: 1.25rem;">📅 Timeline Attuale</h2>
        <div id="timeline-list" style="display: flex; flex-direction: column; gap: 0.75rem;"></div>
      </div>

      <!-- SCOREBOARD -->
      <div class="card" style="padding: 2rem 1rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; text-align: center;">
          <div style="flex: 1;">
            <div id="name-t1" style="font-weight: 800; color: var(--text-muted); font-size: 0.9rem; text-transform: uppercase;">---</div>
            <div id="score-t1" style="font-family: var(--font-head); font-size: 3.5rem; font-weight: 900; color: var(--accent);">0</div>
            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                <button class="btn btn-primary btn-full" onclick="addPts('t1', 1)">+1</button>
                <button class="btn btn-ghost btn-sm" onclick="addPts('t1', -1)">-</button>
            </div>
          </div>
          <div style="font-weight: 900; color: var(--text-muted); opacity: 0.3; margin-top: -40px;">VS</div>
          <div style="flex: 1;">
            <div id="name-t2" style="font-weight: 800; color: var(--text-muted); font-size: 0.9rem; text-transform: uppercase;">---</div>
            <div id="score-t2" style="font-family: var(--font-head); font-size: 3.5rem; font-weight: 900; color: var(--cyan);">0</div>
            <div style="display: flex; gap: 0.5rem; margin-top: 1rem;">
                <button class="btn btn-full" style="background:var(--cyan); color:white;" onclick="addPts('t2', 1)">+1</button>
                <button class="btn btn-ghost btn-sm" onclick="addPts('t2', -1)">-</button>
            </div>
          </div>
        </div>
        <button class="btn btn-ghost btn-full btn-sm" style="margin-top: 1.5rem; border-color: rgba(255,214,10,0.2); color: var(--gold);" onclick="addPtsSpecial()">⭐ PUNTO BONUS (+3)</button>
      </div>

      <div class="card">
        <h2 style="font-size: 1.1rem; margin-bottom: 1rem;">🏆 Classifica Live</h2>
        <div id="mini-rank"></div>
      </div>

      <div style="text-align:center; padding-bottom: 3rem;">
        <button class="btn btn-ghost btn-sm" onclick="resetSetup()">🔄 Cambia Stand / Squadre</button>
      </div>
    </div>
  </div>

  <div id="toast"></div>

  <script src="assets/js/base.js"></script>
  <script>
    const CODE = "<?php echo htmlspecialchars($code); ?>";
    let sessionData = <?php echo json_encode(session_snapshot($session)); ?>;
    let myStand = null, myTeam1 = null, myTeam2 = null, currentSong = null;

    function onSessionEnded() {
        window.location.href = 'index.php';
    }

    const engine = new PollingEngine(CODE, data => {
        sessionData = data;
        populateSelects(data.teams);
        if (myStand && data.stands[myStand]) {
            const standState = data.stands[myStand];
            updateUI(data);
            
            // Render only if song changed to avoid iframe reload
            const newSongId = standState.current_song ? standState.current_song.id : null;
            const curSongId = currentSong ? currentSong.id : null;
            if (newSongId !== curSongId) {
                currentSong = standState.current_song;
                renderCurrentSong(standState.current_song);
            }
            renderTimeline(standState.history, standState.current_song);
            document.getElementById('clue-machine').style.display = (myStand === '4') ? 'block' : 'none';
        }
    });
    engine.start();

    // Initial populate
    populateSelects(sessionData.teams);

    function updateStandPreview() {
      const sid = document.getElementById('stand-select').value;
      const preview = document.getElementById('stand-preview-card');
      if (!sid || !sessionData) { preview.style.display = 'none'; return; }
      const info = sessionData.stands_info.find(s => s.id === sid);
      if (info) { document.getElementById('preview-name').innerText = info.name; document.getElementById('preview-desc').innerText = info.desc; preview.style.display = 'block'; }
    }

    function renderCurrentSong(song) {
      const infoDisplay = document.getElementById('song-info-display');
      const playerCont = document.getElementById('player-container');
      const widget = document.getElementById('spotify-widget');
      const spotifyLink = document.getElementById('full-spotify-link');

      if (!song) {
        infoDisplay.innerHTML = '<p class="muted">Pesca una canzone...</p>';
        playerCont.style.display = 'none';
        document.getElementById('clue-display').innerText = "Clicca per generare un indizio...";
        return;
      }
      
      infoDisplay.innerHTML = `
        <div style="animation: fadeIn 0.4s ease;">
            <h3 style="font-family: var(--font-head); font-size: 1.5rem; margin-bottom: 0.2rem; font-weight:800;">${esc(song.title)}</h3>
            <p class="muted" style="font-size: 1.1rem; margin-bottom: 0.75rem;">${esc(song.artist)}</p>
            <span style="background: rgba(255, 214, 10, 0.15); color: var(--gold); padding: 4px 12px; border-radius: 10px; font-weight: 800; font-size: 0.9rem;">📅 ${song.year}</span>
        </div>
      `;
      
      const embedUrl = `https://open.spotify.com/embed/track/${song.id}?utm_source=generator&theme=0`;
      if (widget.src !== embedUrl) widget.src = embedUrl;
      
      spotifyLink.href = song.spotify_url || `https://open.spotify.com/track/${song.id}`;
      playerCont.style.display = 'block';
    }

    function generateClue() {
        if (!currentSong) return;
        
        const intuitiveMask = (str) => {
            return str.split('').map(char => {
                if (char === ' ') return '  ';
                return Math.random() > 0.5 ? char.toUpperCase() : '_';
            }).join(' ');
        };

        const firstWord = currentSong.title.split(' ')[0];
        const year = currentSong.year;

        const clues = [
            `TITOLO (completa): ${intuitiveMask(currentSong.title)}`,
            `INIZIA CON: Il titolo inizia con la parola "${firstWord.toUpperCase()}"`,
            `ARTISTA (aiutino): ${currentSong.artist.split('').map(c => Math.random() > 0.4 ? c.toUpperCase() : '_').join(' ')}`,
            `ANNO: È uscita precisamente nell'anno ${year}`,
            `ESTATE: ${year > 2010 ? 'È una hit moderna che hanno ballato tutti!' : 'È un grande classico del passato!'}`,
            `LUNGHEZZA: Il titolo ha ${currentSong.title.split(' ').length} parole.`
        ];

        const title = currentSong.title.toLowerCase();
        const themes = {
            "❤️ AMORE": ["amore", "cuore", "love", "bacio", "te", "noi", "vita"],
            "🏖️ ESTATE/MARE": ["estate", "mare", "sole", "spiaggia", "summer", "sale", "caldo"],
            "🌙 NOTTE": ["notte", "sera", "buio", "night", "luna", "stelle"],
            "🕺 FESTA": ["festa", "ballo", "dance", "party", "musica", "disco"],
            "🚗 VIAGGIO": ["viaggio", "strada", "auto", "macchina", "volare", "treno"]
        };

        for (const [name, keywords] of Object.entries(themes)) {
            if (keywords.some(k => title.includes(k))) {
                clues.push(`TEMA: Parla di ${name}!`);
                break;
            }
        }
        
        const randomClue = clues[Math.floor(Math.random() * clues.length)];
        const display = document.getElementById('clue-display');
        display.style.opacity = 0;
        setTimeout(() => {
            display.innerHTML = `<span style="color:var(--cyan); font-size:0.8rem; display:block; margin-bottom:0.5rem; font-family:var(--font-head);">RISULTATO GENERATORE:</span> ${randomClue}`;
            display.style.opacity = 1;
        }, 200);
    }

    function renderTimeline(history, current) {
        const cont = document.getElementById('timeline-card');
        const list = document.getElementById('timeline-list');
        if (history.length === 0 && !current) { cont.style.display = 'none'; return; }
        cont.style.display = 'block';

        let html = history.map(s => `
            <div style="padding: 0.75rem; background: rgba(255,255,255,0.03); border-radius: 12px; border-left: 4px solid var(--gold); display: flex; justify-content: space-between; align-items: center;">
                <div style="font-size: 0.85rem; font-weight: 700;">${esc(s.title)}</div>
                <div style="font-weight: 800; color: var(--gold);">${s.year}</div>
            </div>
        `).join('');

        if (current && history.length > 0) {
            let lower = [...history].reverse().find(s => s.year <= current.year);
            let higher = history.find(s => s.year >= current.year);
            
            let msg = "";
            if (!lower) msg = `📍 ALL'INIZIO (Prima del ${higher.year})`;
            else if (!higher) msg = `🏁 ALLA FINE (Dopo il ${lower.year})`;
            else if (lower.year === current.year || higher.year === current.year) msg = `✨ ANNO ESATTO (${current.year})`;
            else msg = `↔️ TRA IL ${lower.year} E IL ${higher.year}`;
            
            html = `<div style="background: rgba(10, 132, 255, 0.1); padding: 0.8rem; border-radius: 12px; text-align: center; font-size: 0.85rem; font-weight: 800; color: var(--accent); margin-bottom: 1rem; border: 1px solid var(--accent); animation: pulse 2s infinite;">
                        ${msg}
                    </div>` + html;
        }

        list.innerHTML = html;
    }

    async function doApiCall(action, body = {}) {
        body.action = action;
        body.code = CODE;
        const res = await fetch('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });
        const data = await res.json();
        engine.poll(); // force refresh
        return data;
    }

    async function confirmAndAdd() {
        const { value: team } = await Swal.fire({
            title: 'Posizione corretta?',
            text: 'La squadra riceverà +1 punto e la canzone entrerà nella timeline.',
            input: 'select',
            inputOptions: { [myTeam1]: myTeam1, [myTeam2]: myTeam2 },
            inputPlaceholder: 'Chi ha indovinato?',
            showCancelButton: true
        });
        if (team) {
            await doApiCall('add_points', { team: team === myTeam1 ? myTeam1 : myTeam2, points: 1 });
            await doApiCall('confirm_song', { stand: myStand });
        }
    }

    function populateSelects(teams) {
      if(!teams) return;
      const names = Object.keys(teams);
      ['team1-select', 'team2-select'].forEach(id => {
        const sel = document.getElementById(id);
        const val = sel.value;
        sel.innerHTML = `<option value="">Squadra ${id.includes('1')?'1':'2'}...</option>`;
        names.forEach(n => {
          const opt = document.createElement('option');
          opt.value = opt.textContent = n;
          if (n === val) opt.selected = true;
          sel.appendChild(opt);
        });
      });
    }

    async function nextSong() { 
        if (!myStand) return;
        const btn = document.getElementById('next-song-btn');
        btn.disabled = true;
        btn.textContent = '...';
        try {
            const data = await doApiCall('next_song', { stand: myStand });
            if (data.error) {
                showToast(data.message || data.error, true);
            }
        } finally {
            btn.disabled = false;
            btn.textContent = 'Pesca Nuova';
        }
    }

    async function confirmSetup() {
      const s = document.getElementById('stand-select').value;
      const t1 = document.getElementById('team1-select').value;
      const t2 = document.getElementById('team2-select').value;
      if (!s || !t1 || !t2) { showToast('Seleziona tutto!', true); return; }
      myStand = s; myTeam1 = t1; myTeam2 = t2;
      
      await doApiCall('set_stand', { stand: s, team1: t1, team2: t2 });
      
      const info = sessionData.stands_info.find(i => i.id === s);
      document.getElementById('active-stand-name').innerText = info.name;
      document.getElementById('active-stand-desc').innerText = info.desc;
      document.getElementById('setup-card').style.display = 'none';
      document.getElementById('game-panel').style.display = 'block';
      document.getElementById('name-t1').textContent = t1;
      document.getElementById('name-t2').textContent = t2;
      updateUI(sessionData);
      
      // Force initial fetch to ensure song sync
      engine.poll();
    }

    function resetSetup() {
      myStand = null; myTeam1 = myTeam2 = null;
      document.getElementById('setup-card').style.display = 'block';
      document.getElementById('game-panel').style.display = 'none';
    }

    function updateUI(data) {
      if(!data || !data.teams) return;
      const t = data.teams;
      animateVal('score-t1', t[myTeam1] ?? 0);
      animateVal('score-t2', t[myTeam2] ?? 0);
      renderMiniRank(t);
    }

    function animateVal(id, val) {
      const el = document.getElementById(id);
      if(!el) return;
      const old = parseInt(el.textContent) || 0;
      el.textContent = val;
      if (old !== val) { el.animate([{ transform: 'scale(1)' }, { transform: 'scale(1.2)', color: 'var(--gold)' }, { transform: 'scale(1)' }], { duration: 300 }); }
    }

    function renderMiniRank(teams) {
      const container = document.getElementById('mini-rank');
      if(!container) return;
      const sorted = Object.entries(teams).sort((a,b) => b[1]-a[1]);
      container.innerHTML = sorted.map(([name, pts], i) => {
        const isMe = (name === myTeam1 || name === myTeam2);
        return `<div class="rank-item" style="${isMe ? 'border-color: var(--accent); background: rgba(99,102,241,0.1);' : ''}"><div class="rank-pos">${i+1}</div><div class="rank-name">${esc(name)}</div><div class="rank-score">${pts}</div></div>`;
      }).join('');
    }

    async function addPts(which, p) {
      const team = (which === 't1') ? myTeam1 : myTeam2;
      await doApiCall('add_points', { team, points: p });
    }

    async function addPtsSpecial() {
        const { value: team } = await Swal.fire({
            title: 'Assegna Bonus +3',
            input: 'select',
            inputOptions: { [myTeam1]: myTeam1, [myTeam2]: myTeam2 },
            inputPlaceholder: 'Scegli squadra...',
            showCancelButton: true
        });
        if (team) await addPts(team === myTeam1 ? 't1' : 't2', 3);
    }
  </script>
</body>
</html>
