/*
 * background.js — MV3 service worker.
 * The loop: get one business that has an email and was never mailed from the portal, have Groq
 * write an email for it from all its details (portal generate_email.php), fill Gmail's compose
 * window and send it, record the result in the portal, wait the set delay, repeat.
 * All run state lives in chrome.storage.local and tick() does the next step from whatever phase
 * is stored, so the run carries on after the service worker is restarted.
 */

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
// Business Portal (see portal/API.md). On another machine, replace localhost with the server's
// IP or domain and add that host to manifest.json "host_permissions".
const PORTAL_URL = 'http://localhost/business_extractor/portal/';
const PORTAL_API_KEY = ''; // must match API_KEY in portal/config.php ('' = no key)
const LEASE_MINUTES = 15;  // how long the portal holds a business for us; longer than writing + sending one email

const EXT_VERSION = chrome.runtime.getManifest().version;
const TICK_MS = 1000;
const PAGE_LOAD_TIMEOUT_MS = 60000;   // Gmail must show its Compose button by then
const INJECT_AFTER_MS = 15000;        // inject content.js if it hasn't answered by then
const WRITE_TIMEOUT_MS = 150000;      // generate_email.php (Groq) must answer by then
const FAIL_DELAY_MS = 5000;           // wait after a failed business (nothing was sent, no need for the full delay)
const MAX_OPEN_ATTEMPTS = 2;          // Gmail reloads per business before it counts as failed
const MAX_CONSECUTIVE_ERRORS = 3;
const MAX_ERRORS_KEPT = 50;
const MAX_RECENT = 10;
const GMAIL_URL_RE = /^https:\/\/mail\.google\.com\//;
const DEFAULT_GMAIL_URL = 'https://mail.google.com/mail/u/0/#inbox';
// Groq problems that will fail every business the same way: stop instead of burning through them.
const FATAL_WRITE_ERROR = /no groq api key|refused the api key|model:/i;

const TONES = ['friendly', 'professional', 'short', 'casual', 'persuasive'];
const HIGHLIGHTS = ['bold', 'none']; // a saved 'highlight' (bold on yellow, before 1.2.1) becomes the default
const LENGTHS = ['short', 'medium', 'long'];
const SOURCES = ['', 'website', 'chatgpt', 'claude'];

const DEFAULT_SETTINGS = {
  gmailUrl: DEFAULT_GMAIL_URL,
  zipcode: '',
  category: '',
  source: '',        // '' any | website | chatgpt | claude (where the email address came from)
  minDelay: 60,      // seconds between emails (random between min and max)
  maxDelay: 180,
  maxPerRun: 50,     // emails sent, 0 = no limit
  maxPerDay: 100,    // emails sent today (all runs), 0 = no limit
  runMinutes: 0,     // stop the run after this long, 0 = no limit
  tone: 'friendly',
  length: 'medium',
  brief: '',         // extra instructions for Groq
  allowUntracked: false, // Mailtrack's Send ignored the real click: send untracked (true) or not at all (false)
  highlight: 'bold',     // details taken from the business: bold | none (plain text)
};

function freshState() {
  return {
    running: false,
    paused: false,
    testMode: false,        // fill one email in Gmail and stop, without sending
    phase: 'idle',          // idle|opening|fetching|writing|sending|saving|waiting|done|stopped|error
    message: 'Ready',
    tabId: null,
    gmailUrl: null,         // the Gmail inbox URL of the run's tab (keeps /u/N/)
    account: '',            // Gmail address the tab is signed in to
    business: null,         // the business being mailed
    recipients: [],
    mail: null,             // {subject, body, text, html} from generate_email.php
    phaseAt: null,          // ms, when the current phase started
    reloadAt: null,         // ms, when the Gmail tab was last reloaded by us
    openAttempts: 0,
    nextAt: null,           // ms, when "waiting" moves on
    runUntil: null,         // ms, end of the run time (runMinutes)
    sent: 0,                // this run
    untracked: 0,           // sent this run without Mailtrack tracking
    failed: 0,
    errorCount: 0,
    consecutiveErrors: 0,
    remaining: null,        // from the portal
    pendingSaves: [],       // sent emails the portal hasn't recorded yet
    errors: [],
    recent: [],
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

/** Patch only while the run is still going. Returns the new state, or null if it was stopped. */
async function patchRunning(delta) {
  const s = await patchState((p) => (p.running ? delta : {}));
  return s.running ? s : null;
}

const int = (v, min, max, dflt) => {
  const n = parseInt(v, 10);
  return Number.isFinite(n) ? Math.min(max, Math.max(min, n)) : dflt;
};

function normalizeSettings(raw) {
  const s = { ...DEFAULT_SETTINGS, ...(raw || {}) };
  s.gmailUrl = GMAIL_URL_RE.test(String(s.gmailUrl || '').trim()) ? String(s.gmailUrl).trim() : DEFAULT_GMAIL_URL;
  s.zipcode = String(s.zipcode || '').trim();
  s.category = String(s.category || '').trim();
  s.source = SOURCES.includes(s.source) ? s.source : '';
  s.minDelay = int(s.minDelay, 0, 86400, DEFAULT_SETTINGS.minDelay);
  s.maxDelay = Math.max(s.minDelay, int(s.maxDelay, 0, 86400, DEFAULT_SETTINGS.maxDelay));
  s.maxPerRun = int(s.maxPerRun, 0, 100000, 0);
  s.maxPerDay = int(s.maxPerDay, 0, 100000, 0);
  s.runMinutes = int(s.runMinutes, 0, 100000, 0);
  s.tone = TONES.includes(s.tone) ? s.tone : DEFAULT_SETTINGS.tone;
  s.length = LENGTHS.includes(s.length) ? s.length : DEFAULT_SETTINGS.length;
  s.brief = String(s.brief || '').slice(0, 2000);
  s.allowUntracked = !!s.allowUntracked;
  s.highlight = HIGHLIGHTS.includes(s.highlight) ? s.highlight : DEFAULT_SETTINGS.highlight;
  return s;
}

async function getSettings() {
  const { settings } = await chrome.storage.local.get('settings');
  return normalizeSettings(settings);
}

// Emails sent today (local date), across runs, for maxPerDay.
const today = () => new Date().toLocaleDateString('en-CA'); // YYYY-MM-DD
async function sentToday() {
  const { daily } = await chrome.storage.local.get('daily');
  return daily?.date === today() ? daily.count : 0;
}
async function countSentToday() {
  const count = (await sentToday()) + 1;
  await chrome.storage.local.set({ daily: { date: today(), count } });
  return count;
}

function addError(error, name = '') {
  return patchState((s) => ({
    errorCount: s.errorCount + 1,
    errors: [{ at: new Date().toISOString(), name, error }, ...s.errors].slice(0, MAX_ERRORS_KEPT),
  }));
}

function addRecent(business, status, to = [], subject = '', note = '', tracked = null) {
  return patchState((s) => ({
    recent: [
      { at: new Date().toISOString(), id: business.id, name: business.name, status, to, subject, note, tracked },
      ...s.recent,
    ].slice(0, MAX_RECENT),
  }));
}

const rand = (min, max) => min + Math.random() * (max - min);

// ---------------------------------------------------------------------------
// Portal
// ---------------------------------------------------------------------------
async function portal(path, { query, body, timeoutMs = 30000 } = {}) {
  const url = new URL(path, PORTAL_URL);
  for (const [k, v] of Object.entries(query || {})) {
    if (v !== '' && v != null) url.searchParams.set(k, v);
  }
  const headers = {};
  if (PORTAL_API_KEY) headers['X-API-Key'] = PORTAL_API_KEY;
  if (body) headers['Content-Type'] = 'application/json';
  const res = await fetch(url, {
    method: body ? 'POST' : 'GET',
    headers,
    body: body ? JSON.stringify({ extension_version: EXT_VERSION, ...body }) : undefined,
    cache: 'no-store',
    signal: AbortSignal.timeout(timeoutMs),
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  return data;
}

/** Give a business back to the portal right away (run stopped before it was sent). */
function releaseBusiness(business) {
  if (!business?.id) return Promise.resolve();
  return portal('save_email_result.php', { body: { business_id: business.id, status: 'released' } }).catch(() => {});
}

/**
 * Records sent emails the portal hasn't confirmed yet. A sent email that isn't recorded would be
 * sent again, so these are kept in state and retried before anything else.
 */
async function flushPendingSaves() {
  const { pendingSaves } = await getState();
  for (const save of pendingSaves) {
    try {
      await portal('save_email_result.php', { body: save });
    } catch (e) {
      return e.message;
    }
    await patchState((p) => ({ pendingSaves: p.pendingSaves.filter((x) => x.key !== save.key) }));
  }
  return null;
}

// ---------------------------------------------------------------------------
// Gmail tab
// ---------------------------------------------------------------------------
async function tabMessage(tabId, msg) {
  try {
    return await chrome.tabs.sendMessage(tabId, msg);
  } catch {
    return null; // no content script (page loading, or not a Gmail page)
  }
}

async function injectContent(tabId) {
  try {
    await chrome.scripting.executeScript({ target: { tabId }, files: ['selectors.js', 'content.js'] });
  } catch (e) {
    console.warn('[GmailMailer] inject failed:', e.message);
  }
}

/** The tab to send from: the active Gmail tab, or a new one. Returns {tabId, gmailUrl}. */
async function getOrCreateGmailTab(hintTabId, settings) {
  if (hintTabId != null) {
    try {
      const t = await chrome.tabs.get(hintTabId);
      if (GMAIL_URL_RE.test(t.url || '')) return { tabId: t.id, gmailUrl: `${t.url.split('#')[0]}#inbox` };
    } catch { /* tab gone */ }
  }
  const t = await chrome.tabs.create({ url: settings.gmailUrl, active: true });
  return { tabId: t.id, gmailUrl: settings.gmailUrl };
}

/** Reload Gmail in the run's tab and wait for it in "opening". */
async function reloadGmail(s, message) {
  const next = await patchRunning({ phase: 'opening', phaseAt: Date.now(), reloadAt: Date.now(), openAttempts: s.openAttempts + 1, message });
  if (!next) return;
  try {
    await chrome.tabs.update(next.tabId, { url: next.gmailUrl || DEFAULT_GMAIL_URL });
  } catch (e) {
    await finishRun('error', `Could not open Gmail in the tab: ${e.message}`);
  }
}

// ---------------------------------------------------------------------------
// Real mouse clicks (Chrome debugger)
// Mailtrack adds its tracking pixel only when a real person clicks its Send button, and ignores
// clicks made by a script. Clicks sent through the debugger count as real. While attached, Chrome
// shows "… started debugging this browser" above the page; we attach once per run so it stays put.
// ---------------------------------------------------------------------------
let debuggeeTabId = null;

/** Attaches the debugger to the tab. Returns {ok, attachedNow} or {ok: false, error}. */
async function ensureDebugger(tabId) {
  if (debuggeeTabId === tabId) return { ok: true, attachedNow: false };
  try {
    await chrome.debugger.attach({ tabId }, '1.3');
    debuggeeTabId = tabId;
    return { ok: true, attachedNow: true };
  } catch (e) {
    // Still attached from before the service worker restarted.
    if (/already attached/i.test(e.message)) {
      debuggeeTabId = tabId;
      return { ok: true, attachedNow: false };
    }
    return { ok: false, error: e.message };
  }
}

async function detachDebugger(tabId = debuggeeTabId) {
  if (tabId == null) return;
  debuggeeTabId = null;
  await chrome.debugger.detach({ tabId }).catch(() => {});
}

// Closed with the bar's Cancel button, or the tab went away: attach again next time.
chrome.debugger.onDetach.addListener((source) => {
  if (source.tabId === debuggeeTabId) debuggeeTabId = null;
});

/** A real left click at (x, y), CSS pixels in the tab's viewport. {attached: true} = measure again first. */
async function realClick(tabId, x, y) {
  const a = await ensureDebugger(tabId);
  if (!a.ok) return { ok: false, error: `Chrome would not allow the click (${a.error})` };
  if (a.attachedNow) return { ok: false, attached: true }; // the debugging bar may have moved the page
  const mouse = (type, extra = {}) => chrome.debugger.sendCommand(
    { tabId }, 'Input.dispatchMouseEvent', { type, x, y, button: 'left', clickCount: 1, ...extra },
  );
  try {
    await mouse('mouseMoved', { button: 'none', clickCount: 0 });
    await mouse('mousePressed', { buttons: 1 });
    await mouse('mouseReleased', { buttons: 0 });
    return { ok: true };
  } catch (e) {
    if (/not attached/i.test(e.message)) {
      debuggeeTabId = null;
      if ((await ensureDebugger(tabId)).ok) return { ok: false, attached: true };
    }
    return { ok: false, error: e.message };
  }
}

// ---------------------------------------------------------------------------
// Steps (one per phase)
// ---------------------------------------------------------------------------
/** Why the run should stop now (limits reached), or null. */
async function limitReached(s, settings) {
  if (settings.maxPerRun && s.sent >= settings.maxPerRun) return `Finished: sent ${s.sent} emails (max per run).`;
  if (settings.maxPerDay && (await sentToday()) >= settings.maxPerDay) {
    return `Finished: ${settings.maxPerDay} emails sent today (max per day). Sent ${s.sent} this run.`;
  }
  if (s.runUntil && Date.now() >= s.runUntil) return `Finished: run time of ${settings.runMinutes} min is over. Sent ${s.sent} this run.`;
  return null;
}

async function checkOpened(s, now) {
  let tab;
  try {
    tab = await chrome.tabs.get(s.tabId);
  } catch {
    return finishRun('error', 'The Gmail tab was closed.');
  }
  if (/^https:\/\/accounts\.google\.com\//.test(tab.url || '')) {
    return finishRun('error', 'Not signed in to Gmail. Sign in in the tab, then press Start.');
  }
  const snap = await tabMessage(s.tabId, { type: 'SNAPSHOT' });
  // After our reload, the answer must come from the new page, not the old one.
  const fresh = snap && (!s.reloadAt || snap.pageStart > s.reloadAt);
  if (fresh && snap.ready) {
    if (snap.limitHit) return finishRun('error', `Gmail sending limit: ${snap.alert}`);
    const next = await patchRunning({ account: snap.account || s.account, phaseAt: now });
    if (!next) return;
    if (next.business && next.mail) return sendMail(next, await getSettings());
    return patchRunning({ phase: 'fetching', message: `Gmail ready${next.account ? ` (${next.account})` : ''}. Getting a business…` });
  }
  const waited = now - s.phaseAt;
  if (!snap && waited > INJECT_AFTER_MS) await injectContent(s.tabId); // content.js ignores a second load
  if (waited < PAGE_LOAD_TIMEOUT_MS) return;
  return finishRun('error', snap ? 'Gmail loaded but its Compose button never showed.' : 'Gmail did not load in the tab.');
}

async function fetchNext(s, settings) {
  if (s.paused) return patchRunning({ phase: 'waiting', nextAt: Date.now(), message: 'Paused' });
  const unsaved = await flushPendingSaves();
  if (unsaved) return finishRun('error', `Portal: could not record a sent email, stopping so it isn't sent twice: ${unsaved}`);
  const stop = await limitReached(s, settings);
  if (stop) return finishRun('done', stop);

  let data;
  try {
    data = await portal('get_business_to_email.php', {
      query: { zipcode: settings.zipcode, category: settings.category, source: settings.source, lease: LEASE_MINUTES },
    });
  } catch (e) {
    return finishRun('error', `Portal: could not get a business: ${e.message}`);
  }
  if (!data.business) {
    const filter = [settings.zipcode && `ZIP ${settings.zipcode}`, settings.category, settings.source && `${settings.source} emails`].filter(Boolean).join(', ');
    return finishRun('done', `Finished: no businesses left to email${filter ? ` (${filter})` : ''}. Sent ${s.sent} this run.`);
  }
  const b = data.business;
  const business = { id: b.id, name: b.name, city: b.city, category: b.category, website: b.website, email: b.email };
  const next = await patchRunning({
    business, recipients: data.recipients || [], mail: null, remaining: data.remaining, openAttempts: 0,
  });
  if (!next) return releaseBusiness(business);
  if (!next.recipients.length) return businessFailed(next, 'No valid email address saved for this business', { giveUp: true });
  await patchRunning({ phase: 'writing', phaseAt: Date.now(), message: `Groq is writing an email for ${business.name}…` });
  return writeEmail(await getState(), settings);
}

async function writeEmail(s, settings) {
  const b = s.business;
  let mail;
  try {
    mail = await portal('generate_email.php', {
      body: { business_id: b.id, tone: settings.tone, length: settings.length, brief: settings.brief, highlight: settings.highlight, from_email: s.account },
      timeoutMs: WRITE_TIMEOUT_MS,
    });
  } catch (e) {
    if (FATAL_WRITE_ERROR.test(e.message)) return finishRun('error', `Groq: ${e.message}`);
    return businessFailed(s, `Groq could not write the email: ${e.message}`);
  }
  const next = await patchRunning({
    mail: { subject: mail.subject, body: mail.body, text: mail.text, html: mail.html, facts: mail.facts || [] },
  });
  if (!next) return releaseBusiness(b);
  return sendMail(next, settings);
}

let sendingId = null; // business id being composed by this service worker (lost on restart)

async function sendMail(s, settings) {
  const b = s.business;
  const snap = await tabMessage(s.tabId, { type: 'SNAPSHOT' });
  if (!snap?.ready) {
    if (s.openAttempts >= MAX_OPEN_ATTEMPTS) return businessFailed(s, 'Gmail was not ready to compose after reloading');
    return reloadGmail(s, 'Reloading Gmail…');
  }
  const next = await patchRunning({
    phase: 'sending', phaseAt: Date.now(), account: snap.account || s.account,
    message: s.testMode ? `Test: filling an email for ${b.name} (won't send)…` : `Sending to ${b.name} (${s.recipients.join(', ')})…`,
  });
  if (!next) return releaseBusiness(b);

  // Attach before composing, so the debugging bar is already up when content.js measures the page.
  if (!s.testMode && (await ensureDebugger(s.tabId)).attachedNow) await new Promise((res) => setTimeout(res, 1000));

  sendingId = b.id;
  let r;
  try {
    r = await tabMessage(s.tabId, {
      type: 'COMPOSE', to: s.recipients, subject: s.mail.subject, html: s.mail.html, text: s.mail.text,
      send: !s.testMode, allowUntracked: settings.allowUntracked,
    });
  } finally {
    sendingId = null;
  }

  // A sent email is always recorded, even if the run was stopped meanwhile.
  if (r?.ok && r.sent) {
    const st = await getState();
    if (!st.running) await detachDebugger(s.tabId);
    return recordSent(st, settings, b, r.account || next.account, r.tracked);
  }

  const now = await getState();
  if (!now.running) {
    await detachDebugger(s.tabId);
    await patchState({ business: null, mail: null });
    return releaseBusiness(b);
  }
  if (r?.ok && !r.sent) {
    await releaseBusiness(b);
    await addRecent(b, 'test', s.recipients, s.mail.subject, 'Filled in Gmail, not sent');
    return finishRun('done', `Test: the email for ${b.name} is filled in Gmail and was NOT sent. Check it, then discard it (or send it yourself; the portal won't know).`);
  }
  if (!r) return businessFailed(now, 'The Gmail tab stopped answering while composing (page reloaded or closed)');
  if (r.limitHit) {
    await releaseBusiness(b);
    return finishRun('error', `Gmail sending limit reached, stopping: ${r.error}`);
  }
  if (r.stage === 'open' && now.openAttempts < MAX_OPEN_ATTEMPTS) {
    await addError(`${r.error}. Reloading Gmail and trying again.`, b.name);
    return reloadGmail(now, `Reloading Gmail for ${b.name}…`);
  }
  return businessFailed(now, r.error || 'Gmail error', { giveUp: !!r.badAddress, logMail: r.stage === 'send' });
}

/** tracked: true/false with Mailtrack, null without it. */
async function recordSent(s, settings, b, account, tracked = null) {
  const save = {
    key: `${b.id}-${Date.now()}`,
    business_id: b.id, status: 'sent', to: s.recipients, subject: s.mail.subject, body: s.mail.body, from_email: account,
  };
  await patchState((p) => ({ phase: p.running ? 'saving' : p.phase, pendingSaves: [...p.pendingSaves, save], message: p.running ? `Sent to ${b.name}. Recording it…` : p.message }));
  await countSentToday();
  const unsaved = await flushPendingSaves();
  await addRecent(b, 'sent', s.recipients, s.mail.subject, tracked === false ? 'untracked' : '', tracked);
  const next = await patchState((p) => ({
    sent: p.sent + 1, untracked: p.untracked + (tracked === false ? 1 : 0), consecutiveErrors: 0, business: null, mail: null,
  }));
  if (!next.running) return;
  if (unsaved) return finishRun('error', `Sent to ${b.name}, but the portal could not record it (it will be retried on the next Start): ${unsaved}`);
  return goNext(next, settings, `Sent to ${b.name} (${s.recipients.join(', ')})`);
}

/**
 * The current business failed. giveUp = never hand it out again (bad address); a problem with this
 * business's data, so it doesn't count toward MAX_CONSECUTIVE_ERRORS.
 * logMail = the email was written and Gmail refused it: keep it in the portal's email log.
 */
async function businessFailed(s, error, { giveUp = false, logMail = false } = {}) {
  const b = s.business;
  await addError(error, b?.name || '');
  if (b) {
    await addRecent(b, 'error', s.recipients || [], s.mail?.subject || '', error);
    const mail = logMail && s.mail ? { to: s.recipients, subject: s.mail.subject, body: s.mail.body, from_email: s.account } : {};
    await portal('save_email_result.php', { body: { business_id: b.id, status: 'error', error, give_up: giveUp, ...mail } }).catch(() => {});
  }
  const next = await patchState((p) => ({
    failed: p.failed + 1, consecutiveErrors: p.consecutiveErrors + (giveUp ? 0 : 1), business: null, mail: null,
  }));
  if (!next.running) return;
  if (next.consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
    return finishRun('error', `${MAX_CONSECUTIVE_ERRORS} errors in a row, stopping. Last: ${error}`);
  }
  return goNext(next, await getSettings(), `${b?.name || 'Business'} failed: ${error}`, FAIL_DELAY_MS);
}

async function goNext(s, settings, summary, delay = rand(settings.minDelay, settings.maxDelay) * 1000) {
  const stop = await limitReached(s, settings);
  if (stop) return finishRun('done', `${stop} Last: ${summary}`);
  if (s.runUntil && Date.now() + delay >= s.runUntil) {
    return finishRun('done', `Finished: run time of ${settings.runMinutes} min is over. Sent ${s.sent} this run. Last: ${summary}`);
  }
  await patchRunning({
    phase: 'waiting', business: null, mail: null, nextAt: Date.now() + delay,
    message: s.paused ? `${summary}. Paused.` : `${summary}.`, // the popup adds a live countdown to nextAt
  });
}

// ---------------------------------------------------------------------------
// Loop
// ---------------------------------------------------------------------------
let loopTimer = null;
let ticking = false;
let lastTickAt = 0;

function scheduleTick(ms = TICK_MS) {
  clearTimeout(loopTimer);
  loopTimer = setTimeout(tick, ms);
}

async function tick() {
  if (ticking) return;
  ticking = true;
  lastTickAt = Date.now();
  // A step can take a while (Groq, Gmail sending); an extension API call now and then keeps
  // Chrome from stopping the service worker as idle meanwhile.
  const keepAlive = setInterval(() => chrome.runtime.getPlatformInfo().catch(() => {}), 20000);
  try {
    const s = await getState();
    if (!s.running) return;
    const settings = await getSettings();
    const now = Date.now();
    switch (s.phase) {
      case 'opening': await checkOpened(s, now); break;
      case 'fetching': await fetchNext(s, settings); break;
      case 'writing': await writeEmail(s, settings); break; // only after a restart mid-step
      case 'sending':
        // Only after a restart mid-send: we can't tell whether Gmail sent it, so never retry it.
        if (sendingId == null) {
          await businessFailed(s, 'Interrupted while sending (extension restarted). Check Gmail\'s Sent folder.', { giveUp: true });
        }
        break;
      case 'saving':
        if (sendingId == null) {
          const unsaved = await flushPendingSaves();
          if (unsaved) await finishRun('error', `Portal: could not record a sent email: ${unsaved}`);
          else await goNext(s, settings, 'Recorded the last email');
        }
        break;
      case 'waiting':
        if (!s.paused && now >= (s.nextAt || 0)) await patchRunning({ phase: 'fetching', message: 'Getting the next business…' });
        break;
      default: break;
    }
  } catch (e) {
    console.warn('[GmailMailer] tick failed:', e);
    await addError(`Internal error: ${e.message}`);
  } finally {
    clearInterval(keepAlive);
    ticking = false;
    const s = await getState();
    if (s.running) scheduleTick();
  }
}

// ---------------------------------------------------------------------------
// Run control
// ---------------------------------------------------------------------------
async function startRun(msg) {
  const s = await getState();
  if (s.running) return { ok: false, error: 'Already running' };
  const settings = await getSettings();
  const { tabId, gmailUrl } = await getOrCreateGmailTab(msg.tabId, settings);
  // A Gmail tab opened before the extension was loaded has no content script yet.
  if (!(await tabMessage(tabId, { type: 'PING' }))) injectContent(tabId);
  const now = Date.now();
  await patchState((prev) => ({
    ...freshState(),
    recent: prev.recent,
    errors: prev.errors,
    errorCount: prev.errorCount,
    pendingSaves: prev.pendingSaves,
    running: true,
    testMode: !!msg.test,
    tabId,
    gmailUrl,
    phase: 'opening',
    phaseAt: now,
    runUntil: settings.runMinutes && !msg.test ? now + settings.runMinutes * 60000 : null,
    message: msg.test ? 'Test: waiting for Gmail…' : 'Waiting for Gmail…',
    startedAt: new Date().toISOString(),
  }));
  chrome.alarms.create('watchdog', { periodInMinutes: 0.5 });
  scheduleTick(0);
  return { ok: true };
}

async function pauseRun(paused) {
  const s = await patchState((p) => (p.running ? { paused, message: paused ? 'Pausing after this email…' : 'Resumed' } : {}));
  if (!s.running) return { ok: false, error: 'Not running' };
  if (paused && s.phase === 'waiting') await patchState({ message: 'Paused' });
  return { ok: true };
}

async function finishRun(phase, message) {
  const prev = await getState();
  // While Gmail is composing, sendMail() decides: a sent email is recorded, anything else released.
  const composing = sendingId != null;
  await patchState({ running: false, paused: false, phase, message, nextAt: null, ...(composing ? {} : { business: null, mail: null }) });
  clearTimeout(loopTimer);
  chrome.alarms.clear('watchdog');
  if (!composing) await detachDebugger(prev.tabId);
  if (phase === 'error') await addError(message, prev.business?.name || '');
  if (prev.business && !composing && ['opening', 'fetching', 'writing'].includes(prev.phase)) await releaseBusiness(prev.business);
}

async function clearLog() {
  await patchState((s) => ({ recent: [], errors: [], errorCount: 0, ...(s.running ? {} : { sent: 0, failed: 0, untracked: 0 }) }));
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------
async function route(msg, sender) {
  if (sender.tab?.id != null) {
    // CONTENT_READY: a Gmail page finished loading; check it now instead of on the next tick.
    if (msg?.type === 'CONTENT_READY') {
      const s = await getState();
      if (s.running && sender.tab.id === s.tabId && s.phase === 'opening') scheduleTick(300);
    }
    // TRUSTED_CLICK: content.js wants a real click on the Send button, only while it sends our email.
    if (msg?.type === 'TRUSTED_CLICK') {
      const s = await getState();
      if (sendingId == null || sender.tab.id !== s.tabId) return { ok: false, error: 'not sending an email from this tab' };
      return realClick(sender.tab.id, Number(msg.x), Number(msg.y));
    }
    return { ok: true };
  }
  switch (msg?.type) {
    case 'START': return startRun(msg);
    case 'PAUSE': return pauseRun(true);
    case 'RESUME': return pauseRun(false);
    case 'STOP': await finishRun('stopped', 'Stopped by user'); return { ok: true };
    case 'CLEAR_LOG': return clearLog();
    case 'GET_SETTINGS': return { ok: true, settings: await getSettings(), defaults: DEFAULT_SETTINGS, sentToday: await sentToday() };
    case 'SAVE_SETTINGS': {
      const settings = normalizeSettings({ ...(await getSettings()), ...(msg.settings || {}) });
      await chrome.storage.local.set({ settings });
      return { ok: true, settings };
    }
    default: return { ok: false, error: `Unknown message ${msg?.type}` };
  }
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  route(msg, sender).then(
    (r) => sendResponse(r || { ok: true }),
    (e) => sendResponse({ ok: false, error: String(e?.message || e) }),
  );
  return true; // async response
});

// ---------------------------------------------------------------------------
// Tabs, watchdog, lifecycle
// ---------------------------------------------------------------------------
chrome.tabs.onRemoved.addListener(async (tabId) => {
  const s = await getState();
  if (s.running && tabId === s.tabId) finishRun('error', 'The Gmail tab was closed.');
});

// The service worker can be stopped between ticks; the alarm wakes it and restarts the loop.
chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name !== 'watchdog') return;
  const s = await getState();
  if (!s.running) return chrome.alarms.clear('watchdog');
  if (Date.now() - lastTickAt > TICK_MS * 5) scheduleTick(0);
});

/** Browser restarted or extension updated mid-run: stop, giving back a business that wasn't sent. */
async function stopAfterRestart(message) {
  const s = await getState();
  if (!s.running) return;
  if (['opening', 'fetching', 'writing'].includes(s.phase)) await releaseBusiness(s.business);
  else if (s.phase === 'sending' && s.business) {
    await portal('save_email_result.php', {
      body: { business_id: s.business.id, status: 'error', error: 'Interrupted while sending. Check Gmail\'s Sent folder.', give_up: true },
    }).catch(() => {});
  }
  await detachDebugger(s.tabId);
  await patchState({ running: false, paused: false, phase: 'stopped', business: null, mail: null, message });
}

chrome.runtime.onInstalled.addListener(async () => {
  const { settings } = await chrome.storage.local.get('settings');
  await chrome.storage.local.set({ settings: normalizeSettings(settings) });
  await stopAfterRestart('Extension updated. Press Start to continue.');
});

chrome.runtime.onStartup.addListener(() => stopAfterRestart('Browser restarted, run stopped.'));

// Woken up mid-run (any event): carry on.
getState().then((s) => { if (s.running) scheduleTick(500); });
