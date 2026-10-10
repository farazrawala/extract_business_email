/*
 * content.js — runs on chatgpt.com. Does only the DOM work; background.js decides what to do.
 *   SNAPSHOT     -> what the page shows right now (composer ready? reply text? still writing?)
 *   SEND_PROMPT  -> paste a prompt into the message box and send it
 * Waiting for the reply is done by background.js polling SNAPSHOT, because Chrome throttles
 * timers in background tabs but not extension messages.
 */
(() => {
  if (window.__gptEmailFinderLoaded) return;
  window.__gptEmailFinderLoaded = true;

  const S = GPT_SELECTORS;
  const P = GPT_PATTERNS;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
  const visible = (el) => !!el && el.getClientRects().length > 0;
  const firstVisible = (sel) => [...document.querySelectorAll(sel)].find(visible) || null;
  const norm = (s) => String(s || '').replace(/\s+/g, ' ').trim();

  async function waitFor(fn, timeoutMs, everyMs = 250) {
    const end = Date.now() + timeoutMs;
    for (;;) {
      const v = fn();
      if (v) return v;
      if (Date.now() > end) return null;
      await sleep(everyMs);
    }
  }

  const composer = () => firstVisible(S.COMPOSER);
  const composerText = (el) => (el.tagName === 'TEXTAREA' ? el.value : el.innerText);
  /** Matches of sel that aren't inside another match. */
  const outermost = (sel, root = document) => {
    const all = [...root.querySelectorAll(sel)];
    return all.filter((n) => !all.some((o) => o !== n && o.contains(n)));
  };
  const userTurns = () => outermost(S.USER_MESSAGE).length;

  function authStatus() {
    try {
      return JSON.parse(document.querySelector(S.BOOTSTRAP_JSON)?.textContent || '{}').authStatus || '';
    } catch {
      return '';
    }
  }

  /** Assistant messages after the last user message: the reply to our prompt. */
  function lastReply() {
    const users = outermost(S.USER_MESSAGE);
    const lastUser = users[users.length - 1];
    const nodes = outermost(S.ASSISTANT_MESSAGE).filter((n) => {
      if (!lastUser) return true;
      const pos = lastUser.compareDocumentPosition(n);
      return pos & Node.DOCUMENT_POSITION_FOLLOWING && !(pos & Node.DOCUMENT_POSITION_CONTAINED_BY);
    });
    // No rendered answer yet (still searching or thinking) = no text.
    const parts = nodes.flatMap((n) => (n.matches(S.REPLY_TEXT) ? [n] : outermost(S.REPLY_TEXT, n)));
    const text = parts.map((n) => n.innerText.trim()).filter(Boolean).join('\n\n');
    const emails = parts.flatMap((n) => [...n.querySelectorAll('a[href^="mailto:" i]')].map((a) => {
      try { return decodeURIComponent(a.getAttribute('href').slice(7).split('?')[0]); } catch { return ''; }
    })).filter(Boolean);
    return { text, emails, assistantCount: nodes.length };
  }

  function snapshot() {
    const reply = lastReply();
    const errorText = [...document.querySelectorAll(S.ERROR_TEXT)]
      .filter(visible).map((e) => norm(e.innerText)).filter(Boolean).join(' | ').slice(0, 300);
    const auth = authStatus();
    return {
      ok: true,
      url: location.href,
      pageStart: performance.timeOrigin, // when this page load started (ms since epoch)
      composer: !!composer(),
      loggedOut: auth === 'logged_out' || (!auth && !!firstVisible(S.LOGIN_BUTTON)),
      challenge: P.CHALLENGE_TITLE.test(document.title) || !!document.querySelector(S.CHALLENGE),
      generating: !!firstVisible(S.STOP_BUTTON),
      userTurns: userTurns(),
      replyText: reply.text,
      replyEmails: reply.emails,
      assistantCount: reply.assistantCount,
      errorText,
      limitHit: P.LIMIT.test(errorText) || P.LIMIT.test(reply.text),
    };
  }

  // Always look the box up again: ChatGPT may re-render it while the page is starting up.
  const boxText = () => { const el = composer(); return el ? norm(composerText(el)) : ''; };

  /** True when the box holds (nearly) all of the prompt. */
  function looksFilled(text) {
    const have = boxText();
    const want = norm(text);
    return have.length >= want.length * 0.9 && have.includes(want.slice(0, 40));
  }

  function clearBox(el) {
    el.focus();
    document.execCommand('selectAll', false);
    document.execCommand('delete', false);
  }

  /** Paste the prompt, falling back to insertText. ChatGPT applies a paste a little later, so poll. */
  async function fillComposer(text) {
    let el = composer();
    if (!el) return false;
    if (el.tagName === 'TEXTAREA') {
      el.focus();
      Object.getOwnPropertyDescriptor(HTMLTextAreaElement.prototype, 'value').set.call(el, text);
      el.dispatchEvent(new Event('input', { bubbles: true }));
      return waitFor(() => looksFilled(text), 3000);
    }
    clearBox(el);
    const data = new DataTransfer();
    data.setData('text/plain', text);
    el.dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    if (await waitFor(() => looksFilled(text), 5000)) return true;

    // Paste ignored: type it instead (only into an empty box, so it can't end up twice).
    el = composer();
    if (!el) return false;
    if (boxText()) clearBox(el); else el.focus();
    document.execCommand('insertText', false, text);
    return waitFor(() => looksFilled(text), 5000);
  }

  function sendButton() {
    const b = firstVisible(S.SEND_BUTTON);
    return b && !b.disabled && b.getAttribute('aria-disabled') !== 'true' ? b : null;
  }

  function pressEnter() {
    const el = composer();
    if (!el) return;
    el.focus();
    for (const type of ['keydown', 'keypress', 'keyup']) {
      el.dispatchEvent(new KeyboardEvent(type, { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true, cancelable: true }));
    }
  }

  function clickButton(b) {
    const opts = { bubbles: true, cancelable: true, composed: true, view: window, button: 0 };
    b.dispatchEvent(new PointerEvent('pointerdown', opts));
    b.dispatchEvent(new MouseEvent('mousedown', opts));
    b.dispatchEvent(new PointerEvent('pointerup', opts));
    b.dispatchEvent(new MouseEvent('mouseup', opts));
    b.click();
  }

  async function sendPrompt(text) {
    // The new chat's HTML arrives before ChatGPT's code takes over the box; wait for that.
    if (!(await waitFor(() => document.readyState === 'complete' && composer(), 15000))) {
      return { ok: false, error: 'message box not found' };
    }
    await sleep(1500);
    const before = userTurns();
    const startUrl = location.href;

    if (!(await fillComposer(text))) return { ok: false, error: 'could not paste the prompt into the message box' };
    await sleep(500); // let ChatGPT switch the voice button to the send button

    // Sent = a new user message, the stop button, a /c/<id> URL, or the box emptied.
    const emptied = () => { const el = composer(); return !!el && !norm(composerText(el)); };
    const isSent = () => userTurns() > before || !!firstVisible(S.STOP_BUTTON) || location.href !== startUrl || emptied();
    const tries = [
      ['send button', () => { const b = sendButton(); if (!b) return false; clickButton(b); return true; }],
      ['Enter key', () => { pressEnter(); return true; }],
      ['form submit', () => {
        const form = composer()?.closest('form');
        if (!form) return false;
        const b = sendButton();
        try {
          if (b && b.form === form && b.type === 'submit') form.requestSubmit(b); else form.requestSubmit();
        } catch {
          return false;
        }
        return true;
      }],
    ];
    await waitFor(sendButton, 5000);
    const tried = [];
    for (const [name, attempt] of tries) {
      if (isSent()) break;
      if (!attempt()) continue;
      tried.push(name);
      if (await waitFor(isSent, 4000)) return { ok: true, via: name };
    }
    if (await waitFor(isSent, 3000)) return { ok: true, via: tried[tried.length - 1] || '' };
    return { ok: false, error: `the prompt was not sent (tried: ${tried.join(', ') || 'nothing, no send button'})` };
  }

  let sending = false;
  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    switch (msg?.type) {
      case 'PING':
        sendResponse({ ok: true });
        return false;
      case 'SNAPSHOT':
        sendResponse(snapshot());
        return false;
      case 'SEND_PROMPT':
        if (sending) {
          sendResponse({ ok: false, error: 'already sending a prompt' });
          return false;
        }
        sending = true;
        sendPrompt(String(msg.text || ''))
          .catch((e) => ({ ok: false, error: String(e?.message || e) }))
          .then((r) => { sending = false; sendResponse(r); });
        return true; // async response
      default:
        return false;
    }
  });

  chrome.runtime.sendMessage({ type: 'CONTENT_READY' }).catch(() => {});
})();
