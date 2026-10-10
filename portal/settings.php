<?php
/*
 * Portal settings: SMTP (Gmail), the Groq API key and the marketing email template, stored in the `settings` table.
 * GET shows the form, POST saves it. When API_KEY is set, open the page with ?key=<value>.
 * Values are stored in plain text (SMTP needs the real password), so keep the portal private.
 */
require_once __DIR__ . '/db.php';

$key = (string)($_GET['key'] ?? '');
if (API_KEY !== '' && !hash_equals(API_KEY, $key)) {
    http_response_code(401);
    exit('Invalid API key. Open this page with ?key=<API_KEY>.');
}

const SECURITY_OPTIONS = [
    'starttls' => ['STARTTLS (port 587)', 587],
    'ssl'      => ['SSL/TLS (port 465)', 465],
    'none'     => ['None (port 25)', 25],
];

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$qs = $key !== '' ? '?key=' . rawurlencode($key) : '';
$error = '';
$errors = [];
$saved = isset($_GET['saved']);
$s = SETTINGS_DEFAULTS;

try {
    $s = get_settings();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = [
            'smtp_host'      => trim((string)($_POST['smtp_host'] ?? '')),
            'smtp_port'      => trim((string)($_POST['smtp_port'] ?? '')),
            'smtp_security'  => (string)($_POST['smtp_security'] ?? 'starttls'),
            'smtp_user'      => trim((string)($_POST['smtp_user'] ?? '')),
            // Gmail shows app passwords in groups of 4; the spaces aren't part of it.
            'smtp_password'  => preg_replace('/\s+/', '', (string)($_POST['smtp_password'] ?? '')),
            'smtp_from_name' => trim((string)($_POST['smtp_from_name'] ?? '')),
            'sender_phone'   => trim(preg_replace('/[\r\n]+/', ' ', (string)($_POST['sender_phone'] ?? ''))),
            'groq_api_key'   => trim((string)($_POST['groq_api_key'] ?? '')),
            'groq_model'     => trim((string)($_POST['groq_model'] ?? '')),
            'email_subject'  => trim(preg_replace('/[\r\n]+/', ' ', (string)($_POST['email_subject'] ?? ''))),
            'email_body'     => rtrim(str_replace("\r\n", "\n", (string)($_POST['email_body'] ?? ''))),
        ];
        // Empty template fields go back to the built-in default.
        foreach (['email_subject', 'email_body', 'groq_model'] as $k) if ($in[$k] === '') $in[$k] = SETTINGS_DEFAULTS[$k];
        if (!isset(SECURITY_OPTIONS[$in['smtp_security']])) $errors[] = 'Pick a security option.';
        if ($in['smtp_port'] === '') $in['smtp_port'] = (string)(SECURITY_OPTIONS[$in['smtp_security']][1] ?? 587);
        if (!ctype_digit($in['smtp_port']) || (int)$in['smtp_port'] < 1 || (int)$in['smtp_port'] > 65535) {
            $errors[] = 'Port must be a number from 1 to 65535.';
        }
        if ($in['smtp_user'] !== '' && !filter_var($in['smtp_user'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Gmail address is not a valid email address.';
        }
        if ($errors) {
            $s = $in; // keep what was typed
        } else {
            save_settings($in);
            header('Location: settings.php?' . http_build_query(array_filter(['key' => $key, 'saved' => 1])), true, 303);
            exit;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Portal Settings</title>
<style>
:root {
  --bg: #f6f7f9; --panel: #fff; --text: #1d2330; --muted: #6b7280; --line: #e3e6eb;
  --accent: #2f6fdf; --accent-soft: #e8f0fd;
  --error-bg: #fdecec; --error: #a12828; --ok-bg: #e7f5ec; --ok: #1d6b3a;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #12151b; --panel: #1a1e26; --text: #e5e8ee; --muted: #959cab; --line: #2b313c;
    --accent: #6ea2ff; --accent-soft: #1f2b44;
    --error-bg: #3a1d1d; --error: #ffb3b3; --ok-bg: #173323; --ok: #9fe0b5;
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
.wrap { max-width: 560px; margin: 0 auto; padding: 20px 16px 40px; }
header { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 16px; margin-bottom: 16px; }
h1 { font-size: 22px; margin: 0; }
details { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
summary { cursor: pointer; font-weight: 600; }
summary .muted { color: var(--muted); font-weight: 400; }
.grid { display: grid; grid-template-columns: 1fr 110px; gap: 12px; margin-top: 12px; }
.field { display: flex; flex-direction: column; gap: 4px; grid-column: 1 / -1; }
.field.half { grid-column: auto; }
label { font-size: 13px; color: var(--muted); }
textarea { resize: vertical; line-height: 1.5; }
code { font-size: 12px; background: var(--accent-soft); padding: 0 4px; border-radius: 4px; }
input, select, textarea, button, .btn { font: inherit; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 7px; padding: 7px 10px; width: 100%; min-width: 0; }
button, .btn { cursor: pointer; background: var(--panel); width: auto; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.btn:hover, button:hover { text-decoration: none; border-color: var(--accent); }
.secret { display: flex; gap: 8px; }
.secret button { color: var(--accent); border-color: var(--accent); border-radius: 999px; padding: 6px 14px; }
.actions { display: flex; gap: 8px; justify-content: flex-end; }
.hint { color: var(--muted); font-size: 13px; margin: 0 0 12px; }
.msg { border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
.msg.error { background: var(--error-bg); color: var(--error); }
.msg.ok { background: var(--ok-bg); color: var(--ok); }
.msg ul { margin: 0; padding-left: 18px; }
@media (max-width: 480px) { .grid { grid-template-columns: 1fr 90px; } }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Settings</h1>
    <span style="display:flex;gap:8px">
      <a class="btn" href="templates.php<?= h($qs) ?>">&#9993; Templates</a>
      <a class="btn" href="index.php<?= h($qs) ?>">&larr; Business Listings</a>
    </span>
  </header>

  <?php if ($error): ?><div class="msg error">Database error: <?= h($error) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="msg error"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($saved && !$errors && !$error): ?><div class="msg ok">Settings saved.</div><?php endif; ?>

  <form method="post" action="settings.php<?= h($qs) ?>" autocomplete="off">
    <details open>
      <summary>Email (SMTP) <?php if ($s['smtp_user'] !== ''): ?><span class="muted">(<?= h($s['smtp_user']) ?>)</span><?php endif; ?></summary>
      <div class="grid">
        <div class="field half">
          <label for="smtp_host">Host</label>
          <input id="smtp_host" name="smtp_host" type="text" value="<?= h($s['smtp_host']) ?>" placeholder="smtp.gmail.com">
        </div>
        <div class="field half">
          <label for="smtp_port">Port</label>
          <input id="smtp_port" name="smtp_port" type="number" min="1" max="65535" value="<?= h($s['smtp_port']) ?>">
        </div>
        <div class="field">
          <label for="smtp_security">Security</label>
          <select id="smtp_security" name="smtp_security">
            <?php foreach (SECURITY_OPTIONS as $v => [$label, $port]): ?>
              <option value="<?= h($v) ?>" data-port="<?= $port ?>"<?= $s['smtp_security'] === $v ? ' selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="smtp_user">Gmail address</label>
          <input id="smtp_user" name="smtp_user" type="email" value="<?= h($s['smtp_user']) ?>" placeholder="you@gmail.com">
        </div>
        <div class="field">
          <label for="smtp_password">App password</label>
          <div class="secret">
            <input id="smtp_password" name="smtp_password" type="password" value="<?= h($s['smtp_password']) ?>" autocomplete="new-password">
            <button type="button" data-show="smtp_password">Show</button>
          </div>
        </div>
        <div class="field">
          <label for="smtp_from_name">From name</label>
          <input id="smtp_from_name" name="smtp_from_name" type="text" value="<?= h($s['smtp_from_name']) ?>">
        </div>
        <div class="field">
          <label for="sender_phone">Your phone (for <code>{from_phone}</code>)</label>
          <input id="sender_phone" name="sender_phone" type="tel" value="<?= h($s['sender_phone']) ?>" placeholder="+1 555-123-4567">
        </div>
        <div class="field">
          <label for="groq_api_key">Groq API key</label>
          <div class="secret">
            <input id="groq_api_key" name="groq_api_key" type="password" value="<?= h($s['groq_api_key']) ?>" autocomplete="new-password">
            <button type="button" data-show="groq_api_key">Show</button>
          </div>
        </div>
        <div class="field">
          <label for="groq_model">Groq model <span class="muted">(for generating templates on the <a href="templates.php<?= h($qs) ?>">Templates</a> page; empty = <?= h(SETTINGS_DEFAULTS['groq_model']) ?>)</span></label>
          <input id="groq_model" name="groq_model" type="text" value="<?= h($s['groq_model']) ?>" placeholder="<?= h(SETTINGS_DEFAULTS['groq_model']) ?>" list="groq_models">
          <datalist id="groq_models"><option value="openai/gpt-oss-120b"><option value="openai/gpt-oss-20b"><option value="qwen/qwen3.8-27b"></datalist>
        </div>
      </div>
    </details>
    <details open>
      <summary>Marketing email template</summary>
      <div class="grid">
        <div class="field">
          <label for="email_subject">Subject</label>
          <input id="email_subject" name="email_subject" type="text" value="<?= h($s['email_subject']) ?>">
        </div>
        <div class="field">
          <label for="email_body">Message</label>
          <textarea id="email_body" name="email_body" rows="24"><?= h($s['email_body']) ?></textarea>
        </div>
      </div>
      <p class="hint" style="margin:8px 0 0">More templates (and Groq-generated ones) are on the <a href="templates.php<?= h($qs) ?>">Templates</a> page. This one is used by the <b>Email</b> button on Business Listings, where you can still edit it before sending. Placeholders: <code>{business_name}</code> <code>{website}</code> <code>{city}</code> <code>{category}</code> <code>{from_name}</code> <code>{from_email}</code> <code>{from_phone}</code>. A line with only an empty placeholder (e.g. no phone set) is left out. Wrap text in <code>**double asterisks**</code> to make it <b>bold</b>. Clear a field to restore the default.</p>
    </details>
    <p class="hint">For Gmail, use an <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">app password</a>, not your normal password. Values are stored in plain text in the database.</p>
    <div class="actions">
      <a class="btn" href="index.php<?= h($qs) ?>">Cancel</a>
      <button type="submit" class="primary">Save settings</button>
    </div>
  </form>
</div>
<script>
for (const btn of document.querySelectorAll('[data-show]')) {
  btn.addEventListener('click', () => {
    const input = document.getElementById(btn.dataset.show);
    const hide = input.type === 'text';
    input.type = hide ? 'password' : 'text';
    btn.textContent = hide ? 'Show' : 'Hide';
  });
}
// Follow the security mode with the port, unless a non-standard port was typed.
const security = document.getElementById('smtp_security');
const port = document.getElementById('smtp_port');
security.addEventListener('change', () => {
  const standard = [...security.options].map((o) => o.dataset.port);
  if (port.value === '' || standard.includes(port.value)) port.value = security.selectedOptions[0].dataset.port;
});
</script>
</body>
</html>
