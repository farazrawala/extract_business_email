/*
 * background.js — MV3 service worker.
 * The loop: get one business the portal still has no email for after ChatGPT searched, open a new
 * Claude chat, paste the prompt, wait until the reply is finished, take the email out of it, save
 * it to the portal, wait a little, repeat.
 * All run state lives in chrome.storage.local and tick() does the next step from whatever phase
 * is stored, so the run carries on after the service worker is restarted.
 */

// ---------------------------------------------------------------------------
// Config
// ---------------------------------------------------------------------------
// Business Portal (see portal/API.md).
const PORTAL_URL = 'https://email.demo-website.cc/';
const PORTAL_API_KEY = ''; // must match API_KEY in portal/config.php ('' = no key)
const LEASE_MINUTES = 20;  // how long the portal holds a business for us; longer than the max reply timeout

const EXT_VERSION = chrome.runtime.getManifest().version;
const TICK_MS = 1000;
const PAGE_LOAD_TIMEOUT_MS = 60000;   // new chat must show an empty message box by then
const INJECT_AFTER_MS = 15000;        // inject content.js if it hasn't answered by then
const REPLY_START_TIMEOUT_MS = 90000; // nothing at all came back after sending
const REPLY_STABLE_MS = 3000;         // not writing and text unchanged this long = reply finished...
const REPLY_UNCLEAR_STABLE_MS = 20000; // ...or this long when the last block has no email, no NONE and no Copy/Retry buttons
const MAX_SEND_ATTEMPTS = 2;          // per business, each in a fresh chat
const MAX_CONSECUTIVE_ERRORS = 3;
const MAX_ERRORS_KEPT = 50;
const MAX_RECENT = 10;
const MAX_EMAILS = 5;
const CHAT_URL_RE = /^https:\/\/claude\.ai\//;
const IN_FLIGHT_PHASES = ['opening', 'sending', 'replying'];

const DEFAULT_PROMPT = `Find the public contact email address of this business. A first search found none, so look carefully. Search the web: its website (contact, about and footer), Google Business Profile, Facebook and Instagram pages, Yelp, BBB, the local chamber of commerce and other directories.

Business: {name}
Category: {category}
Address: {address}
Phone: {phone}
Website: {website}
Google Maps: {google_maps_url}

End your reply with the email address only, no other words. If there are several, give the main one first, separated by commas.
Only give an address you actually found published for this business. Don't make one up (like info@ + the website domain).
If you can't find one, end your reply with exactly: NONE`;

const DEFAULT_SETTINGS = {
  chatUrl: 'https://claude.ai/new',
  zipcode: '',
  category: '',
  minDelay: 5,
  maxDelay: 15,
  replyTimeout: 240, // seconds
  saveMentioned: true, // answer is NONE but the reply names addresses: save them as "mentioned"
  maxPerRun: 0,      // 0 = until the portal has none left
  prompt: DEFAULT_PROMPT,
};

function freshState() {
  return {
    running: false,
    paused: false,
    phase: 'idle',          // idle|fetching|opening|sending|replying|saving|waiting|done|stopped|error
    message: 'Ready',
    tabId: null,
    business: null,         // the business being checked (fields used by the prompt)
    phaseAt: null,          // ms, when the current phase started
    sendAttempts: 0,
    replyText: '',
    replyStableAt: null,    // ms, when replyText last changed
    nextAt: null,           // ms, when "waiting" moves on
    checked: 0,             // businesses finished this run (found + none)
    found: 0,
    none: 0,
    errorCount: 0,
    consecutiveErrors: 0,
    remaining: null,        // from the portal
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

function normalizeSettings(raw) {
  const s = { ...DEFAULT_SETTINGS, ...(raw || {}) };
  s.chatUrl = CHAT_URL_RE.test(String(s.chatUrl || '').trim()) ? String(s.chatUrl).trim() : DEFAULT_SETTINGS.chatUrl;
  s.zipcode = String(s.zipcode || '').trim();
  s.category = String(s.category || '').trim();
  s.minDelay = Math.max(0, parseFloat(s.minDelay) || 0);
  s.maxDelay = Math.max(s.minDelay, parseFloat(s.maxDelay) || 0);
  s.saveMentioned = s.saveMentioned !== false;
  s.replyTimeout = Math.min(900, Math.max(30, parseInt(s.replyTimeout, 10) || DEFAULT_SETTINGS.replyTimeout));
  s.maxPerRun = Math.max(0, parseInt(s.maxPerRun, 10) || 0);
  s.prompt = String(s.prompt || '').trim() ? String(s.prompt) : DEFAULT_PROMPT;
  return s;
}

async function getSettings() {
  const { settings } = await chrome.storage.local.get('settings');
  return normalizeSettings(settings);
}

function addError(error, name = '') {
  return patchState((s) => ({
    errorCount: s.errorCount + 1,
    errors: [{ at: new Date().toISOString(), name, error }, ...s.errors].slice(0, MAX_ERRORS_KEPT),
  }));
}

function addRecent(business, status, emails = [], note = '') {
  return patchState((s) => ({
    recent: [
      { at: new Date().toISOString(), id: business.id, name: business.name, status, emails, note },
      ...s.recent,
    ].slice(0, MAX_RECENT),
  }));
}

const rand = (min, max) => min + Math.random() * (max - min);

// ---------------------------------------------------------------------------
// Portal
// ---------------------------------------------------------------------------
async function portal(path, { query, body } = {}) {
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
  });
  const data = await res.json().catch(() => null);
  if (!res.ok || !data?.ok) throw new Error(data?.error || `HTTP ${res.status}`);
  return data;
}

/** Give a business back to the portal right away (run stopped before it was checked). */
function releaseBusiness(business) {
  if (!business?.id) return Promise.resolve();
  return portal('save_claude_email.php', { body: { business_id: business.id, release: true } }).catch(() => {});
}

// ---------------------------------------------------------------------------
// Prompt + reply parsing
// ---------------------------------------------------------------------------
function promptValues(b) {
  const address = b.address || [b.street, b.city, b.state, b.postal_code].filter(Boolean).join(', ');
  return {
    name: b.name, category: b.category, address, street: b.street, city: b.city, state: b.state,
    postal_code: b.postal_code, country: b.country, zipcode: b.zipcode, phone: b.phone,
    website: b.website, google_maps_url: b.google_maps_url,
  };
}

/** Fill {placeholders}. A line whose placeholders are all empty is dropped ("Phone: " with no phone). */
function buildPrompt(template, business) {
  const values = promptValues(business);
  return template.split('\n').flatMap((line) => {
    const keys = [...line.matchAll(/\{(\w+)\}/g)].map((m) => m[1]).filter((k) => k in values);
    if (keys.length && keys.every((k) => !String(values[k] ?? '').trim())) return [];
    return [line.replace(/\{(\w+)\}/g, (m, k) => (k in values ? String(values[k] ?? '').trim() : m))];
  }).join('\n').trim();
}

const EMAIL_RE = /[a-z0-9][a-z0-9._+-]{0,63}@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,24}/gi;
const EMAIL_JUNK_DOMAIN = /(^|\.)(example\.(com|org|net)|domain\.com|yourdomain\.com|email\.com|website\.com|businessname\.com|company\.com)$/i;
const EMAIL_JUNK_LOCAL = /^(user|name|your|yourname|youremail|email|example|test|someone|john\.?doe|firstname\.?lastname)$/i;
/** The answer starts with NONE, or ends with it in capitals ("…other contacts: none" doesn't count). */
const isNone = (text) => /^\W*none\b/i.test(text) || /\bNONE\W*$/.test(text);
// Claude answering without web search ("I can't browse the internet").
const NO_WEB_RE = /(?:can(?:not|['’]t)|unable to|not able to)\s+(?:browse|search (?:the )?(?:web|internet)|access (?:the )?(?:internet|web)\b)|(?:don['’]t|do not) have (?:access to (?:real-time information|the internet|the web\b|web search|search)|(?:the ability|a way) to (?:browse|search the (?:web|internet)|access (?:the )?(?:internet|web)\b))|web search (?:is )?(?:not|isn['’]t) (?:enabled|available|turned on)/i;

/** Addresses in the answer, in order. An answer that starts or ends with NONE has none. */
function emailsInReply(text, linkEmails = []) {
  return isNone(text) ? [] : emailsIn(text, linkEmails);
}

/** Every address in the text and links, in order, without placeholders like user@example.com. */
function emailsIn(text, linkEmails = []) {
  const out = [];
  for (const raw of [...(text.match(EMAIL_RE) || []), ...linkEmails]) {
    const e = raw.toLowerCase().replace(/^mailto:/, '').replace(/[.-]+$/, '');
    const [local, domain] = e.split('@');
    if (!local || !domain || EMAIL_JUNK_DOMAIN.test(domain) || EMAIL_JUNK_LOCAL.test(local)) continue;
    if (!out.includes(e)) out.push(e);
  }
  return out.slice(0, MAX_EMAILS);
}

// ---------------------------------------------------------------------------
// Claude tab
// ---------------------------------------------------------------------------
async function tabMessage(tabId, msg) {
  try {
    return await chrome.tabs.sendMessage(tabId, msg);
  } catch {
    return null; // no content script (page loading, or not a Claude page)
  }
}

async function injectContent(tabId) {
  try {
    await chrome.scripting.executeScript({ target: { tabId }, files: ['selectors.js', 'content.js'] });
  } catch (e) {
    console.warn('[ClaudeEmailFinder] inject failed:', e.message);
  }
}

async function getOrCreateChatTab(hintTabId, settings) {
  if (hintTabId != null) {
    try {
      const t = await chrome.tabs.get(hintTabId);
      if (CHAT_URL_RE.test(t.url || '')) return t.id;
    } catch { /* tab gone */ }
  }
  const t = await chrome.tabs.create({ url: settings.chatUrl, active: true });
  return t.id;
}

/** Load a fresh chat in the tab and move to "opening". */
async function openNewChat(s, settings, message) {
  const next = await patchRunning({ phase: 'opening', phaseAt: Date.now(), replyText: '', replyStableAt: null, message });
  if (!next) return;
  try {
    await chrome.tabs.update(next.tabId, { url: settings.chatUrl });
  } catch (e) {
    await finishRun('error', `Could not open Claude in the tab: ${e.message}`);
  }
}

// ---------------------------------------------------------------------------
// Steps (one per phase)
// ---------------------------------------------------------------------------
async function fetchNext(s, settings) {
  if (s.paused) return patchRunning({ phase: 'waiting', nextAt: Date.now(), message: 'Paused' });
  if (settings.maxPerRun && s.checked >= settings.maxPerRun) {
    return finishRun('done', `Finished: checked ${s.checked} businesses (max per run).`);
  }
  let data;
  try {
    data = await portal('get_business_for_claude.php', {
      query: { zipcode: settings.zipcode, category: settings.category, lease: LEASE_MINUTES },
    });
  } catch (e) {
    return finishRun('error', `Portal: could not get a business: ${e.message}`);
  }
  if (!data.business) {
    const filter = [settings.zipcode && `ZIP ${settings.zipcode}`, settings.category].filter(Boolean).join(', ');
    return finishRun('done', `Finished: no businesses left that ChatGPT found no email for${filter ? ` (${filter})` : ''}. Checked ${s.checked} this run.`);
  }
  const b = data.business;
  const business = { ...promptValues(b), id: b.id };
  const next = await patchRunning({ business, remaining: data.remaining, sendAttempts: 0 });
  if (!next) return releaseBusiness(business);
  await openNewChat(next, settings, `New chat for ${business.name}…`);
}

async function checkOpened(s, now) {
  const snap = await tabMessage(s.tabId, { type: 'SNAPSHOT' });
  if (snap?.challenge) {
    return finishRun('error', 'Claude is showing a "verify you are human" check. Solve it in the tab, then press Start.');
  }
  // pageStart > phaseAt: the answer comes from the page we just loaded, not the old one.
  const fresh = snap && snap.pageStart > s.phaseAt;
  if (fresh && snap.loggedOut && !snap.composer) {
    return finishRun('error', 'Not logged in to Claude. Log in in the tab, then press Start.');
  }
  if (fresh && snap.composer && snap.userTurns === 0 && !snap.generating) {
    await patchRunning({ phase: 'sending', phaseAt: now });
    return sendPrompt(await getState(), await getSettings());
  }
  const waited = now - s.phaseAt;
  if (!snap && waited > INJECT_AFTER_MS) await injectContent(s.tabId); // content.js ignores a second load
  if (waited < PAGE_LOAD_TIMEOUT_MS) return;
  return businessFailed(s, snap ? 'Claude page loaded but the message box never showed' : 'Claude page did not load');
}

async function sendPrompt(s, settings) {
  const text = buildPrompt(settings.prompt, s.business);
  // Move to "replying" before sending, so a restart halfway can't paste the prompt twice.
  const next = await patchRunning({
    phase: 'replying', phaseAt: Date.now(), replyText: '', replyStableAt: null,
    sendAttempts: s.sendAttempts + 1, message: `Asking Claude about ${s.business.name}…`,
  });
  if (!next) return;
  const r = await tabMessage(s.tabId, { type: 'SEND_PROMPT', text });
  if (r?.ok) return;
  const error = r?.error || 'the Claude tab did not answer';
  if (next.sendAttempts < MAX_SEND_ATTEMPTS) {
    await addError(`Send failed, retrying in a new chat: ${error}`, s.business.name);
    return openNewChat(next, settings, `Retrying ${s.business.name} in a new chat…`);
  }
  return businessFailed(next, `Could not send the prompt: ${error}`);
}

async function checkReply(s, settings, now) {
  const snap = await tabMessage(s.tabId, { type: 'SNAPSHOT' });
  const waited = now - s.phaseAt;
  const secs = Math.round(waited / 1000);
  if (!snap || snap.pageStart > s.phaseAt) {
    // Content script gone, or the page reloaded after we sent: the reply is lost.
    if (waited > 30000 || snap) return businessFailed(s, 'The Claude page reloaded or stopped answering while waiting for the reply');
    return;
  }
  if (snap.challenge) {
    return finishRun('error', 'Claude is showing a "verify you are human" check. Solve it in the tab, then press Start.');
  }

  const text = (snap.replyText || '').trim();
  const answer = (snap.answerText || text).trim();
  // replyStableAt = when the text last changed, kept while Claude is writing too.
  const stableAt = text !== s.replyText || !s.replyStableAt ? now : s.replyStableAt;
  if (snap.generating || !text) {
    if (!snap.generating && snap.limitHit && waited > 5000) {
      return businessFailed(s, `Claude limit reached: ${snap.errorText || answer}`, { fatal: true });
    }
    if (!text && !snap.generating && waited > REPLY_START_TIMEOUT_MS) {
      return businessFailed(s, `No reply from Claude${snap.errorText ? `: ${snap.errorText}` : ''} (${snap.userTurns} user / ${snap.assistantCount ?? 0} reply messages on the page)`);
    }
    if (waited > settings.replyTimeout * 1000) {
      // Out of time, but a reply that hasn't changed for a while and holds an answer is used anyway.
      const emails = emailsInReply(answer, snap.answerEmails);
      if (text && now - stableAt >= REPLY_STABLE_MS && (emails.length || isNone(answer))) {
        return saveResult(s, settings, text, emails, snap);
      }
      const seen = !text
        ? `no reply text found on the page: ${snap.userTurns} user / ${snap.assistantCount ?? 0} reply messages, ${snap.generating ? 'still writing' : 'not writing'}`
        : `Claude was still writing: "${answer.slice(0, 80)}"`;
      return businessFailed(s, `No complete reply after ${settings.replyTimeout}s (${seen})`);
    }
    return patchRunning({
      replyText: text, replyStableAt: stableAt,
      message: `${s.business.name}: Claude is ${text ? 'writing' : 'searching'}… ${secs}s`,
    });
  }
  if (text !== s.replyText || !s.replyStableAt) {
    return patchRunning({ replyText: text, replyStableAt: now, message: `${s.business.name}: reading the reply… ${secs}s` });
  }
  if (now - s.replyStableAt < REPLY_STABLE_MS) return;

  const emails = emailsInReply(answer, snap.answerEmails);
  // A last block with no email and no NONE may be Claude's "Let me search…" before a slow web
  // search, so wait longer unless the Copy/Retry buttons show the reply is done.
  if (!emails.length && !isNone(answer) && !snap.done && now - s.replyStableAt < REPLY_UNCLEAR_STABLE_MS) {
    return;
  }
  if (!emails.length && snap.limitHit) {
    return businessFailed(s, `Claude limit reached: ${answer.slice(0, 200)}`, { fatal: true });
  }
  if (!emails.length && !isNone(answer) && NO_WEB_RE.test(text)) {
    return businessFailed(s, `Claude can't search the web: turn on Web search in Claude (the tools button under the message box), then press Start. Reply: "${answer.slice(0, 150)}"`, { fatal: true });
  }
  return saveResult(s, settings, text, emails, snap);
}

async function saveResult(s, settings, answer, emails, snap) {
  const b = s.business;
  // No email as the answer: keep any address Claude names elsewhere in the reply ("…shows an
  // email for a related restaurant (x@y.com) … NONE"), marked "mentioned".
  const mentioned = !emails.length && settings.saveMentioned;
  if (mentioned) emails = emailsIn(answer, snap?.replyEmails || []);
  if (!(await patchRunning({ phase: 'saving', message: `Saving ${b.name}…` }))) return;
  let res;
  try {
    res = await portal('save_claude_email.php', { body: { business_id: b.id, emails, answer, mentioned: mentioned && emails.length > 0 } });
  } catch (e) {
    return finishRun('error', `Portal: could not save the result for ${b.name}: ${e.message}`);
  }
  const found = res.status === 'found' || res.status === 'mentioned';
  await addRecent(b, res.status, res.emails || [], found ? '' : answer.slice(-200));
  const next = await patchState((p) => ({
    checked: p.checked + 1,
    found: p.found + (found ? 1 : 0),
    none: p.none + (found ? 0 : 1),
    consecutiveErrors: 0,
  }));
  if (!next.running) return;
  const summary = res.status === 'mentioned' ? `${b.name}: ${res.email} (named in the reply, not the answer)` : `${b.name}: ${res.email}`;
  return goNext(next, settings, found ? summary : `${b.name}: no email`);
}

/** The current business failed. fatal = stop the run (and give the business back untouched). */
async function businessFailed(s, error, { fatal = false } = {}) {
  const b = s.business;
  if (fatal) return finishRun('error', error);
  await addError(error, b?.name || '');
  if (b) {
    await addRecent(b, 'error', [], error);
    await portal('save_claude_email.php', { body: { business_id: b.id, error } }).catch(() => {});
  }
  const next = await patchState((p) => ({ consecutiveErrors: p.consecutiveErrors + 1, business: null }));
  if (!next.running) return;
  if (next.consecutiveErrors >= MAX_CONSECUTIVE_ERRORS) {
    return finishRun('error', `${MAX_CONSECUTIVE_ERRORS} errors in a row, stopping. Last: ${error}`);
  }
  return goNext(next, await getSettings(), `${b?.name || 'Business'} failed: ${error}`);
}

async function goNext(s, settings, summary) {
  if (settings.maxPerRun && s.checked >= settings.maxPerRun) {
    return finishRun('done', `Finished: checked ${s.checked} businesses (max per run). Last: ${summary}`);
  }
  const delay = rand(settings.minDelay, settings.maxDelay) * 1000;
  await patchRunning({
    phase: 'waiting', business: null, nextAt: Date.now() + delay,
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
  try {
    const s = await getState();
    if (!s.running) return;
    const settings = await getSettings();
    const now = Date.now();
    switch (s.phase) {
      case 'fetching': await fetchNext(s, settings); break;
      case 'opening': await checkOpened(s, now); break;
      case 'sending': await sendPrompt(s, settings); break; // only after a restart mid-step
      case 'replying': await checkReply(s, settings, now); break;
      case 'waiting':
        if (!s.paused && now >= (s.nextAt || 0)) await patchRunning({ phase: 'fetching', message: 'Getting the next business…' });
        break;
      default: break; // saving: finishes inside saveResult
    }
  } catch (e) {
    console.warn('[ClaudeEmailFinder] tick failed:', e);
    await addError(`Internal error: ${e.message}`);
  } finally {
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
  const tabId = await getOrCreateChatTab(msg.tabId, settings);
  await patchState((prev) => ({
    ...freshState(),
    recent: prev.recent,
    running: true,
    tabId,
    phase: 'fetching',
    message: 'Starting…',
    startedAt: new Date().toISOString(),
  }));
  chrome.alarms.create('watchdog', { periodInMinutes: 0.5 });
  scheduleTick(0);
  return { ok: true };
}

async function pauseRun(paused) {
  const s = await patchState((p) => (p.running ? { paused, message: paused ? 'Pausing after this business…' : 'Resumed' } : {}));
  if (!s.running) return { ok: false, error: 'Not running' };
  if (paused && s.phase === 'waiting') await patchState({ message: 'Paused' });
  return { ok: true };
}

async function finishRun(phase, message) {
  const prev = await getState();
  await patchState({ running: false, paused: false, phase, message, nextAt: null, business: null });
  clearTimeout(loopTimer);
  chrome.alarms.clear('watchdog');
  if (phase === 'error') await addError(message, prev.business?.name || '');
  if (prev.business && IN_FLIGHT_PHASES.includes(prev.phase)) await releaseBusiness(prev.business);
}

async function clearLog() {
  await patchState((s) => ({ recent: [], errors: [], errorCount: 0, ...(s.running ? {} : { checked: 0, found: 0, none: 0 }) }));
  return { ok: true };
}

// ---------------------------------------------------------------------------
// Messages
// ---------------------------------------------------------------------------
async function route(msg, sender) {
  if (sender.tab?.id != null) {
    // CONTENT_READY: a Claude page finished loading; check it now instead of on the next tick.
    if (msg?.type === 'CONTENT_READY') {
      const s = await getState();
      if (s.running && sender.tab.id === s.tabId && s.phase === 'opening') scheduleTick(300);
    }
    return { ok: true };
  }
  switch (msg?.type) {
    case 'START': return startRun(msg);
    case 'PAUSE': return pauseRun(true);
    case 'RESUME': return pauseRun(false);
    case 'STOP': await finishRun('stopped', 'Stopped by user'); return { ok: true };
    case 'CLEAR_LOG': return clearLog();
    case 'GET_SETTINGS': return { ok: true, settings: await getSettings(), defaults: DEFAULT_SETTINGS };
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
  if (s.running && tabId === s.tabId) finishRun('error', 'The Claude tab was closed.');
});

// The service worker can be stopped between ticks; the alarm wakes it and restarts the loop.
chrome.alarms.onAlarm.addListener(async (alarm) => {
  if (alarm.name !== 'watchdog') return;
  const s = await getState();
  if (!s.running) return chrome.alarms.clear('watchdog');
  if (Date.now() - lastTickAt > TICK_MS * 5) scheduleTick(0);
});

chrome.runtime.onInstalled.addListener(async () => {
  const { settings } = await chrome.storage.local.get('settings');
  await chrome.storage.local.set({ settings: normalizeSettings(settings) });
  const s = await getState();
  if (s.running) {
    await releaseBusiness(s.business);
    await patchState({ running: false, paused: false, phase: 'stopped', business: null, message: 'Extension updated. Press Start to continue.' });
  }
});

chrome.runtime.onStartup.addListener(async () => {
  const s = await getState();
  if (s.running) {
    await releaseBusiness(s.business);
    await patchState({ running: false, paused: false, phase: 'stopped', business: null, message: 'Browser restarted, run stopped.' });
  }
});

// Woken up mid-run (any event): carry on.
getState().then((s) => { if (s.running) scheduleTick(500); });
