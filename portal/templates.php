<?php
/*
 * Email templates: list, add, edit, duplicate, delete, turn on/off, and generate new ones with Groq.
 * get_random_template.php hands out a random active one. When API_KEY is set, open with ?key=<value>.
 * POST actions (form-encoded, then redirect): save, delete, toggle, duplicate, import (copy the Settings template).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/groq.php';

$key = (string)($_GET['key'] ?? '');
if (API_KEY !== '' && !hash_equals(API_KEY, $key)) {
    http_response_code(401);
    exit('Invalid API key. Open this page with ?key=<API_KEY>.');
}

const MAX_NAME = 120;
const MAX_SUBJECT = 255;
const MAX_BODY = 20000;

function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** templates.php URL with the key kept. */
function page_url(array $params = []): string
{
    global $key;
    $q = http_build_query(array_filter($params + ['key' => $key], fn($v) => $v !== '' && $v !== null));
    return 'templates.php' . ($q !== '' ? "?$q" : '');
}

function redirect(array $params): void
{
    header('Location: ' . page_url($params), true, 303);
    exit;
}

$error = '';
$errors = [];
$templates = [];
$s = SETTINGS_DEFAULTS;
$form = ['id' => '', 'name' => '', 'subject' => '', 'body' => '', 'is_active' => 1, 'source' => 'manual', 'clean' => 1];

try {
    $pdo = db();
    ensure_email_templates_table();
    $s = get_settings();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);

        if ($action === 'delete' && $id) {
            $pdo->prepare('DELETE FROM email_templates WHERE id = ?')->execute([$id]);
            redirect(['msg' => 'deleted']);
        }
        if ($action === 'toggle' && $id) {
            $pdo->prepare('UPDATE email_templates SET is_active = 1 - is_active WHERE id = ?')->execute([$id]);
            redirect([]);
        }
        if ($action === 'duplicate' && $id) {
            $pdo->prepare("INSERT INTO email_templates (name, subject, body, is_active, source)
                           SELECT LEFT(CONCAT(name, ' (copy)'), " . MAX_NAME . "), subject, body, 0, source FROM email_templates WHERE id = ?")
                ->execute([$id]);
            redirect(['edit' => (int)$pdo->lastInsertId(), 'msg' => 'duplicated']);
        }
        if ($action === 'import') {
            $pdo->prepare("INSERT INTO email_templates (name, subject, body, is_active, source) VALUES ('Default (from Settings)', ?, ?, 1, 'manual')")
                ->execute([$s['email_subject'], $s['email_body']]);
            redirect(['edit' => (int)$pdo->lastInsertId(), 'msg' => 'imported']);
        }
        if ($action === 'save') {
            $form = [
                'id'        => $id ?: '',
                'name'      => trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? ''))),
                'subject'   => trim(preg_replace('/[\r\n]+/', ' ', (string)($_POST['subject'] ?? ''))),
                'body'      => rtrim(str_replace("\r\n", "\n", (string)($_POST['body'] ?? ''))),
                'is_active' => empty($_POST['is_active']) ? 0 : 1,
                'source'    => ($_POST['source'] ?? '') === 'groq' ? 'groq' : 'manual',
                'clean'     => empty($_POST['clean']) ? 0 : 1,
            ];
            if ($form['clean']) {
                $form['subject'] = clean_template_text($form['subject'], false);
                $form['body'] = clean_template_text($form['body']);
            }
            if ($form['name'] === '') $form['name'] = mb_substr($form['subject'], 0, 60);
            if ($form['subject'] === '') $errors[] = 'Subject is required.';
            if (trim($form['body']) === '') $errors[] = 'Message is required.';
            if (mb_strlen($form['name']) > MAX_NAME) $errors[] = 'Name is longer than ' . MAX_NAME . ' characters.';
            if (mb_strlen($form['subject']) > MAX_SUBJECT) $errors[] = 'Subject is longer than ' . MAX_SUBJECT . ' characters.';
            if (mb_strlen($form['body']) > MAX_BODY) $errors[] = 'Message is longer than ' . MAX_BODY . ' characters.';
            if (!$errors) {
                $vals = [$form['name'], $form['subject'], $form['body'], $form['is_active'], $form['source']];
                if ($form['id']) {
                    $pdo->prepare('UPDATE email_templates SET name = ?, subject = ?, body = ?, is_active = ?, source = ? WHERE id = ?')
                        ->execute([...$vals, $form['id']]);
                    $savedId = (int)$form['id'];
                } else {
                    $pdo->prepare('INSERT INTO email_templates (name, subject, body, is_active, source) VALUES (?, ?, ?, ?, ?)')->execute($vals);
                    $savedId = (int)$pdo->lastInsertId();
                }
                redirect(['msg' => 'saved', 'edit' => $savedId]);
            }
        }
    } elseif (($editId = (int)($_GET['edit'] ?? 0)) > 0) {
        $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id = ?');
        $stmt->execute([$editId]);
        if ($row = $stmt->fetch()) $form = ['clean' => 1] + $row;
    }

    $templates = $pdo->query('SELECT * FROM email_templates ORDER BY is_active DESC, updated_at DESC, id DESC')->fetchAll();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$msg = ['saved' => 'Template saved.', 'deleted' => 'Template deleted.', 'duplicated' => 'Copy made. It is off until you turn it on.', 'imported' => 'Settings template copied here and turned on.'][$_GET['msg'] ?? ''] ?? '';
$activeCount = count(array_filter($templates, fn($t) => (int)$t['is_active'] === 1));
$editing = $form['id'] !== '';
$groqReady = trim((string)$s['groq_api_key']) !== '';
$settingsUrl = 'settings.php' . ($key !== '' ? '?key=' . rawurlencode($key) : '');
$listUrl = 'index.php' . ($key !== '' ? '?key=' . rawurlencode($key) : '');

// Sample business for the live preview: one with an email, else made-up values.
$sample = ['name' => "Joe's Pizza", 'website' => 'https://joespizza.com', 'city' => 'Union City', 'category' => 'Pizza restaurant'];
try {
    $row = db()->query("SELECT name, website, city, category FROM businesses WHERE email IS NOT NULL AND email <> '' ORDER BY id DESC LIMIT 1")->fetch();
    if ($row) $sample = $row;
} catch (Throwable $e) { /* preview only */ }
$previewValues = [];
foreach (TEMPLATE_PLACEHOLDERS as $ph) $previewValues[$ph] = trim(fill_template($ph, $sample, $s));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Email Templates</title>
<style>
:root {
  --bg: #f6f7f9; --panel: #fff; --text: #1d2330; --muted: #6b7280; --line: #e3e6eb;
  --accent: #2f6fdf; --accent-soft: #e8f0fd;
  --error-bg: #fdecec; --error: #a12828; --ok-bg: #e7f5ec; --ok: #1d6b3a; --warn-bg: #fff6e0; --warn: #8a5a00;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #12151b; --panel: #1a1e26; --text: #e5e8ee; --muted: #959cab; --line: #2b313c;
    --accent: #6ea2ff; --accent-soft: #1f2b44;
    --error-bg: #3a1d1d; --error: #ffb3b3; --ok-bg: #173323; --ok: #9fe0b5; --warn-bg: #3a2e14; --warn: #f3cf7a;
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
.wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px 40px; }
header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px 16px; margin-bottom: 16px; }
header .links { display: flex; gap: 8px; flex-wrap: wrap; }
h1 { font-size: 22px; margin: 0; }
h2 { font-size: 16px; margin: 0 0 10px; }
.card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 14px; margin-bottom: 14px; }
.muted, .sub { color: var(--muted); }
.sub { font-size: 12px; }
label { font-size: 13px; color: var(--muted); }
code { font-size: 12px; background: var(--accent-soft); padding: 0 4px; border-radius: 4px; }
input, select, textarea, button, .btn { font: inherit; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 7px; padding: 7px 10px; width: 100%; min-width: 0; }
textarea { resize: vertical; line-height: 1.5; }
button, .btn { cursor: pointer; background: var(--panel); width: auto; white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
button.primary, .btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
button.danger { color: var(--error); }
button.small { padding: 3px 9px; font-size: 13px; }
button:disabled { opacity: .5; cursor: default; }
.btn:hover, button:hover:not(:disabled) { text-decoration: none; border-color: var(--accent); }
.msg { border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
.msg.error { background: var(--error-bg); color: var(--error); }
.msg.ok { background: var(--ok-bg); color: var(--ok); }
.msg.warn { background: var(--warn-bg); color: var(--warn); }
.msg ul { margin: 0; padding-left: 18px; }

.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { font-size: 12px; color: var(--muted); font-weight: 600; white-space: nowrap; }
tr.off td:not(.acts) { opacity: .55; }
tr.current { background: var(--accent-soft); }
td.acts { white-space: nowrap; }
td.acts form { display: inline; }
.tag { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--accent-soft); color: var(--accent); margin-left: 4px; vertical-align: 1px; }
.tag.warn { background: var(--warn-bg); color: var(--warn); }
.switch { padding: 2px 10px; border-radius: 999px; font-size: 12px; }
.switch.on { background: var(--ok-bg); color: var(--ok); border-color: transparent; }

.cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 14px; align-items: start; }
.field { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.checks { display: flex; flex-wrap: wrap; gap: 6px 18px; margin: 4px 0 12px; }
.checks label { display: inline-flex; align-items: center; gap: 6px; color: var(--text); }
.checks input { width: auto; }
.actions { display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap; }
.preview { border: 1px solid var(--line); border-radius: 8px; padding: 12px; background: var(--bg); white-space: pre-wrap; word-break: break-word; font-size: 13px; max-height: 560px; overflow: auto; }
.preview .subj { font-weight: 600; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid var(--line); white-space: normal; }
.hint { color: var(--muted); font-size: 13px; margin: 8px 0 0; }
#gen-status:empty { display: none; }
.card-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px; }
.card-head h2 { margin: 0; }
.gen { border: 1px dashed var(--line); border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; }
.gen summary { cursor: pointer; font-weight: 600; }
.gen[open] summary { margin-bottom: 10px; }
@media (max-width: 800px) { .cols, .row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Email Templates</h1>
    <div class="links">
      <a class="btn" href="<?= h($listUrl) ?>">&larr; Business Listings</a>
      <a class="btn" href="<?= h($settingsUrl) ?>">&#9881; Settings</a>
    </div>
  </header>

  <?php if ($error): ?><div class="msg error">Database error: <?= h($error) ?></div><?php endif; ?>
  <?php if ($errors): ?><div class="msg error"><ul><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <?php if ($msg && !$errors): ?><div class="msg ok"><?= h($msg) ?></div><?php endif; ?>

  <section class="card">
    <div class="card-head">
      <h2>Templates <span class="muted" style="font-weight:400">(<?= count($templates) ?>, <?= $activeCount ?> on)</span></h2>
      <a class="btn primary" href="<?= h(page_url()) ?>#editor">+ New template</a>
    </div>
    <?php if (!$templates): ?>
      <p class="muted" style="margin:0 0 10px">No templates yet. Write one in the editor below (Groq is optional). Until then the template in <a href="<?= h($settingsUrl) ?>">Settings</a> is used.</p>
      <form method="post" action="<?= h(page_url()) ?>">
        <input type="hidden" name="action" value="import">
        <button type="submit">Copy the Settings template here</button>
      </form>
    <?php else: ?>
      <div class="table-wrap">
      <table>
        <thead><tr><th>Name</th><th>Subject</th><th>On</th><th>Used</th><th>Updated (UTC)</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($templates as $t):
            $unknown = template_unknown_placeholders($t['subject'] . "\n" . $t['body']); ?>
          <tr class="<?= $t['is_active'] ? '' : 'off' ?><?= (string)$form['id'] === (string)$t['id'] ? ' current' : '' ?>">
            <td>
              <a href="<?= h(page_url(['edit' => $t['id']])) ?>#editor"><?= h($t['name']) ?></a>
              <?php if ($t['source'] === 'groq'): ?><span class="tag">Groq</span><?php endif; ?>
              <?php if ($unknown): ?><span class="tag warn" title="Not filled in when sending: <?= h(implode(', ', $unknown)) ?>">&#9888; <?= h(implode(' ', $unknown)) ?></span><?php endif; ?>
            </td>
            <td><?= h($t['subject']) ?></td>
            <td>
              <form method="post" action="<?= h(page_url()) ?>">
                <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="switch<?= $t['is_active'] ? ' on' : '' ?>" title="<?= $t['is_active'] ? 'On: can be picked at random. Click to turn off.' : 'Off: never picked. Click to turn on.' ?>"><?= $t['is_active'] ? 'On' : 'Off' ?></button>
              </form>
            </td>
            <td><?= (int)$t['times_used'] ?></td>
            <td class="sub"><?= h(substr((string)$t['updated_at'], 0, 16)) ?></td>
            <td class="acts">
              <a class="btn small" href="<?= h(page_url(['edit' => $t['id']])) ?>#editor" style="padding:3px 9px;font-size:13px">Edit</a>
              <form method="post" action="<?= h(page_url()) ?>">
                <input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="small">Duplicate</button>
              </form>
              <form method="post" action="<?= h(page_url()) ?>" onsubmit="return confirm('Delete the template “<?= h(addslashes($t['name'])) ?>”?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button type="submit" class="small danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <p class="hint"><code>get_random_template.php</code> picks one of the templates that are <b>On</b> at random<?= $activeCount ? '' : '. None are on, so it returns the Settings template' ?>.</p>
    <?php endif; ?>
  </section>

  <section class="card" id="editor">
    <h2 id="editor-title"><?= $editing ? 'Edit: ' . h($form['name']) : 'New template' ?></h2>
    <details class="gen" id="gen">
      <summary>&#10024; Generate with Groq <span class="sub" style="font-weight:400">(optional: or just write your own below)</span></summary>
    <?php if (!$groqReady): ?>
      <div class="msg warn" style="margin:0 0 10px">Add your Groq API key in <a href="<?= h($settingsUrl) ?>">Settings</a> first.</div>
    <?php endif; ?>
    <div class="field">
      <label for="gen-brief">What should this email focus on? <span class="sub">(optional)</span></label>
      <textarea id="gen-brief" rows="3" maxlength="2000" placeholder="e.g. Restaurants without online ordering. Mention a mobile-friendly menu and Google Maps visibility. Offer a free homepage mockup."></textarea>
    </div>
    <div class="row">
      <div class="field">
        <label for="gen-tone">Tone</label>
        <select id="gen-tone"><?php foreach (TEMPLATE_TONES as $v => $label): ?><option value="<?= h($v) ?>"><?= h($label) ?></option><?php endforeach; ?></select>
      </div>
      <div class="field">
        <label for="gen-length">Length</label>
        <select id="gen-length"><?php foreach (TEMPLATE_LENGTHS as $v => $label): ?><option value="<?= h($v) ?>"<?= $v === 'medium' ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select>
      </div>
    </div>
    <div class="actions" style="justify-content:space-between;align-items:center">
      <span class="sub">Model: <?= h($s['groq_model']) ?> (change it in Settings). The result fills in the editor below. Check it, then press Save.</span>
      <button type="button" id="gen-btn" class="primary"<?= $groqReady ? '' : ' disabled' ?>>&#10024; Generate</button>
    </div>
    <div class="msg" id="gen-status" style="margin:10px 0 0"></div>
    </details>
    <form method="post" action="<?= h(page_url()) ?>" id="tpl-form">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="tpl-id" value="<?= h($form['id']) ?>">
      <input type="hidden" name="source" id="tpl-source" value="<?= h($form['source']) ?>">
      <div class="cols">
        <div>
          <div class="field">
            <label for="tpl-name">Name <span class="sub">(only shown in this list; empty = the subject)</span></label>
            <input id="tpl-name" name="name" type="text" maxlength="<?= MAX_NAME ?>" value="<?= h($form['name']) ?>">
          </div>
          <div class="field">
            <label for="tpl-subject">Subject</label>
            <input id="tpl-subject" name="subject" type="text" maxlength="<?= MAX_SUBJECT ?>" value="<?= h($form['subject']) ?>" required>
          </div>
          <div class="field">
            <label for="tpl-body">Message</label>
            <textarea id="tpl-body" name="body" rows="22" required><?= h($form['body']) ?></textarea>
          </div>
          <div class="checks">
            <label><input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>> On (can be picked at random)</label>
            <label title="Turns ### headings and *stars* into plain text, • bullets into '- ', and [Your Name]-style blanks into placeholders"><input type="checkbox" name="clean" value="1"<?= $form['clean'] ? ' checked' : '' ?>> Clean up Markdown and [Your Name] blanks on save</label>
          </div>
          <div class="actions">
            <?php if ($editing): ?><a class="btn" href="<?= h(page_url()) ?>#editor">New template</a><?php endif; ?>
            <button type="submit" class="primary" id="tpl-save"><?= $editing ? 'Save changes' : 'Save template' ?></button>
          </div>
        </div>
        <div>
          <label>Preview <span class="sub">(filled in for <?= h($sample['name']) ?>)</span></label>
          <div class="preview" id="preview"></div>
          <div class="msg warn" id="unknown" hidden style="margin:10px 0 0"></div>
        </div>
      </div>
      <p class="hint">Placeholders: <?php foreach (TEMPLATE_PLACEHOLDERS as $ph): ?><code><?= h($ph) ?></code> <?php endforeach; ?>. A line with only an empty placeholder (for example no phone set) is left out. Wrap text on one line in <code>**double asterisks**</code> to send it in <b>bold</b>.</p>
    </form>
  </section>
</div>

<script>
(() => {
  const KEY = <?= json_encode($key) ?>;
  const VALUES = <?= json_encode($previewValues, JSON_UNESCAPED_UNICODE) ?>;
  const KNOWN = Object.keys(VALUES);
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // Same rules as mailer.php fill_template(): drop a line that is only an empty placeholder.
  function fill(t) {
    for (const [ph, v] of Object.entries(VALUES)) {
      if (v === '') t = t.replace(new RegExp('^[ \\t]*' + ph.replace(/[{}]/g, '\\$&') + '[ \\t]*(\\r?\\n|$)', 'gm'), '');
    }
    return t.replace(/\{[a-z_]+\}/g, (m) => (m in VALUES ? VALUES[m] : m));
  }
  const bold = (html) => html.replace(/\*\*([^\n*]+?)\*\*/g, '<b>$1</b>');

  function render() {
    const subject = $('tpl-subject').value, body = $('tpl-body').value;
    $('preview').innerHTML = `<div class="subj">${esc(fill(subject).replace(/\*\*/g, '')) || '<span class="muted">(no subject)</span>'}</div>`
      + (bold(esc(fill(body))) || '<span class="muted">(no message)</span>');
    const found = (subject + '\n' + body).match(/\{[a-z_]+\}|\[(?:your|their|recipient|business|company)[^\]\n]{0,40}\]/gi) || [];
    const unknown = [...new Set(found)].filter((p) => !KNOWN.includes(p));
    $('unknown').hidden = !unknown.length;
    $('unknown').textContent = unknown.length ? `Not filled in when sending: ${unknown.join(', ')}` : '';
  }
  ['tpl-subject', 'tpl-body'].forEach((id) => $(id).addEventListener('input', render));
  render();

  const genBtn = $('gen-btn');
  function status(text, kind) {
    $('gen-status').className = 'msg ' + (kind || '');
    $('gen-status').textContent = text || '';
  }
  genBtn.addEventListener('click', async () => {
    const editing = $('tpl-id').value !== '';
    const hasText = $('tpl-body').value.trim() !== '';
    if (hasText && !confirm(editing
      ? 'Put the generated text in a new template? Your unsaved changes to this one will be lost.'
      : 'Replace what is in the editor with the generated template?')) return;
    genBtn.disabled = true;
    status('Generating… this takes a few seconds.');
    try {
      const res = await fetch('generate_template.php' + (KEY ? '?key=' + encodeURIComponent(KEY) : ''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ brief: $('gen-brief').value, tone: $('gen-tone').value, length: $('gen-length').value }),
      });
      const d = await res.json().catch(() => ({ ok: false, error: `HTTP ${res.status}` }));
      if (!d.ok) throw new Error(d.error || 'Generation failed.');
      $('tpl-id').value = '';
      $('tpl-source').value = 'groq';
      $('tpl-name').value = d.name;
      $('tpl-subject').value = d.subject;
      $('tpl-body').value = d.body;
      $('editor-title').textContent = 'New template (from Groq, not saved yet)';
      $('tpl-save').textContent = 'Save template';
      render();
      status('Done. Check it in the editor, change anything you like, then press Save template. Generate again for a different version.', 'ok');
      $('tpl-subject').scrollIntoView({ behavior: 'smooth', block: 'center' });
    } catch (e) {
      status(e.message, 'error');
    } finally {
      genBtn.disabled = <?= $groqReady ? 'false' : 'true' ?>;
    }
  });
})();
</script>
</body>
</html>
