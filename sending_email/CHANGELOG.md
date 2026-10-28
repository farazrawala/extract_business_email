# Changelog

## 1.2.5 – 2026-10-28
- The portal API and the Open portal button now use `https://email.demo-website.cc/`.

## 1.2.4 – 2026-10-10
- **Email found by** has a new choice, **Claude only**: addresses found by the new Claude Email Finder (`find_emails_from_claude/`, portal API 1.26).

## 1.2.3 – 2026-10-10
- New Gmail icon in the toolbar and on the Extensions page, instead of Chrome's default grey letter.

## 1.2.2 – 2026-10-09
- Emails now pitch one thing: getting more customers and sales from Google search with $0 spent on marketing or ads. They no longer mention HTTPS, security or the business's email address or provider (portal change, API 1.25). The hint under **Email writer (Groq)** says so.

## 1.2.1 – 2026-10-09
- Business details in the email are now **bold**, never highlighted in yellow. The **Highlighted (bold on yellow)** option is gone; **Business details in the email** is Bold (the default) or Plain text. A saved Highlighted setting switches to Bold by itself.

## 1.2.0 – 2026-10-08
- Every detail the email takes from the business is now highlighted: the name, city, rating, number of reviews, website, HTTPS, email domain, and each dish, product or service from the highlights and review summary. Groq marks them, and the portal (API 1.17) shows them as asked.
- New setting under **Email writer (Groq)**: **Business details in the email**: Highlighted (bold on yellow, the default), Bold, or Plain text. The subject is never formatted.
- The popup lists the details used in the current email ("Details used: …"), highlighted.

## 1.1.0 – 2026-10-08
- Emails are tracked by Mailtrack again. Mailtrack adds its tracking pixel only when its Send button gets a real mouse click, and ignores clicks made by a script, so since 1.0.1 emails went out through Gmail's own Send button, untracked ("Email not tracked"). The extension now makes a real click on Mailtrack's Send through Chrome's debugger. New permission: `debugger`. While a run is sending, Chrome shows a bar saying "Gmail Business Mailer started debugging this browser". It is attached once per run, and removed when the run ends.
- New setting **Send untracked if Mailtrack's Send doesn't respond** (off by default). Off: when the real click doesn't send the email, the draft is discarded and the error says why. On: it falls back to the earlier ways (script click, Gmail's own Send button, Ctrl+Enter), which send untracked. Without Mailtrack the setting doesn't matter.
- Before clicking, it checks that nothing covers the Send button (like a Mailtrack notice) and names what does.
- The popup shows **Untracked** (sent this run without tracking), and Last 10 marks untracked sends.
- If a slow Compose click opens a second, empty compose window, the extension closes it. A window that was already open, or has anything typed in it, is never touched.

## 1.0.2 – 2026-10-08
- Fixed: when Gmail didn't take a typed address, the extension pressed Enter, which picks Gmail's top autocomplete suggestion (another contact) instead of the typed address. It never presses Enter or Tab in To any more. It types each address key by key, then a comma; if no chip appears, it leaves the field (which keeps the typed text); then it tries pasting the missing addresses. Addresses not taken the first time are typed once more.
- Safety: To must hold exactly the wanted addresses. If Gmail adds any other recipient, the draft is discarded and nothing is sent ("Gmail put a recipient that wasn't asked for in To … Not sent."). To is checked again just before Send. Before, only the wanted addresses were checked, so an extra recipient could have been sent the email too.
- Recipients are read only from Gmail's chips, not from the text in the To area, which could glue two addresses together ("…@gmail.cominfo").
- The "did not accept the recipients" error now also shows any text left typed in To.

## 1.0.1 – 2026-10-08
- Fixed "Gmail did not send it within 30 s" with Mailtrack installed. Mailtrack replaces Gmail's Send button with its own copy, which can ignore the extension's click. When the compose window is still open 8 s after clicking Send, the extension now clicks Gmail's own (hidden) Send button, then tries Ctrl+Enter. Gmail closes the window as soon as it starts sending, so this can't send an email twice. "Send later", "Schedule send" and "More send options" are never clicked.
- When the email still isn't sent, the error lists what was tried and names any popup that is open (like a Mailtrack notice).
- Fixed "Gmail did not accept the recipients (… To shows nothing)" on the first email after Gmail loads. The extension now waits until the To and message fields of the new window are ready, plus 1 s, before typing. If To still shows nothing, it clears the field and types the addresses once more.

## 1.0.0 – 2026-10-08
- Initial release.
- Gets one business at a time that has an email and was never mailed from the Business Portal (`portal/get_business_to_email.php`). Filters: ZIP code, category, and where the address came from (website or ChatGPT).
- Groq writes a subject and message for that business from all its saved details (`portal/generate_email.php`): rating and reviews, website or none, HTTPS, email domain, menu and booking links, hours, highlights, review summary. Settings: tone, length, extra instructions. The signature uses the Gmail account the email is sent from.
- Fills Gmail's compose window (To as recipient chips, Subject, the message with bold and links, above any signature), clicks Send, and counts it as sent when the window closes without an error popup. Records the result with `portal/save_email_result.php`, which marks every row of the same business and every business with the same address as mailed.
- Duration settings: random delay between emails (min/max seconds), run time limit (minutes), max emails per run, max emails per day across runs. The popup shows the expected pace, a live "Next email in…" countdown and the run time.
- **Test one (don't send)** fills one email in Gmail without sending it.
- Live status with the current subject, the last 10 results, and an error list. Pause finishes the current email first. Stop gives a business that wasn't sent back to the portal.
- Stops by itself on a Gmail sending limit, when not signed in, on a Groq key or model problem, after 3 errors in a row, and when the portal can't be reached. A refused address gives up on that business only. A sent email the portal couldn't record is kept and recorded on the next Start.
