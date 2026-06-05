<?php
session_start();
if (empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/migrations.php';

// ─── Migrazioni automatiche ─────────────────────────────────────────────────────────
// Eseguito ad ogni apertura dell'admin: applica silenziosamente le migrazioni
// pendenti. Se c'è qualcosa di nuovo, lo mostra nella sezione "Database".
$migration_result = run_migrations();

$config = load_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['admin_password']          = $_POST['admin_password'] ?? '';
    $config['spotify']['client_id']    = $_POST['spotify_id']     ?? '';
    $config['spotify']['client_secret']= $_POST['spotify_secret'] ?? '';
    $config['lastfm']['api_key']       = $_POST['lastfm_key']     ?? '';

    $labels = $_POST['age_label'] ?? [];
    $mins   = $_POST['age_min']   ?? [];
    $maxs   = $_POST['age_max']   ?? [];

    $new_groups = [];
    foreach ($labels as $i => $label) {
        if ($label) {
            $new_groups[] = [
                'id'      => (string)$i,
                'label'   => $label,
                'min_age' => (int)($mins[$i] ?? 0),
                'max_age' => (int)($maxs[$i] ?? 0),
            ];
        }
    }
    $config['age_groups'] = $new_groups;
    save_config($config);
    header('Location: admin.php');
    exit;
}

// ─── Dati per le sezioni dinamiche ───────────────────────────────────────────
$db = DB::getInstance();

// Artisti seed
$seeds_by_group = [];
$seeds_stmt = $db->query("SELECT * FROM hitster_artist_seeds ORDER BY age_group, source DESC, popularity DESC");
foreach ($seeds_stmt->fetchAll() as $row) {
    $seeds_by_group[$row['age_group']][] = $row;
}

// Servizi cron
$services = $db->query("SELECT * FROM hitster_cron_services ORDER BY created_at DESC")->fetchAll();

// Ultimo refresh log
$last_log = $db->query("SELECT * FROM hitster_seed_refresh_log ORDER BY ran_at DESC LIMIT 1")->fetch();

// URL base del sito (per mostrare l'endpoint completo)
$site_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
          . '://' . $_SERVER['HTTP_HOST']
          . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$cron_endpoint = $site_url . '/cron/refresh_seeds.php';

$age_groups_list = ['8-11', '12-14', '14-17', '18-22', '23+'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <title>Admin – Hitster Camp</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .badge { display:inline-block; font-size:0.65rem; font-weight:800; padding:0.15rem 0.5rem; border-radius:99px; text-transform:uppercase; letter-spacing:0.5px; }
    .badge-auto  { background:rgba(100,210,255,0.15); color:var(--cyan); }
    .badge-admin { background:rgba(255,214,10,0.15);  color:var(--gold); }
    .badge-on    { background:rgba(52,199,89,0.15);   color:#34C759; }
    .badge-off   { background:rgba(255,69,58,0.15);   color:var(--rose); }
    .seed-table  { width:100%; border-collapse:collapse; font-size:0.82rem; }
    .seed-table th { color:var(--text-muted); font-weight:700; text-transform:uppercase; font-size:0.7rem; letter-spacing:0.5px; padding:0.4rem 0.5rem; border-bottom:1px solid rgba(255,255,255,0.07); text-align:left; }
    .seed-table td { padding:0.45rem 0.5rem; border-bottom:1px solid rgba(255,255,255,0.04); vertical-align:middle; }
    .seed-table tr:last-child td { border-bottom:none; }
    .tab-btn { background:none; border:1px solid rgba(255,255,255,0.1); color:var(--text-muted); padding:0.35rem 0.8rem; border-radius:8px; font-size:0.8rem; cursor:pointer; transition:all 0.2s; }
    .tab-btn.active { background:rgba(100,210,255,0.15); border-color:var(--cyan); color:var(--cyan); font-weight:700; }
    .copy-btn { background:none; border:1px solid rgba(255,255,255,0.12); color:var(--text-muted); padding:0.2rem 0.6rem; border-radius:6px; font-size:0.75rem; cursor:pointer; }
    .copy-btn:hover { border-color:var(--cyan); color:var(--cyan); }
    .token-pill { font-family:monospace; font-size:0.75rem; background:rgba(255,255,255,0.05); padding:0.3rem 0.6rem; border-radius:6px; letter-spacing:1px; word-break:break-all; }
  </style>
</head>
<body>
  <header class="app-header">
    <a class="app-logo" href="index.php">
      <img src="assets/img/icon.png" alt="Logo" onerror="this.style.display='none'">
      Hitster<span>Camp</span>
    </a>
    <a href="api.php?action=admin_logout" class="btn btn-ghost btn-sm" style="color: var(--rose); border-color: rgba(255,69,58,0.3);">Logout</a>
  </header>

  <div class="container">
    <div style="margin-bottom: 2rem;">
      <h1 style="font-family: var(--font-head); font-size: 1.8rem; font-weight: 800; letter-spacing: -1px;">
        ⚙️ Impostazioni <span style="font-weight: 300; color: var(--text-muted);">Admin</span>
      </h1>
    </div>

    <!-- ═══ FORM CONFIGURAZIONE ════════════════════════════════════════════════ -->
    <form method="POST">
      <!-- SICUREZZA -->
      <div class="card" style="padding: 1.5rem; border-color: var(--rose); background: rgba(255,69,58,0.02); margin-bottom:1rem;">
        <h2 style="font-size: 1.1rem; color: var(--rose); margin-bottom: 1rem;">🔐 Sicurezza</h2>
        <div style="margin-bottom: 1rem;">
          <label class="muted" style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; display: block;">PASSWORD MASTER</label>
          <input type="text" name="admin_password" value="<?php echo htmlspecialchars($config['admin_password']); ?>" required />
        </div>
        <button type="button" class="btn btn-ghost btn-sm btn-full" onclick="reset2FA()" style="color: var(--rose);">Reset 2FA (Richiederà nuovo setup)</button>
      </div>

      <!-- SPOTIFY -->
      <div class="card" style="padding: 1.5rem; border-color: #1DB954; background: rgba(29, 185, 84, 0.03); margin-bottom:1rem;">
        <h2 style="font-size: 1.1rem; color: #1DB954; margin-bottom: 1rem;">🎵 Spotify API</h2>
        <p class="muted" style="font-size: 0.85rem; margin-bottom: 1rem;">Ottieni queste chiavi creando un'app su <a href="https://developer.spotify.com/dashboard" target="_blank" style="color:#1DB954;">Spotify Developer</a>.</p>
        <div style="margin-bottom: 1rem;">
          <label class="muted" style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; display: block;">CLIENT ID</label>
          <input type="text" name="spotify_id" value="<?php echo htmlspecialchars($config['spotify']['client_id'] ?? ''); ?>" />
        </div>
        <div>
          <label class="muted" style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; display: block;">CLIENT SECRET</label>
          <input type="password" name="spotify_secret" value="<?php echo htmlspecialchars($config['spotify']['client_secret'] ?? ''); ?>" />
        </div>
      </div>

      <!-- LAST.FM -->
      <div class="card" style="padding: 1.5rem; border-color: #d51007; background: rgba(213,16,7,0.03); margin-bottom:1rem;">
        <h2 style="font-size: 1.1rem; color: #e8534a; margin-bottom: 1rem;">📻 Last.fm API</h2>
        <p class="muted" style="font-size: 0.85rem; margin-bottom: 1rem;">Usata per scoprire artisti italiani per genere e fascia d'età. API key gratuita su <a href="https://www.last.fm/api/account/create" target="_blank" style="color:#e8534a;">last.fm/api</a>.</p>
        <div>
          <label class="muted" style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; display: block;">API KEY</label>
          <input type="text" name="lastfm_key" value="<?php echo htmlspecialchars($config['lastfm']['api_key'] ?? ''); ?>" placeholder="Lascia vuoto per disabilitare Last.fm" />
        </div>
      </div>

      <!-- FASCE D'ETÀ -->
      <div class="card" style="padding: 1.5rem; border-color: var(--cyan); background: rgba(100, 210, 255, 0.03); margin-bottom:1rem;">
        <h2 style="font-size: 1.1rem; color: var(--cyan); margin-bottom: 1rem;">👶 Fasce d'Età</h2>
        <div id="age-groups-container">
          <?php foreach ($config['age_groups'] as $g): ?>
          <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
            <input type="text"   name="age_label[]" value="<?php echo htmlspecialchars($g['label']); ?>"   placeholder="Etichetta" />
            <input type="number" name="age_min[]"   value="<?php echo htmlspecialchars($g['min_age']); ?>" placeholder="Min" />
            <input type="number" name="age_max[]"   value="<?php echo htmlspecialchars($g['max_age']); ?>" placeholder="Max" />
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="addAgeGroup()" style="margin-top: 1rem; color: var(--cyan);">+ Aggiungi Fascia</button>
      </div>

      <button type="submit" class="btn btn-primary btn-full" style="height: 60px; font-size: 1.1rem; margin-bottom: 2.5rem;">💾 Salva Configurazioni</button>
    </form>

    <!-- ═══ DATABASE / MIGRAZIONI ════════════════════════════════════════════════ -->
    <div style="margin-bottom: 2.5rem;" id="migrations">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
        <div>
          <h2 style="font-family:var(--font-head); font-size:1.3rem; font-weight:800; margin:0;">
            🗄️ Database
            <?php if ($migration_result['applied'] > 0): ?>
            <span style="font-size:0.7rem; background:rgba(52,199,89,0.2); color:#34C759; padding:0.15rem 0.5rem; border-radius:99px; vertical-align:middle; font-weight:700; margin-left:0.5rem;">
              +<?= $migration_result['applied'] ?> nuove
            </span>
            <?php endif; ?>
          </h2>
          <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.25rem;">
            Le migrazioni vengono eseguite automaticamente ad ogni apertura di questa pagina.
          </div>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="runMigrations()" style="color:var(--cyan);">&#9654; Esegui Ora</button>
      </div>

      <div class="card" style="padding:1rem;">
        <?php foreach ($migration_result['log'] as $line): ?>
        <div style="display:flex; align-items:center; gap:0.75rem; padding:0.5rem 0.25rem; border-bottom:1px solid rgba(255,255,255,0.04); font-size:0.82rem;">
          <span style="flex-shrink:0; font-size:1rem;">
            <?php echo match($line['status']) { 'ok' => '✅', 'skip' => '⏭️', 'error' => '❌' }; ?>
          </span>
          <div style="flex:1;">
            <span><?= htmlspecialchars($line['description']) ?></span>
            <span style="color:var(--text-muted); font-size:0.72rem; margin-left:0.5rem; font-family:monospace;">
              <?= htmlspecialchars($line['version']) ?>
            </span>
            <?php if (!empty($line['error'])): ?>
            <div style="color:var(--rose); font-size:0.75rem; margin-top:0.2rem;"><?= htmlspecialchars($line['error']) ?></div>
            <?php endif; ?>
          </div>
          <span class="badge <?php echo match($line['status']) { 'ok' => 'badge-on', 'skip' => 'badge-auto', 'error' => 'badge-off' }; ?>">
            <?= $line['status'] === 'ok' ? 'applicata' : ($line['status'] === 'skip' ? 'già presente' : 'errore') ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ═══ ARTISTI SEED ══════════════════════════════════════════════════════ -->
    <div style="margin-bottom: 2.5rem;">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
        <div>
          <h2 style="font-family:var(--font-head); font-size:1.3rem; font-weight:800; margin:0;">🎤 Artisti Seed</h2>
          <?php if ($last_log): ?>
          <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.25rem;">
            Ultimo refresh: <strong><?php echo date('d/m/Y H:i', $last_log['ran_at']); ?></strong>
            — +<?php echo $last_log['artists_added']; ?> aggiunti, <?php echo $last_log['artists_updated']; ?> aggiornati
            (<?php echo $last_log['service_name'] ?? 'admin'; ?>)
          </div>
          <?php else: ?>
          <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.25rem;">Nessun refresh ancora eseguito.</div>
          <?php endif; ?>
        </div>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
          <button class="btn btn-ghost btn-sm" onclick="addSeedArtist()" style="color:var(--gold);">+ Aggiungi Artista</button>
          <button class="btn btn-ghost btn-sm" onclick="triggerManualRefresh()" style="color:var(--cyan);">🔄 Aggiorna Ora</button>
        </div>
      </div>

      <!-- Tab fasce d'età -->
      <div style="display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1rem;" id="seed-tabs">
        <?php foreach ($age_groups_list as $i => $grp): ?>
        <button class="tab-btn <?php echo $i===0?'active':''; ?>" onclick="showSeedGroup('<?php echo $grp; ?>', this)"><?php echo $grp; ?></button>
        <?php endforeach; ?>
      </div>

      <!-- Tabelle seed per fascia -->
      <?php foreach ($age_groups_list as $i => $grp):
            $grp_seeds = $seeds_by_group[$grp] ?? [];
      ?>
      <div id="seed-group-<?php echo $grp; ?>" class="card" style="padding:1rem; <?php echo $i>0?'display:none;':''; ?>">
        <?php if (empty($grp_seeds)): ?>
          <p class="muted" style="text-align:center; padding:1rem 0; font-size:0.85rem;">
            Nessun artista seed per questa fascia. Esegui un refresh automatico o aggiungine uno manualmente.
          </p>
        <?php else: ?>
        <table class="seed-table">
          <thead>
            <tr>
              <th>Artista</th>
              <th>Genere</th>
              <th>Pop.</th>
              <th>Fonte</th>
              <th>Stato</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($grp_seeds as $s): ?>
            <tr id="seed-row-<?php echo $s['id']; ?>">
              <td style="font-weight:600;"><?php echo htmlspecialchars($s['artist_name']); ?></td>
              <td style="color:var(--text-muted);"><?php echo htmlspecialchars($s['genre'] ?? '—'); ?></td>
              <td style="color:var(--text-muted);"><?php echo $s['popularity']; ?></td>
              <td><span class="badge <?php echo $s['source']==='admin'?'badge-admin':'badge-auto'; ?>">
                <?php echo $s['source']==='admin'?'👤 admin':'🤖 auto'; ?></span></td>
              <td>
                <span class="badge <?php echo $s['active']?'badge-on':'badge-off'; ?>">
                  <?php echo $s['active']?'on':'off'; ?></span>
              </td>
              <td style="text-align:right;">
                <button class="copy-btn" onclick="toggleSeed(<?php echo $s['id']; ?>, <?php echo $s['active']?0:1; ?>)">
                  <?php echo $s['active']?'Disabilita':'Abilita'; ?></button>
                <?php if ($s['source']==='admin'): ?>
                <button class="copy-btn" style="color:var(--rose);margin-left:0.25rem;" onclick="deleteSeed(<?php echo $s['id']; ?>)">✕</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ═══ SERVIZI CRON / MONITORAGGIO ══════════════════════════════════════ -->
    <div style="margin-bottom: 3rem;">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:1rem; flex-wrap:wrap; gap:0.75rem;">
        <div>
          <h2 style="font-family:var(--font-head); font-size:1.3rem; font-weight:800; margin:0;">📡 Servizi di Monitoraggio</h2>
          <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.25rem;">
            Ogni servizio ha il proprio token. Configura l'URL su UptimeRobot, Freshping, ecc.
          </div>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="addCronService()" style="color:var(--cyan);">+ Nuovo Servizio</button>
      </div>

      <?php if (empty($services)): ?>
      <div class="card" style="padding:1.5rem; text-align:center;">
        <p class="muted" style="font-size:0.85rem; margin-bottom:1rem;">Nessun servizio configurato. Aggiungi il primo per abilitare il refresh automatico.</p>
        <button class="btn btn-ghost btn-sm" onclick="addCronService()" style="color:var(--cyan);">+ Aggiungi Servizio</button>
      </div>
      <?php else: ?>
      <?php foreach ($services as $svc): ?>
      <div class="card" style="padding:1.25rem; margin-bottom:0.75rem;" id="svc-card-<?php echo $svc['id']; ?>">
        <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
          <div style="flex:1; min-width:0;">
            <div style="display:flex; align-items:center; gap:0.5rem; margin-bottom:0.5rem; flex-wrap:wrap;">
              <strong style="font-size:1rem;"><?php echo htmlspecialchars($svc['service_name']); ?></strong>
              <span class="badge <?php echo $svc['active']?'badge-on':'badge-off'; ?>">
                <?php echo $svc['active']?'attivo':'disattivato'; ?></span>
            </div>
            <?php if ($svc['description']): ?>
            <div style="font-size:0.8rem; color:var(--text-muted); margin-bottom:0.75rem;"><?php echo htmlspecialchars($svc['description']); ?></div>
            <?php endif; ?>

            <!-- URL da usare su UptimeRobot -->
            <div style="margin-bottom:0.6rem;">
              <div style="font-size:0.7rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.3rem;">URL da incollare in UptimeRobot</div>
              <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                <span class="token-pill" id="url-<?php echo $svc['id']; ?>"><?php echo htmlspecialchars($cron_endpoint . '?token=' . $svc['token']); ?></span>
                <button class="copy-btn" onclick="copyText('url-<?php echo $svc['id']; ?>')">📋 Copia</button>
              </div>
            </div>

            <!-- Token -->
            <div>
              <div style="font-size:0.7rem; color:var(--text-muted); font-weight:700; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.3rem;">Token</div>
              <div style="display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;">
                <span class="token-pill" id="tok-<?php echo $svc['id']; ?>"><?php echo htmlspecialchars($svc['token']); ?></span>
                <button class="copy-btn" onclick="copyText('tok-<?php echo $svc['id']; ?>')">📋 Copia</button>
              </div>
            </div>

            <?php if ($svc['last_called_at']): ?>
            <div style="font-size:0.75rem; color:var(--text-muted); margin-top:0.6rem;">
              Ultima chiamata: <?php echo date('d/m/Y H:i', $svc['last_called_at']); ?>
            </div>
            <?php endif; ?>
          </div>

          <div style="display:flex; flex-direction:column; gap:0.4rem; flex-shrink:0;">
            <button class="copy-btn" onclick="toggleService(<?php echo $svc['id']; ?>, <?php echo $svc['active']?0:1; ?>)">
              <?php echo $svc['active']?'Disabilita':'Abilita'; ?></button>
            <button class="copy-btn" onclick="regenServiceToken(<?php echo $svc['id']; ?>)">🔑 Rigenera Token</button>
            <button class="copy-btn" style="color:var(--rose);" onclick="deleteService(<?php echo $svc['id']; ?>)">✕ Elimina</button>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div>

  <div id="toast"></div>

  <script src="assets/js/base.js"></script>
  <script>
    // ─── Utility ─────────────────────────────────────────────────────────────
    function copyText(elemId) {
      const txt = document.getElementById(elemId).textContent;
      navigator.clipboard.writeText(txt).then(() => showToast('Copiato!'));
    }

    async function adminApi(action, body = {}) {
      const r = await fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action, ...body }),
      });
      return r.json();
    }

    // ─── Config Form ─────────────────────────────────────────────────────────
    function addAgeGroup() {
      const c = document.getElementById('age-groups-container');
      const d = document.createElement('div');
      d.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr;gap:0.5rem;margin-bottom:0.5rem;';
      d.innerHTML = `<input type="text" name="age_label[]" placeholder="Nuova etichetta"/>
                     <input type="number" name="age_min[]" placeholder="Min"/>
                     <input type="number" name="age_max[]" placeholder="Max"/>`;
      c.appendChild(d);
    }

    async function runMigrations() {
      Swal.fire({ title:'Migrazione in corso…', didOpen:()=>Swal.showLoading() });
      const res = await adminApi('run_migrations');
      Swal.close();
      if (res.applied !== undefined) {
        showToast(`Completato: ${res.applied} applicate, ${res.skipped} già presenti`);
        setTimeout(()=>location.reload(),1000);
      } else showToast(res.error || 'Errore', true);
    }

    async function reset2FA() {
      const res = await Swal.fire({ title:'Sei sicuro?', text:'Il QR code attuale non funzionerà più.', icon:'warning', showCancelButton:true });
      if (res.isConfirmed) {
        await adminApi('reset_2fa');
        window.location.href = 'index.php';
      }
    }

    // ─── Seed Tabs ───────────────────────────────────────────────────────────
    function showSeedGroup(grp, btn) {
      document.querySelectorAll('[id^="seed-group-"]').forEach(el => el.style.display = 'none');
      document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      document.getElementById('seed-group-' + grp).style.display = 'block';
      btn.classList.add('active');
    }

    // ─── Seed Actions ────────────────────────────────────────────────────────
    async function addSeedArtist() {
      const { value: artistName } = await Swal.fire({
        title: 'Aggiungi Artista',
        input: 'text',
        inputPlaceholder: 'Nome artista (es. Ghali)',
        showCancelButton: true,
      });
      if (!artistName) return;

      const { value: ageGroup } = await Swal.fire({
        title: 'Fascia d\'Età',
        input: 'select',
        inputOptions: { '8-11':'8-11', '12-14':'12-14', '14-17':'14-17', '18-22':'18-22', '23+':'23+' },
        showCancelButton: true,
      });
      if (!ageGroup) return;

      Swal.fire({ title:'Ricerca in corso…', didOpen: () => Swal.showLoading() });
      const res = await adminApi('seed_add', { artist_name: artistName, age_group: ageGroup });
      Swal.close();

      if (res.status === 'ok') {
        showToast('Artista aggiunto: ' + res.artist_name);
        setTimeout(() => location.reload(), 1000);
      } else {
        showToast(res.error || 'Errore durante la ricerca.', true);
      }
    }

    async function toggleSeed(id, newActive) {
      const res = await adminApi('seed_toggle', { id, active: newActive });
      if (res.status === 'ok') location.reload();
      else showToast(res.error, true);
    }

    async function deleteSeed(id) {
      const conf = await Swal.fire({ title:'Eliminare?', icon:'warning', showCancelButton:true });
      if (!conf.isConfirmed) return;
      const res = await adminApi('seed_delete', { id });
      if (res.status === 'ok') { document.getElementById('seed-row-' + id)?.remove(); showToast('Eliminato.'); }
      else showToast(res.error, true);
    }

    async function triggerManualRefresh() {
      const conf = await Swal.fire({ title:'Avviare refresh artisti?', text:'Potrebbe richiedere 1-2 minuti. La cache canzoni verrà invalidata.', icon:'question', showCancelButton:true });
      if (!conf.isConfirmed) return;
      Swal.fire({ title:'Refresh in corso…', text:'Attendere prego.', didOpen:()=>Swal.showLoading() });
      const res = await adminApi('seeds_refresh');
      Swal.close();
      if (res.status === 'ok') {
        showToast(`Completato: +${res.totals?.added||0} aggiunti, ${res.totals?.updated||0} aggiornati`);
        setTimeout(()=>location.reload(), 1500);
      } else {
        showToast(res.error || 'Errore durante il refresh.', true);
      }
    }

    // ─── Service Actions ─────────────────────────────────────────────────────
    async function addCronService() {
      const { value: name } = await Swal.fire({ title:'Nome Servizio', input:'text', inputPlaceholder:'Es: UptimeRobot', showCancelButton:true });
      if (!name) return;
      const { value: desc } = await Swal.fire({ title:'Descrizione (opzionale)', input:'text', inputPlaceholder:'Note...', showCancelButton:true, inputAttributes:{required:false} });
      const res = await adminApi('service_add', { service_name: name, description: desc || '' });
      if (res.status === 'ok') { showToast('Servizio creato!'); setTimeout(()=>location.reload(),800); }
      else showToast(res.error, true);
    }

    async function toggleService(id, newActive) {
      const res = await adminApi('service_toggle', { id, active: newActive });
      if (res.status === 'ok') location.reload();
      else showToast(res.error, true);
    }

    async function regenServiceToken(id) {
      const conf = await Swal.fire({ title:'Rigenerare token?', text:'Il vecchio token non funzionerà più. Dovrai aggiornare l\'URL su UptimeRobot.', icon:'warning', showCancelButton:true });
      if (!conf.isConfirmed) return;
      const res = await adminApi('service_regen_token', { id });
      if (res.status === 'ok') { showToast('Token rigenerato!'); setTimeout(()=>location.reload(),800); }
      else showToast(res.error, true);
    }

    async function deleteService(id) {
      const conf = await Swal.fire({ title:'Eliminare il servizio?', icon:'warning', showCancelButton:true });
      if (!conf.isConfirmed) return;
      const res = await adminApi('service_delete', { id });
      if (res.status === 'ok') { document.getElementById('svc-card-' + id)?.remove(); showToast('Servizio eliminato.'); }
      else showToast(res.error, true);
    }
  </script>
</body>
</html>
