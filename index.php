<?php
require_once __DIR__ . '/includes/config.php';
$config = load_config();
$age_groups = $config['age_groups'];

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'join') {
    require_once __DIR__ . '/includes/session_store.php';
    $code = strtoupper(trim($_POST['code'] ?? ''));
    if (session_get($code)) {
        header("Location: stand.php?code=" . urlencode($code));
        exit;
    } else {
        $error = "Codice \"$code\" non trovato.";
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <title>Hitster Camp</title>
  <link rel="manifest" href="manifest.json">
  <meta name="theme-color" content="#007aff">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <link rel="apple-touch-icon" href="assets/img/icon.png">
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
</head>
<body>

  <header class="app-header">
    <a class="app-logo" href="index.php">
      <img src="assets/img/icon.png" alt="Logo" onerror="this.style.display='none'">
      Hitster<span>Camp</span>
    </a>
  </header>

  <div class="container">
    <div class="text-center" style="padding: 2rem 0 3rem;">
      <div style="font-size: 5rem; line-height: 1; margin-bottom: 1.5rem;">🎵</div>
      <h1 style="font-family: var(--font-head); font-size: 3.5rem; font-weight: 800; letter-spacing: -2px; margin-bottom: 0.5rem;">
        Hitster <span style="color: var(--accent);">Camp</span>
      </h1>
      <p class="muted" style="font-size: 1.2rem; max-width: 450px; margin: 0 auto; font-weight: 400;">
        L'esperienza di gioco musicale definitiva per il tuo campo estivo.
      </p>

      <div style="margin-top: 2.5rem; display: flex; gap: 1rem; justify-content: center;">
        <button class="btn btn-ghost btn-sm" onclick="showTutorial()">📱 Guida Installazione</button>
      </div>
    </div>

    <?php if ($error): ?>
    <div class="card" style="background: rgba(244, 63, 94, 0.1); border-color: var(--rose); color: var(--rose); border-radius: 20px;">
      <strong>⚠️</strong> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <div class="card" style="padding: 2.5rem;">
      <h2 style="font-size: 1.5rem; letter-spacing: -0.5px;">🚀 Crea Partita</h2>
      <p class="muted" style="margin-bottom: 2rem;">Scegli la fascia d'età e inizia una nuova sessione.</p>

      <form action="api.php" method="POST" id="create-form">
        <input type="hidden" name="action" value="create">
        <div style="margin-bottom: 1.5rem;">
          <label class="muted" style="display:block; margin-bottom:0.5rem; font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Fascia d'Età</label>
          <select name="age_group" style="height: 55px; font-size: 1.1rem; border-radius: 15px;" required>
            <?php foreach ($age_groups as $group): ?>
            <option value="<?php echo htmlspecialchars($group['id']); ?>"><?php echo htmlspecialchars($group['label']); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <button type="submit" id="create-btn" class="btn btn-primary btn-full" style="height: 60px; font-size: 1.2rem;">
          <span id="create-btn-text">✨ Inizia Gioco</span>
          <span id="create-btn-loading" style="display:none; align-items:center; gap:0.6rem;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation: spin 0.8s linear infinite; flex-shrink:0;">
              <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
            </svg>
            Caricamento canzoni...
          </span>
        </button>
      </form>
      <script>
        document.getElementById('create-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const btn = document.getElementById('create-btn');
            btn.disabled = true;
            btn.style.opacity = '0.75';
            document.getElementById('create-btn-text').style.display = 'none';
            document.getElementById('create-btn-loading').style.display = 'flex';
            
            const formData = new FormData(this);
            const data = Object.fromEntries(formData.entries());
            
            try {
                const res = await fetch('api.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(data)
                });
                const json = await res.json();
                if (json.ok && json.code) {
                    window.location.href = `master.php?code=${json.code}`;
                } else {
                    alert(json.message || "Errore durante la creazione.");
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    document.getElementById('create-btn-text').style.display = 'block';
                    document.getElementById('create-btn-loading').style.display = 'none';
                }
            } catch (err) {
                alert("Errore di rete.");
                btn.disabled = false;
                btn.style.opacity = '1';
                document.getElementById('create-btn-text').style.display = 'block';
                document.getElementById('create-btn-loading').style.display = 'none';
            }
        });
      </script>
    </div>

    <div class="card" style="padding: 2.5rem;">
      <h2 style="font-size: 1.5rem; letter-spacing: -0.5px;">🎯 Unisciti</h2>
      <p class="muted" style="margin-bottom: 2rem;">Inserisci il codice per entrare come animatore.</p>

      <form action="index.php" method="POST">
        <input type="hidden" name="action" value="join">
        <div style="display: flex; flex-direction: column; gap: 1.5rem;">
          <input type="text" name="code" placeholder="ABC123" maxlength="6"
            style="text-transform:uppercase; font-size:2rem; letter-spacing:8px; font-weight:800; text-align:center; height: 80px; border-radius: 20px; background: rgba(255,255,255,0.05);"
            required />
          <button type="submit" class="btn btn-gold btn-full" style="height: 60px; font-size: 1.2rem;">
            Entra ora →
          </button>
        </div>
      </form>
    </div>

    <div style="margin-top: 4rem; text-align: center; opacity: 0.5;">
      <p style="font-size: 0.85rem; font-weight: 500;">
        &copy; 2026 Hitster Camp.
      </p>
      <button class="btn btn-ghost btn-sm" style="border: none; background: transparent; opacity: 0.6;"
        onclick="showAdminModal()">⚙️ Admin Access</button>
    </div>
  </div>

  <div class="modal-overlay" id="tutorial-modal">
    <div class="modal">
      <h2 id="tutorial-title">Installa l'App</h2>
      <div id="tutorial-content" style="text-align: left; margin-bottom: 2rem;"></div>
      <button class="btn btn-primary btn-full" onclick="closeTutorial()">Fine</button>
    </div>
  </div>

  <div class="modal-overlay" id="admin-modal">
    <div class="modal">
      <h2 id="admin-modal-title">🔐 Admin Access</h2>
      <div id="admin-pwd-view" style="display:none; text-align: left;">
        <p class="muted" style="margin-bottom: 1.5rem;">Inserisci la password master per configurare il 2FA.</p>
        <input type="password" id="admin-master-pwd" placeholder="Password..." style="margin-bottom:1.5rem;" />
        <div style="display:flex; gap:1rem;">
          <button class="btn btn-ghost btn-full" onclick="closeAdminModal()">Annulla</button>
          <button class="btn btn-primary btn-full" onclick="proceedToSetup()">Procedi</button>
        </div>
      </div>
      <div id="admin-setup-view" style="display:none; text-align: left;">
        <p class="muted" style="margin-bottom: 1.5rem;">Scansiona questo QR con <strong>Google Authenticator</strong>.</p>
        <div style="text-align:center; background:white; padding:1.5rem; border-radius:20px; margin-bottom:1.5rem;">
          <div id="qrcode" style="display: inline-block;"></div>
        </div>
        <button class="btn btn-primary btn-full" onclick="showLoginView()">L'ho scansionato</button>
      </div>
      <div id="admin-login-view" style="display:none;">
        <p class="muted" style="margin-bottom: 1.5rem;">Inserisci il codice a 6 cifre dall'app.</p>
        <input type="text" id="totp-code" placeholder="000 000" maxlength="6" inputmode="numeric" 
          style="text-align:center; font-size:2rem; letter-spacing:8px; font-weight:800; margin-bottom:1.5rem;" />
        <div style="display:flex; gap:1rem;">
          <button class="btn btn-ghost btn-full" onclick="closeAdminModal()">Esci</button>
          <button class="btn btn-primary btn-full" onclick="verifyTOTP()">Entra</button>
        </div>
      </div>
      <div id="admin-footer-links" style="margin-top: 1.5rem; font-size: 0.8rem;">
        <a href="#" class="muted" onclick="showPasswordView(); return false;">Problemi col codice? Setup Device</a>
      </div>
    </div>
  </div>

  <div id="toast"></div>

  <script src="assets/js/base.js"></script>
</body>
</html>
