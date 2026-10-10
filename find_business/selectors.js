/*
 * selectors.js — every DOM selector and text pattern the scraper depends on.
 *
 * When Google changes its markup, fix it HERE (content.js only reads these).
 * Prefer stable hooks: role=, aria-label=, data-item-id=, jsaction=, href patterns.
 * Avoid Google's obfuscated class names (e.g. "DUwDvf", "Io6YTe") — they change often.
 * The few class names below are only used as fallbacks.
 *
 * Text patterns assume English UI; the extension forces ?hl=en on every search URL.
 *
 * Uses assignment to `self.` (not const) so the file can be re-injected safely.
 */
self.MS_SELECTORS = {
  // ---------- Results list (left panel) ----------
  FEED: 'div[role="feed"]',                       // scrollable results list
  FEED_CARD: 'div[role="article"]',               // one result card
  FEED_LINK: 'a[href*="/maps/place/"]',           // card link (aria-label = business name)
  SPONSORED_BADGE: '[aria-label="Sponsored"]',    // "Sponsored" heading inside ad cards

  // ---------- Business details panel ----------
  DETAILS_PANEL: 'div[role="main"][aria-label]',  // aria-label = business name
  PLACE_NAME: 'h1',
  TABLIST: '[role="tablist"]',
  OVERVIEW_TAB: 'button[role="tab"][aria-label^="Overview"]',
  ABOUT_TAB: 'button[role="tab"][aria-label^="About"]',

  // Header (name / rating / price / category)
  HEADER_IMG_LABELS: '[role="img"][aria-label]',  // "4.7 stars", "65 reviews", "$20 to $70"
  RATING_FALLBACK: '.F7nice',                     // obfuscated fallback: "4.7 ★★★★★ (65)"
  CATEGORY: 'button[jsaction*="category"]',       // "Italian restaurant"
  WHEELCHAIR: '[aria-label*="heelchair"]',

  // Info rows
  ADDRESS: 'button[data-item-id="address"]',
  PHONE: 'button[data-item-id^="phone:tel:"]',
  WEBSITE: 'a[data-item-id="authority"]',
  PLUS_CODE: 'button[data-item-id="oloc"]',
  LOCATED_IN: 'button[data-item-id="locatedin"]',
  MENU: 'a[data-item-id="menu"]',
  ACTIONS: '[data-item-id^="action:"]',           // "Find a table", "Place an order"
  RESERVE_LINK: 'a[href*="/reserve/"]',
  CLAIM: '[data-item-id="merchant"]',             // "Claim this business"
  ITEM_TEXT: '.Io6YTe',                           // obfuscated fallback: visible text of an info row
  PRICE_RANGE: '[aria-label*="Price range"]',     // " Price range, $20–70 per person, ..."

  // Opening hours
  HOURS_TOGGLE: '[jsaction*="openhours"][aria-expanded]',  // "Open · Closes 10 PM" dropdown
  HOURS_TABLE: 'table',                                     // weekly table (filtered by day names)
  HOURS_COPY_BUTTON: 'button[jsaction*="openhours"][data-value]', // data-value="Thursday, 8 AM–10 PM"
  HOURS_ARIA_SUMMARY: '[aria-label*="Monday"][aria-label*="Sunday"]',

  // Attributes
  OVERVIEW_ATTRIBUTE: 'div[role="region"][aria-label^="About"] [role="group"][aria-label]',
  ABOUT_REGION: 'div[role="region"][aria-label^="About"]',
  ABOUT_HEADING: 'h2',

  // Extras
  REVIEW_SUMMARY: '[data-report-this-summary-url] button',
  RATING_HISTOGRAM_ROW: 'tr[role="img"][aria-label]',      // "5 stars, 56 reviews"
  HERO_IMAGE: 'button[aria-label^="Photo of"] img',

  // Navigation (narrow single-pane layout)
  BACK_BUTTON: 'button[aria-label="Back"]',
};

self.MS_PATTERNS = {
  END_OF_LIST: /you['’]ve reached the end of the list/i,
  VISITED_SUFFIX: /\s*·\s*Visited link\s*$/i,
  LABEL_PREFIX: /^\s*(Address|Phone|Plus code|Website|Located in|Menu)\s*:\s*/i,
  RATING: /(\d+(?:[.,]\d+)?)\s*stars?/i,
  REVIEW_COUNT: /(\d[\d,.]*)\s*reviews?/i,
  PRICE: /[$€£¥₹]|price|expensive|moderate/i,
  PRICE_PER_PERSON: /([$€£¥₹][^,]*per person)/i,
  DAY_NAME: /^(mon|tue|wed|thu|fri|sat|sun)[a-z]*/i,
  CLOSED_STATUS: /(permanently closed|temporarily closed)/i,
  HISTOGRAM_ROW: /(\d)\s*stars?,\s*([\d,]+)\s*review/i,

  // Place URL data blob: .../data=!4m7!3m6!1s0x..:0x..!8m2!3d40.72!4d-73.97!16s..!19sChIJ...
  URL_LAT: /!3d(-?\d+(?:\.\d+)?)/,
  URL_LNG: /!4d(-?\d+(?:\.\d+)?)/,
  URL_HEX_ID: /!1s(0x[0-9a-f]+:0x[0-9a-f]+)/i,   // 2nd half (hex) = CID
  URL_PLACE_ID: /!19s([^!?&#/]+)/,                // Google place_id (ChIJ...)
};
