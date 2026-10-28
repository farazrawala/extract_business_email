# Changelog

## 1.1.7 – 2026-10-28
- The portal API and the Open portal button now use `https://email.demo-website.cc/`.

## 1.1.6 – 2026-10-10
- New ChatGPT icon in the toolbar and on the Extensions page, instead of Chrome's default grey letter.

## 1.1.5 – 2026-10-09
- Fixed finished replies not being read ("No complete reply after 40s … no reply text found on the page: 1 user / 1 reply messages") when ChatGPT answers with its new renderer (checked against `sample2.html`). That answer sits in `div.puik-root[data-dil-widget-copy-target]` with no `.markdown`, so it is now read as the reply text too.

## 1.1.4 – 2026-10-08
- Fixed for ChatGPT's new page markup (checked against `sample_response.html`): messages are now found by `data-content-search-unit-key="…:user"` / `"…:assistant"`, and the Send button by `button[type="submit"][aria-label="Send"]`. The stop button also matches any composer button whose label starts with "Stop".
- Only the rendered answer (`data-markdown-text-style="assistant-message"`) is read as the reply, not the hidden "ChatGPT said:" heading or the "Searching the web" status, so `NONE` replies are still recognised.

## 1.1.3 – 2026-10-08
- Fixed finished replies not being seen ("No complete reply after 40s" while the answer was already on the page). ChatGPT changed its markup, so the reply is now also found by the turn's `data-turn="assistant"` attribute or its rendered `.markdown` answer, not only by `data-message-author-role`. A message matched more than one way counts once.
- The "No reply" and "No complete reply" errors now say how many user and reply messages were on the page and whether the stop button showed, so a markup change is easy to spot.

## 1.1.2 – 2026-10-08
- When the reply timeout runs out, a reply that has an email (or says `NONE`) and hasn't changed for 3 s is now saved instead of counted as an error. Before, the answer was thrown away if ChatGPT still showed the stop button.
- The "No complete reply" error now says what the extension saw: no reply text on the page, or the first words ChatGPT was still writing.
- Businesses are now handed out in random order by the portal (API 1.15), so a business that just failed isn't handed straight back.

## 1.1.1 – 2026-10-03
- Email addresses in a reply can no longer contain `%`, so URL-encoded junk (like `04%7c01%7c` in front of an address) isn't taken as part of one. The portal also cleans every address it receives.

## 1.1.0 – 2026-10-03
- Added a live countdown to the popup while waiting between businesses: "Next business in 20s", 19s, 18s… It counts from the time stored in the run, so it's right after you close and reopen the popup. It hides while paused.
- The status message no longer includes a fixed "Next in Ns", because the countdown replaces it.

## 1.0.1 – 2026-10-03
- Fixed "could not paste the prompt into the message box". The prompt was being pasted, but the check ran only 0.4 s later, before ChatGPT had shown the text, so the run gave up and never pressed Send. It now waits up to 5 s for the text, and only types it in another way if the box is still empty, so the prompt can't end up in the box twice.
- Waits for the new chat page to finish loading, plus 1.5 s, before pasting.
- Sending tries the send button first, then Enter, then submitting the form, and goes to the next way only if nothing was sent. A prompt counts as sent when a new message, the stop button or a `/c/…` URL appears, or the box empties. The error now lists what was tried.

## 1.0.0 – 2026-10-03
- Initial release.
- Gets one business without an email at a time from the Business Portal (`portal/get_business_without_email.php`), opens a new ChatGPT chat, pastes a prompt with the business's details and sends it.
- Waits until the reply is finished (no stop button, and the text hasn't changed for 3 seconds), takes the email addresses out of it (text and `mailto:` links; a reply that starts with `NONE` has none) and saves the result with `portal/save_gpt_email.php`. Then it waits a random delay and starts again with the next business in a new chat.
- Popup settings: new chat URL (a model or custom GPT link works), temporary chat, ZIP code and category filters, min/max delay, reply timeout, max businesses per run, and an editable prompt with placeholders.
- Live status, the last 10 results, and an error list. Pause finishes the current business first. Stop gives the current business back to the portal.
- Stops by itself on a "verify you are human" check, when ChatGPT is logged out with no message box, on a ChatGPT usage limit, and after 3 errors in a row. A failed send is retried once in a new chat.
