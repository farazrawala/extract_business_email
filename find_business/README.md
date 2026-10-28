# Google Maps Business Scraper (Chrome extension, Manifest V3)

This extension gets a ZIP code from your API and searches Google Maps for `<keyword> in <zipcode>`. It scrolls the results with human-like random pauses, opens each listing one at a time, and scrapes its details. Results stay in the extension's storage until you export them as CSV or JSON.

## Install (Load unpacked)
1. Open `chrome://extensions`.
2. Turn on **Developer mode** (top right).
3. Click **Load unpacked** and pick this folder (the one that contains `manifest.json`).
4. Pin the extension from the puzzle-piece menu so the popup is easy to reach.

After you edit any file, click the reload icon on the extension's card. Then reload any open Google Maps tab.

## Use
1. Open the popup and set:
   - **Search keyword** (default `restaurants`)
   - **Max results / ZIP** (default 100)
   - **Min/Max delay**: the random wait, in seconds, after a listing opens and before its data is read
   - **Auto-loop ZIPs**: when a ZIP is done, fetch the next ZIP from the API and keep going
   - **Include sponsored listings**: off by default, because ads often belong to other areas
   - **Open "About" tab**: collects all attribute sections, but adds a few seconds per listing
2. Click **Start**. If the active tab is Google Maps, the extension uses it. Otherwise it opens a new Maps tab. Leave that tab open. It doesn't need focus, but Chrome slows down background tabs, so a visible tab runs faster.
3. Watch the live status: phase, ZIP, *Scraped X/Y*, errors, and the last 10 businesses scraped.
4. **Pause/Resume** and **Stop** work at any time. The run keeps going after you close the popup.
5. Optional: open **Export fields** and uncheck any columns you don't want. The selection is saved, and every field is still scraped, so you can change it and export again later. `extension_version` is always included.
6. Click **Export CSV** or **Export JSON**. The file names include the extension version, and every record has an `extension_version` field. **Clear data** deletes the stored records.

The run survives a tab reload: the content script reconnects and skips listings already saved for the current ZIP. A browser restart stops the run.

If Google shows a consent page or a CAPTCHA ("unusual traffic"), the run stops with a message. Deal with it in the tab, then press Start again.

## Output fields
`name, category, rating, review_count, price_level, price_label, price_per_person, address, street, city, state, postal_code, country, phone, phone_e164, email, emails, website, plus_code, located_in, hours_status, hours, hours_weekly, business_status, latitude, longitude, google_maps_url, place_id, cid, hex_id, cid_url, service_options, attributes_text, attributes, accessibility, menu_url, reservation_url, actions, image_url, review_summary, rating_breakdown, is_claimed, is_sponsored, zip_id, zipcode, zip_lat, zip_long, search_keyword, search_query, scraped_at, extension_version`

Missing values are `""`. In CSV files, arrays are joined with ` | ` and objects (`hours_weekly`, `attributes`, `rating_breakdown`) are written as JSON. `latitude`/`longitude` come from the place URL (`!3d…!4d…`), not from the map centre. `cid` is the decimal form of the second half of the `0x…:0x…` id in the URL.

`email` and `emails` come from the business's own website, not from Google Maps (see below). `email` is the best match (an address on the site's own domain if there is one) and `emails` lists up to 5.

## Emails
With **Find emails on each business website** on (default), the background worker fetches each listing's `website` after it is scraped: the homepage first, then up to 2 contact/about pages if the homepage has none. It reads `mailto:` links, plain-text addresses, obfuscated ones like `name [at] site [dot] com`, and Cloudflare-protected addresses. Social and ordering sites (Facebook, Instagram, Yelp, DoorDash, Toast, …) are skipped. Each page has an 8-second timeout, so this adds a few seconds per listing. Many restaurants only have a contact form, so expect no email for a good share of them.

This is why `manifest.json` asks for access to all `http://*/*` and `https://*/*` sites. Tune it with the `EMAIL_*` constants at the top of `background.js`.

Duplicates are removed per ZIP only. A business that shows up for two ZIPs is saved once for each, with that ZIP's `zip_id`.

## Settings (SMTP and API keys)
Click **Settings** in the popup header to open the Settings view, and **Back** to return. Under **Email (SMTP)** you can set Host (default `smtp.gmail.com`), Port (default 587), Security, Gmail address, App password, and From name. Below those is a Groq API key field. For Gmail, use an [app password](https://myaccount.google.com/apppasswords), not your normal password.

Values save as soon as you leave a field. They are stored in plain text under `config` in `chrome.storage.local`, so only use this on your own machine:
```json
{ "smtp": { "host": "", "port": 587, "security": "starttls|ssl|none", "user": "", "password": "", "fromName": "" }, "groqApiKey": "" }
```
Clear data doesn't remove them. Nothing in the extension uses them yet.

## Configuration in code
- `background.js`, top of file:
  - `ZIP_API_URL`, `ZIP_UPDATE_URL`: ZIPs come from the portal's `zipcodes` table (`portal/get_zipcode.php` and `portal/update_zipcode.php`, see `portal/API.md`). Each call claims a random pending ZIP; the extension reports it done or failed when it finishes, or released when you stop the run (the portal marks it stopped). A ZIP is handed out only once, so every run and every next ZIP is a new one. `SAVE_API_KEY` is sent with these too.
  - `SAVE_ENDPOINT`, `SAVE_API_KEY`, `ENABLE_SAVE_ENDPOINT`, `SAVE_BATCH_SIZE`: records are POSTed to the Business Portal (`portal/save_business.php`, see `portal/API.md`) as `{ extension_version, records: [...] }`, in batches of `SAVE_BATCH_SIZE` and again at the end of each ZIP and run. The portal updates a business it already has for the same `zip_id`, so retries are safe.
    - Defaults to `https://email.demo-website.cc/save_business.php`.
    - `SAVE_API_KEY` must match `API_KEY` in `portal/config.php` (leave `''` if that is empty). It is sent as `X-API-Key`.
    - If the portal is down or returns 400/401/413/500, the batch stays in `pendingSave` and is retried on the next flush. Records the portal rejects one by one (`errors` in the response, or 422) are logged in the popup's error list and not retried.
  - `MAX_CONSECUTIVE_ZIP_FAILURES`
- `content.js`, `TUNING`: scroll step size, scroll pause range, timeouts, and the "no growth" limit.
- **Auto-loop guard:** if the API returns the same ZIP id that just finished, the run stops so it can't loop forever. The portal's `get_zipcode.php` claims a different ZIP on every call, so this only happens with another API.

## Updating selectors when Google changes its markup
Every selector and text pattern lives in **`selectors.js`** (`MS_SELECTORS` and `MS_PATTERNS`). `content.js` doesn't hard-code any.

1. Open Google Maps, run a search, and open a business. Then open DevTools (F12) and inspect the element that stopped working (address row, rating, results list, and so on).
2. Look for a stable hook, in this order: `data-item-id`, `role`, `aria-label`, `jsaction`, `href` patterns. Avoid short random class names like `DUwDvf`; they change often. A saved copy of a real page (like `sample_google.html`) is useful for checking selectors.
3. Test the new selector in the DevTools console, for example `document.querySelector('button[data-item-id="address"]')`.
4. Update `selectors.js`, bump the version (`node bump-version.js patch`), write the change in `CHANGELOG.md`, and reload the extension.

Common breakages:

| Symptom | Key to check |
|---|---|
| "Results list not found" | `FEED` (`div[role="feed"]`) |
| Scrolling never stops | `MS_PATTERNS.END_OF_LIST` |
| "Details panel did not load in time" | `DETAILS_PANEL`, `PLACE_NAME`, `FEED_LINK` |
| Rating/reviews/price empty | `HEADER_IMG_LABELS`, `MS_PATTERNS.RATING / REVIEW_COUNT / PRICE` |
| Hours empty | `HOURS_TOGGLE`, `HOURS_TABLE`, `HOURS_COPY_BUTTON` |
| Attributes empty | `OVERVIEW_ATTRIBUTE`, `ABOUT_TAB`, `ABOUT_REGION` |

The text patterns assume Google's English UI. Every search URL adds `?hl=en` to force it.

## Versioning
- `manifest.json` holds the version (MAJOR.MINOR.PATCH). The code reads it with `chrome.runtime.getManifest().version`.
- To bump it: `node bump-version.js patch|minor|major`. This updates `manifest.json`, adds a dated header to the top of `CHANGELOG.md`, and prints the new version. Then fill in the bullet points under that header.

## Files
| File | Role |
|---|---|
| `manifest.json` | MV3 manifest |
| `background.js` | Service worker: API calls, run state machine, tab control, storage, optional upload |
| `content.js` | On Maps: waits for elements, scrolls, clicks listings, extracts details |
| `selectors.js` | All DOM selectors and text patterns |
| `popup.html/js/css` | Settings, controls, live status, export |
| `bump-version.js` | Version bump helper |
| `CHANGELOG.md` | Change history |

## Notes
- Scraping Google Maps may break Google's Terms of Service. Keep delays reasonable. Google may rate-limit you or show CAPTCHAs.
- Records are stored in `chrome.storage.local` with the `unlimitedStorage` permission. Export and clear the data now and then on very large runs.
