# Changelog

## 1.7.3 – 2026-10-28
- ZIP fetch, ZIP update, and business save now go to the live portal at `https://email.demo-website.cc/`.

## 1.7.2 – 2026-10-10
- New icon in the toolbar and on the Extensions page: a red map pin with a shop front, instead of Chrome's default grey letter.

## 1.7.1 – 2026-10-09
- ZIPs are now handed out in random order instead of lowest id first (portal change in `get_zipcode.php`). Each ZIP is still handed out only once. Only comments and the README changed in the extension.

## 1.7.0 – 2026-10-09
- The status bar shows **New leads** (businesses the portal didn't have yet) and **Duplicates** (businesses already in the portal, from this ZIP or another, which are updated instead of added) for this run.
- "Last 10 scraped" tags each business **New** or **Dup** once the portal has saved it.
- Both come from the portal's reply to each save (`save_business.php` now returns `new`, `duplicate` and `results`). If saving is behind, the counts catch up when the records are sent.

## 1.6.4 – 2026-10-09
- Every ZIP is now scraped only once. The portal never hands out a ZIP that was already fetched, so each run and each next ZIP is a new one. Before, a ZIP came back when the run was stopped or its 2-hour claim ran out, and its businesses were scraped again.
- Stopping a run no longer hands the ZIP back: the portal marks it **stopped** (status 4) with the number of businesses saved. To scrape it again, set its status back to 0.
- This is a portal change (`get_zipcode.php`, `update_zipcode.php`); the extension code is the same. Only comments and the README changed.

## 1.6.3 – 2026-10-08
- The popup now shows a countdown next to the ZIP ("Next in 4s", "3s", "2s", "1s") during the Min/Max delay before each listing is scraped. Pausing freezes the countdown, and it picks up where it left off when you resume.

## 1.6.2 – 2026-10-08
- Each business is now sent to the portal as soon as it is scraped (`SAVE_BATCH_SIZE` = 1), instead of in batches of 10. If a save fails, the record stays pending and is sent again along with the next one.

## 1.6.1 – 2026-10-08
- Fixed "Listing link not found in results list" errors. Google sometimes reloads the results list with only the first page of results, for example after going back from a place. When a listing isn't in the list anymore, the scraper now scrolls the list to load more results and keeps looking. It gives up only when the list ends or stops growing.

## 1.6.0 – 2026-10-06
- ZIPs now come from the portal's own `zipcodes` table instead of the remote API: `ZIP_API_URL` is `http://localhost/business_extractor/portal/get_zipcode.php`. Each call claims the next pending ZIP (lowest id first), so ZIPs are worked through in order and never handed out twice.
- When a ZIP finishes, the extension reports it to `ZIP_UPDATE_URL` (`portal/update_zipcode.php`): done (with the number of businesses saved) or failed. Stopping a run hands the current ZIP back so the next run starts with it.
- When no pending ZIPs are left, the run ends with "No pending ZIP codes left." instead of an error.
- ZIP API errors now show the portal's message (for example a database error) instead of only the HTTP status.
- `SAVE_API_KEY` is also sent to the ZIP endpoints.

## 1.5.1 – 2026-10-03
- Fixed emails like `04%7c01%7canniedvorak@bloominbrands.com`. URL-encoded characters on a page (here `|`, from an Outlook "safe link") are now decoded before looking for addresses, and `%` is no longer allowed in an address, so the junk in front is dropped: `anniedvorak@bloominbrands.com`.

## 1.5.0 – 2026-10-03
- Added an **Email** column to "Last 10 scraped". It shows the best address found (in green, with `+N` when there are more; hover to see all of them), or *none found*, *no website*, or `–` when "Find emails" was off.
- Added an **Emails** counter to the status bar: businesses this run that had an email.
- The popup is a little wider (460px) to fit the new column.

## 1.4.0 – 2026-10-02
- Added a **Settings** button in the popup header. It opens a Settings view with an **Email (SMTP)** section: Host, Port, Security (STARTTLS / SSL/TLS / None), Gmail address, App password, From name, and a Groq API key.
- The app password and Groq key fields are masked, with a Show/Hide toggle. Spaces are removed from the app password, since Gmail shows it in groups of four.
- Changing Security also changes Port, unless you set a non-standard port yourself.
- Values save automatically to `config` in `chrome.storage.local`. Clear data leaves them alone. Nothing uses them yet.

## 1.3.0 – 2026-10-02
- 

## 1.2.0 – 2026-10-02
- Upload to the Business Portal is on by default: `SAVE_ENDPOINT` now points at `http://localhost/business_extractor/portal/save_business.php`, and `http://localhost/*` is in `host_permissions`.
- Added `SAVE_API_KEY`, sent as `X-API-Key` when set.
- The upload reads the portal's JSON reply. Records it rejects are logged by name and dropped; network errors and 400/401/413/500 keep the batch pending for a retry.
- Pending records are sent in chunks of at most 500, and the queue is flushed at the end of every ZIP, not only at the end of the run.

## 1.1.0 – 2026-10-02
- Added: "Export fields" panel in the popup with a checkbox for every field, grouped by type, plus group toggles and Select all / Select none. Only the checked fields go into CSV and JSON exports.
- The selection is saved (`excludedFields` in `chrome.storage.local`) and survives Clear data. Fields added in later versions are exported by default.
- Scraping and storage are unchanged: every field is still collected, so you can change the selection and export again.
- `extension_version` is always exported. JSON exports now include a top-level `fields` list.

## 1.0.0 – 2026-10-02
- Initial release.
- Fetches a ZIP from the API in the background service worker, then searches Google Maps for `<keyword> in <zip>` centred on the ZIP's lat/long (`hl=en`).
- Scrolls the results feed in random steps with random pauses. Stops at "end of the list", after 3 scrolls at the bottom with no new results, or at max results.
- Opens each listing one at a time and extracts name, category, rating, review count, price, address (with parsed parts), phone, website, plus code, hours (status and weekly), lat/lng, place_id, CID, service options and attributes (optionally from the About tab), menu and reservation links, image, review summary, and rating breakdown.
- Records are saved to `chrome.storage.local` as the run progresses. Includes CSV/JSON export, Clear data, Pause/Resume/Stop, auto-loop ZIPs, and an optional `SAVE_ENDPOINT` upload.
- Adds `extension_version` to every record and export.
