// assets/js/base.js
function showToast(msg, isError = false) {
    const t = document.getElementById('toast');
    t.innerHTML = (isError ? '⚠️ ' : '✅ ') + msg;
    t.className = isError ? 'error show' : 'show';
    setTimeout(() => t.className = isError ? 'error' : '', 3500);
}

function esc(s) {
    return s ? s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])) : '';
}

function isIOS() {
    return ['iPad', 'iPhone', 'iPod'].includes(navigator.platform) || (navigator.userAgent.includes("Mac") && "ontouchend" in document);
}

function showTutorial() {
    const overlay = document.getElementById('tutorial-modal');
    const content = document.getElementById('tutorial-content');
    overlay.style.display = 'grid';
    if (isIOS()) {
        content.innerHTML = `<p class="muted" style="margin-bottom: 1rem;">Per iPhone e iPad:</p><div style="display:flex; gap:1rem; margin-bottom:1rem;"><div style="font-weight:800; color:var(--accent)">1.</div><div>Tocca il tasto <strong>Condividi</strong> in Safari.</div></div><div style="display:flex; gap:1rem;"><div style="font-weight:800; color:var(--accent)">2.</div><div>Scorri e scegli <strong>Aggiungi alla Home</strong>.</div></div>`;
    } else {
        content.innerHTML = `<p class="muted" style="margin-bottom: 1rem;">Per Android / Chrome:</p><div style="display:flex; gap:1rem; margin-bottom:1rem;"><div style="font-weight:800; color:var(--accent)">1.</div><div>Tocca i <strong>tre puntini</strong>.</div></div><div style="display:flex; gap:1rem;"><div>Seleziona <strong>Installa app</strong>.</div></div>`;
    }
}
function closeTutorial() { document.getElementById('tutorial-modal').style.display = 'none'; }

// ── ADMIN TOTP LOGIC ──
async function showAdminModal() {
    const res = await fetch('api.php?action=admin_check');
    const data = await res.json();
    resetAdminViews();
    if (data.needs_setup) { showPasswordView(); } else { showLoginView(); }
    document.getElementById('admin-modal').style.display = 'grid';
}

function resetAdminViews() {
    ['admin-pwd-view', 'admin-setup-view', 'admin-login-view'].forEach(id => {
        document.getElementById(id).style.display = 'none';
    });
    document.getElementById('qrcode').innerHTML = "";
}

function showPasswordView() { resetAdminViews(); document.getElementById('admin-pwd-view').style.display = 'block'; }

async function proceedToSetup() {
    const pwd = document.getElementById('admin-master-pwd').value;
    const res = await fetch('api.php?action=admin_setup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ password: pwd })
    });
    const data = await res.json();
    if (data.error) {
        showToast(data.error, true);
    } else {
        resetAdminViews();
        document.getElementById('admin-setup-view').style.display = 'block';
        new QRCode(document.getElementById("qrcode"), {
            text: data.uri,
            width: 200,
            height: 200,
            colorDark: "#000000",
            colorLight: "#ffffff",
            correctLevel: QRCode.CorrectLevel.H
        });
    }
}

function showLoginView() { resetAdminViews(); document.getElementById('admin-login-view').style.display = 'block'; }
function closeAdminModal() { document.getElementById('admin-modal').style.display = 'none'; }

async function verifyTOTP() {
    const code = document.getElementById('totp-code').value;
    if (code.length < 6) return;
    const res = await fetch('api.php?action=admin_verify', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code })
    });
    const data = await res.json();
    if (data.ok) {
        window.location.href = 'admin.php';
    } else {
        showToast(data.error, true);
    }
}

// ── POLLING ENGINE (Replaces Socket.IO) ──
class PollingEngine {
    constructor(code, onUpdate, intervalMs = 2500) {
        this.code = code;
        this.onUpdate = onUpdate;
        this.intervalMs = intervalMs;
        this.lastUpdate = 0;
        this.timerId = null;
        this.isActive = false;
    }

    start() {
        if (this.isActive) return;
        this.isActive = true;
        this.poll();
        this.timerId = setInterval(() => this.poll(), this.intervalMs);
    }

    stop() {
        this.isActive = false;
        if (this.timerId) clearInterval(this.timerId);
    }

    async poll() {
        if (!this.isActive) return;
        try {
            const res = await fetch(`api.php?action=session&code=${this.code}`);
            if (res.status === 404) {
                // Session deleted
                this.stop();
                if (typeof onSessionEnded === 'function') onSessionEnded();
                else window.location.href = 'index.php';
                return;
            }
            if (!res.ok) return;
            const data = await res.json();
            if (data.updated_at && data.updated_at !== this.lastUpdate) {
                this.lastUpdate = data.updated_at;
                this.onUpdate(data);
            }
        } catch (e) {
            console.error("Polling error:", e);
        }
    }
}
