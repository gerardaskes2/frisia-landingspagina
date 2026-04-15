<?php
// ── Frisia CMS – Beheerpagina ──
session_start();

// ── Configuratie ──
// Standaardwachtwoord: Frisia2024!
// De hash wordt bij de eerste inlog aangemaakt en opgeslagen in cms-data/admin-hash.php
// Wil je het wachtwoord wijzigen? Verwijder cms-data/admin-hash.php en pas $DEFAULT_PASSWORD aan.
define('DEFAULT_PASSWORD',  'Frisia2024!');
define('RATE_LIMIT_FILE',   __DIR__ . '/cms-data/rate-limit.json');
define('ADMIN_HASH_FILE',   __DIR__ . '/cms-data/admin-hash.php');
define('MAX_ATTEMPTS', 5);
define('RATE_WINDOW',  900); // 15 minuten in seconden

// ── Hulpfuncties ──

function getRateLimitData(): array {
    if (!file_exists(RATE_LIMIT_FILE)) return [];
    $data = json_decode(file_get_contents(RATE_LIMIT_FILE), true);
    return is_array($data) ? $data : [];
}

function saveRateLimitData(array $data): void {
    file_put_contents(RATE_LIMIT_FILE, json_encode($data));
}

function isRateLimited(string $ip): bool {
    $data = getRateLimitData();
    if (!isset($data[$ip])) return false;
    $entry = $data[$ip];
    // Verwijder verlopen window
    if (time() - $entry['first'] > RATE_WINDOW) return false;
    return $entry['count'] >= MAX_ATTEMPTS;
}

function recordFailedAttempt(string $ip): void {
    $data = getRateLimitData();
    if (!isset($data[$ip]) || time() - $data[$ip]['first'] > RATE_WINDOW) {
        $data[$ip] = ['count' => 1, 'first' => time()];
    } else {
        $data[$ip]['count']++;
    }
    saveRateLimitData($data);
}

function clearRateLimit(string $ip): void {
    $data = getRateLimitData();
    unset($data[$ip]);
    saveRateLimitData($data);
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

// ── Uitloggen ──
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: beheer.php');
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$error = '';
$loggedIn = isset($_SESSION['cms_auth']) && $_SESSION['cms_auth'] === true;

// ── Inloggen verwerken ──
if (!$loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (isRateLimited($ip)) {
        $error = 'Te veel pogingen, probeer het over 15 minuten opnieuw.';
    } else {
        // Laad opgeslagen hash of maak deze aan bij eerste gebruik
        if (file_exists(ADMIN_HASH_FILE)) {
            include ADMIN_HASH_FILE; // definieert $storedHash
        } else {
            $storedHash = password_hash(DEFAULT_PASSWORD, PASSWORD_BCRYPT);
            file_put_contents(ADMIN_HASH_FILE, '<?php $storedHash = ' . var_export($storedHash, true) . ';');
        }

        if (isset($storedHash) && password_verify($_POST['password'], $storedHash)) {
            clearRateLimit($ip);
            session_regenerate_id(true);
            $_SESSION['cms_auth'] = true;
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            $loggedIn = true;
        } else {
            recordFailedAttempt($ip);
            $error = 'Ongeldig wachtwoord. Probeer het opnieuw.';
        }
    }
}

// ── Huidige instellingen laden voor dashboard ──
$currentMailTo = 'gerardaskes@gmail.com';
if ($loggedIn) {
    $settingsFile = __DIR__ . '/cms-data/settings.json';
    if (file_exists($settingsFile)) {
        $s = json_decode(file_get_contents($settingsFile), true);
        $currentMailTo = $s['mail_to'] ?? $currentMailTo;
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Beheer – Frisia Pensioen Advies</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;600&family=Open+Sans:wght@400;600&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
    :root {
      --navy: #103366;
      --blue: #3299cc;
      --lime: #d6e264;
      --cream: #ecece4;
    }
    body {
      font-family: 'Open Sans', sans-serif;
      background: var(--cream);
      color: #333;
      min-height: 100vh;
    }

    /* ── LOGIN ── */
    .login-wrap {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background: linear-gradient(135deg, var(--navy) 0%, #1a4a8a 50%, var(--blue) 100%);
    }
    .login-card {
      background: #fff;
      border-radius: 20px;
      padding: 48px 40px;
      max-width: 420px;
      width: 100%;
      box-shadow: 0 24px 64px rgba(0,0,0,0.2);
    }
    .login-logo {
      text-align: center;
      margin-bottom: 32px;
    }
    .login-logo img {
      height: 56px;
      width: auto;
    }
    .login-title {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
      font-size: 22px;
      color: var(--navy);
      text-align: center;
      margin-bottom: 8px;
    }
    .login-subtitle {
      font-size: 14px;
      color: #888;
      text-align: center;
      margin-bottom: 32px;
    }
    .form-group {
      margin-bottom: 20px;
    }
    .form-group label {
      display: block;
      font-size: 12px;
      font-weight: 600;
      color: var(--navy);
      letter-spacing: 0.5px;
      text-transform: uppercase;
      margin-bottom: 6px;
    }
    .form-group input {
      width: 100%;
      padding: 12px 16px;
      border: 1.5px solid #e0e0e0;
      border-radius: 10px;
      font-size: 15px;
      font-family: inherit;
      transition: border-color 0.2s, box-shadow 0.2s;
      outline: none;
    }
    .form-group input:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(50,153,204,0.12);
    }
    .btn-login {
      width: 100%;
      background: var(--navy);
      color: #fff;
      border: none;
      border-radius: 10px;
      padding: 14px;
      font-size: 15px;
      font-weight: 600;
      font-family: 'Poppins', sans-serif;
      cursor: pointer;
      transition: background 0.2s, transform 0.15s;
      margin-top: 8px;
    }
    .btn-login:hover {
      background: #0c2850;
      transform: translateY(-1px);
    }
    .login-error {
      background: #fce4ec;
      color: #c62828;
      border-radius: 8px;
      padding: 10px 14px;
      font-size: 13px;
      margin-bottom: 16px;
    }

    /* ── DASHBOARD ── */
    .dashboard {
      max-width: 960px;
      margin: 0 auto;
      padding: 40px 24px 80px;
    }
    .dash-header {
      background: var(--navy);
      color: #fff;
      padding: 0;
      margin-bottom: 0;
    }
    .dash-header-inner {
      max-width: 960px;
      margin: 0 auto;
      padding: 20px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .dash-header img {
      height: 40px;
      filter: brightness(0) invert(1);
    }
    .dash-header-right {
      display: flex;
      align-items: center;
      gap: 16px;
    }
    .dash-header-right a {
      color: rgba(255,255,255,0.7);
      text-decoration: none;
      font-size: 13px;
      transition: color 0.2s;
    }
    .dash-header-right a:hover { color: var(--lime); }
    .dash-title {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
      font-size: 26px;
      color: var(--navy);
      margin-bottom: 6px;
      margin-top: 36px;
    }
    .dash-welcome {
      color: #666;
      font-size: 14px;
      margin-bottom: 36px;
    }
    .dash-section {
      background: #fff;
      border-radius: 16px;
      padding: 28px 32px;
      margin-bottom: 24px;
      box-shadow: 0 2px 16px rgba(16,51,102,0.07);
    }
    .dash-section h2 {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
      font-size: 16px;
      color: var(--navy);
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid #eee;
    }
    .btn-edit-site {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: var(--navy);
      color: #fff;
      text-decoration: none;
      border-radius: 10px;
      padding: 14px 28px;
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
      font-size: 14px;
      transition: background 0.2s, transform 0.15s;
    }
    .btn-edit-site:hover {
      background: #0c2850;
      transform: translateY(-1px);
    }
    .btn-edit-site-desc {
      font-size: 13px;
      color: #888;
      margin-top: 10px;
    }

    /* Instellingen */
    .settings-row {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }
    .settings-row input {
      flex: 1;
      min-width: 200px;
      padding: 10px 14px;
      border: 1.5px solid #e0e0e0;
      border-radius: 8px;
      font-size: 14px;
      font-family: inherit;
      outline: none;
      transition: border-color 0.2s;
    }
    .settings-row input:focus {
      border-color: var(--blue);
    }
    .btn-save {
      background: var(--navy);
      color: #fff;
      border: none;
      border-radius: 8px;
      padding: 10px 22px;
      font-size: 14px;
      font-family: inherit;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.2s;
      white-space: nowrap;
    }
    .btn-save:hover { background: #0c2850; }
    .save-msg {
      font-size: 13px;
      padding: 6px 12px;
      border-radius: 6px;
      display: none;
    }
    .save-msg.ok { background: #e8f5e9; color: #2e7d32; display: inline-block; }
    .save-msg.err { background: #fce4ec; color: #c62828; display: inline-block; }

    /* Formuliervelden */
    .field-item {
      display: flex;
      align-items: center;
      gap: 12px;
      background: #f7f8fc;
      border-radius: 10px;
      padding: 12px 16px;
      margin-bottom: 10px;
    }
    .field-item-info { flex: 1; }
    .field-item-label { font-weight: 600; font-size: 14px; color: #333; }
    .field-item-meta { font-size: 12px; color: #888; margin-top: 2px; }
    .btn-sm {
      background: #fff;
      border: 1px solid #ddd;
      border-radius: 6px;
      padding: 5px 12px;
      font-size: 12px;
      cursor: pointer;
      font-family: inherit;
      transition: background 0.15s;
    }
    .btn-sm:hover { background: #f0f0f0; }
    .btn-sm.danger { border-color: #ffcdd2; color: #c62828; }
    .btn-sm.danger:hover { background: #fce4ec; }
    .btn-add-field {
      background: none;
      border: 2px dashed #ccc;
      border-radius: 8px;
      padding: 10px 20px;
      font-size: 13px;
      color: #888;
      cursor: pointer;
      font-family: inherit;
      width: 100%;
      transition: border-color 0.2s, color 0.2s;
      margin-top: 4px;
    }
    .btn-add-field:hover { border-color: var(--blue); color: var(--blue); }
    .form-save-row {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-top: 16px;
    }

    /* Field editor modal */
    .modal-bg {
      position: fixed; inset: 0;
      background: rgba(0,0,0,0.45);
      z-index: 1000;
      display: flex; align-items: center; justify-content: center;
      padding: 20px;
    }
    .modal {
      background: #fff;
      border-radius: 16px;
      padding: 32px;
      max-width: 480px;
      width: 100%;
      box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }
    .modal h3 {
      font-family: 'Poppins', sans-serif;
      color: var(--navy);
      font-size: 17px;
      margin-bottom: 20px;
    }
    .modal .form-group { margin-bottom: 14px; }
    .modal .form-group label {
      display: block; font-size: 12px; font-weight: 600; color: var(--navy);
      text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px;
    }
    .modal .form-group input,
    .modal .form-group select {
      width: 100%; padding: 9px 13px; border: 1.5px solid #e0e0e0;
      border-radius: 8px; font-size: 14px; font-family: inherit; outline: none;
    }
    .modal .form-group input:focus,
    .modal .form-group select:focus { border-color: var(--blue); }
    .modal-footer { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }
    .btn-cancel {
      background: #f0f0f0; color: #555; border: none; border-radius: 8px;
      padding: 9px 20px; font-size: 13px; cursor: pointer; font-family: inherit;
    }
    .btn-cancel:hover { background: #e0e0e0; }

    @media (max-width: 600px) {
      .login-card { padding: 32px 24px; }
      .dash-section { padding: 20px; }
    }
  </style>
</head>
<body>

<?php if (!$loggedIn): ?>
<!-- ── LOGINSCHERM ── -->
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <img src="img/Frisia logo.svg" alt="Frisia Pensioen Advies" onerror="this.style.display='none'">
    </div>
    <div class="login-title">Website beheer</div>
    <div class="login-subtitle">Frisia Pensioen Advies</div>
    <?php if ($error): ?>
      <div class="login-error"><?= h($error) ?></div>
    <?php endif; ?>
    <form method="POST" action="beheer.php" autocomplete="off">
      <div class="form-group">
        <label for="password">Wachtwoord</label>
        <input type="password" id="password" name="password" placeholder="••••••••••" required autofocus>
      </div>
      <button type="submit" class="btn-login">Inloggen</button>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ── DASHBOARD ── -->
<div class="dash-header">
  <div class="dash-header-inner">
    <img src="img/Frisia logo.svg" alt="Frisia" onerror="this.style.display='none'">
    <div class="dash-header-right">
      <a href="index.html" target="_blank">Website bekijken</a>
      <a href="beheer.php?logout=1">Uitloggen</a>
    </div>
  </div>
</div>

<div class="dashboard">
  <div class="dash-title">Dashboard</div>
  <div class="dash-welcome">Welkom terug. Beheer hieronder de inhoud van uw website.</div>

  <!-- Bewerk website -->
  <div class="dash-section">
    <h2>Bewerk website</h2>
    <a href="index.html?cms=edit" target="_blank" class="btn-edit-site">
      ✏️ Website bewerken
    </a>
    <div class="btn-edit-site-desc">Opent de website in een nieuw tabblad met de bewerkingstoolbar.</div>
  </div>

  <!-- Instellingen -->
  <div class="dash-section">
    <h2>Instellingen</h2>
    <p style="font-size:13px;color:#666;margin-bottom:14px;">E-mailadres waarop formulierinzendingen worden ontvangen:</p>
    <div class="settings-row">
      <input type="email" id="mail-to-input" value="<?= h($currentMailTo) ?>" placeholder="naam@voorbeeld.nl">
      <button class="btn-save" onclick="saveSettings()">Opslaan</button>
      <span id="settings-msg" class="save-msg"></span>
    </div>
  </div>

  <!-- Formulier beheer -->
  <div class="dash-section">
    <h2>Formulier beheer</h2>
    <div id="field-list">
      <p style="color:#888;font-size:13px;">Laden...</p>
    </div>
    <button class="btn-add-field" onclick="addField()">+ Veld toevoegen</button>
    <div class="form-save-row">
      <button class="btn-save" onclick="saveForm()">Formulier opslaan</button>
      <span id="form-msg" class="save-msg"></span>
    </div>
  </div>
</div>

<!-- Field editor modal -->
<div id="field-modal" class="modal-bg" style="display:none;">
  <div class="modal">
    <h3>Veld bewerken</h3>
    <input type="hidden" id="edit-idx">
    <div class="form-group">
      <label>Label</label>
      <input type="text" id="fe-label" placeholder="Veldnaam">
    </div>
    <div class="form-group">
      <label>Type</label>
      <select id="fe-type">
        <option value="text">Tekst (text)</option>
        <option value="email">E-mail (email)</option>
        <option value="tel">Telefoon (tel)</option>
        <option value="select">Keuzemenu (select)</option>
        <option value="textarea">Tekstvak (textarea)</option>
      </select>
    </div>
    <div class="form-group">
      <label>Placeholder</label>
      <input type="text" id="fe-placeholder" placeholder="Helptekst in het veld">
    </div>
    <div class="form-group">
      <label>Breedte</label>
      <select id="fe-width">
        <option value="half">Half (naast elkaar)</option>
        <option value="full">Volledig (volle breedte)</option>
      </select>
    </div>
    <div class="form-group">
      <label style="display:flex;align-items:center;gap:8px;text-transform:none;letter-spacing:0;">
        <input type="checkbox" id="fe-required" style="width:auto;padding:0;"> Verplicht veld
      </label>
    </div>
    <div class="modal-footer">
      <button class="btn-cancel" onclick="closeModal()">Annuleren</button>
      <button class="btn-save" onclick="saveFieldEdit()">Opslaan</button>
    </div>
  </div>
</div>

<script>
const CSRF = <?= json_encode($_SESSION['csrf'] ?? '') ?>;
let formFields = [];

// ── Instellingen laden & opslaan ──
async function saveSettings() {
  const mail = document.getElementById('mail-to-input').value.trim();
  const msg = document.getElementById('settings-msg');
  msg.className = 'save-msg';
  try {
    const r = await fetch('cms-api.php?action=save-settings', {
      method: 'POST',
      body: JSON.stringify({ mail_to: mail }),
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }
    });
    const d = await r.json();
    msg.textContent = d.ok ? 'Opgeslagen!' : (d.error || 'Fout bij opslaan');
    msg.className = 'save-msg ' + (d.ok ? 'ok' : 'err');
    if (d.ok) setTimeout(() => msg.className = 'save-msg', 3000);
  } catch(e) {
    msg.textContent = 'Verbindingsfout';
    msg.className = 'save-msg err';
  }
}

// ── Formuliervelden laden ──
async function loadFields() {
  try {
    const r = await fetch('cms-api.php?action=get-form', {
      headers: { 'X-CSRF-Token': CSRF }
    });
    const d = await r.json();
    formFields = d.fields || [];
    renderFieldList();
  } catch(e) {
    document.getElementById('field-list').innerHTML = '<p style="color:#c62828;font-size:13px;">Fout bij laden.</p>';
  }
}

function renderFieldList() {
  const list = document.getElementById('field-list');
  const visible = formFields.filter(f => f.type !== 'honeypot');
  if (visible.length === 0) {
    list.innerHTML = '<p style="color:#888;font-size:13px;">Geen velden gevonden.</p>';
    return;
  }
  list.innerHTML = visible.map((f, i) => `
    <div class="field-item" data-idx="${i}">
      <div class="field-item-info">
        <div class="field-item-label">${escHtml(f.label || '(geen label)')}</div>
        <div class="field-item-meta">${escHtml(f.type)} · ${f.required ? 'Verplicht' : 'Optioneel'} · ${f.width === 'half' ? 'Half breed' : 'Volle breedte'}</div>
      </div>
      <button class="btn-sm" onclick="openEditModal(${i})">Bewerken</button>
      <button class="btn-sm danger" onclick="deleteField(${i})">Verwijderen</button>
    </div>
  `).join('');
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Veld bewerken ──
function openEditModal(idx) {
  const f = formFields[idx];
  document.getElementById('edit-idx').value = idx;
  document.getElementById('fe-label').value = f.label || '';
  document.getElementById('fe-type').value = f.type || 'text';
  document.getElementById('fe-placeholder').value = f.placeholder || '';
  document.getElementById('fe-width').value = f.width || 'full';
  document.getElementById('fe-required').checked = !!f.required;
  document.getElementById('field-modal').style.display = 'flex';
}

function closeModal() {
  document.getElementById('field-modal').style.display = 'none';
}

function saveFieldEdit() {
  const idx = parseInt(document.getElementById('edit-idx').value);
  formFields[idx] = {
    ...formFields[idx],
    label: document.getElementById('fe-label').value,
    type: document.getElementById('fe-type').value,
    placeholder: document.getElementById('fe-placeholder').value,
    width: document.getElementById('fe-width').value,
    required: document.getElementById('fe-required').checked
  };
  closeModal();
  renderFieldList();
}

function deleteField(idx) {
  if (!confirm('Veld "' + (formFields[idx].label || 'onbekend') + '" verwijderen?')) return;
  formFields.splice(idx, 1);
  renderFieldList();
}

function addField() {
  const honeypotIdx = formFields.findIndex(f => f.type === 'honeypot');
  const newField = { id: 'veld_' + Date.now(), label: 'Nieuw veld', type: 'text', placeholder: '', required: false, width: 'full' };
  if (honeypotIdx > -1) formFields.splice(honeypotIdx, 0, newField);
  else formFields.push(newField);
  renderFieldList();
  // Open editor meteen
  const newIdx = formFields.findIndex(f => f.id === newField.id);
  if (newIdx > -1) openEditModal(newIdx);
}

async function saveForm() {
  const msg = document.getElementById('form-msg');
  msg.className = 'save-msg';
  try {
    const r = await fetch('cms-api.php?action=save-form', {
      method: 'POST',
      body: JSON.stringify({ fields: formFields }),
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF }
    });
    const d = await r.json();
    msg.textContent = d.ok ? 'Opgeslagen!' : (d.error || 'Fout bij opslaan');
    msg.className = 'save-msg ' + (d.ok ? 'ok' : 'err');
    if (d.ok) setTimeout(() => msg.className = 'save-msg', 3000);
  } catch(e) {
    msg.textContent = 'Verbindingsfout';
    msg.className = 'save-msg err';
  }
}

// Sluit modal bij klik buiten
document.getElementById('field-modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// Laden bij start
loadFields();
</script>

<?php endif; ?>

</body>
</html>
