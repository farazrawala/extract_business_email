/*
 * selectors.js — every Gmail selector and text pattern the content script uses.
 * When Gmail changes its markup, fix it here (see README.md "Updating selectors").
 * Checked against samp.html (Gmail inbox with a "New Message" compose window open, Mailtrack installed).
 */
const GMAIL_SELECTORS = {
  // Left-hand "Compose" button (div.T-I.T-I-KE.L3 role=button).
  COMPOSE_BUTTON: 'div.T-I.T-I-KE[role="button"], div[role="button"][gh="cm"]',
  // Inside a compose window. The window itself is found by walking up from the subject box.
  SUBJECT: 'input[name="subjectbox"]',
  TO_INPUT: 'input[aria-label="To recipients"], div[name="to"] input[role="combobox"], textarea[name="to"]',
  TO_AREA: 'div[name="to"], div[aria-label="To"]',
  // Recipient chips once an address is accepted.
  TO_CHIP: '[data-hovercard-id], [email], [data-name][data-email]',
  BODY: 'div[aria-label="Message Body"][contenteditable="true"], div.editable[contenteditable="true"][role="textbox"]',
  // Gmail's Send button. Mailtrack hides the original at 0x0 and shows its own copy; only a
  // button with a real size is clicked. "More send options" doesn't start with "Send".
  SEND_BUTTON: 'div[role="button"][data-tooltip^="Send"], div[role="button"][aria-label^="Send"]',
  // Mailtrack's copy of Send. Only a real mouse click on it adds the tracking pixel.
  MAILTRACK_SEND: '.mt-send, [class*="mt-send"]',
  // Every Send button in the window, including Gmail's own that Mailtrack hid (role=""): clicked
  // directly when Mailtrack's copy ignores the click.
  SEND_ANY: 'div[data-tooltip^="Send"], div[aria-label^="Send"]',
  // Other popups that can sit on top of Gmail (Mailtrack notices and prompts), named in errors.
  MODAL: '[aria-modal="true"], .modal.is-open .modal__container',
  DISCARD_BUTTON: 'div[role="button"][data-tooltip^="Discard draft"], div[role="button"][aria-label^="Discard draft"]',
  // Error popups after Send ("The address … was not recognized", sending limit, …).
  ALERT_DIALOG: 'div[role="alertdialog"]',
  ALERT_OK: 'button[name="ok"], button',
  // Bottom-left notice: "Sending…", "Message sent", "Undo", "View message".
  TOAST: 'div.b8.UC[role="alert"] .vh, div[role="alert"] .vh, .bAq',
  // aria-label "Google Account: john david (johndavid78663@gmail.com)".
  ACCOUNT: 'a[aria-label^="Google Account:"], a[aria-label*="@"][href*="SignOutOptions"]',
};

const GMAIL_PATTERNS = {
  TOAST_SENT: /message sent|sent\.|mensaje enviado|message envoyé|nachricht gesendet/i,
  TOAST_SENDING: /sending/i,
  // Gmail's daily sending cap. The run stops when one of these shows.
  LIMIT: /limit for sending|sending limit|daily (?:user )?sending quota|exceeded .*(?:sending|limit)/i,
  // The address was refused: never retry this business.
  BAD_ADDRESS: /not recognized|properly formed|invalid (?:email )?address|address .* (?:is|was) (?:not|in)valid|couldn't be delivered/i,
  ACCOUNT_EMAIL: /[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i,
};
