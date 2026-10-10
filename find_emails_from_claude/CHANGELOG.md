# Changelog

## 1.1.0 – 2026-10-10
- When Claude's answer is NONE but its reply names an email (say, one it found for a related restaurant), that email is now saved too, with the result **mentioned** instead of **found** (portal API 1.27). Before, the business was saved as "none" and the email was lost. Last 10 shows these as "(in reply)".
- New setting **Also save emails named in the reply when the answer is NONE** (on by default). Turn it off to save only emails Claude gives as its answer.

## 1.0.0 – 2026-10-10
- First version, built from the ChatGPT Email Finder 1.1.6. Looks for emails on claude.ai for businesses that still have none after the Maps scrape and the ChatGPT search (portal API 1.26: `get_business_for_claude.php`, `save_claude_email.php`).
- Opens a new chat at `https://claude.ai/new` for each business. Only the last block of Claude's reply is read as the answer, so the "Let me search…" lines between web searches aren't taken for it.
- Stops the run when Claude says it can't search the web (turn on Web search), when a usage limit shows, or when claude.ai asks you to log in.
- Claude icon in the toolbar and on the Extensions page.
