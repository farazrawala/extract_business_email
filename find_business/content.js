/*
 * content.js — runs on Google Maps pages.
 * Receives a job (one ZIP) from background.js, scrolls the results feed, opens each listing
 * one at a time and sends each scraped record back to the background.
 *
 * All DOM selectors come from selectors.js (self.MS_SELECTORS / self.MS_PATTERNS).
 */
(() => {
  'use strict';
  if (self.__msContentLoaded) return; // guard against double injection
  self.__msContentLoaded = true;

  const S = self.MS_SELECTORS;
  const P = self.MS_PATTERNS;
  const LOG = (...a) => console.log('[MapsScraper]', ...a);

  // Timing knobs (ms). Listing delay (min/max) comes from the popup settings instead.
  const TUNING = {
    scrollStepMin: 300, scrollStepMax: 900,
    scrollWaitMin: 1500, scrollWaitMax: 4000,
    noGrowthLimit: 3,        // stop after N scrolls at the bottom with no new results
    maxScrollRounds: 300,
    relocateRounds: 40,      // max scrolls when a listing's link dropped out of a re-rendered feed
    feedTimeout: 20000,
    detailsTimeout: 15000,
    clickPauseMin: 400, clickPauseMax: 1200,
    tabPauseMin: 600, tabPauseMax: 1500,
    heartbeatMs: 15000,      // keeps the MV3 service worker awake during long waits
  };

  class StopError extends Error {}
  class NavigatingSignal extends Error {}

  const ctl = { running: false, paused: false, stopped: false };
  let lastScrapedName = '';

  // ======================================================================
  // Generic helpers
  // ======================================================================
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const rand = (min, max) => min + Math.random() * (max - min);
  const randInt = (min, max) => Math.floor(rand(min, max + 1));

  /** Collapse whitespace, drop icon-font glyphs (private-use chars) and bidi marks. */
  function clean(t) {
    return String(t ?? '')
      .replace(/[-]/g, '')
      .replace(/[​-‏‪-‮⁦-⁩]/g, '')
      .replace(/[   ]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }
  const normName = (t) => clean(t).replace(P.VISITED_SUFFIX, '').toLowerCase();
  const safeDecode = (s) => { try { return decodeURIComponent(s || ''); } catch { return s || ''; } };
  const ariaLabel = (el) => clean(el?.getAttribute?.('aria-label'));
  const stripLabel = (t) => clean(t).replace(P.LABEL_PREFIX, '');
  const isVisible = (el) => !!el && el.isConnected && el.getClientRects().length > 0;

  function send(type, payload = {}) {
    try {
      return chrome.runtime.sendMessage({ type, ...payload }).catch(() => {});
    } catch (e) {
      // "Extension context invalidated" — the extension was reloaded. Stop quietly.
      ctl.stopped = true;
      return Promise.resolve();
    }
  }
  const sendProgress = (phase, message, extra = {}) => send('PROGRESS', { phase, message, ...extra });

  /** Throws StopError if stopped; blocks while paused. Call often. */
  async function checkpoint() {
    if (ctl.stopped) throw new StopError('Stopped');
    while (ctl.paused) {
      await sleep(400);
      if (ctl.stopped) throw new StopError('Stopped');
    }
  }

  /** Random delay that respects pause/stop. */
  async function humanDelay(minMs, maxMs) {
    let remaining = rand(minMs, Math.max(minMs, maxMs));
    while (remaining > 0) {
      await checkpoint();
      const step = Math.min(250, remaining);
      await sleep(step);
      remaining -= step;
    }
  }

  /** The between-listings delay. Sends its end time so the popup can show a countdown. */
  async function countdownDelay(minMs, maxMs) {
    let end = Date.now() + rand(minMs, Math.max(minMs, maxMs));
    sendProgress(null, null, { waitUntil: end });
    while (Date.now() < end) {
      if (ctl.paused) {
        // Pause freezes the countdown: resume with the time that was left.
        const left = end - Date.now();
        await checkpoint();
        end = Date.now() + left;
        sendProgress(null, null, { waitUntil: end });
      }
      await checkpoint();
      await sleep(Math.max(0, Math.min(250, end - Date.now())));
    }
    sendProgress(null, null, { waitUntil: 0 });
  }

  /**
   * Wait until check() returns something truthy (or a selector matches).
   * Uses a MutationObserver (throttled) plus polling as a safety net. Resolves null on timeout/stop.
   */
  function waitFor(check, { timeout = 10000, interval = 300 } = {}) {
    const fn = typeof check === 'string' ? () => document.querySelector(check) : check;
    return new Promise((resolve) => {
      let done = false;
      let scheduled = false;
      let observer, poll, timer;
      const finish = (v) => {
        if (done) return;
        done = true;
        observer && observer.disconnect();
        clearInterval(poll);
        clearTimeout(timer);
        resolve(v);
      };
      const test = () => {
        scheduled = false;
        if (done) return;
        if (ctl.stopped) return finish(null);
        let v = null;
        try { v = fn(); } catch { /* ignore */ }
        if (v) finish(v);
      };
      observer = new MutationObserver(() => {
        if (!scheduled) { scheduled = true; setTimeout(test, 50); }
      });
      observer.observe(document.documentElement, { childList: true, subtree: true, characterData: true });
      poll = setInterval(test, interval);
      timer = setTimeout(() => finish(null), timeout);
      test();
    });
  }

  /** Pull ids/coords out of a /maps/place/ URL. */
  function parsePlaceUrl(url) {
    const s = safeDecode(url);
    const m = (re) => (s.match(re) || [])[1] || '';
    const hex = m(P.URL_HEX_ID);
    const placeId = m(P.URL_PLACE_ID);
    let cid = '';
    if (hex) { try { cid = BigInt(hex.split(':')[1]).toString(); } catch { /* ignore */ } }
    return { lat: m(P.URL_LAT), lng: m(P.URL_LNG), hex, placeId, cid, key: placeId || hex || s.split('?')[0] };
  }

  /** Google sometimes wraps outbound links in /url?q=... */
  function normalizeLink(href) {
    if (!href) return '';
    try {
      const u = new URL(href);
      if (/google\./.test(u.hostname) && u.pathname === '/url') return u.searchParams.get('q') || u.searchParams.get('url') || href;
    } catch { /* ignore */ }
    return href;
  }

  /** Best-effort split of a US-style address: "222 Avenue B, New York, NY 10009, United States". */
  function parseAddress(address) {
    const out = { street: '', city: '', state: '', postal_code: '', country: '' };
    if (!address) return out;
    const parts = address.split(',').map((p) => p.trim()).filter(Boolean);
    if (parts.length && /^(united states|usa|us)$/i.test(parts[parts.length - 1])) out.country = parts.pop();
    const last = parts[parts.length - 1] || '';
    const m = last.match(/^([A-Z]{2})\s+(\d{5}(?:-\d{4})?)$/);
    if (m) {
      out.state = m[1];
      out.postal_code = m[2];
      parts.pop();
      out.city = parts.pop() || '';
    } else {
      const z = address.match(/\b(\d{5}(?:-\d{4})?)\b(?!.*\b\d{5}\b)/);
      if (z) out.postal_code = z[1];
    }
    out.street = parts.join(', ');
    return out;
  }

  // ======================================================================
  // Results feed
  // ======================================================================
  const getFeed = () => document.querySelector(S.FEED);

  function getScroller(feed) {
    let el = feed;
    while (el && el !== document.body) {
      const oy = getComputedStyle(el).overflowY;
      if ((oy === 'auto' || oy === 'scroll') && el.scrollHeight > el.clientHeight + 5) return el;
      el = el.parentElement;
    }
    return feed;
  }
  const atBottom = (el) => el.scrollTop + el.clientHeight >= el.scrollHeight - 60;
  const endOfListReached = (feed) => !!feed && P.END_OF_LIST.test(feed.textContent || '');

  /** Scroll the feed with random steps/pauses and collect unique listings. */
  async function collectListings(job, skipKeys) {
    const max = job.settings.maxResults;
    const found = new Map();
    const ignored = new Set();
    let alreadyDone = 0;

    const harvest = () => {
      const feed = getFeed();
      if (!feed) return 0;
      const links = feed.querySelectorAll(S.FEED_LINK);
      for (const a of links) {
        const ids = parsePlaceUrl(a.href);
        if (!ids.key || found.has(ids.key) || ignored.has(ids.key)) continue;
        const card = a.closest(S.FEED_CARD) || a.parentElement;
        const sponsored = !!card?.querySelector(S.SPONSORED_BADGE);
        if (sponsored && !job.settings.includeSponsored) { ignored.add(ids.key); continue; }
        if (skipKeys.has(ids.key)) { ignored.add(ids.key); alreadyDone++; continue; }
        if (found.size + alreadyDone >= max) break;
        const name = clean(ariaLabel(a) || a.textContent).replace(P.VISITED_SUFFIX, '');
        found.set(ids.key, { ...ids, href: a.href, name, sponsored });
      }
      return links.length;
    };

    let lastRaw = harvest();
    let noGrowth = 0;
    let endReached = false;

    for (let round = 0; round < TUNING.maxScrollRounds; round++) {
      await checkpoint();
      const feed = getFeed();
      if (!feed) throw new Error('Results list disappeared while scrolling');
      sendProgress('scrolling', `Scrolling results… ${found.size} listings found`, { found: found.size + alreadyDone });

      if (found.size + alreadyDone >= max) break;
      if (endOfListReached(feed)) { endReached = true; break; }

      const scroller = getScroller(feed);
      scroller.scrollBy({ top: randInt(TUNING.scrollStepMin, TUNING.scrollStepMax), behavior: 'smooth' });
      await humanDelay(TUNING.scrollWaitMin, TUNING.scrollWaitMax);

      const raw = harvest();
      if (raw > lastRaw) {
        noGrowth = 0;
        lastRaw = raw;
      } else if (atBottom(scroller)) {
        // Only count "no growth" when we're actually at the bottom waiting for more results.
        noGrowth++;
        if (noGrowth >= TUNING.noGrowthLimit) break;
      }
    }
    harvest();
    endReached = endReached || endOfListReached(getFeed());
    const listings = [...found.values()].slice(0, Math.max(0, max - alreadyDone));
    return { listings, alreadyDone, endReached };
  }

  function findLinkInFeed(listing) {
    const feed = getFeed();
    if (!feed) return null;
    for (const a of feed.querySelectorAll(S.FEED_LINK)) {
      const h = safeDecode(a.href);
      if ((listing.placeId && h.includes(listing.placeId)) || (listing.hex && h.includes(listing.hex))
        || a.href === listing.href || h.split('?')[0] === listing.key) return a;
    }
    return null;
  }

  /**
   * Find the listing's link. Google sometimes re-renders the feed with only the first page of
   * results (e.g. after going back from a place), so scroll to load more before giving up.
   */
  async function locateLinkInFeed(listing) {
    let link = findLinkInFeed(listing);
    let noGrowth = 0;
    for (let round = 0; !link && round < TUNING.relocateRounds; round++) {
      await checkpoint();
      const feed = getFeed();
      if (!feed || endOfListReached(feed)) break;
      if (round === 0) sendProgress('scraping', `Reloading results list to find ${listing.name}…`);
      const before = feed.querySelectorAll(S.FEED_LINK).length;
      const scroller = getScroller(feed);
      scroller.scrollBy({ top: randInt(TUNING.scrollStepMin, TUNING.scrollStepMax) * 2, behavior: 'smooth' });
      await humanDelay(TUNING.scrollWaitMin, TUNING.scrollWaitMax);
      link = findLinkInFeed(listing);
      if ((getFeed()?.querySelectorAll(S.FEED_LINK).length || 0) > before) noGrowth = 0;
      else if (atBottom(scroller) && ++noGrowth >= TUNING.noGrowthLimit) break;
    }
    return link;
  }

  /** In narrow (single-pane) layouts the details replace the list — go back to it. */
  async function ensureFeedVisible() {
    if (isVisible(getFeed())) return true;
    const back = document.querySelector(S.BACK_BUTTON);
    if (back) back.click();
    else history.back();
    const feed = await waitFor(() => (isVisible(getFeed()) ? getFeed() : null), { timeout: 10000 });
    return !!feed;
  }

  // ======================================================================
  // Details panel
  // ======================================================================
  function findDetailsPanel(expectedName) {
    const want = expectedName ? normName(expectedName) : '';
    const panels = [...document.querySelectorAll(S.DETAILS_PANEL)]
      .filter((p) => p.querySelector(S.PLACE_NAME) && !p.querySelector(S.FEED));
    if (!panels.length) return null;
    if (want) {
      const exact = panels.find((p) =>
        normName(p.querySelector(S.PLACE_NAME).textContent) === want || normName(p.getAttribute('aria-label')) === want);
      if (exact) return exact;
    }
    return panels[panels.length - 1];
  }

  /** The header block (name, rating, category) — everything above the tab bar. */
  function headerOf(panel) {
    const h1 = panel.querySelector(S.PLACE_NAME);
    if (!h1) return panel;
    let node = h1;
    for (let i = 0; i < 8; i++) {
      const p = node.parentElement;
      if (!p || p === panel || p.querySelector(S.TABLIST)) break;
      node = p;
    }
    return node;
  }

  /** Wait until the details panel shows THIS listing (URL id and/or h1 name match). */
  function waitForDetails(listing) {
    const start = Date.now();
    const want = normName(listing.name);
    const prev = normName(lastScrapedName);
    return waitFor(() => {
      const panel = findDetailsPanel(listing.name);
      if (!panel) return null;
      const h1 = normName(panel.querySelector(S.PLACE_NAME)?.textContent);
      if (!h1) return null;
      const href = safeDecode(location.href);
      const urlOk = (listing.hex && href.includes(listing.hex)) || (listing.placeId && href.includes(listing.placeId));
      const nameOk = want && h1 === want;
      const elapsed = Date.now() - start;
      if (nameOk && (urlOk || elapsed > 3000)) return panel;
      if (urlOk && elapsed > 6000 && h1 !== prev) return panel; // name differs slightly (e.g. translation)
      return null;
    }, { timeout: TUNING.detailsTimeout });
  }

  // ---------- Hours ----------
  function readHoursTable(panel) {
    const out = {};
    for (const table of panel.querySelectorAll(S.HOURS_TABLE)) {
      for (const tr of table.querySelectorAll('tr')) {
        const cells = tr.querySelectorAll('td, th');
        if (cells.length < 2) continue;
        const dayMatch = clean(cells[0].textContent).match(P.DAY_NAME);
        if (!dayMatch) continue;
        const items = [...cells[1].querySelectorAll('li')].map((li) => clean(li.textContent)).filter(Boolean);
        out[dayMatch[0]] = items.length ? items.join(', ') : clean(cells[1].getAttribute('aria-label') || cells[1].textContent);
      }
      if (Object.keys(out).length) break;
    }
    return out;
  }

  function readHoursFromCopyButtons(panel) {
    const out = {};
    for (const b of panel.querySelectorAll(S.HOURS_COPY_BUTTON)) {
      const v = clean(b.getAttribute('data-value'));
      const i = v.indexOf(', ');
      if (i > 0 && P.DAY_NAME.test(v)) out[v.slice(0, i)] = v.slice(i + 2);
    }
    return out;
  }

  function readHoursFromAria(panel) {
    const out = {};
    const el = panel.querySelector(S.HOURS_ARIA_SUMMARY);
    if (!el) return out;
    for (const part of ariaLabel(el).split(';')) {
      const v = clean(part).replace(/\.?\s*Hide open hours.*$/i, '');
      const i = v.indexOf(', ');
      if (i > 0 && P.DAY_NAME.test(v)) out[v.slice(0, i)] = v.slice(i + 2);
    }
    return out;
  }

  async function extractHours(panel) {
    const toggle = panel.querySelector(S.HOURS_TOGGLE);
    const status = toggle ? clean(String(toggle.innerText || toggle.textContent).split('\n')[0]) : '';
    let weekly = readHoursTable(panel);
    if (!Object.keys(weekly).length && toggle && toggle.getAttribute('aria-expanded') === 'false') {
      toggle.click(); // expand the weekly table
      await waitFor(() => (Object.keys(readHoursTable(panel)).length ? true : null), { timeout: 3000 });
      weekly = readHoursTable(panel);
    }
    if (!Object.keys(weekly).length) weekly = readHoursFromCopyButtons(panel);
    if (!Object.keys(weekly).length) weekly = readHoursFromAria(panel);
    const text = Object.entries(weekly).map(([d, h]) => `${d}: ${h}`).join(' | ');
    return { status, weekly, text };
  }

  // ---------- Attributes ("About" tab) ----------
  async function extractAboutTab(listing) {
    const panel = findDetailsPanel(listing.name);
    const tab = panel?.querySelector(S.ABOUT_TAB);
    if (!tab) return null;
    await humanDelay(TUNING.tabPauseMin, TUNING.tabPauseMax);
    tab.click();
    // The overview also has an "About ..." region, but only the About tab has h2 section headings.
    const region = await waitFor(() => {
      const p = findDetailsPanel(listing.name);
      return p ? [...p.querySelectorAll(S.ABOUT_REGION)].find((r) => r.querySelector(S.ABOUT_HEADING)) || null : null;
    }, { timeout: 6000 });
    await checkpoint();
    if (!region) return null;

    const sections = {};
    let current = 'Other';
    for (const el of region.querySelectorAll(`${S.ABOUT_HEADING}, li`)) {
      if (el.matches(S.ABOUT_HEADING)) { current = clean(el.textContent) || 'Other'; continue; }
      const lab = el.querySelector('[aria-label]')?.getAttribute('aria-label');
      const v = clean(lab || el.textContent);
      if (!v) continue;
      (sections[current] = sections[current] || []);
      if (!sections[current].includes(v)) sections[current].push(v);
    }
    // Go back to Overview so the next listing starts from a normal state (harmless if it fails).
    findDetailsPanel(listing.name)?.querySelector(S.OVERVIEW_TAB)?.click();
    return Object.keys(sections).length ? sections : null;
  }

  function attributesText(attrs) {
    return Object.entries(attrs || {}).map(([k, v]) => `${k}: ${v.join(', ')}`).join(' | ');
  }

  function mergeAbout(record, sections) {
    record.attributes = sections;
    record.attributes_text = attributesText(sections);
    const svcKey = Object.keys(sections).find((k) => /service options/i.test(k));
    if (svcKey) record.service_options = sections[svcKey];
    const accKey = Object.keys(sections).find((k) => /accessib/i.test(k));
    if (accKey) record.accessibility = sections[accKey].join(', ');
  }

  // ---------- Main extractor ----------
  async function extractDetails(panel, listing) {
    const q = (sel) => panel.querySelector(sel);
    const header = headerOf(panel);
    const name = clean(q(S.PLACE_NAME)?.textContent) || listing.name;

    // Rating / review count / price — read from aria-labels in the header only
    let rating = '', reviewCount = '', priceLevel = '', priceLabel = '';
    for (const el of header.querySelectorAll(S.HEADER_IMG_LABELS)) {
      const lab = ariaLabel(el);
      let m;
      if (!rating && (m = lab.match(P.RATING))) rating = m[1].replace(',', '.');
      if (!reviewCount && (m = lab.match(P.REVIEW_COUNT))) reviewCount = m[1].replace(/\D/g, '');
      if (!priceLevel && !P.RATING.test(lab) && !P.REVIEW_COUNT.test(lab) && P.PRICE.test(lab)) {
        priceLevel = clean(el.textContent) || lab;
        priceLabel = lab;
      }
    }
    if (!reviewCount) {
      const m = clean(header.querySelector(S.RATING_FALLBACK)?.textContent).match(/\(([\d,.]+)\)/);
      if (m) reviewCount = m[1].replace(/\D/g, '');
    }

    const category = clean(header.querySelector(S.CATEGORY)?.textContent);
    const wheelchair = ariaLabel(header.querySelector(S.WHEELCHAIR));

    const addressEl = q(S.ADDRESS);
    const address = stripLabel(ariaLabel(addressEl) || addressEl?.querySelector(S.ITEM_TEXT)?.textContent);
    const phoneEl = q(S.PHONE);
    const phone = stripLabel(ariaLabel(phoneEl) || phoneEl?.querySelector(S.ITEM_TEXT)?.textContent);
    const phoneE164 = (phoneEl?.getAttribute('data-item-id') || '').replace(/^phone:tel:/, '');
    const website = normalizeLink(q(S.WEBSITE)?.href);
    const plusCodeEl = q(S.PLUS_CODE);
    const plusCode = stripLabel(ariaLabel(plusCodeEl) || plusCodeEl?.querySelector(S.ITEM_TEXT)?.textContent);
    const locatedIn = stripLabel(ariaLabel(q(S.LOCATED_IN)));
    const menuUrl = normalizeLink(q(S.MENU)?.href);
    const reservationUrl = q(S.RESERVE_LINK)?.href || '';
    const actions = [...new Set([...panel.querySelectorAll(S.ACTIONS)].map((el) => ariaLabel(el) || clean(el.textContent)).filter(Boolean))];
    const pricePerPerson = (ariaLabel(q(S.PRICE_RANGE)).match(P.PRICE_PER_PERSON) || [])[1] || '';

    const hours = await extractHours(panel);
    await checkpoint();

    // Overview attribute chips: "Dine-in", "Curbside pickup", ...
    const overviewAttrs = [...panel.querySelectorAll(S.OVERVIEW_ATTRIBUTE)].map((g) => {
      const visible = [...g.querySelectorAll('[aria-hidden="true"]')].map((x) => clean(x.textContent)).filter(Boolean).pop();
      return visible || ariaLabel(g);
    }).filter(Boolean);

    const ratingBreakdown = {};
    for (const tr of panel.querySelectorAll(S.RATING_HISTOGRAM_ROW)) {
      const m = ariaLabel(tr).match(P.HISTOGRAM_ROW);
      if (m) ratingBreakdown[m[1]] = Number(m[2].replace(/\D/g, ''));
    }

    const closed = (clean(header.textContent) + ' ' + hours.status).match(P.CLOSED_STATUS);
    const ids = listing.hex || listing.placeId ? listing : parsePlaceUrl(location.href);
    const coords = listing.lat ? listing : parsePlaceUrl(location.href);
    const attrs = overviewAttrs.length ? { Highlights: overviewAttrs } : {};

    return {
      name,
      category,
      rating,
      review_count: reviewCount,
      price_level: priceLevel,
      price_label: priceLabel,
      price_per_person: pricePerPerson,
      address,
      ...parseAddress(address),
      phone,
      phone_e164: phoneE164,
      website,
      plus_code: plusCode,
      located_in: locatedIn,
      hours_status: hours.status,
      hours: hours.text,
      hours_weekly: hours.weekly,
      business_status: closed ? closed[1] : (hours.status ? 'Operational' : ''),
      latitude: coords.lat || '',
      longitude: coords.lng || '',
      google_maps_url: listing.href || location.href,
      place_id: ids.placeId || '',
      cid: ids.cid || '',
      hex_id: ids.hex || '',
      cid_url: ids.cid ? `https://maps.google.com/?cid=${ids.cid}` : '',
      service_options: overviewAttrs,
      attributes: attrs,
      attributes_text: attributesText(attrs),
      accessibility: wheelchair,
      menu_url: menuUrl,
      reservation_url: reservationUrl,
      actions,
      image_url: q(S.HERO_IMAGE)?.src || '',
      review_summary: clean(q(S.REVIEW_SUMMARY)?.textContent),
      rating_breakdown: ratingBreakdown,
      is_claimed: q(S.CLAIM) ? 'no' : '',
      is_sponsored: !!listing.sponsored,
      listing_key: listing.key, // used by background for de-duplication, then removed
    };
  }

  async function scrapeOpenPanel(listing, job, panelHint) {
    await countdownDelay(job.settings.minDelay * 1000, job.settings.maxDelay * 1000);
    const panel = findDetailsPanel(listing.name) || panelHint;
    if (!panel) throw new Error('Details panel vanished before extraction');
    const record = await extractDetails(panel, listing);
    if (job.settings.openAboutTab) {
      try {
        const about = await extractAboutTab(listing);
        if (about) mergeAbout(record, about);
      } catch (e) {
        if (e instanceof StopError) throw e;
        LOG('About tab failed:', e.message);
      }
    }
    lastScrapedName = record.name;
    return record;
  }

  /** Click a listing in the feed, wait for its panel, extract. Retries the click once. */
  async function scrapeListing(listing, job) {
    let opened = null;
    for (let attempt = 1; attempt <= 2 && !opened; attempt++) {
      await checkpoint();
      await ensureFeedVisible();
      const link = await locateLinkInFeed(listing);
      if (!link) throw new Error('Listing link not found in results list');
      link.scrollIntoView({ block: 'center', behavior: 'smooth' });
      await humanDelay(TUNING.clickPauseMin, TUNING.clickPauseMax);
      link.click();
      opened = await waitForDetails(listing);
      await checkpoint();
    }
    if (!opened) throw new Error('Details panel did not load in time');
    return scrapeOpenPanel(listing, job, opened);
  }

  /** Google redirected a search straight to one place (single result). */
  async function scrapeSinglePlace(job, skipKeys) {
    const panel = findDetailsPanel();
    const ids = parsePlaceUrl(location.href);
    const listing = { ...ids, href: location.href, name: clean(panel.querySelector(S.PLACE_NAME)?.textContent), sponsored: false };
    if (skipKeys.has(listing.key)) return { found: 1, scraped: 0, errors: 0, alreadyDone: 1, endReached: true };
    sendProgress('scraping', `Scraping single result: ${listing.name}`, { found: 1, done: 0, total: 1 });
    const record = await scrapeOpenPanel(listing, job, panel);
    await send('RECORD', { record });
    return { found: 1, scraped: 1, errors: 0, alreadyDone: 0, endReached: true };
  }

  // ======================================================================
  // Job runner
  // ======================================================================
  async function doZip(job) {
    const skipKeys = new Set(job.skipKeys || []);
    const navKey = 'ms_nav_' + job.searchUrl;

    sendProgress('searching', `Waiting for results: ${job.query}`);
    const feed = await waitFor(() => (isVisible(getFeed()) ? getFeed() : null), { timeout: TUNING.feedTimeout });
    await checkpoint();

    if (!feed) {
      const tries = Number(sessionStorage.getItem(navKey) || 0);
      if (tries < 1) {
        // e.g. the tab reloaded on a place page — reopen the search once.
        sessionStorage.setItem(navKey, String(tries + 1));
        sendProgress('searching', 'Reopening search results…');
        location.href = job.searchUrl;
        throw new NavigatingSignal();
      }
      sessionStorage.removeItem(navKey);
      if (location.pathname.includes('/maps/place/') && findDetailsPanel()) return scrapeSinglePlace(job, skipKeys);
      throw new Error('Results list not found (role="feed"). Google may have changed its markup or shown a block page.');
    }
    sessionStorage.removeItem(navKey);

    const { listings, alreadyDone, endReached } = await collectListings(job, skipKeys);
    const total = listings.length;
    let scraped = 0;
    let errors = 0;

    for (let i = 0; i < total; i++) {
      await checkpoint();
      const listing = listings[i];
      sendProgress('scraping', `Scraping ${i + 1} of ${total}: ${listing.name}`, { found: total + alreadyDone, done: i, total });
      try {
        const record = await scrapeListing(listing, job);
        await send('RECORD', { record });
        scraped++;
      } catch (e) {
        if (e instanceof StopError) throw e;
        errors++;
        LOG('Listing failed:', listing.name, e);
        await send('LISTING_ERROR', { error: e.message || String(e), name: listing.name, key: listing.key });
      }
    }
    sendProgress('scraping', `Finished ZIP: ${scraped} of ${total} scraped`, { found: total + alreadyDone, done: total, total });
    return { found: total + alreadyDone, scraped, errors, alreadyDone, endReached };
  }

  async function runZip(job) {
    ctl.running = true;
    ctl.stopped = false;
    ctl.paused = !!job.paused;
    lastScrapedName = '';
    const hb = setInterval(() => send('HEARTBEAT'), TUNING.heartbeatMs);
    try {
      const result = await doZip(job);
      await send('ZIP_DONE', { result });
    } catch (e) {
      if (e instanceof NavigatingSignal) return; // page is reloading; background re-dispatches on CONTENT_READY
      if (e instanceof StopError) { await send('ZIP_STOPPED'); return; }
      LOG('ZIP failed:', e);
      await send('ZIP_FAILED', { error: e.message || String(e) });
    } finally {
      clearInterval(hb);
      ctl.running = false;
    }
  }

  // ======================================================================
  // Messaging
  // ======================================================================
  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    switch (msg?.type) {
      case 'PING':
        sendResponse({ ok: true, running: ctl.running });
        break;
      case 'RUN_ZIP':
        if (ctl.running) { sendResponse({ ok: false, busy: true }); break; }
        sendResponse({ ok: true });
        runZip(msg.job);
        break;
      case 'PAUSE':
        ctl.paused = true;
        sendResponse({ ok: true });
        break;
      case 'RESUME':
        ctl.paused = false;
        sendResponse({ ok: true });
        break;
      case 'STOP':
        ctl.stopped = true;
        ctl.paused = false;
        sendResponse({ ok: true });
        break;
      default:
        break;
    }
    return false;
  });

  send('CONTENT_READY', { url: location.href });
})();
