/*
 * selectors.js — every claude.ai selector and text pattern the content script uses.
 * When Claude changes its markup, fix it here (see README.md "Updating selectors").
 * Checked against sample.html (the "first chat" page with only Claude's welcome message,
 * 2026-10-10): the message box, the Send button, the transcript phase and the sidebar status.
 * Messages, the stop button and the reply's action bar aren't in it; save a page with a finished
 * reply, and one while Claude is writing, to check those.
 */
const CLAUDE_SELECTORS = {
  // Tiptap/ProseMirror editor: <div contenteditable data-testid="chat-input" aria-label="Write your
  // prompt to Claude">. Only the editable one; the boot-time static composer is never typed into.
  COMPOSER: '[data-testid="chat-input"][contenteditable="true"], div.ProseMirror[contenteditable="true"]',
  // Disabled, inside an `inert` send-slot, while the box is empty (the voice button shows instead).
  SEND_BUTTON: 'button[data-testid="chat-input-send"], button[aria-label="Send message" i]',
  // Present while a reply is being written (including web search and thinking).
  STOP_BUTTON: 'button[data-testid*="stop" i], button[aria-label^="Stop" i]',
  // Older markup marks the reply being written with data-is-streaming="true".
  STREAMING: '[data-is-streaming="true"]',
  // <span hidden data-chat-dock-kind="composer" data-chat-transcript-phase="empty">; any value
  // matching PATTERNS.BUSY counts as writing.
  TRANSCRIPT_PHASE: '[data-chat-transcript-phase]',
  // Status dot of the open chat in the sidebar: <span role="img" aria-label="Idle">.
  CHAT_STATUS: '[aria-current="page"] [role="img"][aria-label]',
  // The content script keeps only the outermost match, so a message matched twice counts once.
  USER_MESSAGE: '[data-testid="user-message"], [data-message-author-role="user"], [data-testid="user-turn"], .font-user-message',
  ASSISTANT_MESSAGE: '.font-claude-response, .font-claude-message, [data-message-author-role="assistant"], [data-testid="assistant-message"]',
  // Rendered text inside a reply. Each text block between web searches is its own block; the
  // last one is the answer. Collapsed thinking and the "Searched the web" lists aren't read.
  REPLY_TEXT: '.standard-markdown, .progressive-markdown',
  REPLY_TEXT_FALLBACK: '[data-cds="Prose"], .prose',
  // Copy/Retry buttons shown under a finished reply.
  REPLY_DONE: '[data-testid="action-bar-retry"], [data-testid="action-bar-copy"], button[aria-label="Retry" i]',
  LOGIN: 'a[href^="/login"], button[data-testid="login-button"], input#email[type="email"]',
  // Visible notices: toasts and the banners above the message box (limits, errors).
  ERROR_TEXT: '[data-cds="Toast"], [data-cds="ChatComposerNotices"], [data-composer-banner-layer], [data-testid*="error" i]',
  CHALLENGE: 'iframe[src*="challenges.cloudflare.com"], #challenge-form, #cf-challenge-running',
};

const CLAUDE_PATTERNS = {
  CHALLENGE_TITLE: /just a moment|attention required|verify you are human/i,
  LOGIN_PATH: /^\/(login|signup|logout)\b/,
  BUSY: /stream|respond|generat|working|thinking|running|busy|pending|submitting/i,
  // Message cap / rate limit. The run stops when one of these shows instead of a reply.
  LIMIT: /out of (?:free )?messages|message limit|(?:hit|reached) (?:your|the|our) [^.]{0,40}limit|usage limit|too many requests|rate limit/i,
};
