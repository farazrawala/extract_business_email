/*
 * popup.js — settings form, Start/Pause/Stop/Test, and a live view of the run state.
 * Settings are saved through background.js (which normalizes them) as soon as a field changes.
 */
const $ = (id) => document.getElementById(id);
const PORTAL_PAGE = 'http://localhost/business_extractor/portal/';
const FIELDS = ['gmailUrl', 'zipcode', 'category', 'source', 'minDelay', 'maxDelay', 'runMinutes', 'maxPerRun', 'maxPerDay', 'tone', 'length', 'brief', 'allowUntracked', 'highlight'];
const PER_EMAIL_WORK_S = 20; // rough time to write (Groq) and send (Gmail) one email, for the pace hint

const send = (msg) => chrome.runtime.sendMessage(msg).catch((e) => ({ ok: false, error: e.message }));

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function duration(secs) {
  secs = Math.max(0, Math.round(secs));
  const h = Math.floor(secs / 3600);
  const m = Math.floor((secs % 3600) / 60);
  const s = secs % 60;
  return h ? `${h}h ${String(m).padStart(2, '0')}m` : m ? `${m}m ${String(s).padStart(2, '0')}s` : `${s}s`;
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
  $('gmailUrl').classList.remove('invalid');
  renderPace(settings);
}

function readForm() {
  const out = {};
  for (const k of FIELDS) out[k] = $(k).type === 'checkbox' ? $(k).checked : $(k).value;
  return out;
}

/** "About 24 emails an hour…" from the delay settings. */
function renderPace(s) {
  const perEmail = (Number(s.minDelay) + Number(s.maxDelay)) / 2 + PER_EMAIL_WORK_S;
  const perHour = Math.max(1, Math.round(3600 / perEmail));
  let text = `About ${perHour} emails an hour (average wait ${duration((Number(s.minDelay) + Number(s.maxDelay)) / 2)} + ~${PER_EMAIL_WORK_S}s to write and send each).`;
  const caps = [];
  if (Number(s.runMinutes)) caps.push(`about ${Math.max(1, Math.floor((s.runMinutes * 60) / perEmail))} in ${s.runMinutes} min`);
  if (Number(s.maxPerRun)) caps.push(`at most ${s.maxPerRun} per run`);
  if (Number(s.maxPerDay)) caps.push(`${s.maxPerDay} per day`);
  if (caps.length) text += ` Stops at ${caps.join(', ')}, whichever comes first.`;
  $('pace').textContent = text;
}

async function saveForm() {
  const wanted = $('gmailUrl').value.trim();
  const r = await send({ type: 'SAVE_SETTINGS', settings: readForm() });
  if (!r?.ok) return;
  fillForm(r.settings);
  // An invalid URL is replaced by the default; show that it was rejected.
  $('gmailUrl').classList.toggle('invalid', !!wanted && wanted !== r.settings.gmailUrl);
}

for (const k of FIELDS) $(k).addEventListener('change', saveForm);

// ---------------------------------------------------------------------------
// State
// ---------------------------------------------------------------------------
function timeOf(iso) {
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? '' : d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

let lastState = {};

/** Live countdown while waiting between emails, and the run time. */
function renderClock() {
  const s = lastState;
  const el = $('countdown');
  const waiting = s.running && !s.paused && s.phase === 'waiting' && s.nextAt;
  el.hidden = !waiting;
  if (waiting) {
    const secs = Math.max(0, Math.ceil((s.nextAt - Date.now()) / 1000));
    el.textContent = secs > 0 ? `Next email in ${duration(secs)}` : 'Starting the next email…';
  }
  const started = s.startedAt ? new Date(s.startedAt).getTime() : null;
  if (s.running && started) {
    const ran = duration((Date.now() - started) / 1000);
    $('runTime').textContent = s.runUntil ? `${ran} (${duration((s.runUntil - Date.now()) / 1000)} left)` : ran;
  } else {
    $('runTime').textContent = '–';
  }
}
setInterval(renderClock, 250);

function recentCell(r) {
  if (r.status === 'sent' || r.status === 'test') {
    const to = r.to || [];
    const label = `${r.status === 'test' ? 'test: ' : ''}${to[0] || ''}${to.length > 1 ? ` +${to.length - 1}` : ''}`;
    const track = r.tracked === true ? ' · tracked' : r.tracked === false ? ' · untracked' : '';
    return `<td class="${r.status}" title="${esc(`${to.join(', ')}${track}\n${r.subject || ''}`)}">${esc(label)}${r.tracked === false ? ' <span class="tag">untracked</span>' : ''}</td>`;
  }
  return `<td class="error" title="${esc(r.note)}">${esc(r.note || 'error')}</td>`;
}

function render(s) {
  lastState = s;
  renderClock();
  const phase = s.paused && s.running ? 'paused' : s.phase;
  $('phasePill').textContent = s.testMode && s.running ? `test · ${phase}` : phase;
  $('phasePill').className = `pill ${phase}`;
  $('business').textContent = s.business?.name || '–';
  $('business').title = s.business ? [s.business.name, s.business.city, s.business.website, (s.recipients || []).join(', ')].filter(Boolean).join('\n') : '';
  $('remaining').textContent = s.remaining ?? '–';
  $('remaining').title = 'Businesses with an email, never mailed, that the portal can still hand out';
  $('account').textContent = s.account || '–';
  $('account').title = s.account || '';
  $('subject').hidden = !s.mail?.subject;
  $('subject').innerHTML = s.mail?.subject ? `Subject: <b>${esc(s.mail.subject)}</b>` : '';
  $('subject').title = s.mail?.text || '';
  const facts = s.mail?.facts || [];
  $('facts').hidden = !facts.length;
  $('facts').innerHTML = facts.length ? `Details used: ${facts.map((f) => `<mark>${esc(f)}</mark>`).join(' ')}` : '';
  $('message').textContent = s.message || '';
  $('sent').textContent = s.sent || 0;
  $('failed').textContent = s.failed || 0;
  $('errorCount').textContent = s.errorCount || 0;
  $('untrackedWrap').hidden = !s.untracked;
  $('untracked').textContent = s.untracked || 0;
  const pending = (s.pendingSaves || []).length;
  $('pendingWrap').hidden = !pending;
  $('pending').textContent = pending;
  $('pendingWrap').title = 'Sent, but the portal has not recorded it yet. Retried on the next Start.';

  $('errors').innerHTML = (s.errors || []).slice(0, 10)
    .map((e) => `<li title="${esc(e.at)}">${e.name ? `<b>${esc(e.name)}</b>: ` : ''}${esc(e.error)}</li>`).join('');

  const rows = (s.recent || []).map((r) => `<tr><td title="${esc(r.name)}">${esc(r.name)}</td>${recentCell(r)}<td>${esc(timeOf(r.at))}</td></tr>`);
  $('recent').tBodies[0].innerHTML = rows.join('') || '<tr><td colspan="3" class="empty">Nothing yet</td></tr>';

  $('startBtn').disabled = !!s.running;
  $('testBtn').disabled = !!s.running;
  $('pauseBtn').disabled = !s.running || s.testMode;
  $('stopBtn').disabled = !s.running;
  $('pauseBtn').textContent = s.paused ? 'Resume' : 'Pause';
}

async function loadState() {
  const { state } = await chrome.storage.local.get('state');
  render(state || { phase: 'idle', message: 'Ready' });
  return state || {};
}

async function loadSentToday() {
  const { daily } = await chrome.storage.local.get('daily');
  $('sentToday').textContent = daily?.date === new Date().toLocaleDateString('en-CA') ? daily.count : 0;
}

chrome.storage.onChanged.addListener((changes, area) => {
  if (area !== 'local') return;
  if (changes.state) render(changes.state.newValue || {});
  if (changes.daily) loadSentToday();
});

// ---------------------------------------------------------------------------
// Buttons
// ---------------------------------------------------------------------------
async function showResult(r) {
  if (!r?.ok && r?.error) $('message').textContent = r.error;
}

async function start(test) {
  await saveForm();
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  showResult(await send({ type: 'START', tabId: tab?.id ?? null, test }));
}

$('startBtn').addEventListener('click', () => start(false));
$('testBtn').addEventListener('click', () => start(true));

$('pauseBtn').addEventListener('click', async () => {
  const s = await loadState();
  showResult(await send({ type: s.paused ? 'RESUME' : 'PAUSE' }));
});

$('stopBtn').addEventListener('click', async () => showResult(await send({ type: 'STOP' })));
$('clearBtn').addEventListener('click', async () => showResult(await send({ type: 'CLEAR_LOG' })));
$('portalBtn').addEventListener('click', () => chrome.tabs.create({ url: PORTAL_PAGE }));

(async () => {
  const r = await send({ type: 'GET_SETTINGS' });
  if (r?.ok) fillForm(r.settings);
  await loadState();
  await loadSentToday();
})();
