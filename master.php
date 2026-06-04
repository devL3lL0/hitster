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
  <title>Master Dashboard – <?php echo htmlspecialchars($session['code']); ?></title>
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
    <div style="text-align: center; margin-bottom: 2.5rem; animation: fadeIn 0.5s ease;">
      <h1 style="font-family: var(--font-head); font-size: 2.2rem; font-weight: 800; letter-spacing: -1px; margin-bottom: 0.2rem;">
        👑 Master <span style="font-weight: 300; opacity: 0.5;">Control</span>
      </h1>
      <div style="display:flex; gap:0.5rem; align-items:center; justify-content: center;">
        <span style="font-weight: 700; color: var(--accent);"><?php echo htmlspecialchars($session['age_label']); ?></span>
        <span class="muted">•</span>
        <span class="muted" id="team-count-label">0 squadre</span>
      </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 1.5rem; max-width: 600px; margin: 0 auto;">
      <div class="card" style="padding: 1.5rem; border-color: var(--accent); background: rgba(10, 132, 255, 0.03);">
        <h2 style="font-size: 1rem; margin-bottom: 1rem;">➕ Iscrivi Nuova Squadra</h2>
        <div style="display: flex; gap: 0.75rem;">
          <input type="text" id="new-team-name" placeholder="Nome squadra..." style="flex:1; padding: 0.8rem 1.2rem;" onkeypress="if(event.key==='Enter') addTeam()" />
          <button class="btn btn-primary" onclick="addTeam()" style="padding: 0 1.25rem;">Ok</button>
        </div>
      </div>

      <div class="card" style="padding: 2rem 1.5rem;">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem;">
          <h2 style="margin:0; font-size: 1.3rem;">🏆 Classifica Live</h2>
          <button class="btn btn-ghost btn-sm" onclick="confirmReset()" style="font-size: 0.7rem; padding: 6px 12px;">RESET</button>
        </div>
        <div id="leaderboard" style="display: flex; flex-direction: column; gap: 0.75rem;">
        </div>
      </div>

      <div class="card" style="padding: 1.5rem;">
        <h2 style="font-size: 1.1rem; margin-bottom: 1.25rem; color: var(--cyan);">📍 Stato degli Stand</h2>
        <div id="stands-overview" style="display: flex; flex-direction: column; gap: 0.75rem;">
          <p class="muted text-center" style="font-size: 0.9rem; padding: 1rem;">In attesa di animatori...</p>
        </div>
      </div>

      <div class="card" style="padding: 1.5rem; border-color: var(--emerald); background: rgba(16, 185, 129, 0.03);">
        <h2 style="color: var(--emerald); font-size: 1rem; margin-bottom: 1rem;">🔗 Link per Animatori</h2>
        <div style="display: flex; gap: 0.5rem;">
          <input type="text" id="stand-link" readonly style="font-size:0.8rem; background:rgba(0,0,0,0.2); border-color: rgba(16, 185, 129, 0.2);" />
          <button class="btn btn-emerald btn-sm" onclick="copyLink()">Copia</button>
        </div>
      </div>

      <div style="text-align: center; padding: 2rem 0;">
        <button class="btn btn-ghost btn-sm" onclick="confirmDelete()" style="color: var(--rose); opacity: 0.5; font-size: 0.8rem;">
          🗑️ TERMINA PARTITA DEFINITIVAMENTE
        </button>
      </div>
    </div>
  </div>

  <div id="toast"></div>

  <script src="assets/js/base.js"></script>
  <script>
    const CODE = "<?php echo htmlspecialchars($code); ?>";
    document.getElementById('stand-link').value = window.location.origin + window.location.pathname.replace('master.php', 'stand.php') + '?code=' + CODE;

    function onSessionEnded() {
        window.location.href = 'index.php';
    }

    const engine = new PollingEngine(CODE, data => {
        renderLeaderboard(data.teams);
        renderStands(data);
    });
    engine.start();

    function renderLeaderboard(teams) {
      const container = document.getElementById('leaderboard');
      const sorted = Object.entries(teams).sort((a,b) => b[1]-a[1]);
      document.getElementById('team-count-label').innerText = sorted.length + (sorted.length === 1 ? ' squadra' : ' squadre');
      
      if (sorted.length === 0) {
        container.innerHTML = '<div class="text-center" style="padding: 2rem 0;"><p class="muted" style="font-size: 0.9rem;">Nessuna squadra.<br>Iscrivila nel box in alto.</p></div>';
        return;
      }
      
      container.innerHTML = sorted.map(([name, pts], i) => `
        <div class="rank-item" style="padding: 1rem; background: ${i === 0 ? 'rgba(10, 132, 255, 0.08)' : 'rgba(255, 255, 255, 0.02)'}; border-color: ${i === 0 ? 'var(--accent)' : 'var(--surface-border)'}; border-radius: 18px; animation: fadeIn 0.3s ease;">
          <div class="rank-pos" style="font-size: 1.1rem; width: 1.5rem;">${i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : i + 1}</div>
          <div class="rank-name" style="font-size: 1.05rem; font-weight: 700;">${esc(name)}</div>
          <div class="rank-score" style="font-size: 1.6rem; color: var(--accent);">${pts}</div>
          <button class="btn btn-ghost btn-sm" style="padding: 5px 10px; color: var(--rose); opacity: 0.3;" onclick="removeTeam('${esc(name)}')">✕</button>
        </div>
      `).join('');
    }

    function renderStands(data) {
      const container = document.getElementById('stands-overview');
      const stands = data.stands;
      const activeStands = Object.entries(stands).filter(([id, s]) => s.team1 || s.team2);
      
      if (activeStands.length === 0) {
        container.innerHTML = '<p class="muted text-center" style="padding:1rem; font-size: 0.85rem;">In attesa di animatori...</p>';
        return;
      }

      container.innerHTML = activeStands.map(([id, s]) => {
        const info = data.stands_info.find(i => i.id === id);
        const song = s.current_song;
        return `
          <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--surface-border); padding: 1rem; border-radius: 18px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
              <span style="font-weight:800; font-size:0.75rem; color:var(--cyan); text-transform: uppercase;">STAND ${id} • ${esc(info.name)}</span>
              <span style="width: 7px; height: 7px; background: #32d74b; border-radius: 50%;"></span>
            </div>
            <div style="font-weight:700; font-size:0.95rem; margin-bottom:0.75rem;">
              ${esc(s.team1)} <span class="muted" style="font-weight:400; font-size:0.8rem;">VS</span> ${esc(s.team2)}
            </div>
            ${song ? `
              <div style="background:rgba(0,0,0,0.2); padding:0.75rem; border-radius:12px; font-size:0.8rem; border: 1px solid rgba(255,255,255,0.05);">
                <div style="font-weight:700;">${esc(song.title)}</div>
                <div class="muted" style="margin-bottom:0.3rem;">${esc(song.artist)}</div>
                <div style="font-size:0.75rem; color:var(--gold); font-weight:700;">📅 ${song.year}</div>
              </div>` : ''}
          </div>
        `;
      }).join('');
    }

    async function doApiCall(action, body = {}) {
        body.action = action;
        body.code = CODE;
        await fetch('api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(body)
        });
        engine.poll(); // force immediate refresh
    }

    async function addTeam() {
      const input = document.getElementById('new-team-name');
      const name = input.value.trim();
      if (!name) return;
      await doApiCall('add_team', { name });
      input.value = '';
    }

    async function removeTeam(name) {
      const result = await Swal.fire({ title: 'Rimuovere squadra?', icon: 'warning', showCancelButton: true });
      if (result.isConfirmed) { await doApiCall('remove_team', { name }); }
    }

    async function confirmReset() {
      const result = await Swal.fire({ title: 'Azzerare i punti?', icon: 'question', showCancelButton: true });
      if (result.isConfirmed) { await doApiCall('reset_scores'); }
    }

    async function confirmDelete() {
      const result = await Swal.fire({ title: 'CHIUDERE TUTTO?', icon: 'error', showCancelButton: true });
      if (result.isConfirmed) { await doApiCall('delete'); window.location.href = 'index.php'; }
    }

    function copyLink() {
      const link = document.getElementById('stand-link');
      link.select();
      document.execCommand('copy');
      showToast("Link copiato!");
    }
  </script>
</body>
</html>
