/*
 * background.js — MV3 service worker.
 * Owns all run state (persisted in chrome.storage.local so it survives the popup closing and
 * service-worker restarts), calls the ZIP API, drives the Maps tab, and stores records.
 */

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
// ZIPs come from the portal's `zipcodes` table (portal/get_zipcode.php, see portal/API.md): each
// call claims a random pending ZIP, and a claimed ZIP is never handed out again, so every call
// gets a new one. The result (done, failed, or released on Stop) is reported to ZIP_UPDATE_URL.
// Uses SAVE_API_KEY.
const ZIP_API_URL = 'https://email.demo-website.cc/get_zipcode.php';
const ZIP_UPDATE_URL = 'https://email.demo-website.cc/update_zipcode.php';

// POST scraped records to the Business Portal (portal/save_business.php, see portal/API.md).
// Body: { extension_version, records: [ ... ] }. The portal upserts on business id + zip_id, so
// retries are safe.
const SAVE_ENDPOINT = 'https://email.demo-website.cc/save_business.php';
const SAVE_API_KEY = ''; // must match API_KEY in portal/config.php ('' = no key)
const ENABLE_SAVE_ENDPOINT = true;
const SAVE_BATCH_SIZE = 1; // 1 = POST every record immediately
const SAVE_MAX_PER_REQUEST = 500; // portal MAX_RECORDS_PER_REQUEST

const EXT_VERSION = chrome.runtime.getManifest().version;
const MAPS_HOME = 'https://www.google.com/maps/?hl=en';
const MAX_ERRORS_KEPT = 50;
const ERRORS_BEFORE_RELOAD = 5; // more than this many errors in a row reloads the Maps tab
const NEXT_ZIP_ALARM = 'next-zip';
const RESUME_ALARM = 'resume-run';
const TAB_CLOSED_ALARM = 'tab-closed';
const WATCHDOG_ALARM = 'keep-alive';
const STALL_MS = 3 * 60 * 1000;
const ACTIVE_PHASES = ['searching', 'scrolling', 'scraping'];

const DEFAULT_SETTINGS = {
  keyword: 'restaurants',
  maxResults: 100,
  minDelay: 2,
  maxDelay: 5,
  autoLoop: false,
  includeSponsored: false,
  openAboutTab: true,
  findEmails: true,
};

// Email lookup on each business's website (see findEmails).
const EMAIL_FETCH_TIMEOUT_MS = 8000;
const EMAIL_MAX_EXTRA_PAGES = 2;      // contact/about pages tried when the homepage has none
const EMAIL_MAX_HTML_CHARS = 1500000;
const EMAIL_MAX_KEPT = 5;
// Sites that block fetches or never show the business's own email in the HTML.
const EMAIL_SKIP_HOSTS = /(^|\.)(facebook|instagram|twitter|x|tiktok|linkedin|youtube|yelp|google|goo|tripadvisor|doordash|ubereats|grubhub|toasttab|squareup|opentable|linktr)\.[a-z.]+$/i;

function freshState() {
  return {
    running: false,
    paused: false,
    phase: 'idle',          // idle|starting|fetching_zip|searching|scrolling|scraping|waiting|done|stopped|error
    message: 'Ready',
    tabId: null,
    zip: null,              // { id, zipcode, lat, long, status }
    zipScraped: 0,          // records saved for the current ZIP
    zipReported: false,     // current ZIP reported to the portal (done/failed/released)
    keyword: '',
    query: '',
    searchUrl: '',
    found: 0,
    done: 0,
    total: 0,
    scraped: 0,             // records saved during this run
    emailsFound: 0,         // of those, records with an email
    newLeads: 0,            // saved by the portal as a business it didn't have yet
    duplicates: 0,          // saved by the portal, but the business was already there (any ZIP)
    recordCount: 0,         // records in storage
    errorCount: 0,
    consecutiveErrors: 0,   // reset after a successful portal save; reload Maps when this passes 5
    errors: [],
    recent: [],
    doneKeys: [],           // listing keys already saved for the current ZIP
    zipsCompleted: 0,
    lastCompletedZipId: null,
    consecutiveFailures: 0,
    startedAt: null,
    updatedAt: null,
  };
}

// ---------------------------------------------------------------------------
// Storage helpers (all writes go through one serial queue to avoid races)
// ---------------------------------------------------------------------------
let queue = Promise.resolve();
function serial(fn) {
  const run = queue.then(fn, fn);
  queue = run.catch(() => {});
  return run;
}

async function getState() {
  const { state } = await chrome.storage.local.get('state');
  return { ...freshState(), ...(state || {}) };
}

/** Merge a patch (object or fn(prevState) => object) into state. Returns the new state. */
function patchState(patch) {
  return serial(async () => {
    const prev = await getState();
    const delta = typeof patch === 'function' ? patch(prev) : patch;
    const next = { ...prev, ...(delta || {}), updatedAt: new Date().toISOString() };
    await chrome.storage.local.set({ state: next });
    return next;
  });
}

function normalizeSettings(raw) {
  const s = { ...DEFAULT_SETTINGS, ...(raw || {}) };
  s.keyword = String(s.keyword || '').trim() || DEFAULT_SETTINGS.keyword;
  s.maxResults = Math.min(500, Math.max(1, parseInt(s.maxResults, 10) || DEFAULT_SETTINGS.maxResults));
  s.minDelay = Math.max(0.5, parseFloat(s.minDelay) || DEFAULT_SETTINGS.minDelay);
  s.maxDelay = Math.max(s.minDelay, parseFloat(s.maxDelay) || DEFAULT_SETTINGS.maxDelay);
  s.autoLoop = !!s.autoLoop;
  s.includeSponsored = !!s.includeSponsored;
  s.openAboutTab = !!s.openAboutTab;
  s.findEmails = !!s.findEmails;
  return s;
}

async function getSettings() {
  const { settings } = await chrome.storage.local.get('settings');
  return normalizeSettings(settings);
}

function addError(error, name = '') {
  return patchState((s) => ({
    errorCount: s.errorCount + 1,
    errors: [{ at: new Date().toISOString(), zip: s.zip?.zipcode || '', name, error }, ...s.errors].slice(0, MAX_ERRORS_KEPT),
  }));
}

let reloadInFlight = false;

/** Log an error and keep going. More than 5 in a row reloads the Maps tab. Returns { reloaded }. */
async function noteError(error, name = '') {
  const logged = await addError(error, name);
  const count = (logged.consecutiveErrors || 0) + 1;
  if (count > ERRORS_BEFORE_RELOAD && logged.running) {
    await patchState({ consecutiveErrors: 0 });
    await reloadScraperPage();
    return { reloaded: true };
  }
  await patchState({ consecutiveErrors: count });
  return { reloaded: false };
}

/** Reload the Maps tab so the content script starts again. Already-saved places stay skipped. */
async function reloadScraperPage() {
  if (reloadInFlight) return;
  reloadInFlight = true;
  try {
    const s = await getState();
    if (!s.running) return;
    const url = s.searchUrl
      || (s.zip && s.keyword ? buildSearchUrl(`${s.keyword} in ${s.zip.zipcode}`, s.zip) : MAPS_HOME);
    let tabId = s.tabId;
    try {
      if (tabId != null) await chrome.tabs.get(tabId);
      else tabId = null;
    } catch { tabId = null; }
    await patchState({
      tabId,
      phase: 'searching',
      message: 'More than 5 errors in a row — reloading Maps…',
    });
    if (tabId == null) {
      const created = await chrome.tabs.create({ url, active: true });
      await patchState({ tabId: created.id });
    } else {
      await chrome.tabs.reload(tabId, { bypassCache: true });
    }
  } catch (e) {
    await addError(`Could not reload the Maps tab: ${e.message}`);
  } finally {
    setTimeout(() => { reloadInFlight = false; }, 8000);
  }
}

const randInt = (min, max) => Math.floor(min + Math.random() * (max - min + 1));

function scheduleNextZip(minMs, maxMs) {
  chrome.alarms.create(NEXT_ZIP_ALARM, { when: Date.now() + randInt(minMs, maxMs) });
}

function clearNextZip() {
  chrome.alarms.clear(NEXT_ZIP_ALARM);
}

function armWatchdog() {
  chrome.alarms.create(WATCHDOG_ALARM, { periodInMinutes: 1 });
}

function clearWatchdog() {
  chrome.alarms.clear(WATCHDOG_ALARM);
}

function stayAwake(on) {
  if (!chrome.power) return;
  if (on) chrome.power.requestKeepAwake('display');
  else chrome.power.releaseKeepAwake();
}

async function pinTab(tabId) {
  if (tabId == null) return;
  try { await chrome.tabs.update(tabId, { autoDiscardable: false }); } catch { /* tab gone */ }
}
const isMapsUrl = (u) => /^https:\/\/www\.google\.com\/maps/.test(u || '');

// ---------------------------------------------------------------------------
// ZIP API + search URL
// ---------------------------------------------------------------------------
const apiHeaders = (json) => ({
  ...(json ? { 'Content-Type': 'application/json' } : {}),
  ...(SAVE_API_KEY ? { 'X-API-Key': SAVE_API_KEY } : {}),
});

/** Claims a random pending ZIP. Returns null when the portal has none left. */
async function fetchZip() {
  const res = await fetch(ZIP_API_URL, { cache: 'no-store', headers: apiHeaders(false), signal: AbortSignal.timeout(20000) });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { throw new Error(`API did not return JSON (HTTP ${res.status}): ${text.slice(0, 120)}`); }
  if (!res.ok || data?.ok === false) throw new Error(data?.error || `HTTP ${res.status}`);
  if (data && 'zip' in data) data = data.zip; // portal: { ok, remaining, zip }
  else if (Array.isArray(data)) data = data[0];
  if (!data) return null;
  if (!data.zipcode) throw new Error('API returned no ZIP code');
  const lat = parseFloat(data.lat);
  const lng = parseFloat(data.long ?? data.lng);
  return {
    id: String(data.id ?? ''),
    zipcode: String(data.zipcode).trim(),
    lat: Number.isFinite(lat) ? lat : null,
    long: Number.isFinite(lng) ? lng : null,
    status: data.status ?? '',
  };
}

/** Tells the portal a ZIP is done, failed or released (run stopped). Never throws. */
async function reportZip(zip, status, scraped) {
  if (!zip?.id) return;
  try {
    const res = await fetch(ZIP_UPDATE_URL, {
      method: 'POST',
      headers: apiHeaders(true),
      body: JSON.stringify({ id: zip.id, status, scraped }),
    });
    const data = await res.json().catch(() => null);
    if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  } catch (e) {
    await addError(`Could not mark ZIP ${zip.zipcode} ${status}: ${e.message}`);
  }
}

function buildSearchUrl(query, zip) {
  const q = encodeURIComponent(query).replace(/%20/g, '+');
  const at = zip.lat != null && zip.long != null ? `@${zip.lat},${zip.long},13z` : '';
  return `https://www.google.com/maps/search/${q}/${at}?hl=en`;
}

// ---------------------------------------------------------------------------
// Run control
// ---------------------------------------------------------------------------
async function getOrCreateMapsTab(hintTabId) {
  if (hintTabId != null) {
    try {
      const t = await chrome.tabs.get(hintTabId);
      if (isMapsUrl(t.url)) return t.id;
    } catch { /* tab gone */ }
  }
  const t = await chrome.tabs.create({ url: MAPS_HOME, active: true });
  await pinTab(t.id);
  return t.id;
}

async function startRun(msg) {
  const s = await getState();
  if (s.running) return { ok: false, error: 'Already running' };
  const settings = normalizeSettings(msg.settings);
  await chrome.storage.local.set({ settings });
  const tabId = await getOrCreateMapsTab(msg.tabId);
  await patchState((prev) => ({
    ...freshState(),
    recordCount: prev.recordCount,
    recent: prev.recent,
    running: true,
    tabId,
    phase: 'starting',
    message: 'Starting…',
    startedAt: new Date().toISOString(),
  }));
  startNextZip();
  stayAwake(true);
  armWatchdog();
  pinTab(tabId);
  return { ok: true };
}

/** Fetch a ZIP and point the Maps tab at the search. Safe to call twice (claims the phase atomically). */
async function startNextZip() {
  let claimed = false;
  const s = await patchState((prev) => {
    if (prev.running && !prev.paused && (prev.phase === 'starting' || prev.phase === 'waiting')) {
      claimed = true;
      return { phase: 'fetching_zip', message: 'Fetching ZIP from API…' };
    }
    return {};
  });
  if (!claimed) return;

  let zip;
  try {
    zip = await fetchZip();
  } catch (e) {
    await noteError(`ZIP API failed: ${e.message}`);
    await patchState({ phase: 'waiting', message: `ZIP API failed: ${e.message} — trying again…` });
    scheduleNextZip(8000, 15000);
    return;
  }
  if (!zip) return finishRun('done', 'No pending ZIP codes left.');
  if (s.lastCompletedZipId && zip.id && zip.id === s.lastCompletedZipId) {
    await noteError(`API returned ZIP ${zip.zipcode} (id ${zip.id}) again — trying another.`);
    await patchState({ phase: 'waiting', message: 'Same ZIP returned — trying again…' });
    scheduleNextZip(8000, 15000);
    return;
  }

  const settings = await getSettings();
  const query = `${settings.keyword} in ${zip.zipcode}`;
  const searchUrl = buildSearchUrl(query, zip);
  await patchState({
    zip, keyword: settings.keyword, query, searchUrl,
    phase: 'searching', message: `Opening Maps: ${query}`,
    found: 0, done: 0, total: 0, doneKeys: [], zipScraped: 0, zipReported: false,
  });
  try {
    await chrome.tabs.update(s.tabId, { url: searchUrl, active: true });
  } catch {
    try {
      const created = await chrome.tabs.create({ url: searchUrl, active: true });
      await patchState({ tabId: created.id });
    } catch (e) {
      await noteError(`Could not open the Maps tab: ${e.message}`);
      await patchState({ phase: 'waiting', message: 'Could not open Maps — trying again…' });
      scheduleNextZip(5000, 10000);
      return;
    }
  }
  // Content script announces itself with CONTENT_READY; tabs.onUpdated is the fallback.
}

async function dispatchJob(tabId) {
  const s = await getState();
  if (!s.running || !s.zip || tabId !== s.tabId) return;
  const settings = await getSettings();
  const job = {
    zip: s.zip,
    keyword: s.keyword,
    query: s.query,
    searchUrl: s.searchUrl,
    settings,
    skipKeys: s.doneKeys,
    paused: s.paused,
  };
  try {
    await chrome.tabs.sendMessage(tabId, { type: 'RUN_ZIP', job });
  } catch (e) {
    console.warn('[MapsScraper] RUN_ZIP failed:', e.message);
  }
}

async function ensureContent(tabId) {
  try {
    await chrome.tabs.sendMessage(tabId, { type: 'PING' });
  } catch {
    try {
      await chrome.scripting.executeScript({ target: { tabId }, files: ['selectors.js', 'content.js'] });
    } catch (e) {
      console.warn('[MapsScraper] inject failed:', e.message);
    }
  }
}

async function pauseRun(paused) {
  const s = await patchState((prev) => (prev.running ? { paused, message: paused ? 'Paused' : 'Resumed' } : {}));
  if (!s.running) return { ok: false, error: 'Not running' };
  if (s.tabId != null) chrome.tabs.sendMessage(s.tabId, { type: paused ? 'PAUSE' : 'RESUME' }).catch(() => {});
  if (paused) clearNextZip();
  if (!paused && s.phase === 'waiting') startNextZip();
  return { ok: true };
}

async function stopRun() {
  await finishRun('stopped', 'Stopped by user');
  return { ok: true };
}

async function finishRun(phase, message) {
  let release = null;
  const s = await patchState((prev) => {
    if (prev.zip && !prev.zipReported) release = prev.zip;
    return { running: false, paused: false, phase, message, zipReported: true };
  });
  if (phase === 'error') await addError(message);
  // Stopped (or broke) before the ZIP was reported: the portal marks it stopped, and the next run gets a new ZIP.
  clearNextZip();
  clearWatchdog();
  stayAwake(false);
  if (release) reportZip(release, 'released');
  if (s.tabId != null) chrome.tabs.sendMessage(s.tabId, { type: 'STOP' }).catch(() => {});
  if (ENABLE_SAVE_ENDPOINT) queueSave(null, true);
}

async function clearData() {
  await serial(async () => {
    const s = await getState();
    await chrome.storage.local.set({ records: [], state: { ...s, recent: [], recordCount: 0, scraped: 0 } });
  });
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Emails from the business website
// ---------------------------------------------------------------------------
const EMAIL_RE = /[a-z0-9][a-z0-9._+-]{0,63}@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,24}/gi;
const EMAIL_JUNK_TLD = /\.(png|jpe?g|gif|svg|webp|avif|ico|css|js|json|php|html?|woff2?|ttf|mp4)$/i;
const EMAIL_JUNK_DOMAIN = /(^|\.)(example\.(com|org|net)|domain\.com|yourdomain\.com|email\.com|mysite\.com|website\.com|sentry\.io|sentry-next\.wixpress\.com|wixpress\.com|sentry\.wixpress\.com|godaddy\.com|wordpress\.(com|org)|squarespace\.com|schema\.org)$/i;
const EMAIL_JUNK_LOCAL = /^(user|name|your|yourname|youremail|email|example|test|someone|john\.?doe|firstname\.?lastname)$|^[0-9a-f]{24,}$/i;

const bareHost = (h) => String(h || '').toLowerCase().replace(/^www\./, '');

/** Cloudflare "email protection" hides addresses as hex in data-cfemail / #cfemail links. */
function decodeCfEmail(hex) {
  try {
    const key = parseInt(hex.slice(0, 2), 16);
    let out = '';
    for (let i = 2; i < hex.length; i += 2) out += String.fromCharCode(parseInt(hex.slice(i, i + 2), 16) ^ key);
    return out;
  } catch { return ''; }
}

function emailsInHtml(html) {
  const text = html
    .replace(/&#0*64;|&#x0*40;|&commat;/gi, '@')
    .replace(/&#0*46;|&#x0*2e;|&period;/gi, '.')
    .replace(/%40/g, '@')
    // Other URL-encoded characters ("04%7c01%7cname@site.com" = "04|01|name@site.com"), so the junk
    // in front isn't read as part of the address.
    .replace(/%([0-9a-f]{2})/gi, (m, hex) => { const c = String.fromCharCode(parseInt(hex, 16)); return c >= ' ' && c <= '~' ? c : ' '; })
    .replace(/\s*[[(]\s*at\s*[\])]\s*/gi, '@')
    .replace(/\s*[[(]\s*dot\s*[\])]\s*/gi, '.');
  const found = text.match(EMAIL_RE) || [];
  for (const m of html.matchAll(/(?:data-cfemail=["']|\/cdn-cgi\/l\/email-protection#)([0-9a-f]{4,})/gi)) {
    const e = decodeCfEmail(m[1]);
    if (e.includes('@')) found.push(e);
  }
  return found
    .map((e) => e.toLowerCase().replace(/^(mailto:|u003e|x3e)/, '').replace(/[.-]+$/, ''))
    .filter((e) => {
      const [local, domain] = e.split('@');
      return local && domain && !EMAIL_JUNK_TLD.test(e) && !EMAIL_JUNK_DOMAIN.test(domain) && !EMAIL_JUNK_LOCAL.test(local);
    });
}

/** Same-site links that look like contact/about pages, contact pages first. */
function contactLinks(html, baseUrl) {
  const base = new URL(baseUrl);
  const scored = new Map();
  for (const m of html.matchAll(/<a\b[^>]*?href\s*=\s*["']([^"'#]+)["'][^>]*>([\s\S]{0,300}?)<\/a>/gi)) {
    const hint = `${m[1]} ${m[2].replace(/<[^>]+>/g, ' ')}`;
    const score = /contact|get[\s-]*in[\s-]*touch|reach[\s-]*us/i.test(hint) ? 2 : /about|team|info/i.test(hint) ? 1 : 0;
    if (!score) continue;
    let u;
    try { u = new URL(m[1].trim(), base); } catch { continue; }
    if (!/^https?:$/.test(u.protocol) || bareHost(u.hostname) !== bareHost(base.hostname)) continue;
    u.hash = '';
    if (u.href === base.href || /\.(pdf|jpe?g|png|zip)$/i.test(u.pathname)) continue;
    scored.set(u.href, Math.max(score, scored.get(u.href) || 0));
  }
  const links = [...scored].sort((a, b) => b[1] - a[1]).map(([href]) => href);
  for (const p of ['/contact', '/contact-us']) {
    const href = new URL(p, base).href;
    if (!links.includes(href)) links.push(href);
  }
  return links;
}

async function fetchHtml(url) {
  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), EMAIL_FETCH_TIMEOUT_MS);
  try {
    const res = await fetch(url, { signal: ctrl.signal, credentials: 'omit', redirect: 'follow', cache: 'no-store' });
    if (!res.ok || !/html|text\/plain/i.test(res.headers.get('content-type') || 'text/html')) return null;
    return { html: (await res.text()).slice(0, EMAIL_MAX_HTML_CHARS), url: res.url || url };
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

/**
 * Look for email addresses on a business website: the homepage, then up to
 * EMAIL_MAX_EXTRA_PAGES contact/about pages if the homepage has none. Addresses on the site's
 * own domain come first. Never throws; returns [] when nothing is found.
 */
async function findEmails(website) {
  let start;
  try { start = new URL(website); } catch { return []; }
  if (!/^https?:$/.test(start.protocol) || EMAIL_SKIP_HOSTS.test(start.hostname)) return [];

  const emails = new Set();
  const home = await fetchHtml(start.href);
  if (!home) return [];
  emailsInHtml(home.html).forEach((e) => emails.add(e));
  if (!emails.size) {
    const pages = contactLinks(home.html, home.url).slice(0, EMAIL_MAX_EXTRA_PAGES);
    const results = await Promise.all(pages.map(fetchHtml));
    results.forEach((p) => p && emailsInHtml(p.html).forEach((e) => emails.add(e)));
  }

  const site = bareHost(new URL(home.url).hostname);
  const ownDomain = (e) => { const d = e.split('@')[1]; return d === site || site.endsWith(`.${d}`) || d.endsWith(`.${site}`); };
  return [...emails].sort((a, b) => ownDomain(b) - ownDomain(a)).slice(0, EMAIL_MAX_KEPT);
}

/** Adds `email` (best match) and `emails` (all found) to a record when the setting is on. */
async function addEmails(record) {
  const settings = await getSettings();
  if (!settings.findEmails || !record.website) return record;
  await patchState({ message: `Looking for emails: ${record.name || record.website}` });
  const emails = await findEmails(record.website);
  return { ...record, email: emails[0] || '', emails };
}

// ---------------------------------------------------------------------------
// Records
// ---------------------------------------------------------------------------
function appendRecord(raw) {
  return serial(async () => {
    const s = await getState();
    if (!s.zip) return { ok: false, error: 'No active ZIP' };
    const key = raw.listing_key || raw.place_id || raw.cid || raw.google_maps_url || raw.name;
    if (s.doneKeys.includes(key)) return { ok: true, duplicate: true };

    const record = {
      ...raw,
      zip_id: s.zip.id,
      zipcode: s.zip.zipcode,
      zip_lat: s.zip.lat ?? '',
      zip_long: s.zip.long ?? '',
      search_keyword: s.keyword,
      search_query: s.query,
      scraped_at: new Date().toISOString(),
      extension_version: EXT_VERSION,
    };
    delete record.listing_key;

    const { records = [] } = await chrome.storage.local.get('records');
    records.push(record);
    // found | none (website checked, nothing on it) | no_site | off (lookup disabled)
    const emailStatus = record.email ? 'found'
      : Array.isArray(record.emails) ? 'none'
      : !record.website ? 'no_site' : 'off';
    const recent = [
      {
        name: record.name, rating: record.rating, phone: record.phone, zipcode: record.zipcode, category: record.category,
        email: record.email || '', emails: Array.isArray(record.emails) ? record.emails : [], emailStatus,
        scrapedAt: record.scraped_at, lead: '', // lead: new | duplicate once the portal has saved it
      },
      ...s.recent,
    ].slice(0, 10);
    const next = {
      ...s,
      doneKeys: [...s.doneKeys, key],
      scraped: s.scraped + 1,
      zipScraped: (s.zipScraped || 0) + 1,
      emailsFound: (s.emailsFound || 0) + (record.email ? 1 : 0),
      recordCount: records.length,
      recent,
      updatedAt: new Date().toISOString(),
    };
    await chrome.storage.local.set({ records, state: next });
    return { ok: true, record };
  }).then((r) => {
    if (ENABLE_SAVE_ENDPOINT && r?.record) queueSave(r.record);
    return { ok: !!r?.ok, duplicate: r?.duplicate };
  });
}

/**
 * POST one batch to the portal. Returns true when the batch is finished with (saved, or rejected
 * record-by-record so a retry can't help); false when it should stay pending and be retried.
 */
async function postBatch(batch) {
  const headers = { 'Content-Type': 'application/json' };
  if (SAVE_API_KEY) headers['X-API-Key'] = SAVE_API_KEY;
  let res, data;
  try {
    res = await fetch(SAVE_ENDPOINT, {
      method: 'POST',
      headers,
      body: JSON.stringify({ extension_version: EXT_VERSION, records: batch }),
      signal: AbortSignal.timeout(20000),
    });
    data = await res.json().catch(() => null);
  } catch (e) {
    await noteError(`Save endpoint unreachable (${batch.length} pending): ${e.message}`);
    return false;
  }

  // 422 = every record failed, 200 + ok:false = some failed. Both are data problems: log and drop.
  if (data && Array.isArray(data.errors) && (res.ok || res.status === 422)) {
    for (const err of data.errors.slice(0, 10)) {
      await noteError(`Save rejected: ${err.error}`, err.name || batch[err.index]?.name || '');
    }
    if (data.errors.length > 10) await noteError(`Save rejected ${data.errors.length - 10} more records`);
    await markLeads(batch, data.results);
    return true;
  }
  if (res.ok && data?.ok) {
    await markLeads(batch, data.results);
    await patchState({ consecutiveErrors: 0 });
    return true;
  }

  // 400/401/405/413/500 or a non-JSON reply: keep the batch and retry on the next flush.
  const why = data?.error || `HTTP ${res.status}`;
  await noteError(`Save endpoint failed (${batch.length} pending): ${why}`);
  return false;
}

/**
 * Counts the portal's verdict on each saved record (`results` from save_business.php: new = a business
 * it didn't have, duplicate = already saved for some ZIP) and tags the matching row in "Last 10 scraped".
 */
function markLeads(batch, results) {
  if (!Array.isArray(results) || !results.length) return Promise.resolve();
  return patchState((prev) => {
    let newLeads = prev.newLeads || 0;
    let duplicates = prev.duplicates || 0;
    const recent = prev.recent.map((r) => ({ ...r }));
    for (const res of results) {
      const rec = batch[res.index];
      if (!rec || (res.status !== 'new' && res.status !== 'duplicate')) continue;
      if (res.status === 'new') newLeads++;
      else duplicates++;
      const row = recent.find((r) => r.scrapedAt === rec.scraped_at && r.name === rec.name);
      if (row) row.lead = res.status;
    }
    return { newLeads, duplicates, recent };
  });
}

// Server upload, batched; pending records survive service-worker restarts.
let saveChain = Promise.resolve();
function queueSave(record, flush = false) {
  saveChain = saveChain.then(async () => {
    let { pendingSave = [] } = await chrome.storage.local.get('pendingSave');
    if (record) pendingSave.push(record);
    if (!pendingSave.length || (!flush && pendingSave.length < SAVE_BATCH_SIZE)) {
      await chrome.storage.local.set({ pendingSave });
      return;
    }
    while (pendingSave.length) {
      const batch = pendingSave.slice(0, SAVE_MAX_PER_REQUEST);
      if (!(await postBatch(batch))) break;
      pendingSave = pendingSave.slice(batch.length);
    }
    await chrome.storage.local.set({ pendingSave });
  }).catch(() => {});
  return saveChain;
}

// ---------------------------------------------------------------------------
// ZIP completion
// ---------------------------------------------------------------------------
async function onZipDone(result = {}) {
  const s = await patchState((prev) => ({
    zipsCompleted: prev.zipsCompleted + 1,
    lastCompletedZipId: prev.zip?.id ?? null,
    consecutiveFailures: 0,
    consecutiveErrors: 0,
    zipReported: true,
  }));
  await reportZip(s.zip, 'done', s.zipScraped || 0);
  if (ENABLE_SAVE_ENDPOINT) queueSave(null, true);
  if (!s.running) return;
  const summary = `ZIP ${s.zip?.zipcode}: ${result.scraped ?? 0} scraped, ${result.errors ?? 0} errors` +
    (result.alreadyDone ? `, ${result.alreadyDone} already done` : '');
  const settings = await getSettings();
  if (settings.autoLoop) {
    await patchState({ phase: 'waiting', message: `${summary} — next ZIP soon…` });
    scheduleNextZip(3000, 8000);
  } else {
    await finishRun('done', `Finished. ${summary}`);
  }
}

async function onZipFailed(error) {
  const { reloaded } = await noteError(`ZIP failed: ${error}`);
  const s = await getState();
  if (!s.running || reloaded) return;
  const settings = await getSettings();
  if (settings.autoLoop) {
    await reportZip(s.zip, 'failed', s.zipScraped || 0);
    await patchState({
      phase: 'waiting', zipReported: true, consecutiveFailures: (s.consecutiveFailures || 0) + 1,
      message: `ZIP ${s.zip?.zipcode} failed: ${error} — next ZIP…`,
    });
    scheduleNextZip(5000, 10000);
  } else {
    await patchState({ phase: 'searching', message: `ZIP error: ${error} — trying the page again…` });
    if (s.tabId != null) dispatchJob(s.tabId);
  }
}

// ---------------------------------------------------------------------------
// Message routing
// ---------------------------------------------------------------------------
async function onContentMessage(msg, tabId) {
  const s = await getState();
  if (tabId !== s.tabId) return { ok: false, ignored: true };

  switch (msg.type) {
    case 'CONTENT_READY':
      if (s.running && s.zip && ACTIVE_PHASES.includes(s.phase)) dispatchJob(tabId);
      return { ok: true };
    case 'PROGRESS':
      if (!s.running) {
        chrome.tabs.sendMessage(tabId, { type: 'STOP' }).catch(() => {});
        return { ok: false, stop: true };
      }
      await patchState({
        phase: msg.phase || s.phase,
        message: msg.message || s.message,
        found: msg.found ?? s.found,
        done: msg.done ?? s.done,
        total: msg.total ?? s.total,
        waitUntil: msg.waitUntil ?? 0,
      });
      return { ok: true };
    case 'RECORD':
      return appendRecord(await addEmails(msg.record || {}));
    case 'LISTING_ERROR':
      await noteError(msg.error || 'Unknown error', msg.name || '');
      return { ok: true };
    case 'ZIP_DONE':
      if (s.running) await onZipDone(msg.result);
      return { ok: true };
    case 'ZIP_FAILED':
      if (s.running) await onZipFailed(msg.error || 'Unknown error');
      return { ok: true };
    case 'ZIP_STOPPED':
    case 'HEARTBEAT':
      await patchState({});
      return { ok: true };
    default:
      return { ok: false, error: `Unknown message ${msg.type}` };
  }
}

async function route(msg, sender) {
  if (sender.tab?.id != null) return onContentMessage(msg, sender.tab.id);
  switch (msg?.type) {
    case 'START': return startRun(msg);
    case 'PAUSE': return pauseRun(true);
    case 'RESUME': return pauseRun(false);
    case 'STOP': return stopRun();
    case 'CLEAR_DATA': return clearData();
    default: return { ok: false, error: `Unknown message ${msg?.type}` };
  }
}

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === NEXT_ZIP_ALARM) startNextZip();
  if (alarm.name === RESUME_ALARM) resumeAfterRestart();
  if (alarm.name === TAB_CLOSED_ALARM) confirmTabClosed();
  if (alarm.name === WATCHDOG_ALARM) keepAlive();
});

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  route(msg, sender).then(
    (r) => sendResponse(r || { ok: true }),
    (e) => sendResponse({ ok: false, error: String(e?.message || e) }),
  );
  return true; // async response
});

// ---------------------------------------------------------------------------
// Tab events
// ---------------------------------------------------------------------------
chrome.tabs.onUpdated.addListener(async (tabId, info, tab) => {
  if (info.status !== 'complete') return;
  const s = await getState();
  if (!s.running || tabId !== s.tabId) return;
  const url = tab.url || '';
  if (/^https:\/\/consent\.google\./.test(url)) {
    await noteError('Google consent page shown — accept it in the tab. The run keeps going.');
    return;
  }
  if (/^https:\/\/(www\.)?google\.[^/]+\/sorry\//.test(url)) {
    await noteError('Google is showing a CAPTCHA ("unusual traffic"). Solve it. The run keeps going.');
    return;
  }
  if (!isMapsUrl(url)) return;
  // Fallback: if the content script didn't announce itself, inject it.
  setTimeout(() => ensureContent(tabId), 3000);
});

chrome.tabs.onRemoved.addListener(async (tabId) => {
  const s = await getState();
  // Chrome also fires this while quitting. Wait briefly so a browser restart can
  // cancel it and continue the run instead of ending it.
  if (s.running && tabId === s.tabId) chrome.alarms.create(TAB_CLOSED_ALARM, { when: Date.now() + 4000 });
});

async function confirmTabClosed() {
  const s = await getState();
  if (!s.running || s.tabId == null) return;
  try {
    await chrome.tabs.get(s.tabId);
  } catch {
    await noteError('The scraper tab was closed. Reopening…');
    await reloadScraperPage();
  }
}

/** Continue a run after Chrome quits or crashes. Places already saved stay in doneKeys. */
async function resumeAfterRestart() {
  let s = await getState();
  const interrupted = !s.running && s.phase === 'stopped'
    && /Browser restarted/.test(s.message || '') && s.zip && !s.zipReported;
  if (s.paused) return;
  if (!s.running && !interrupted) return;
  if (interrupted) s = await patchState({ running: true, paused: false });
  await chrome.alarms.clear(TAB_CLOSED_ALARM);

  if (!s.zip || s.phase === 'waiting' || s.phase === 'starting' || s.phase === 'fetching_zip') {
    if (s.phase === 'waiting') {
      await patchState({ message: 'Browser restarted — next ZIP…' });
      scheduleNextZip(3000, 8000);
      return;
    }
    await patchState({ phase: 'starting', message: 'Browser restarted — continuing…' });
    startNextZip();
    return;
  }

  const url = s.searchUrl
    || (s.keyword ? buildSearchUrl(`${s.keyword} in ${s.zip.zipcode}`, s.zip) : MAPS_HOME);
  let tabId = null;
  try {
    const tabs = await chrome.tabs.query({ url: 'https://www.google.com/maps/*' });
    tabId = tabs[0]?.id ?? null;
  } catch { tabId = null; }
  if (tabId == null) {
    const created = await chrome.tabs.create({ url: 'about:blank', active: true });
    tabId = created.id;
  }
  await patchState({
    tabId,
    phase: 'searching',
    message: `Browser restarted — continuing ZIP ${s.zip.zipcode}…`,
  });
  try {
    await chrome.tabs.update(tabId, { url, active: true });
  } catch (e) {
    await noteError(`Could not reopen Maps after the browser restarted: ${e.message}`);
    chrome.alarms.create(RESUME_ALARM, { when: Date.now() + 8000 });
  }
}

// ---------------------------------------------------------------------------
// Lifecycle
// ---------------------------------------------------------------------------
chrome.runtime.onInstalled.addListener(async () => {
  const { settings } = await chrome.storage.local.get('settings');
  await chrome.storage.local.set({ settings: normalizeSettings(settings) });
  const s = await getState();
  if (s.running && !s.paused) {
    stayAwake(true);
    armWatchdog();
    chrome.alarms.create(RESUME_ALARM, { when: Date.now() + 1500 });
  }
});

chrome.runtime.onStartup.addListener(() => {
  chrome.alarms.clear(TAB_CLOSED_ALARM);
  chrome.alarms.create(RESUME_ALARM, { when: Date.now() + 1500 });
});

// Keep a live run armed after the service worker wakes. Do not reload the page here.
getState().then((s) => {
  if (s.running && !s.paused) {
    stayAwake(true);
    armWatchdog();
  }
  const interrupted = !s.running && s.phase === 'stopped'
    && /Browser restarted/.test(s.message || '') && s.zip && !s.zipReported;
  if (interrupted) chrome.alarms.create(RESUME_ALARM, { when: Date.now() + 1500 });
});

/** Once a minute: retry a stalled Maps tab, and send any businesses still waiting. */
async function keepAlive() {
  const s = await getState();
  if (!s.running || s.paused) return;
  stayAwake(true);
  await pinTab(s.tabId);

  const age = s.updatedAt ? Date.now() - new Date(s.updatedAt).getTime() : STALL_MS + 1;
  const stalled = age > STALL_MS;

  let tabAlive = false;
  if (s.tabId != null) {
    try {
      const tab = await chrome.tabs.get(s.tabId);
      tabAlive = true;
      if (tab.discarded) {
        await chrome.tabs.reload(s.tabId);
        await patchState({ message: 'Maps tab was asleep — reloaded.' });
        return;
      }
    } catch { tabAlive = false; }
  }
  if (!tabAlive) {
    await reloadScraperPage();
    return;
  }

  if (s.phase === 'waiting' || s.phase === 'starting' || (stalled && s.phase === 'fetching_zip')) {
    const pending = await chrome.alarms.get(NEXT_ZIP_ALARM);
    if (!pending) {
      if (s.phase === 'fetching_zip') await patchState({ phase: 'starting' });
      scheduleNextZip(2000, 5000);
    }
    return;
  }

  if (stalled && s.zip && ACTIVE_PHASES.includes(s.phase)) {
    if (ENABLE_SAVE_ENDPOINT) queueSave(null, true);
    await patchState({ message: 'No progress for 3 minutes — reloading Maps…' });
    await reloadScraperPage();
  }
}
