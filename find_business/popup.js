/*
 * popup.js — UI only. All run state lives in the background / chrome.storage.local,
 * so closing the popup never affects a running job.
 */
const VERSION = chrome.runtime.getManifest().version;
const $ = (id) => document.getElementById(id);

const DEFAULTS = {
  keyword: 'restaurants', maxResults: 100, minDelay: 2, maxDelay: 5,
  autoLoop: false, includeSponsored: false, openAboutTab: true, findEmails: true,
};

// Field groups for the "Export fields" checkboxes. Flattened, this is also the CSV
// column order; any extra keys a record has are appended at the end.
const FIELD_GROUPS = [
  ['Basics', ['name', 'category', 'rating', 'review_count', 'price_level', 'price_label', 'price_per_person']],
  ['Address', ['address', 'street', 'city', 'state', 'postal_code', 'country', 'plus_code', 'located_in']],
  ['Contact & links', ['phone', 'phone_e164', 'email', 'emails', 'website', 'menu_url', 'reservation_url']],
  ['Hours', ['hours_status', 'hours', 'hours_weekly', 'business_status']],
  ['Location & IDs', ['latitude', 'longitude', 'google_maps_url', 'place_id', 'cid', 'hex_id', 'cid_url']],
  ['Details', ['service_options', 'attributes_text', 'attributes', 'accessibility', 'actions',
    'image_url', 'review_summary', 'rating_breakdown', 'is_claimed', 'is_sponsored']],
  ['Search & run', ['zip_id', 'zipcode', 'zip_lat', 'zip_long', 'search_keyword', 'search_query',
    'scraped_at', 'extension_version']],
];
const FIELD_ORDER = FIELD_GROUPS.flatMap(([, fields]) => fields);
// Always exported so every file shows which version produced it.
const LOCKED_FIELDS = ['extension_version'];

// Unchecked fields, saved as `excludedFields`. Storing exclusions (not selections) means
// fields added in later versions are exported by default.
let excludedFields = new Set();

const PHASE_LABELS = {
  idle: 'Idle', starting: 'Starting', fetching_zip: 'Fetching ZIP', searching: 'Searching',
  scrolling: 'Scrolling', scraping: 'Scraping', waiting: 'Next ZIP', done: 'Done',
  stopped: 'Stopped', error: 'Error',
};

const SETTING_INPUTS = ['keyword', 'maxResults', 'minDelay', 'maxDelay', 'autoLoop', 'includeSponsored', 'openAboutTab', 'findEmails'];

// ---------------------------------------------------------------------------
// Settings
// ---------------------------------------------------------------------------
function readSettings() {
  return {
    keyword: $('keyword').value.trim() || DEFAULTS.keyword,
    maxResults: parseInt($('maxResults').value, 10) || DEFAULTS.maxResults,
    minDelay: parseFloat($('minDelay').value) || DEFAULTS.minDelay,
    maxDelay: parseFloat($('maxDelay').value) || DEFAULTS.maxDelay,
    autoLoop: $('autoLoop').checked,
    includeSponsored: $('includeSponsored').checked,
    openAboutTab: $('openAboutTab').checked,
    findEmails: $('findEmails').checked,
  };
}

function fillSettings(s) {
  const v = { ...DEFAULTS, ...(s || {}) };
  $('keyword').value = v.keyword;
  $('maxResults').value = v.maxResults;
  $('minDelay').value = v.minDelay;
  $('maxDelay').value = v.maxDelay;
  $('autoLoop').checked = !!v.autoLoop;
  $('includeSponsored').checked = !!v.includeSponsored;
  $('openAboutTab').checked = !!v.openAboutTab;
  $('findEmails').checked = !!v.findEmails;
}

async function saveSettings() {
  await chrome.storage.local.set({ settings: readSettings() });
}

// ---------------------------------------------------------------------------
// Settings view (SMTP + API keys), saved as `config`. Kept apart from `settings`
// (run options) and from `records`, so Clear data never touches it.
// ---------------------------------------------------------------------------
const CONFIG_DEFAULTS = {
  smtp: { host: 'smtp.gmail.com', port: 587, security: 'starttls', user: '', password: '', fromName: '' },
  groqApiKey: '',
};
const SECURITY_PORTS = { starttls: 587, ssl: 465, none: 25 };

function readConfig() {
  return {
    smtp: {
      host: $('smtpHost').value.trim(),
      port: parseInt($('smtpPort').value, 10) || SECURITY_PORTS[$('smtpSecurity').value],
      security: $('smtpSecurity').value,
      user: $('smtpUser').value.trim(),
      password: $('smtpPassword').value.replace(/\s+/g, ''), // Gmail shows app passwords in groups of 4
      fromName: $('smtpFromName').value.trim(),
    },
    groqApiKey: $('groqApiKey').value.trim(),
  };
}

function fillConfig(c) {
  const smtp = { ...CONFIG_DEFAULTS.smtp, ...(c?.smtp || {}) };
  $('smtpHost').value = smtp.host;
  $('smtpPort').value = smtp.port;
  $('smtpSecurity').value = smtp.security;
  $('smtpUser').value = smtp.user;
  $('smtpPassword').value = smtp.password;
  $('smtpFromName').value = smtp.fromName;
  $('groqApiKey').value = c?.groqApiKey ?? CONFIG_DEFAULTS.groqApiKey;
  $('smtpSummary').textContent = smtp.user ? `(${smtp.user})` : '';
}

async function saveConfig() {
  const config = readConfig();
  $('smtpSummary').textContent = config.smtp.user ? `(${config.smtp.user})` : '';
  await chrome.storage.local.set({ config });
}

function onSecurityChange() {
  // Follow the security mode only when the port is still one of the standard ones.
  const port = parseInt($('smtpPort').value, 10);
  if (!port || Object.values(SECURITY_PORTS).includes(port)) $('smtpPort').value = SECURITY_PORTS[$('smtpSecurity').value];
  saveConfig();
}

function showSettings(on) {
  $('settingsView').hidden = !on;
  $('mainView').hidden = on;
  $('settingsBtn').classList.toggle('active', on);
}

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------
// Email column of "Last 10 scraped": the best address (+N more), or why there is none.
const EMAIL_STATUS_TEXT = {
  none: ['none found', 'Website checked, no email on it'],
  no_site: ['no website', 'No website to check'],
  off: ['–', '"Find emails" was off'],
};
function fillEmailCell(td, r) {
  if (r.email) {
    const more = (r.emails || []).length - 1;
    td.textContent = more > 0 ? `${r.email} +${more}` : r.email;
    td.title = (r.emails && r.emails.length ? r.emails : [r.email]).join('\n');
    td.className = 'email-found';
    return;
  }
  // Entries saved before 1.5.0 have no emailStatus.
  const [text, title] = EMAIL_STATUS_TEXT[r.emailStatus] || ['', ''];
  td.textContent = text;
  td.title = title;
  td.className = 'email-none';
}

let lastState = {};

/** "Next in 4s" beside the ZIP while the listing delay counts down. */
function renderCountdown() {
  const s = lastState;
  const left = Math.ceil(((s.waitUntil || 0) - Date.now()) / 1000);
  const show = s.running && !s.paused && left > 0;
  $('countdown').hidden = !show;
  if (show) $('countdown').textContent = `Next in ${left}s`;
}

function render(state) {
  const s = state || {};
  lastState = s;
  renderCountdown();
  const phase = s.paused ? 'paused' : (s.phase || 'idle');
  const pill = $('phasePill');
  pill.textContent = s.paused ? 'Paused' : (PHASE_LABELS[phase] || phase);
  pill.className = `pill ${phase}`;

  $('zip').textContent = s.zip ? `${s.zip.zipcode} (id ${s.zip.id})` : '–';
  $('kw').textContent = s.keyword || '–';
  $('message').textContent = s.message || '';
  $('found').textContent = s.found || 0;
  $('done').textContent = s.done || 0;
  $('total').textContent = s.total || 0;
  $('scraped').textContent = s.scraped || 0;
  $('emailsFound').textContent = s.emailsFound || 0;
  $('newLeads').textContent = s.newLeads || 0;
  $('duplicates').textContent = s.duplicates || 0;
  $('stored').textContent = s.recordCount || 0;
  $('zips').textContent = s.zipsCompleted || 0;
  $('errorCount').textContent = s.errorCount || 0;
  $('bar').style.width = s.total ? `${Math.min(100, Math.round((s.done / s.total) * 100))}%` : '0%';

  const errs = (s.errors || []).slice(0, 3);
  $('errors').innerHTML = '';
  for (const e of errs) {
    const li = document.createElement('li');
    li.textContent = `${e.zip ? `[${e.zip}] ` : ''}${e.name ? `${e.name}: ` : ''}${e.error}`;
    li.title = e.at;
    $('errors').appendChild(li);
  }

  const tbody = $('recent').querySelector('tbody');
  tbody.innerHTML = '';
  const recent = s.recent || [];
  if (!recent.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty">Nothing yet</td></tr>';
  } else {
    for (const r of recent) {
      const tr = document.createElement('tr');
      [r.name, null, r.rating, r.phone, r.zipcode].forEach((v, col) => {
        const td = document.createElement('td');
        if (v === null) fillEmailCell(td, r);
        else if (col === 0 && r.lead) {
          // New / Dup tag from the portal (see markLeads in background.js), then the name.
          const tag = document.createElement('span');
          tag.className = `lead ${r.lead}`;
          tag.textContent = r.lead === 'new' ? 'New' : 'Dup';
          tag.title = r.lead === 'new' ? 'New lead: the portal did not have this business yet' : 'Duplicate: this business was already in the portal';
          td.append(tag, r.name || '');
          td.title = r.name || '';
        } else {
          td.textContent = v || '';
          td.title = v || '';
        }
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    }
  }

  const running = !!s.running;
  $('startBtn').disabled = running;
  $('pauseBtn').disabled = !running;
  $('stopBtn').disabled = !running;
  $('pauseBtn').textContent = s.paused ? 'Resume' : 'Pause';
  $('keyword').disabled = running;
  $('maxResults').disabled = running;
}

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------
async function send(type, extra = {}) {
  const res = await chrome.runtime.sendMessage({ type, ...extra });
  if (res && res.ok === false && res.error) alert(res.error);
  return res;
}

async function onStart() {
  const settings = readSettings();
  if (settings.minDelay > settings.maxDelay) {
    alert('Min delay must be less than or equal to max delay.');
    return;
  }
  await saveSettings();
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  await send('START', { settings, tabId: tab?.id });
}

async function onPause() {
  const { state } = await chrome.storage.local.get('state');
  await send(state?.paused ? 'RESUME' : 'PAUSE');
}

// ---------------------------------------------------------------------------
// Export
// ---------------------------------------------------------------------------
function csvCell(v) {
  if (v == null) v = '';
  else if (Array.isArray(v)) v = v.join(' | ');
  else if (typeof v === 'object') v = JSON.stringify(v);
  v = String(v);
  return /[",\r\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v;
}

function exportKeys(records) {
  const keys = [...FIELD_ORDER];
  for (const r of records) for (const k of Object.keys(r)) if (!keys.includes(k)) keys.push(k);
  return keys.filter((k) => !excludedFields.has(k));
}

function pick(record, keys) {
  const out = {};
  for (const k of keys) if (k in record) out[k] = record[k];
  return out;
}

function toCsv(records) {
  const keys = exportKeys(records);
  const lines = [keys.join(',')];
  for (const r of records) lines.push(keys.map((k) => csvCell(r[k])).join(','));
  return '﻿' + lines.join('\r\n'); // BOM so Excel reads UTF-8
}

function download(filename, content, mime) {
  const url = URL.createObjectURL(new Blob([content], { type: mime }));
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 5000);
}

const stamp = () => new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);

async function getRecords() {
  const { records = [] } = await chrome.storage.local.get('records');
  if (!records.length) alert('No records to export yet.');
  return records;
}

async function exportCsv() {
  const records = await getRecords();
  if (records.length) download(`maps-scraper_v${VERSION}_${stamp()}.csv`, toCsv(records), 'text/csv;charset=utf-8');
}

async function exportJson() {
  const records = await getRecords();
  if (!records.length) return;
  const keys = exportKeys(records);
  const payload = {
    extension_version: VERSION,
    exported_at: new Date().toISOString(),
    count: records.length,
    fields: keys,
    records: records.map((r) => pick(r, keys)),
  };
  download(`maps-scraper_v${VERSION}_${stamp()}.json`, JSON.stringify(payload, null, 2), 'application/json');
}

async function clearData() {
  if (!confirm('Delete all stored records? Export first if you need them.')) return;
  await send('CLEAR_DATA');
}

// ---------------------------------------------------------------------------
// Export field checkboxes
// ---------------------------------------------------------------------------
function updateFieldCount() {
  const selected = FIELD_ORDER.filter((f) => !excludedFields.has(f)).length;
  $('fieldCount').textContent = `(${selected} of ${FIELD_ORDER.length})`;
}

async function saveExcluded() {
  updateFieldCount();
  await chrome.storage.local.set({ excludedFields: [...excludedFields] });
}

function syncCheckboxes() {
  for (const box of document.querySelectorAll('#fieldGroups input[data-field]')) {
    box.checked = !excludedFields.has(box.dataset.field);
  }
  for (const box of document.querySelectorAll('#fieldGroups input[data-group]')) {
    const fields = FIELD_GROUPS[box.dataset.group][1].filter((f) => !LOCKED_FIELDS.includes(f));
    const on = fields.filter((f) => !excludedFields.has(f)).length;
    box.checked = on === fields.length;
    box.indeterminate = on > 0 && on < fields.length;
  }
}

function buildFieldGroups() {
  const root = $('fieldGroups');
  FIELD_GROUPS.forEach(([title, fields], gi) => {
    const group = document.createElement('fieldset');
    const legend = document.createElement('legend');
    const groupLabel = document.createElement('label');
    groupLabel.className = 'check';
    const groupBox = document.createElement('input');
    groupBox.type = 'checkbox';
    groupBox.dataset.group = gi;
    groupBox.addEventListener('change', () => {
      for (const f of fields) {
        if (LOCKED_FIELDS.includes(f)) continue;
        if (groupBox.checked) excludedFields.delete(f); else excludedFields.add(f);
      }
      syncCheckboxes();
      saveExcluded();
    });
    groupLabel.append(groupBox, ` ${title}`);
    legend.appendChild(groupLabel);
    group.appendChild(legend);

    for (const f of fields) {
      const label = document.createElement('label');
      label.className = 'check';
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.dataset.field = f;
      if (LOCKED_FIELDS.includes(f)) {
        box.disabled = true;
        label.title = 'Always exported';
      }
      box.addEventListener('change', () => {
        if (box.checked) excludedFields.delete(f); else excludedFields.add(f);
        syncCheckboxes();
        saveExcluded();
      });
      label.append(box, ` ${f}`);
      group.appendChild(label);
    }
    root.appendChild(group);
  });
}

function setAllFields(on) {
  excludedFields = on ? new Set() : new Set(FIELD_ORDER.filter((f) => !LOCKED_FIELDS.includes(f)));
  syncCheckboxes();
  saveExcluded();
}

// ---------------------------------------------------------------------------
// Init
// ---------------------------------------------------------------------------
(async function init() {
  $('title').textContent = `Maps Scraper v${VERSION}`;
  document.title = `Maps Scraper v${VERSION}`;

  const { settings, state, config, excludedFields: excluded = [] } =
    await chrome.storage.local.get(['settings', 'state', 'config', 'excludedFields']);
  fillSettings(settings);
  fillConfig(config);
  render(state);
  setInterval(renderCountdown, 200);

  excludedFields = new Set(excluded.filter((f) => !LOCKED_FIELDS.includes(f)));
  buildFieldGroups();
  syncCheckboxes();
  updateFieldCount();
  $('fieldsAll').addEventListener('click', () => setAllFields(true));
  $('fieldsNone').addEventListener('click', () => setAllFields(false));

  for (const id of SETTING_INPUTS) $(id).addEventListener('change', saveSettings);
  $('startBtn').addEventListener('click', onStart);
  $('pauseBtn').addEventListener('click', onPause);
  $('stopBtn').addEventListener('click', () => send('STOP'));
  $('csvBtn').addEventListener('click', exportCsv);
  $('jsonBtn').addEventListener('click', exportJson);
  $('clearBtn').addEventListener('click', clearData);

  $('settingsBtn').addEventListener('click', () => showSettings($('settingsView').hidden));
  $('settingsBackBtn').addEventListener('click', () => showSettings(false));
  for (const id of ['smtpHost', 'smtpPort', 'smtpUser', 'smtpPassword', 'smtpFromName', 'groqApiKey']) {
    $(id).addEventListener('change', saveConfig);
  }
  $('smtpSecurity').addEventListener('change', onSecurityChange);
  for (const btn of document.querySelectorAll('.show-btn')) {
    btn.addEventListener('click', () => {
      const input = $(btn.dataset.target);
      const hide = input.type === 'text';
      input.type = hide ? 'password' : 'text';
      btn.textContent = hide ? 'Show' : 'Hide';
    });
  }

  chrome.storage.onChanged.addListener((changes, area) => {
    if (area === 'local' && changes.state) render(changes.state.newValue);
  });
})();
