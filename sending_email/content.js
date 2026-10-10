/*
 * content.js — runs on mail.google.com. Does only the DOM work; background.js decides what to do.
 *   SNAPSHOT  -> what the page shows (Gmail loaded? which account? sending limit notice?)
 *   COMPOSE   -> open a new compose window, fill To / Subject / Body, then click Send (or, with
 *                send: false, leave the filled window open for a test) and wait for Gmail to confirm.
 */
(() => {
  if (window.__gmailMailerLoaded) return;
  window.__gmailMailerLoaded = true;

  const S = GMAIL_SELECTORS;
  const P = GMAIL_PATTERNS;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim();

  /** Really on screen: has a size, isn't transparent or hidden (Mailtrack hides Gmail's Send at 0x0). */
  function shown(el) {
    if (!el || !el.isConnected) return false;
    const r = el.getBoundingClientRect();
    if (r.width < 1 || r.height < 1) return false;
    const cs = getComputedStyle(el);
    return cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0';
  }
  const allShown = (sel, root = document) => [...root.querySelectorAll(sel)].filter(shown);

  async function waitFor(fn, timeoutMs, everyMs = 200) {
    const end = Date.now() + timeoutMs;
    for (;;) {
      const v = fn();
      if (v) return v;
      if (Date.now() > end) return null;
      await sleep(everyMs);
    }
  }

  function clickEl(el) {
    const r = el.getBoundingClientRect();
    const opts = { bubbles: true, cancelable: true, composed: true, view: window, button: 0, clientX: r.left + r.width / 2, clientY: r.top + r.height / 2 };
    el.dispatchEvent(new PointerEvent('pointerdown', opts));
    el.dispatchEvent(new MouseEvent('mousedown', opts));
    el.dispatchEvent(new PointerEvent('pointerup', opts));
    el.dispatchEvent(new MouseEvent('mouseup', opts));
    el.dispatchEvent(new MouseEvent('click', opts));
  }

  function key(el, k, extra = {}) {
    const codes = { Enter: 13, Tab: 9, Escape: 27 };
    for (const type of ['keydown', 'keypress', 'keyup']) {
      el.dispatchEvent(new KeyboardEvent(type, { key: k, code: k, keyCode: codes[k] || 0, which: codes[k] || 0, bubbles: true, cancelable: true, ...extra }));
    }
  }

  // ---------------------------------------------------------------------------
  // Page state
  // ---------------------------------------------------------------------------
  function account() {
    const label = document.querySelector(S.ACCOUNT)?.getAttribute('aria-label') || '';
    const m = label.match(P.ACCOUNT_EMAIL) || document.title.match(P.ACCOUNT_EMAIL);
    return m ? m[0].toLowerCase() : '';
  }

  /** Gmail's own Compose button (Mailtrack adds a look-alike dropdown with the same classes). */
  function composeButton() {
    const list = allShown(S.COMPOSE_BUTTON);
    return list.find((e) => e.hasAttribute('jsaction')) || list.find((e) => norm(e.textContent)) || list[0] || null;
  }

  const alertText = () => allShown(S.ALERT_DIALOG).map((d) => norm(d.innerText)).filter(Boolean).join(' | ').slice(0, 400);
  const toastText = () => allShown(S.TOAST).map((t) => norm(t.innerText)).filter(Boolean).join(' | ').slice(0, 200);

  function snapshot() {
    const alert = alertText();
    return {
      ok: true,
      url: location.href,
      pageStart: performance.timeOrigin, // when this page load started (ms since epoch)
      ready: document.readyState === 'complete' && !!composeButton(),
      account: account(),
      alert,
      limitHit: P.LIMIT.test(alert) || P.LIMIT.test(toastText()),
    };
  }

  // ---------------------------------------------------------------------------
  // Compose window
  // ---------------------------------------------------------------------------
  /** The compose window around a subject box: its dialog, or the nearest block holding every field. */
  function composeRoot(subject) {
    const dlg = subject.closest('[role="dialog"]');
    if (dlg) return dlg;
    let el = subject;
    while (el && !(el.querySelector(S.BODY) && el.querySelector(S.SEND_BUTTON) && el.querySelector(S.TO_INPUT))) el = el.parentElement;
    return el;
  }

  async function openCompose() {
    const before = new Set([...document.querySelectorAll(S.SUBJECT)]);
    const fresh = () => allShown(S.SUBJECT).find((s) => !before.has(s) && !s.value);
    const btn = await waitFor(composeButton, 15000);
    if (!btn) return null;
    clickEl(btn);
    let subject = await waitFor(fresh, 8000);
    if (!subject) {
      // Button click ignored: Gmail also opens a compose window from the URL.
      location.hash = `${(location.hash.split('?')[0] || '#inbox')}?compose=new`;
      subject = await waitFor(fresh, 8000);
    }
    if (!subject) return null;
    const root = composeRoot(subject);
    // A slow first click can open a second, empty window: close the extra ones we opened (never one
    // that was open before, or that has anything typed in it).
    await sleep(1500);
    for (const s of allShown(S.SUBJECT)) {
      if (s === subject || before.has(s) || s.value) continue;
      const extra = composeRoot(s);
      if (extra && extra !== root && !toChips(extra).size && !norm(extra.querySelector(S.TO_INPUT)?.value)) discard(extra);
    }
    return root;
  }

  /** Lowercased addresses Gmail shows as recipient chips in the To field (read from the chips' attributes). */
  function toChips(root) {
    const area = root.querySelector(S.TO_AREA) || root;
    const out = new Set();
    for (const el of area.querySelectorAll(S.TO_CHIP)) {
      for (const a of ['data-hovercard-id', 'email', 'data-email']) {
        const v = el.getAttribute(a);
        if (v && v.includes('@')) out.add(v.trim().toLowerCase());
      }
    }
    return out;
  }

  /** Types like a person: key events around each character, so Gmail's recipient box follows along. */
  async function typeKeys(el, text) {
    for (const ch of text) {
      const code = ch.toUpperCase().charCodeAt(0);
      const opts = { key: ch, keyCode: code, which: code, bubbles: true, cancelable: true };
      el.dispatchEvent(new KeyboardEvent('keydown', opts));
      el.dispatchEvent(new KeyboardEvent('keypress', { ...opts, charCode: ch.charCodeAt(0) }));
      document.execCommand('insertText', false, ch);
      el.dispatchEvent(new KeyboardEvent('keyup', opts));
      await sleep(15);
    }
  }

  function clearInput(input) {
    input.focus();
    input.select?.();
    document.execCommand('delete', false);
  }

  /**
   * Puts exactly these addresses in To. Never presses Enter or Tab: with Gmail's suggestion list
   * open, those pick the top suggestion (some other contact) instead of the typed address. A comma,
   * or leaving the field, keeps the typed text as written.
   * Returns null, or an error. Any recipient that wasn't asked for is an error: the caller discards
   * the draft, so it is never sent.
   */
  async function fillTo(root, to) {
    const input = root.querySelector(S.TO_INPUT);
    if (!input) return 'the To field was not found';
    const want = to.map((a) => a.trim().toLowerCase());
    const extra = () => [...toChips(root)].filter((a) => !want.includes(a));
    const exact = () => { const c = toChips(root); return want.every((a) => c.has(a)) && c.size === want.length; };
    const wrong = () => `Gmail put a recipient that wasn't asked for in To (${extra().join(', ')}; wanted ${want.join(', ')}). Not sent.`;
    if (extra().length) return wrong();

    // 1. Type each address, then a comma. A second pass types any that weren't taken.
    for (const a of [...want, ...want]) {
      if (toChips(root).has(a)) continue;
      clearInput(input);
      await typeKeys(input, a);
      await sleep(300);
      const opts = { key: ',', code: 'Comma', keyCode: 188, which: 188, bubbles: true, cancelable: true };
      input.dispatchEvent(new KeyboardEvent('keydown', opts));
      input.dispatchEvent(new KeyboardEvent('keyup', opts));
      await waitFor(() => toChips(root).has(a) || extra().length, 1500);
      if (!toChips(root).has(a) && !extra().length) {
        // Comma ignored: leave the field, which also turns the typed text into a chip.
        root.querySelector(S.SUBJECT)?.focus();
        await waitFor(() => toChips(root).has(a) || extra().length, 1500);
      }
      if (extra().length) return wrong();
    }
    if (await waitFor(exact, 500)) return null;

    // 2. Paste the missing addresses.
    const missing = want.filter((a) => !toChips(root).has(a));
    clearInput(input);
    const data = new DataTransfer();
    data.setData('text/plain', missing.join(', '));
    input.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    await sleep(500);
    root.querySelector(S.SUBJECT)?.focus();
    if (await waitFor(() => exact() || extra().length, 2000)) return extra().length ? wrong() : null;

    const got = [...toChips(root)];
    const typed = norm(input.value);
    return `Gmail did not accept the recipients (wanted ${want.join(', ')}; To shows ${got.join(', ') || 'nothing'}${typed ? `; still typed: "${typed}"` : ''})`;
  }

  async function fillSubject(root, subject) {
    const el = root.querySelector(S.SUBJECT);
    if (!el) return 'the Subject field was not found';
    el.focus();
    el.select();
    document.execCommand('insertText', false, subject);
    if (el.value !== subject) {
      Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set.call(el, subject);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
    return el.value === subject ? null : 'could not type the subject';
  }

  /** Paste the message (HTML keeps bold and links) above any Gmail signature. */
  async function fillBody(root, html, text) {
    const el = allShown(S.BODY, root)[0] || root.querySelector(S.BODY);
    if (!el) return 'the message box was not found';
    const probe = norm(text).slice(0, 40);
    const has = () => norm(el.innerText).includes(probe);
    el.focus();
    // Caret at the very start, so Gmail's signature (if any) stays below the message.
    const range = document.createRange();
    range.setStart(el, 0);
    range.collapse(true);
    const sel = getSelection();
    sel.removeAllRanges();
    sel.addRange(range);

    const data = new DataTransfer();
    data.setData('text/html', html);
    data.setData('text/plain', text);
    el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    if (await waitFor(has, 2500)) return null;
    el.focus();
    document.execCommand('insertHTML', false, html);
    if (await waitFor(has, 2000)) return null;
    document.execCommand('insertText', false, text);
    return (await waitFor(has, 2000)) ? null : 'could not paste the message';
  }

  function discard(root) {
    const b = allShown(S.DISCARD_BUTTON, root)[0];
    if (b) clickEl(b);
  }

  function closeAlert() {
    const d = allShown(S.ALERT_DIALOG)[0];
    const b = d && ([...d.querySelectorAll(S.ALERT_OK)].find(shown));
    if (b) clickEl(b);
  }

  async function compose({ to, subject, html, text, send, allowUntracked }) {
    if (!to?.length) return { ok: false, error: 'no recipient' };
    if (!(await waitFor(() => document.readyState === 'complete' && composeButton(), 15000))) {
      return { ok: false, stage: 'open', error: 'Gmail is not ready (no Compose button)' };
    }
    const root = await openCompose();
    if (!root) return { ok: false, stage: 'open', error: 'the compose window did not open' };
    // Right after Gmail loads, the window shows before its fields work: wait for them, then a bit more.
    await waitFor(() => allShown(S.TO_INPUT, root).length && allShown(S.BODY, root).length, 8000);
    await sleep(1000);

    const fail = (error, extra = {}) => { discard(root); return { ok: false, stage: 'fill', error, ...extra }; };
    let err = await fillTo(root, to);
    if (err && !toChips(root).size) {
      // Nothing was taken at all (fields not ready yet): clear the field and type once more.
      const input = root.querySelector(S.TO_INPUT);
      if (input) {
        input.focus();
        input.select?.();
        document.execCommand('delete', false);
      }
      await sleep(1500);
      err = await fillTo(root, to);
    }
    if (err) return fail(err);
    if ((err = await fillSubject(root, subject))) return fail(err);
    if ((err = await fillBody(root, html, text))) return fail(err);
    await sleep(800);
    // Last check before sending: To holds exactly the wanted addresses, nothing else.
    const chips = toChips(root);
    const want = to.map((a) => a.trim().toLowerCase());
    if (chips.size !== want.length || !want.every((a) => chips.has(a))) {
      return fail(`To changed before sending (shows ${[...chips].join(', ') || 'nothing'}; wanted ${want.join(', ')}). Not sent.`);
    }

    if (!send) return { ok: true, sent: false, account: account() };

    // Sent = our compose window closes without an error popup. (A "Message sent" notice alone
    // proves nothing: it may still be showing from the previous email.) Gmail closes the window as
    // soon as it starts sending, so while it is open nothing was sent and the next way is safe.
    const gone = () => !root.isConnected || !shown(root) || !root.querySelector(S.SUBJECT);
    const check = () => {
      const a = alertText();
      if (a) return { alert: a };
      return gone() ? { sent: true } : null;
    };
    // Only a real Send: never "Send later", "Schedule send" or "More send options".
    const isSend = (b) => {
      const label = b.getAttribute('data-tooltip') || b.getAttribute('aria-label') || '';
      return /^send\b/i.test(label) && !/later|schedul|option/i.test(label);
    };
    const visibleSend = allShown(S.SEND_BUTTON, root).find(isSend);
    // Mailtrack's Send (a copy over Gmail's) adds its tracking pixel, but only on a click from a
    // real person: background.js makes one through Chrome's debugger. The other ways send untracked.
    const mailtrack = !!visibleSend?.matches(S.MAILTRACK_SEND);
    const tried = [];
    const notes = [];
    let outcome = null;
    let via = '';

    if (visibleSend) {
      tried.push('real click on Send');
      const err = await trustedClick(visibleSend);
      if (err) notes.push(err);
      else if ((outcome = await waitFor(check, 12000, 250))) via = 'real click';
    }

    const fallbacks = [
      ['script click on Send', () => { if (!visibleSend) return false; clickEl(visibleSend); return true; }],
      // Gmail's own Send button, which Mailtrack hides: clicking it directly still sends, untracked.
      ['Gmail\'s own Send button', () => {
        const b = [...root.querySelectorAll(S.SEND_ANY)].find((x) => x !== visibleSend && isSend(x));
        if (!b) return false;
        clickEl(b);
        return true;
      }],
      ['Ctrl+Enter', () => {
        const body = root.querySelector(S.BODY);
        if (!body) return false;
        body.focus();
        key(body, 'Enter', { ctrlKey: true });
        return true;
      }],
    ];
    if (!outcome && (!mailtrack || allowUntracked)) {
      for (const [name, attempt] of fallbacks) {
        if (!attempt()) continue;
        tried.push(name);
        if ((outcome = await waitFor(check, 8000, 250))) { via = name; break; }
      }
    } else if (!outcome) {
      notes.push('not sent untracked: turn on "Send untracked if Mailtrack\'s Send doesn\'t respond" to allow it');
    }
    if (!outcome && tried.length) {
      outcome = await waitFor(check, 6000, 250);
      if (outcome) via = tried[tried.length - 1];
    }
    if (outcome?.sent) {
      // Gmail can still refuse it just after the window closes (sending limit): watch a few seconds.
      const late = await waitFor(() => {
        const a = alertText() || (P.LIMIT.test(toastText()) ? toastText() : '');
        if (a) return { alert: a };
        return P.TOAST_SENT.test(toastText()) ? { sent: true } : null;
      }, 5000, 250);
      if (late?.alert) outcome = late;
    }

    if (outcome?.alert) {
      const limit = P.LIMIT.test(outcome.alert);
      const badAddress = P.BAD_ADDRESS.test(outcome.alert);
      closeAlert();
      await sleep(500);
      if (root.isConnected) discard(root);
      return { ok: false, stage: 'send', error: `Gmail: ${outcome.alert}`, limitHit: limit, badAddress };
    }
    if (!outcome) {
      // Still open: not sent. Name any popup that may be in the way (e.g. a Mailtrack notice).
      const modal = allShown(S.MODAL).filter((m) => !m.contains(root) && !root.contains(m))
        .map((m) => norm(m.innerText)).filter(Boolean).join(' | ').slice(0, 200);
      discard(root);
      return {
        ok: false, stage: 'send',
        error: `Gmail did not send it (tried: ${tried.join(', ') || 'nothing, no Send button'})`
          + `${notes.length ? `; ${notes.join('; ')}` : ''}${modal ? `. A popup is open: "${modal}"` : ''}`,
      };
    }
    // tracked: null when Mailtrack isn't installed.
    return { ok: true, sent: true, account: account(), toast: toastText(), via, tracked: mailtrack ? via === 'real click' : null };
  }

  /**
   * Asks background.js to click the middle of `el` like a real mouse (Chrome debugger). Returns
   * null when the click was made, or why not.
   */
  async function trustedClick(el) {
    for (let attempt = 0; attempt < 2; attempt++) {
      el.scrollIntoView({ block: 'nearest', inline: 'nearest' });
      await sleep(150);
      const r = el.getBoundingClientRect();
      if (r.width < 1 || r.height < 1) return 'the Send button has no size';
      const x = Math.round(r.left + r.width / 2);
      const y = Math.round(r.top + r.height / 2);
      // The click lands on whatever is on top at that point: make sure it is the button.
      const top = document.elementFromPoint(x, y);
      if (top && top !== el && !el.contains(top)) {
        return `something covers the Send button: "${norm(top.closest('[role="dialog"], [aria-modal="true"]')?.innerText || top.innerText || top.className).slice(0, 120)}"`;
      }
      let res;
      try {
        res = await chrome.runtime.sendMessage({ type: 'TRUSTED_CLICK', x, y });
      } catch (e) {
        return `real click failed: ${e.message}`;
      }
      if (res?.ok) return null;
      // Just attached: Chrome's "debugging this browser" bar may have moved the page. Measure again.
      if (res?.attached) { await sleep(800); continue; }
      return `real click failed: ${res?.error || 'no answer from the extension'}`;
    }
    return 'real click failed: the page kept moving';
  }

  let busy = false;
  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    switch (msg?.type) {
      case 'PING':
        sendResponse({ ok: true });
        return false;
      case 'SNAPSHOT':
        sendResponse(snapshot());
        return false;
      case 'COMPOSE':
        if (busy) {
          sendResponse({ ok: false, error: 'already composing an email' });
          return false;
        }
        busy = true;
        compose(msg)
          .catch((e) => ({ ok: false, error: String(e?.message || e) }))
          .then((r) => { busy = false; sendResponse(r); });
        return true; // async response
      default:
        return false;
    }
  });

  chrome.runtime.sendMessage({ type: 'CONTENT_READY' }).catch(() => {});
})();
