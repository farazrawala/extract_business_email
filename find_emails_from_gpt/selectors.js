/*
 * selectors.js — every ChatGPT selector and text pattern the content script uses.
 * When ChatGPT changes its markup, fix it here (see README.md "Updating selectors").
 * Checked against sample_gpt.html (an empty new chat), sample_response.html (a finished
 * reply, 2026-10-08) and sample2.html (a finished reply from the new renderer, 2026-10-09). The stop button is in neither; save a page while ChatGPT is writing to check it.
 */
const GPT_SELECTORS = {
  // ProseMirror editor. A hidden <textarea name="prompt-textarea"> fallback sits next to it;
  // the content script only uses a visible match.
  COMPOSER: '#prompt-textarea[contenteditable="true"], div.ProseMirror[contenteditable="true"], textarea[name="prompt-textarea"]',
  // Shown once the composer has text. With an empty composer the same slot holds "Start Voice"
  // (.composer-submit-button-color), which must never be clicked.
  // Since 2026-10 (sample_response.html) it is a plain button[type=submit][aria-label="Send"].
  SEND_BUTTON: 'button[data-testid="send-button"], button#composer-submit-button[aria-label*="send" i], form button[type="submit"][aria-label="Send"]',
  // Present while a reply is being written (including web search and "thinking").
  STOP_BUTTON: 'button[data-testid="stop-button"], button[aria-label="Stop streaming"], button[aria-label="Stop generating"], form button[aria-label^="Stop" i]',
  // Old and new markup. Since 2026-10 a message is <div data-content-search-unit-key="…:user">
  // or "…:assistant"; before it was data-message-author-role / data-turn. The content script
  // keeps only the outermost match, so a message matched twice counts once.
  USER_MESSAGE: '[data-content-search-unit-key$=":user"], [data-message-author-role="user"], [data-turn="user"]',
  ASSISTANT_MESSAGE: '[data-content-search-unit-key$=":assistant"], [data-message-author-role="assistant"], [data-turn="assistant"]',
  // The rendered answer inside an assistant message. Only this text is read, so the hidden
  // "ChatGPT said:" heading and "Searching the web" status aren't taken as the reply.
  // Since 2026-10-09 (sample2.html) answers can come from the new "DIL" renderer instead:
  // <div data-dil-widget-copy-target class="puik-root not-prose not-markdown"> with no .markdown.
  REPLY_TEXT: '[data-markdown-text-style="assistant-message"], .markdown, [data-dil-widget-copy-target], .puik-root',
  // JSON with "authStatus": "logged_in" | "logged_out".
  BOOTSTRAP_JSON: 'script#client-bootstrap',
  LOGIN_BUTTON: 'button[data-testid="login-button"], a[href*="/auth/login"]',
  // Visible error text in the thread. Not [role="alert"]: that is an always-present sr-only region.
  ERROR_TEXT: '.text-token-text-error, .text-red-500, [data-testid*="error" i]',
  CHALLENGE: 'iframe[src*="challenges.cloudflare.com"], #challenge-form, #cf-challenge-running',
};

const GPT_PATTERNS = {
  CHALLENGE_TITLE: /just a moment|attention required|verify you are human/i,
  // Message cap / rate limit. The run stops when one of these shows instead of a reply.
  LIMIT: /you['’]ve (?:hit|reached) (?:your|the|our)[^.]{0,60}limit|usage (?:limit|cap)|too many requests|rate limit/i,
};
