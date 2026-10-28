/*
 * popup.js — settings form, Start/Pause/Stop, and a live view of the run state.
 * Settings are saved through background.js (which normalizes them) as soon as a field changes.
 */
const $ = (id) => document.getElementById(id);
const PORTAL_PAGE = 'https://email.demo-website.cc/';
const FIELDS = ['chatUrl', 'temporaryChat', 'zipcode', 'category', 'minDelay', 'maxDelay', 'replyTimeout', 'maxPerRun', 'prompt'];

let defaults = {};

const send = (msg) => chrome.runtime.sendMessage(msg).catch((e) => ({ ok: false, error: e.message }));

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------
function fillForm(settings) {
  for (const k of FIELDS) {
    const el = $(k);
    if (el.type === 'checkbox') el.checked = !!settings[k];
    else if (document.activeElement !== el) el.value = settings[k] ?? '';
  }
  $('chatUrl').classList.remove('invalid');
}

function readForm() {
  const out = {};
  for (const k of FIELDS) out[k] = $(k).type === 'checkbox' ? $(k).checked : $(k).value;
  return out;
}

async function saveForm() {
  const wanted = $('chatUrl').value.trim();
  const r = await send({ type: 'SAVE_SETTINGS', settings: readForm() });
  if (!r?.ok) return;
  fillForm(r.settings);
  // An invalid URL is replaced by the default; show that it was rejected.
  $('chatUrl').classList.toggle('invalid', !!wanted && wanted !== r.settings.chatUrl);
}

for (const k of FIELDS) $(k).addEventListener('change', saveForm);

$('resetPrompt').addEventListener('click', async () => {
  $('prompt').value = defaults.prompt || '';
  await saveForm();
});

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------
function timeOf(iso) {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

let lastState = {};

/** Live countdown while waiting between businesses: "Next business in 20s", 19s, 18s… */
function renderCountdown() {
  const s = lastState;
  const el = $('countdown');
  const waiting = s.running && !s.paused && s.phase === 'waiting' && s.nextAt;
  el.hidden = !waiting;
  if (!waiting) return;
  const secs = Math.max(0, Math.ceil((s.nextAt - Date.now()) / 1000));
  el.textContent = secs > 0 ? `Next business in ${secs}s` : 'Starting the next business…';
}
setInterval(renderCountdown, 250);

function render(s) {
  lastState = s;
  renderCountdown();
  const phase = s.paused && s.running ? 'paused' : s.phase;
  $('phasePill').textContent = phase;
  $('phasePill').className = `pill ${phase}`;
  $('business').textContent = s.business?.name || '–';
  $('business').title = s.business ? [s.business.name, s.business.address, s.business.website].filter(Boolean).join('\n') : '';
  $('remaining').textContent = s.remaining ?? '–';
  $('remaining').title = 'Businesses without an email the portal can still hand out';
  $('message').textContent = s.message || '';
  $('checked').textContent = s.checked || 0;
  $('found').textContent = s.found || 0;
  $('none').textContent = s.none || 0;
  $('errorCount').textContent = s.errorCount || 0;

  $('errors').innerHTML = (s.errors || []).slice(0, 10)
    .map((e) => `<li title="${esc(e.at)}">${e.name ? `<b>${esc(e.name)}</b>: ` : ''}${esc(e.error)}</li>`).join('');

  const rows = (s.recent || []).map((r) => {
    const cell = r.status === 'found'
      ? `<td class="found" title="${esc(r.emails.join(', '))}">${esc(r.emails[0])}${r.emails.length > 1 ? ` +${r.emails.length - 1}` : ''}</td>`
      : `<td class="${r.status === 'error' ? 'error' : 'none'}" title="${esc(r.note)}">${r.status === 'error' ? 'error' : 'none found'}</td>`;
    return `<tr><td title="${esc(r.name)}">${esc(r.name)}</td>${cell}<td>${esc(timeOf(r.at))}</td></tr>`;
  });
  $('recent').tBodies[0].innerHTML = rows.join('') || '<tr><td colspan="3" class="empty">Nothing yet</td></tr>';

  $('startBtn').disabled = !!s.running;
  $('pauseBtn').disabled = !s.running;
  $('stopBtn').disabled = !s.running;
  $('pauseBtn').textContent = s.paused ? 'Resume' : 'Pause';
}

async function loadState() {
  const { state } = await chrome.storage.local.get('state');
  render(state || { phase: 'idle', message: 'Ready' });
  return state || {};
}

chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'local' && changes.state) render(changes.state.newValue || {});
});

// ---------------------------------------------------------------------------
// Buttons
// ---------------------------------------------------------------------------
async function showResult(r) {
  if (!r?.ok && r?.error) $('message').textContent = r.error;
}

$('startBtn').addEventListener('click', async () => {
  await saveForm();
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  showResult(await send({ type: 'START', tabId: tab?.id ?? null }));
});

$('pauseBtn').addEventListener('click', async () => {
  const s = await loadState();
  showResult(await send({ type: s.paused ? 'RESUME' : 'PAUSE' }));
});

$('stopBtn').addEventListener('click', async () => showResult(await send({ type: 'STOP' })));
$('clearBtn').addEventListener('click', async () => showResult(await send({ type: 'CLEAR_LOG' })));
$('portalBtn').addEventListener('click', () => chrome.tabs.create({ url: PORTAL_PAGE }));

(async () => {
  const r = await send({ type: 'GET_SETTINGS' });
  if (r?.ok) {
    defaults = r.defaults;
    fillForm(r.settings);
  }
  await loadState();
})();
