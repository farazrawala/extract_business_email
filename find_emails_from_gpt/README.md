# ChatGPT Email Finder (Chrome extension, Manifest V3)

Finds emails for businesses the Maps scraper (`find_business/`) saved without one. It asks the Business Portal for one business with no email, opens a **new ChatGPT chat**, pastes the business details, waits for the full reply, takes the email out of it, saves it to the portal, and moves on to the next business in a fresh chat.

## Install (Load unpacked)
1. Open `chrome://extensions` and turn on **Developer mode**.
2. Click **Load unpacked** and pick this folder (the one with `manifest.json`).
3. Pin the extension so the popup is easy to reach.

After editing a file, click the reload icon on the extension's card and reload the ChatGPT tab.

## Before you start
- The portal must be running (`http://localhost/business_extractor/portal/`). The new columns it needs are added automatically the first time it's used, or open `portal/setup.php` once.
- Log in to ChatGPT in this browser. Use a model with web search, or most answers will be NONE.
- **Keep the ChatGPT tab visible**, ideally in its own window that isn't minimized. Chrome slows down hidden tabs, and ChatGPT may not draw the reply until the tab is shown again.

## Use
1. Open the popup. If the active tab is ChatGPT, the extension uses it. Otherwise it opens a new ChatGPT tab.
2. Settings (saved as soon as you change them):
   - **New chat URL**: `https://chatgpt.com/` by default. A model link (`https://chatgpt.com/?model=…`) or a custom GPT link (`https://chatgpt.com/g/…`) also works. Only chatgpt.com and chat.openai.com links are accepted.
   - **Temporary chat**: adds `?temporary-chat=true`, so the chats don't fill up your history.
   - **Only ZIP code / Only category**: exact match. Empty means any.
   - **Min/Max delay**: random wait, in seconds, between businesses.
   - **Reply timeout**: give up on a business if the reply isn't finished by then (30–900 s, default 180).
   - **Max per run**: stop after this many businesses. `0` keeps going until the portal has none left.
   - **Prompt**: what gets pasted. See below.
3. Click **Start**. Watch the status, the countdown to the next business ("Next business in 20s"), the last 10 results and any errors. The run keeps going after you close the popup.
4. **Pause** finishes the current business and then waits until you click **Resume**. **Stop** stops at once and gives the current business back to the portal.

## The prompt
Placeholders: `{name}`, `{category}`, `{address}` (built from street, city, state and postal code when the full address is missing), `{street}`, `{city}`, `{state}`, `{postal_code}`, `{country}`, `{zipcode}`, `{phone}`, `{website}`, `{google_maps_url}`.
A line whose placeholders are all empty is left out, so `Phone: {phone}` disappears for a business with no phone. **Reset to default** restores the built-in prompt.

Keep these two rules in your prompt, because the reply is read by code:
- Ask for the email address only.
- Ask for exactly `NONE` when nothing is found. A reply that starts with `NONE` counts as no email, even if it mentions an address later.

Every address in the reply (text and `mailto:` links) is kept, up to 5, in order. Placeholder addresses like `user@example.com` are dropped.

## What is saved
Each business gets one of three results in the portal (`portal/save_gpt_email.php`, see `portal/API.md`):

| Result | What happens |
|---|---|
| **found** | `email` and `emails` are filled in and `email_source` becomes `chatgpt`. An email that is already saved is never replaced. The portal shows a **ChatGPT** tag next to it. |
| **none** | No email. The business is marked as checked and isn't handed out again. |
| **error** | Timeout, page didn't load, prompt couldn't be sent, and so on. The business comes back after its lease (20 minutes) and is dropped after 3 failed attempts. |

ChatGPT's whole reply is kept in `gpt_answer`. ChatGPT can still make up an address now and then, so check ChatGPT emails before you send marketing mail to them.

## When the run stops by itself
- ChatGPT shows a "verify you are human" check: solve it in the tab, then press Start.
- You're logged out and there's no message box.
- A usage limit message appears instead of a reply. The business is given back untouched.
- 3 businesses in a row failed.
- The portal can't be reached, or has no businesses without an email left (with your filters).
- The ChatGPT tab was closed, the browser restarted, or the extension was reloaded.

## Configuration in code
`background.js`, top of file:
- `PORTAL_URL`, `PORTAL_API_KEY`: the portal and its key (`API_KEY` in `portal/config.php`, `''` when that is empty). On another machine, change `localhost` to the server's IP or domain and add that host to `host_permissions` in `manifest.json`. The popup's **Open portal** button uses `PORTAL_PAGE` in `popup.js`.
- `LEASE_MINUTES`: how long the portal holds a business for this browser. Keep it longer than the reply timeout.
- `REPLY_STABLE_MS`, `REPLY_START_TIMEOUT_MS`, `PAGE_LOAD_TIMEOUT_MS`, `MAX_SEND_ATTEMPTS`, `MAX_CONSECUTIVE_ERRORS`.
- `DEFAULT_PROMPT`.

## How it works
- `background.js` keeps the run state in `chrome.storage.local` and moves through phases once a second: `fetching` → `opening` (new chat loading) → `sending` → `replying` → `saving` → `waiting` → `fetching` … If Chrome stops the service worker, an alarm wakes it within 30 seconds and it carries on from the saved phase.
- `content.js` only works on the page: it reports what the page shows (`SNAPSHOT`) and pastes and sends the prompt (`SEND_PROMPT`). The background polls it while waiting, because Chrome slows down timers in background tabs but not extension messages.
- A reply is finished when the stop button is gone and the text hasn't changed for 3 seconds.

## Updating selectors when ChatGPT changes its markup
Every selector and text pattern is in **`selectors.js`** (`GPT_SELECTORS`, `GPT_PATTERNS`). `sample_gpt.html` is a saved empty new chat. The message box, the "Start Voice" button and the login JSON were checked against it. Message and stop-button selectors aren't in that sample, so save a page with a conversation to check those.

| Symptom | Key to check |
|---|---|
| "ChatGPT page loaded but the message box never showed" | `COMPOSER` |
| "could not paste the prompt" | `COMPOSER`, and `fillComposer()` in `content.js` (it waits up to 5 s for the pasted text to show) |
| "the prompt was not sent (tried: …)" | `SEND_BUTTON` (it must never match the "Start Voice" button). Sending tries the button, then Enter, then submitting the form. |
| Reply cut off, or every business times out | `STOP_BUTTON`, `ASSISTANT_MESSAGE` |
| Every reply is empty | `ASSISTANT_MESSAGE`, `USER_MESSAGE` |
| Logged-in check is wrong | `BOOTSTRAP_JSON`, `LOGIN_BUTTON` |

Then bump the version (`node bump-version.js patch`), write the change in `CHANGELOG.md`, and reload the extension.
