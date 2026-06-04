<?php
session_start();
if (empty($_SESSION['is_admin'])) {
    header('Location: index.php');
    exit;
}

require_once __DIR__ . '/includes/config.php';
$config = load_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['admin_password'] = $_POST['admin_password'] ?? '';
    $config['spotify']['client_id'] = $_POST['spotify_id'] ?? '';
    $config['spotify']['client_secret'] = $_POST['spotify_secret'] ?? '';
    
    $labels = $_POST['age_label'] ?? [];
    $mins = $_POST['age_min'] ?? [];
    $maxs = $_POST['age_max'] ?? [];
    
    $new_groups = [];
    foreach ($labels as $i => $label) {
        if ($label) {
            $new_groups[] = [
                "id" => (string)$i,
                "label" => $label,
                "min_age" => (int)($mins[$i] ?? 0),
                "max_age" => (int)($maxs[$i] ?? 0)
            ];
        }
    }
    $config['age_groups'] = $new_groups;
    
    // Stands info is typically managed internally for consistency, but we could allow edits.
    // For now we rely on the internal get_default_stands() overriding it in load_config() to prevent breaking the game logic,
    // as per python code: conf["stands_info"] = default_stands
    // But we will save it anyway in case we decide to enable it later.
    save_config($config);
    header('Location: admin.php');
    exit;
}
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

    <form method="POST">
      <!-- SICUREZZA -->
      <div class="card" style="padding: 1.5rem; border-color: var(--rose); background: rgba(255,69,58,0.02);">
        <h2 style="font-size: 1.1rem; color: var(--rose); margin-bottom: 1rem;">🔐 Sicurezza</h2>
        <div style="margin-bottom: 1rem;">
          <label class="muted" style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; display: block;">PASSWORD MASTER</label>
          <input type="text" name="admin_password" value="<?php echo htmlspecialchars($config['admin_password']); ?>" required />
        </div>
        <button type="button" class="btn btn-ghost btn-sm btn-full" onclick="reset2FA()" style="color: var(--rose);">Reset 2FA (Richiederà nuovo setup)</button>
      </div>

      <!-- SPOTIFY -->
      <div class="card" style="padding: 1.5rem; border-color: #1DB954; background: rgba(29, 185, 84, 0.03);">
        <h2 style="font-size: 1.1rem; color: #1DB954; margin-bottom: 1rem;">🎵 Spotify API</h2>
        <p class="muted" style="font-size: 0.85rem; margin-bottom: 1rem;">Ottieni queste chiavi creando un'app su <a href="https://developer.spotify.com/dashboard" target="_blank" style="color:#1DB954;">Spotify Developer</a>.</p>
        
        <div style="margin-bottom: 1rem;">
          <label class="muted" style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; display: block;">CLIENT ID</label>
          <input type="text" name="spotify_id" value="<?php echo htmlspecialchars($config['spotify']['client_id']); ?>" />
        </div>
        <div style="margin-bottom: 1rem;">
          <label class="muted" style="font-size: 0.8rem; font-weight: 700; margin-bottom: 0.5rem; display: block;">CLIENT SECRET</label>
          <input type="password" name="spotify_secret" value="<?php echo htmlspecialchars($config['spotify']['client_secret']); ?>" />
        </div>
      </div>

      <!-- FASCE D'ETÀ -->
      <div class="card" style="padding: 1.5rem; border-color: var(--cyan); background: rgba(100, 210, 255, 0.03);">
        <h2 style="font-size: 1.1rem; color: var(--cyan); margin-bottom: 1rem;">👶 Fasce d'Età</h2>
        <div id="age-groups-container">
          <?php foreach ($config['age_groups'] as $g): ?>
          <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;">
            <input type="text" name="age_label[]" value="<?php echo htmlspecialchars($g['label']); ?>" placeholder="Etichetta" />
            <input type="number" name="age_min[]" value="<?php echo htmlspecialchars($g['min_age']); ?>" placeholder="Min" />
            <input type="number" name="age_max[]" value="<?php echo htmlspecialchars($g['max_age']); ?>" placeholder="Max" />
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-ghost btn-sm" onclick="addAgeGroup()" style="margin-top: 1rem; color: var(--cyan);">+ Aggiungi Fascia</button>
      </div>

      <button type="submit" class="btn btn-primary btn-full" style="height: 60px; font-size: 1.1rem; margin-bottom: 2rem;">💾 Salva Configurazioni</button>
    </form>
  </div>

  <div id="toast"></div>

  <script src="assets/js/base.js"></script>
  <script>
    function addAgeGroup() {
      const container = document.getElementById('age-groups-container');
      const div = document.createElement('div');
      div.style.cssText = 'display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 0.5rem; margin-bottom: 0.5rem;';
      div.innerHTML = `
        <input type="text" name="age_label[]" placeholder="Nuova etichetta" />
        <input type="number" name="age_min[]" placeholder="Min" />
        <input type="number" name="age_max[]" placeholder="Max" />
      `;
      container.appendChild(div);
    }

    async function reset2FA() {
      const res = await Swal.fire({
        title: 'Sei sicuro?',
        text: 'Il QR code attuale non funzionerà più.',
        icon: 'warning',
        showCancelButton: true
      });
      
      if (res.isConfirmed) {
        await fetch('api.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({action: 'reset_2fa'})
        });
        window.location.href = 'index.php';
      }
    }
  </script>
</body>
</html>
