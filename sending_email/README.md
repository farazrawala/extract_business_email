# Gmail Business Mailer (Chrome extension, Manifest V3)

Sends the marketing email to businesses that have an email address but were never mailed. For each one it asks the Business Portal for the business, has **Groq** write an email just for that business from everything the portal knows about it, opens **Gmail's compose window**, fills in To, Subject and the message, clicks **Send**, records the send in the portal, then waits the delay you set before the next one.

## Install (Load unpacked)
1. Open `chrome://extensions` and turn on **Developer mode**.
2. Click **Load unpacked** and pick this folder (the one with `manifest.json`).
3. Pin the extension so the popup is easy to reach.

After editing a file, click the reload icon on the extension's card and reload the Gmail tab.

## Before you start
- The portal is `https://email.demo-website.cc/`. The columns it needs are added automatically the first time it's used, or open `setup.php` once.
- In the portal's **Settings**, fill in the **Groq API key** (and model), your **name** (`smtp_from_name`) and **phone** (`sender_phone`). They go in the signature. SMTP isn't needed for this extension; Gmail sends the mail.
- Sign in to Gmail in this browser with the account you want to send from.
- **Keep the Gmail tab visible**, ideally in its own window that isn't minimized. Chrome slows down hidden tabs, and Gmail may not react in time.
- **Mailtrack:** emails are tracked when Mailtrack's own Send button gets a real mouse click, so the extension clicks it through Chrome's debugger. While a run is sending, Chrome shows a bar saying "Gmail Business Mailer started debugging this browser". That's expected; don't press its **Cancel** (if you do, it attaches again for the next email). Keep DevTools closed on the Gmail tab. If Mailtrack still doesn't send, **Send untracked if Mailtrack's Send doesn't respond** decides: off (default) = the draft is discarded and the business is retried later; on = it is sent untracked.
- Gmail adds your Gmail signature (and Mailtrack's, if installed) under the message. The Groq email already ends with a signature, so turn off the Gmail signature for new emails, or you'll have two.

Businesses that were already emailed from the portal (the **✉ Email** button) are skipped: the portal marks them when it first runs, from its email log.

## Use
1. Open Gmail. Open the popup. If the active tab is Gmail, the extension sends from that tab (and that account). Otherwise it opens the **Gmail URL** in a new tab.
2. Settings (saved as soon as you change them):
   - **Gmail URL**: `https://mail.google.com/mail/u/0/#inbox` by default. `/u/1/` is your second signed-in Google account, and so on.
   - **Only ZIP code / Only category**: exact match. Empty means any.
   - **Email found by**: any, website only, ChatGPT only, or Claude only. ChatGPT and Claude addresses can be wrong now and then; **Website only** gives fewer bounces.
   - **Duration**:
     - **Delay min / max (s)**: random wait between two emails. Default 60 to 180 seconds. Short delays look like spam to Gmail.
     - **Run for (min)**: stop the run after this long. `0` = no time limit.
     - **Max per run**: stop after sending this many. `0` = no limit.
     - **Max per day**: emails sent today by this extension, across runs (your computer's date). `0` = no limit. Gmail allows about 500 a day for a normal account and 2,000 for Google Workspace, and new accounts much fewer.
     - The line under them shows the pace: about how many emails an hour, and where the run will stop.
   - **Email writer (Groq)**: tone, length, and extra instructions (for example "offer a free homepage mockup").
3. Click **Test one (don't send)** first. It writes one email and fills it in Gmail **without sending**, then stops. Check it, then discard it. (If you send it yourself, the portal won't know, and the business can be emailed again later.)
4. Click **Start**. Watch the status, the subject of the current email, the countdown to the next email ("Next email in 1m 20s"), the run time, the last 10 results and any errors. The run keeps going after you close the popup.
5. **Pause** finishes the current email and then waits until you click **Resume**. **Stop** stops at once. A business that wasn't sent yet goes back to the portal; an email Gmail was already sending is still recorded.

## How each email is written
The extension calls `portal/generate_email.php`, which sends Groq every saved detail of the business: name, category, Google rating and review count, price, address and city, phone, website (or that it has none), whether the site uses HTTPS, whether its email is on its own domain or on Gmail/Yahoo, menu and booking links, whether the Google profile is claimed, hours, service options, highlights, accessibility and the review summary. Groq picks the one or two facts that matter most for a website pitch and writes the subject and message for that business. It is told never to invent facts. Your **Settings** email is given as a reference for what you offer, not copied.

Every detail Groq takes from the business (name, city, rating, number of reviews, website, HTTPS, email domain, dishes and services) is marked, and **Business details in the email** decides how it shows: **Highlighted** (bold on a yellow background, the default), **Bold**, or **Plain text**. The popup lists them under the subject ("Details used: …"). The subject itself is never formatted.

The signature is filled in from Settings, with the **Gmail account you send from** as the email address. Bold text (`**…**`) is pasted as bold, and links are clickable.

## What is recorded
Each business gets one result in the portal (`portal/save_email_result.php`, see `portal/API.md`):

| Result | What happens |
|---|---|
| **sent** | A row in `email_log` (`channel = gmail`, the Gmail account in `from_email`). Every row of the same business (other ZIPs) and every business with the same address is marked as mailed and never handed out again. The portal shows the green **Sent** tag. |
| **error** | Groq failed, Gmail didn't send, and so on. The business comes back after its lease (15 minutes) and is dropped after 3 failed attempts. |
| **gave up** | Gmail refused the address, or no valid address is saved. Not handed out again. |

A sent email that the portal couldn't record (portal down) is kept in the extension and recorded on the next **Start**, before anything else is sent. The popup shows it as **Not recorded**.

## When the run stops by itself
- The duration or count limits above are reached.
- No business is left to email (with your filters).
- Gmail shows a sending-limit message. The business is given back untouched.
- Not signed in to Gmail, or Gmail didn't load within 60 seconds.
- Groq has no API key, refuses it, or doesn't know the model (fix it in the portal's Settings).
- 3 businesses in a row failed (a refused address doesn't count toward this).
- The portal can't be reached, or can't record a sent email.
- The Gmail tab was closed, the browser restarted, or the extension was reloaded. If that happens while Gmail is sending, the business is marked as given up (it may have been sent), so check Gmail's **Sent** folder.

## Configuration in code
`background.js`, top of file:
- `PORTAL_URL`, `PORTAL_API_KEY`: the portal (`https://email.demo-website.cc/`) and its key (`API_KEY` in `portal/config.php`, `''` when that is empty). The popup's **Open portal** button uses `PORTAL_PAGE` in `popup.js`.
- `LEASE_MINUTES`: how long the portal holds a business for this browser.
- `PAGE_LOAD_TIMEOUT_MS`, `WRITE_TIMEOUT_MS`, `FAIL_DELAY_MS`, `MAX_OPEN_ATTEMPTS`, `MAX_CONSECUTIVE_ERRORS`.
- `DEFAULT_SETTINGS`.

## How it works
- `background.js` keeps the run state in `chrome.storage.local` and moves through phases once a second: `opening` (Gmail ready?) → `fetching` → `writing` (Groq) → `sending` (Gmail) → `saving` → `waiting` → `fetching` … If Chrome stops the service worker, an alarm wakes it within 30 seconds and it carries on from the saved phase.
- `content.js` only works on the Gmail page: it reports what the page shows (`SNAPSHOT`: ready, account, sending-limit notice) and composes and sends one email (`COMPOSE`). It clicks **Compose**, waits until the new window's fields are ready, types each address into To key by key followed by a comma (never Enter or Tab, which would pick Gmail's top suggestion, another contact), leaves the field or pastes if no chip appears, and checks that To holds exactly those addresses (any other recipient: the draft is discarded, nothing is sent), types the subject, pastes the message above any signature, and clicks **Send**. It asks `background.js` for a real mouse click on **Send** (`TRUSTED_CLICK`; the background attaches Chrome's debugger to the tab once per run and sends `Input.dispatchMouseEvent`). Only that click makes Mailtrack track the email. If the window is still open 12 s later and untracked sending is allowed (or Mailtrack isn't installed), it tries a script click, Gmail's own hidden Send button, then Ctrl+Enter. Gmail closes the window as soon as it starts sending, so this never sends twice.
- An email counts as sent when its compose window closes without an error popup. Then it watches 5 more seconds for a late error, like a sending limit. A "Message sent" notice alone isn't trusted, because it can still be showing from the previous email.

## Updating selectors when Gmail changes its markup
Every selector and text pattern is in **`selectors.js`** (`GMAIL_SELECTORS`, `GMAIL_PATTERNS`). `samp.html` is a saved Gmail inbox with a **New Message** window open (with Mailtrack installed), and the selectors were checked against it. It has no stylesheets, so it can't show what is visible on screen.

| Symptom | Key to check |
|---|---|
| "Gmail loaded but its Compose button never showed" | `COMPOSE_BUTTON` |
| "the compose window did not open" | `COMPOSE_BUTTON`, `SUBJECT` |
| "Gmail did not accept the recipients" | `TO_INPUT`, `TO_AREA`, `TO_CHIP` (each chip must carry the address in `data-hovercard-id`, `email` or `data-email`) |
| "Gmail put a recipient that wasn't asked for in To" | Nothing was sent. Gmail's autocomplete added a contact; check `fillTo()` in `content.js` |
| "could not paste the message" | `BODY` |
| "real click failed: Chrome would not allow the click" | DevTools or another debugger is open on the Gmail tab: close it. |
| "something covers the Send button" | A popup (often a Mailtrack notice) is over the button: close it. |
| "Gmail did not send it (tried: …)" | `SEND_BUTTON`, `SEND_ANY`. Mailtrack hides Gmail's own Send button at 0×0 and shows a copy. The copy is clicked first; if the window is still open 8 s later, Gmail's own button, then Ctrl+Enter. A popup named in the error (`MODAL`) may be in the way: close it. |
| Gmail account shows as `–` | `ACCOUNT` |
| An error popup isn't recognized | `ALERT_DIALOG`, `GMAIL_PATTERNS.LIMIT`, `GMAIL_PATTERNS.BAD_ADDRESS` |

Then bump the version (`node bump-version.js patch`), write the change in `CHANGELOG.md`, and reload the extension.
