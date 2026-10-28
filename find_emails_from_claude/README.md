# Claude Email Finder (Chrome extension, Manifest V3)

Second try at finding emails, after ChatGPT. It only looks at businesses that still have **no email after both** the Maps scrape (`find_business/`) **and** the ChatGPT search (`find_emails_from_gpt/`). That means ChatGPT answered NONE, or gave up after 3 errors. It asks the Business Portal for one of them, opens a **new Claude chat** on claude.ai, pastes the business details, waits for the full reply, takes the email out of it, saves it to the portal, and moves on to the next business in a fresh chat.

Works the same way as the ChatGPT Email Finder, with its own queue in the portal (`claude_*` columns), so both can run at the same time.

## Install (Load unpacked)
1. Open `chrome://extensions` and turn on **Developer mode**.
2. Click **Load unpacked** and pick this folder (the one with `manifest.json`).
3. Pin the extension so the popup is easy to reach.

After editing a file, click the reload icon on the extension's card and reload the Claude tab.

## Before you start
- The portal is `https://email.demo-website.cc/`. The new columns it needs are added automatically the first time it's used, or open `setup.php` once.
- Log in to claude.ai in this browser.
- **Turn on Web search** in Claude (the tools button under the message box). Without it Claude can't look anything up. When Claude answers that it can't search the web, the run stops and tells you, so those businesses aren't marked "none".
- **Keep the Claude tab visible**, ideally in its own window that isn't minimized. Chrome slows down hidden tabs.

## Use
1. Open the popup. If the active tab is claude.ai, the extension uses it. Otherwise it opens a new Claude tab.
2. Settings (saved as soon as you change them):
   - **New chat URL**: `https://claude.ai/new` by default. A project link (`https://claude.ai/project/…`) also works. Only claude.ai links are accepted.
   - **Also save emails named in the reply when the answer is NONE** (on by default): see **mentioned** below.
   - **Only ZIP code / Only category**: exact match. Empty means any.
   - **Min/Max delay**: random wait, in seconds, between businesses.
   - **Reply timeout**: give up on a business if the reply isn't finished by then (30–900 s, default 240; web searches take a while).
   - **Max per run**: stop after this many businesses. `0` keeps going until the portal has none left.
   - **Prompt**: what gets pasted. See below.
3. Click **Start**. Watch the status, the countdown to the next business, the last 10 results and any errors. The run keeps going after you close the popup.
4. **Pause** finishes the current business and then waits until you click **Resume**. **Stop** stops at once and gives the current business back to the portal.

## The prompt
Placeholders: `{name}`, `{category}`, `{address}` (built from street, city, state and postal code when the full address is missing), `{street}`, `{city}`, `{state}`, `{postal_code}`, `{country}`, `{zipcode}`, `{phone}`, `{website}`, `{google_maps_url}`.
A line whose placeholders are all empty is left out. **Reset to default** restores the built-in prompt.

Claude often writes a few lines ("Let me search…") between web searches, so only the **last block of text** in the reply is read as the answer. Keep these rules in your prompt:
- Ask Claude to **end** its reply with the email address only.
- Ask for exactly `NONE` when nothing is found. An answer that starts with `NONE`, or ends with `NONE` in capitals, counts as no email.

Every address in the answer (text and `mailto:` links) is kept, up to 5, in order. Placeholder addresses like `user@example.com` are dropped.

## When is a reply finished?
Claude is done when it isn't writing (no stop button) and the text hasn't changed for 3 seconds. If the last block has no email and no `NONE`, and Claude's Copy/Retry buttons aren't showing, it waits 20 seconds instead, because Claude may be in the middle of a slow web search.

## What is saved
Each business gets one of three results in the portal (`portal/save_claude_email.php`, see `portal/API.md`):

| Result | What happens |
|---|---|
| **found** | `email` and `emails` are filled in and `email_source` becomes `claude`. An email that is already saved is never replaced. The portal shows a **Claude** tag next to it. |
| **mentioned** | Claude's answer was `NONE`, but its reply named one or more addresses (for example one it found for a related restaurant and decided not to give). They are saved like **found**, but the result is `mentioned` so you can tell them apart. Last 10 shows "(in reply)". These are less sure than **found**: check them before mailing. Off when the setting above is off. |
| **none** | No email. The business is marked as checked by Claude and isn't handed out again. |
| **error** | Timeout, page didn't load, prompt couldn't be sent, and so on. The business comes back after its lease (20 minutes) and is dropped after 3 failed attempts. |

Claude's whole reply is kept in `claude_answer`. Claude can be wrong now and then, so check Claude emails before you send marketing mail to them. The Gmail mailer's **Email found by** setting can leave them out.

## When the run stops by itself
- Claude shows a "verify you are human" check: solve it in the tab, then press Start.
- You're logged out (claude.ai sends you to the login page).
- A usage limit message appears instead of a reply ("You are out of free messages until …"). The business is given back untouched.
- Claude says it can't search the web. Turn on Web search, then press Start.
- 3 businesses in a row failed.
- The portal can't be reached, or has no businesses left (with your filters).
- The Claude tab was closed, the browser restarted, or the extension was reloaded.

## Configuration in code
`background.js`, top of file:
- `PORTAL_URL`, `PORTAL_API_KEY`: the portal (`https://email.demo-website.cc/`) and its key (`API_KEY` in `portal/config.php`, `''` when that is empty). The popup's **Open portal** button uses `PORTAL_PAGE` in `popup.js`.
- `LEASE_MINUTES`: how long the portal holds a business for this browser. Keep it longer than the reply timeout.
- `REPLY_STABLE_MS`, `REPLY_UNCLEAR_STABLE_MS`, `REPLY_START_TIMEOUT_MS`, `PAGE_LOAD_TIMEOUT_MS`, `MAX_SEND_ATTEMPTS`, `MAX_CONSECUTIVE_ERRORS`.
- `DEFAULT_PROMPT`.

## How it works
- `background.js` keeps the run state in `chrome.storage.local` and moves through phases once a second: `fetching` → `opening` (new chat loading) → `sending` → `replying` → `saving` → `waiting` → `fetching` … If Chrome stops the service worker, an alarm wakes it within 30 seconds and it carries on from the saved phase.
- `content.js` only works on the page: it reports what the page shows (`SNAPSHOT`) and pastes and sends the prompt (`SEND_PROMPT`). The background polls it while waiting, because Chrome slows down timers in background tabs but not extension messages.
- Businesses come from `portal/get_business_for_claude.php`: no `email`, `gpt_checked_at` set (ChatGPT is done with it), `claude_checked_at` empty.

## Updating selectors when Claude changes its markup
Every selector and text pattern is in **`selectors.js`** (`CLAUDE_SELECTORS`, `CLAUDE_PATTERNS`). `sample.html` is a saved claude.ai page with only Claude's welcome message. The message box, the Send button, the transcript phase and the sidebar status dot were checked against it. Messages, the stop button and the Copy/Retry buttons aren't in it, so save a page with a finished reply (and one while Claude is writing) to check those.

| Symptom | Key to check |
|---|---|
| "Claude page loaded but the message box never showed" | `COMPOSER` |
| "could not paste the prompt" | `COMPOSER`, and `fillComposer()` in `content.js` (it waits up to 5 s for the pasted text to show) |
| "the prompt was not sent (tried: …)" | `SEND_BUTTON`. Sending tries the button, then Enter. |
| Reply read too early (only "Let me search…" saved) | `STOP_BUTTON`, `STREAMING`, `TRANSCRIPT_PHASE`, `PATTERNS.BUSY` |
| Every business waits 20 s after the reply, or times out | `REPLY_DONE`, `STOP_BUTTON` |
| Every reply is empty | `ASSISTANT_MESSAGE`, `USER_MESSAGE`, `REPLY_TEXT` |
| Logged-in check is wrong | `LOGIN`, `PATTERNS.LOGIN_PATH` |

Then bump the version (`node bump-version.js patch`), write the change in `CHANGELOG.md`, and reload the extension.
