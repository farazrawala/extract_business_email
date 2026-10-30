# Business Portal API

**API version:** 1.27 · **Last updated:** 2026-10-30 · **Works with extensions:** Maps scraper 1.7.6 (`find_business/manifest.json`), ChatGPT email finder 1.1.7 (`find_emails_from_gpt/manifest.json`), Claude email finder 1.1.1 (`find_emails_from_claude/manifest.json`), Gmail business mailer 1.2.5 (`sending_email/manifest.json`)

Base URL: `https://email.demo-website.cc/`

All responses are JSON (`Content-Type: application/json; charset=utf-8`) and include `"ok": true|false`.
CORS is open (`Access-Control-Allow-Origin: *`) for `GET`, `POST` and `OPTIONS`.

## Authentication

Controlled by `API_KEY` in [config.php](config.php).

- `API_KEY = ''` (current): no key needed.
- When set, every request must send the header `X-API-Key: <value>`, or add `?key=<value>` to the URL.
  If the key is wrong, the server returns `401 {"ok": false, "error": "Invalid API key"}`.

---

## POST `save_business.php`: insert or update businesses

```
POST https://email.demo-website.cc/save_business.php
Content-Type: application/json
```

### Body

Any of these three shapes is accepted:

```json
{ "extension_version": "1.2.0", "records": [ { ... }, { ... } ] }
```
```json
[ { ... }, { ... } ]
```
```json
{ "name": "Joe's Pizza", "place_id": "ChIJ...", "zip_id": "10001" }
```

- You can send up to **500** records per request (`MAX_RECORDS_PER_REQUEST`).
- A top-level `extension_version` is copied into any record that doesn't have its own.
- No field is required, but every record should carry at least one ID (`hex_id`, `place_id`, `cid`, `google_maps_url`) so it doesn't create duplicates.

### Duplicates

Each record is identified by the first non-empty value in this list: `hex_id`, `place_id`, `cid`, `google_maps_url` (hashed), then `name` + `address` (hashed).
Together with `zip_id`, that identity is unique. If the same business is sent again for the same `zip_id`, the existing row is **updated**, not added again, so retries are safe.

When an update has no `email` (or no `emails`), the saved value is kept, so a re-scrape doesn't erase an email found earlier or found by ChatGPT. A new `email` replaces the old one and sets `email_source` to `website`.

### Record fields

| Field | Type | Notes |
|---|---|---|
| `zip_id` | string (max 32 chars) | Part of the unique key |
| `name`, `category` | string | |
| `rating` | decimal | e.g. `4.5` |
| `review_count` | int | Non-digits are removed, so `"1,234"` becomes `1234` |
| `price_level`, `price_label`, `price_per_person` | string | |
| `address`, `street`, `city`, `state`, `postal_code`, `country` | string | |
| `phone`, `phone_e164`, `website`, `plus_code`, `located_in` | string | |
| `email` | string (max 255 chars) | Best address found on the business website (extension 1.3.0+) |
| `emails` | JSON array | Every address found on the website, best first |
| `email_source` | (set by the server) | `website` when the record has an `email`. Not read from the record. |
| `hours_status`, `hours` | string | |
| `hours_weekly` | JSON object | |
| `business_status` | string | |
| `latitude`, `longitude` | decimal | |
| `google_maps_url`, `place_id`, `cid`, `hex_id`, `cid_url` | string | Used to identify the business |
| `service_options` | JSON array | |
| `attributes_text` | string | |
| `attributes` | JSON object | |
| `accessibility`, `menu_url`, `reservation_url` | string | |
| `actions` | JSON array | |
| `image_url`, `review_summary` | string | |
| `rating_breakdown` | JSON object | |
| `is_claimed` | string | |
| `is_sponsored` | bool | `true` / `"true"` / `1` |
| `zipcode` | string | |
| `zip_lat`, `zip_long` | decimal | |
| `search_keyword`, `search_query` | string | |
| `scraped_at` | ISO date string | Stored as UTC |
| `extension_version` | string | |

Rules for values:
- `email` and `emails` are cleaned: URL-encoded text is decoded and junk stuck to the front is dropped (`04%7c01%7cname@site.com` becomes `name@site.com`), addresses are lowercased, invalid ones and duplicates are removed. If `email` is invalid, the first good address in `emails` is used. `raw_data` keeps the record exactly as sent.
- If a string field gets an array, the items are joined with `" | "`.
- Empty strings are stored as `NULL`.
- Unknown fields are not dropped. The full record is saved in `raw_data`.

### Responses

`200` when all records were saved:
```json
{ "ok": true, "received": 2, "inserted": 1, "updated": 1, "new": 1, "duplicate": 1,
  "results": [ { "index": 0, "name": "Smith", "status": "new" }, { "index": 1, "name": "Cafe Flora", "status": "duplicate" } ],
  "errors": [] }
```

- `inserted` / `updated`: rows added, or updated because the same business was already saved for the same `zip_id`.
- `new` / `duplicate`: businesses the portal didn't have yet, or already had (same `business_key`, for **any** ZIP). A business found again in another ZIP is `inserted` (a new row for that ZIP) but `duplicate`. The Maps scraper shows these as **New leads** and **Duplicates**.
- `results`: one entry per saved record, in order: `index` in `records`, `name`, `status` (`new` or `duplicate`). Failed records are only in `errors`.

`200` with `"ok": false` when some records failed. `errors` lists each failed record:
```json
{ "ok": false, "received": 2, "inserted": 1, "updated": 0, "new": 1, "duplicate": 0,
  "results": [ { "index": 0, "name": "Smith", "status": "new" } ],
  "errors": [ { "index": 1, "name": "Bad Biz", "error": "..." } ] }
```

| Status | Meaning |
|---|---|
| `400` | Body is not valid JSON |
| `401` | Invalid API key |
| `405` | The request did not use POST |
| `413` | More than 500 records |
| `422` | Every record failed |
| `500` | Database error (nothing was saved) |

### Example

```bash
curl -X POST https://email.demo-website.cc/save_business.php \
  -H "Content-Type: application/json" \
  -d '{"extension_version":"1.2.0","records":[{"name":"Test Biz","place_id":"test123","zip_id":"10001","rating":4.5}]}'
```

```js
await fetch('https://email.demo-website.cc/save_business.php', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' /*, 'X-API-Key': '...' */ },
  body: JSON.stringify({ extension_version: '1.2.0', records })
}).then(r => r.json());
```

---

## GET `get_businesses.php`: read saved businesses

```
GET https://email.demo-website.cc/get_businesses.php?zipcode=10001&page=1&limit=50
```

### Query parameters (all optional)

| Param | Description |
|---|---|
| `zipcode` | Exact match |
| `category` | Exact match |
| `q` | Matches any part of the name or email |
| `page` | Page number (default `1`) |
| `limit` | Records per page, 1–500 (default `50`) |

Results come newest first (`ORDER BY id DESC`).

### Response

```json
{
  "ok": true,
  "total": 120,
  "page": 1,
  "limit": 50,
  "data": [ { "id": 120, "name": "...", "hours_weekly": { ... }, "is_sponsored": false, ... } ]
}
```

- JSON columns (`emails`, `hours_weekly`, `service_options`, `attributes`, `actions`, `rating_breakdown`) come back already decoded.
- `is_sponsored` is a boolean.
- `raw_data` is not included.
- Each record also has `id`, `business_key`, `created_at` and `updated_at`.

---

## GET `get_business_without_email.php`: next business to look up

```
GET https://email.demo-website.cc/get_business_without_email.php?zipcode=10001
```

Used by the ChatGPT email finder (`find_emails_from_gpt/`). Returns **one** business that:
- has no `email`,
- hasn't been checked yet (`gpt_checked_at` is empty),
- isn't "Permanently closed",
- isn't already handed out (claimed less than `lease` minutes ago).

The business is **claimed** in the same step (`gpt_claimed_at` is set to now), so the next call, or another browser running at the same time, gets a different one. Report the result with [`save_gpt_email.php`](#post-save_gpt_emailphp-save-a-chatgpt-lookup). If you never do, the claim runs out after `lease` minutes and the business is handed out again.
Businesses are picked at random. Ones with fewer failed attempts come first (`ORDER BY gpt_attempts, RAND()`), so a business that just failed isn't handed back while untried ones are left.

### Query parameters (all optional)

| Param | Description |
|---|---|
| `zipcode` | Exact match |
| `category` | Exact match |
| `lease` | Minutes the business stays claimed, 1–120 (default `10`) |

### Response

```json
{
  "ok": true,
  "remaining": 41,
  "lease_minutes": 10,
  "business": { "id": 12, "name": "Hops & Barley", "address": "...", "phone": "...", "website": "...", "gpt_attempts": 0, ... }
}
```

- `business` has every column, like `get_businesses.php` (JSON columns decoded, `is_sponsored` a boolean, no `raw_data`). It is `null` when nothing is left.
- `remaining`: how many more businesses match and can be handed out right now, not counting this one.

| Status | Meaning |
|---|---|
| `401` | Invalid API key |
| `500` | Database error |

```bash
curl "https://email.demo-website.cc/get_business_without_email.php?lease=20"
```

---

## POST `save_gpt_email.php`: save a ChatGPT lookup

```
POST https://email.demo-website.cc/save_gpt_email.php
Content-Type: application/json
```

Saves what ChatGPT answered for a business from `get_business_without_email.php`.

### Body

| Field | Notes |
|---|---|
| `business_id` | Required. The `id` from `businesses`. |
| `emails` | Array of addresses, best first. `email` (a single string) is also accepted. Cleaned like in `save_business.php` (URL-encoded junk removed, lowercased, invalid ones and duplicates dropped), and at most 5 are kept. |
| `answer` | ChatGPT's reply, stored in `gpt_answer` (first 5,000 characters). |
| `error` | Set when the lookup failed (timeout, ChatGPT error, …). |
| `release` | `true` gives the business back right away without saving anything (the run was stopped). |
| `extension_version` | Optional, not stored. |

What happens:

| Case | Result |
|---|---|
| At least one valid address | `gpt_status = found`. `email` (first address), `emails` and `email_source = chatgpt` are filled in on **every row of the same business** (same `business_key`, other ZIPs). A row that already has an email keeps it. |
| No valid address | `gpt_status = none` on every row of the same business. |
| `error` set | `gpt_status = error`, the error goes into `gpt_answer`, and `gpt_attempts` goes up by one, on this row only. The business is handed out again after its claim runs out. After 3 failed attempts it is marked checked and not handed out again. |
| `release: true` | Only the claim is cleared, so the business can be handed out again right away. |

`found` and `none` set `gpt_checked_at`, so the business isn't handed out again. To check a business again, set its `gpt_checked_at` back to `NULL`.

```json
{ "business_id": 12, "emails": ["info@hopsandbarley.com"], "answer": "info@hopsandbarley.com" }
```

### Responses

`200` (found or none):
```json
{ "ok": true, "business_id": 12, "name": "Hops & Barley", "status": "found",
  "email": "info@hopsandbarley.com", "emails": ["info@hopsandbarley.com"], "rows_updated": 1 }
```

`200` (error):
```json
{ "ok": true, "business_id": 12, "status": "error", "attempts": 1, "gave_up": false }
```

`200` (release):
```json
{ "ok": true, "business_id": 12, "status": "released" }
```

| Status | Meaning |
|---|---|
| `400` | Body is not valid JSON |
| `401` | Invalid API key |
| `404` | No business with that `business_id` |
| `405` | The request did not use POST |
| `422` | Missing `business_id` |
| `500` | Database error |

```bash
curl -X POST https://email.demo-website.cc/save_gpt_email.php \
  -H "Content-Type: application/json" \
  -d '{"business_id":12,"answer":"NONE"}'
```

### Columns used

Added to `businesses` automatically the first time an endpoint needs them (`setup.php` adds them too):

| Column | Meaning |
|---|---|
| `email_source` | `website` (Maps scraper), `chatgpt` or `claude` (since API 1.26, [`save_claude_email.php`](#post-save_claude_emailphp-save-a-claude-lookup)). Emails saved before this column existed are set to `website`. |
| `gpt_status` | `found`, `none` or `error` |
| `gpt_answer` | ChatGPT's reply, or the error |
| `gpt_attempts` | Lookups reported for this row |
| `gpt_claimed_at` | UTC time it was last handed out |
| `gpt_checked_at` | UTC time the lookup finished (found, none, or gave up after 3 errors) |

---

## GET `get_business_for_claude.php`: next business for the Claude lookup

```
GET https://email.demo-website.cc/get_business_for_claude.php?lease=20
```

Added in API 1.26. Used by the Claude email finder (`find_emails_from_claude/`), the second try after ChatGPT. Returns **one** business that:
- has no `email` (none from the Maps scrape, and none from ChatGPT),
- ChatGPT is done with (`gpt_checked_at` is set: ChatGPT answered none, or gave up after 3 errors),
- Claude hasn't checked yet (`claude_checked_at` is empty),
- isn't "Permanently closed",
- isn't already handed out to Claude (`claude_claimed_at` less than `lease` minutes ago).

It works like [`get_business_without_email.php`](#get-get_business_without_emailphp-next-business-to-look-up), with its own claim (`claude_claimed_at`), so the ChatGPT and Claude finders can run at the same time. Report the result with [`save_claude_email.php`](#post-save_claude_emailphp-save-a-claude-lookup). Businesses are picked at random, fewer failed Claude attempts first (`ORDER BY claude_attempts, RAND()`).

Query parameters (all optional): `zipcode` (exact match), `category` (exact match), `lease` (minutes, 1–120, default `10`).

The response has the same shape as `get_business_without_email.php` (`remaining`, `lease_minutes`, `business` or `null`). `401` invalid API key, `500` database error.

```bash
curl "https://email.demo-website.cc/get_business_for_claude.php?lease=20"
```

---

## POST `save_claude_email.php`: save a Claude lookup

```
POST https://email.demo-website.cc/save_claude_email.php
Content-Type: application/json
```

Added in API 1.26. Saves what Claude answered for a business from `get_business_for_claude.php`. Same body and responses as [`save_gpt_email.php`](#post-save_gpt_emailphp-save-a-chatgpt-lookup) (`business_id`, `emails` or `email`, `answer`, `error`, `release`, `extension_version`), but writes the `claude_*` columns. One extra field (API 1.27):

| Field | Notes |
|---|---|
| `mentioned` | `true` when Claude's answer was NONE but its reply named these addresses (for a related business, say). They are saved like a found address, with `claude_status = mentioned`, and the response's `status` is `mentioned`. Ignored when no valid address is sent. |

| Case | Result |
|---|---|
| At least one valid address | `claude_status = found` (`mentioned` when `mentioned: true`). `email` (first address), `emails` and `email_source = claude` are filled in on **every row of the same business** (same `business_key`). A row that already has an email keeps it. |
| No valid address | `claude_status = none` on every row of the same business. |
| `error` set | `claude_status = error`, the error goes into `claude_answer`, `claude_attempts` goes up by one, on this row only. Handed out again after the claim runs out; after 3 failed attempts it is marked checked. |
| `release: true` | Only `claude_claimed_at` is cleared. |

`found` and `none` set `claude_checked_at`. To check a business again, set its `claude_checked_at` back to `NULL`.

```bash
curl -X POST https://email.demo-website.cc/save_claude_email.php   -H "Content-Type: application/json"   -d '{"business_id":12,"emails":["info@hopsandbarley.com"],"answer":"info@hopsandbarley.com"}'
```

### Columns used

Added to `businesses` automatically the first time an endpoint needs them (`setup.php` adds them too):

| Column | Meaning |
|---|---|
| `claude_status` | `found`, `mentioned` (API 1.27), `none` or `error` |
| `claude_answer` | Claude's reply, or the error |
| `claude_attempts` | Lookups reported for this row |
| `claude_claimed_at` | UTC time it was last handed out to Claude |
| `claude_checked_at` | UTC time the lookup finished (found, none, or gave up after 3 errors) |

---

## GET `index.php`: browse listings (HTML)

```
https://email.demo-website.cc/
```

A web page (not JSON) that lists every saved business:
- **Filters:** search across name, phone, address, website and email, plus ZIP code, category, minimum rating and **Email check** (the verification result of the main address: Valid, Invalid, Risky, Unknown or Not checked yet).
- **New leads per day:** a column chart of businesses added each day (by `created_at`, UTC days) for the last 7, 14, 30 or 90 days (`days`, default 30), with totals for the range, today, and how many have an email. It follows the filters above. Hover a day for its numbers; **Show as table** lists every day.
- **Table:** sort by clicking a column header. Click a row to see all its details (hours, attributes, IDs and more).
- **Paging:** 25 to 500 rows per page.
- **Export CSV** downloads every row that matches the current filters.
- **ChatGPT / Claude:** an email found by the ChatGPT or Claude email finder has a **ChatGPT** or **Claude** tag next to it. Check it before sending. The *Email source*, *ChatGPT result*, *ChatGPT answer*, *ChatGPT checked*, *Claude result*, *Claude answer* and *Claude checked* columns can be turned on in the column picker.
- **Email:** each business with an email address has an **✉ Email** button under it. It opens a **random template that is On** (from `templates.php`, or the Settings template when none are On), filled in for that business. The footer shows the template's name, and **↻ Change template** loads a different random one (it asks first if you've edited the text). **👁 Preview** shows the email as it will be received (From, To, Subject and the formatted message, rendered by `send_email.php` with `preview: true`); **✎ Edit** goes back. You can edit it and send it through [`send_email.php`](#getpost-send_emailphp-marketing-email-to-one-business). A green **Sent YYYY-MM-DD** tag shows the last successful send (from this button or from the Gmail mailer). If an address failed verification, the dialog leaves it out of To and says why; you can add it back.
- **Email verification:** a checked address has a badge next to it: **✓ Valid**, **✗ Invalid**, **! Risky** or **? Unknown** (hover it for the reason and the mail server's reply), or **Not checked**. When a business has more than one address, **+N more** under the main one sums up the others (for example `2 valid · 1 unknown · 1 not checked`); click it to list them, each with its own badge. **Verify** under the address checks that business's addresses again. **✓ Verify emails** in the toolbar above the table checks every address that is due, one at a time, until none are left; its badge counts them, and the totals by status are next to it. Click it again to stop. **▶ Auto verify** in the header checks one random address that is due, waits 5 seconds, then checks the next, and keeps going until you click **■ Stop auto verify**. It shows each result and a countdown, keeps running after the page reloads (remembered in the browser), and when nothing is due it looks again every minute, so new leads get checked as they come in. Only one of the two runs at a time. See [`verify_email.php`](#getpost-verify_emailphp-check-email-addresses).

All of these are URL parameters, so a filtered view can be bookmarked: `q`, `zipcode`, `category`, `min_rating`, `check` (`valid`, `invalid`, `risky`, `unknown` or `unchecked`), `sort`, `dir`, `page`, `limit`, `days`, `export=csv`.
When `API_KEY` is set, open the page with `?key=<value>`. The key is kept in every link on the page.
The **Templates** button in the header opens `templates.php`, and **Settings** opens `settings.php`.

---

## GET/POST `settings.php`: portal settings (HTML)

```
https://email.demo-website.cc/settings.php
```

A web form (not JSON) for the email (SMTP) settings, the Groq API key and model, and the marketing email template. `GET` shows the form. `POST` (form-encoded) validates and saves it, then redirects to `settings.php?saved=1` (303). If a field is invalid, the form is shown again with the errors. `send_email.php` uses the SMTP and template values. The Groq key and model are used by `generate_template.php` (the **Generate with Groq** button on `templates.php`).

| Form field | Default | Notes |
|---|---|---|
| `smtp_host` | `smtp.gmail.com` | |
| `smtp_port` | `587` | 1–65535. Empty means the default port for `smtp_security`. |
| `smtp_security` | `starttls` | `starttls` (587), `ssl` (465) or `none` (25) |
| `smtp_user` | | Gmail address. Must be a valid email address, or empty. |
| `smtp_password` | | Gmail app password. Spaces are removed. |
| `smtp_from_name` | | |
| `sender_phone` | | Your phone, used by `{from_phone}`. Line breaks are removed. |
| `groq_api_key` | | |
| `groq_model` | `openai/gpt-oss-120b` | Any chat model your Groq key can use. Empty restores the default. |
| `email_subject` | `Professional Website Design Services – Let’s Build Your Online Presence` | Line breaks are removed. Empty restores the default. |
| `email_body` | See below | Empty restores the default. |

Default `email_body` (plain text, ends with the signature):

```
Dear **{business_name}** team,

I hope this email finds you well.

My name is {from_name}, and I am the founder of wpwebdesignings.com. We specialize in ...

Whether you need:
- A completely new website from scratch
...
Best regards,
{from_name}
Founder | wpwebdesignings.com
{from_phone}
{from_email}
Website: https://wpwebdesignings.com
```

The full text is `SETTINGS_DEFAULTS['email_body']` in [db.php](db.php).

Template placeholders: `{business_name}` (or "your business"), `{website}` (domain only, or "your website"), `{city}` (or "your area"), `{category}` (or "business"), `{from_name}` (`smtp_from_name`), `{from_email}` (`smtp_user`), `{from_phone}` (`sender_phone`).
A line that holds only a placeholder whose value is empty (for example `{from_phone}` with no phone saved) is removed.
Wrap text on one line in `**double asterisks**` to send it in bold, or in `[[double square brackets]]` to send it highlighted (bold on a yellow background). `GET` returns the text with the markers; they are converted when sending (`mail_html()` in [mailer.php](mailer.php)), and removed from the plain-text part.

Values are stored as plain text in the `settings` table (`setting_key`, `setting_value`, `updated_at`), one row per form field. A key that hasn't been saved uses its default. The page creates the table if it is missing. When `API_KEY` is set, open the page with `?key=<value>`.

---

## GET/POST `send_email.php`: marketing email to one business

```
GET  https://email.demo-website.cc/send_email.php?business_id=11
POST https://email.demo-website.cc/send_email.php
Content-Type: application/json
```

Used by the **✉ Email** button on `index.php`. `GET` returns the template filled in for the business (nothing is sent). `POST` sends one email (plain text plus an HTML version, so `**bold**` text shows in bold) over the SMTP settings from `settings.php` and logs the attempt in the `email_log` table (`channel = smtp`). A successful send also marks the business as mailed (`mail_sent_at`, see [`save_email_result.php`](#post-save_email_resultphp-record-a-gmail-send)) on every row of the same business and every row with one of the recipients, so the Gmail mailer skips it.

The endpoint sends **no CORS headers** and `POST` accepts **JSON only**, so other websites can't make your browser send mail through the portal. When `API_KEY` is set, send `X-API-Key` or `?key=`.

### GET: preview

| Param | Description |
|---|---|
| `business_id` | Required. The `id` from `businesses`. |

```json
{
  "ok": true, "business_id": 11, "name": "Flannel Tavern",
  "to": "carrie@flanneltavern.com, flanneltavern@gmail.com",
  "emails": ["carrie@flanneltavern.com", "flanneltavern@gmail.com"],
  "invalid": [],
  "checks": { "carrie@flanneltavern.com": { "status": "valid", "reason": "The mail server confirmed this mailbox exists.", "checked_at": "2026-10-08 11:13:10" } },
  "from": "you@gmail.com",
  "subject": "Professional Website Design Services – Let’s Build Your Online Presence",
  "body": "Dear Flannel Tavern team,\n\nI hope this email finds you well.\n\n...",
  "smtp_ready": true, "smtp_error": null,
  "last_sent": null
}
```

- `emails`: valid addresses from `email` and `emails`, best first, without duplicates. `to` is all of them except the `invalid` ones, joined with `", "` (the **✉ Email** dialog puts them in To), or `""` if there are none.
- `invalid`: addresses that failed verification (`status` `invalid` in [`verify_email.php`](#getpost-verify_emailphp-check-email-addresses)). They are left out of `to`. `POST` still sends to them if you put them back in `to`.
- `checks`: the saved verification result of each checked address (lowercase), as `{status, reason, checked_at}`. Addresses never checked are not in it.
- `smtp_ready` is `false` when Host, Port, Gmail address or App password is missing. `smtp_error` says which ones.
- `last_sent`: UTC time of the last successful send to this business, or `null`.

### POST: send

```json
{ "business_id": 11, "to": "carrie@flanneltavern.com", "subject": "...", "body": "..." }
```

| Field | Notes |
|---|---|
| `business_id` | Required |
| `to` | Required. One address, or several separated by commas (semicolons and spaces work too), or a JSON array. Any valid addresses, not only the ones found for the business. Duplicates are dropped. At most 10 addresses and 255 characters. **One** email is sent, with every address in its To header. |
| `subject` | Optional. Empty uses `email_subject` from Settings. Max 255 characters. |
| `body` | Optional. Empty uses `email_body` from Settings. Max 20,000 characters. |
| `template_id` | Optional. The `email_templates` id the text came from. Its `times_used` goes up by 1 when the email is sent. |
| `preview` | Optional. `true` = don't send: return the email as it would be sent (see below). Nothing is logged or counted. |

Placeholders left in `subject` or `body` are filled in before sending, so you can send the raw template.

With `"preview": true`, `200`:
```json
{ "ok": true, "preview": true, "from": "John David <you@gmail.com>",
  "to": ["carrie@flanneltavern.com", "flanneltavern@gmail.com"], "subject": "...",
  "html": "<!doctype html>…", "text": "...", "warnings": [] }
```
`html` and `text` are exactly the two parts a real send would contain. `warnings` lists what would make a real send fail (for example an invalid address in `to`); a preview still succeeds with them.

`200` (sent):
```json
{ "ok": true, "sent_to": "carrie@flanneltavern.com, flanneltavern@gmail.com",
  "recipients": ["carrie@flanneltavern.com", "flanneltavern@gmail.com"],
  "subject": "...", "log_id": 1, "sent_at": "2026-10-03 10:15:00" }
```

| Status | Meaning |
|---|---|
| `400` | Body is not valid JSON |
| `401` | Invalid API key |
| `404` | No business with that `business_id` |
| `405` | Not GET or POST |
| `415` | POST body is not `application/json` |
| `422` | Missing `business_id`; `to` empty, has an invalid address (named in `error`), more than 10 addresses or over 255 characters; or subject/body too long |
| `500` | Database error |
| `502` | SMTP failed (not set up, couldn't connect, login refused, an address refused, …). If the server refuses any address, nothing is sent. `error` has the server's reply. The attempt is still logged. |

```bash
curl -X POST https://email.demo-website.cc/send_email.php \
  -H "Content-Type: application/json" \
  -d '{"business_id":11,"to":"carrie@flanneltavern.com, flanneltavern@gmail.com"}'
```

### `email_log` table

One row per send attempt: `id`, `business_id`, `to_email` (every recipient, joined with `", "`), `subject`, `body`, `status` (`sent` or `failed`), `channel` (`smtp` from `send_email.php`, `gmail` from [`save_email_result.php`](#post-save_email_resultphp-record-a-gmail-send); empty for rows logged before API 1.16), `from_email` (the Gmail account, `gmail` rows only), `error`, `sent_at` (UTC). It is created automatically if missing, and `channel` and `from_email` are added to an older table (`EMAIL_LOG_ADDED_COLUMNS` in [db.php](db.php)).

---

## GET/POST `templates.php`: email templates (HTML)

```
https://email.demo-website.cc/templates.php
```

A web page (not JSON) for more marketing email templates, next to the one in Settings:
- **List:** name (with a **Groq** tag if generated, and a ⚠ tag naming any placeholder the mailer won't fill), subject, **On/Off**, how often `get_random_template.php` picked it, last update. **Edit**, **Duplicate** (the copy starts Off) and **Delete**.
- **+ New template** opens an empty editor. Write the template yourself; Groq is optional. With no templates yet, **Copy the Settings template here** adds the Settings template as the first one (On).
- **Generate with Groq** (optional, folded inside the editor): an optional brief ("what should it focus on"), a tone and a length. **Generate** calls `generate_template.php` and puts the result in the editor. Nothing is saved until you press **Save template**.
- **Editor:** name, subject, message, **On** (can be picked at random), and **Clean up Markdown and [Your Name] blanks on save** (on by default): `### headings` become `**bold**` lines, `*single stars*` are removed, `•` and `*` bullets become `- `, and blanks like `[Your Name]`, `[Your Phone Number]`, `[Your Email Address]`, `[Business Name]`, `[City]` become `{from_name}`, `{from_phone}`, `{from_email}`, `{business_name}`, `{city}`. A live preview shows the template filled in for a saved business.

Placeholders are the same as the Settings template: `{business_name}`, `{website}`, `{city}`, `{category}`, `{from_name}`, `{from_email}`, `{from_phone}`.

`POST` is form-encoded with `action` = `save` (`id` empty for a new template; `name`, `subject`, `body`, `is_active`, `clean`, `source`), `toggle`, `duplicate` or `delete` (each with `id`), or `import` (copy the Settings template). Every action redirects back (303). When `API_KEY` is set, open the page with `?key=<value>`.

### `email_templates` table

`id`, `name`, `subject`, `body`, `is_active` (1 = can be picked at random), `source` (`manual` or `groq`), `times_used`, `created_at`, `updated_at`. It is created automatically if missing.

---

## POST `generate_template.php`: write a template with Groq

```
POST https://email.demo-website.cc/generate_template.php
Content-Type: application/json
```

Asks Groq (key and model from Settings) for a new template and returns it. **Nothing is saved.** Used by the **Generate** button on `templates.php`. Like `send_email.php`, it sends **no CORS headers** and accepts **JSON only**, so other websites can't spend your Groq quota through your browser.

| Field | Notes |
|---|---|
| `brief` | Optional. What the email should focus on (max 2,000 characters). |
| `tone` | `friendly` (default), `professional`, `short`, `casual` or `persuasive` |
| `length` | `short` (60–100 words), `medium` (default, 120–180) or `long` (200–280), not counting the signature |

The model is told to use only the portal placeholders, to end with the standard signature, and to write something different from the Settings template. Its reply is cleaned the same way as **Clean up** on `templates.php`.

`200`:
```json
{ "ok": true, "name": "Mobile Menu Upgrade", "subject": "Boost {category} sales with a mobile-friendly menu",
  "body": "Hi {business_name} team,\n\n...", "model": "openai/gpt-oss-120b", "unknown_placeholders": [] }
```

`unknown_placeholders` lists anything placeholder-like the mailer won't fill (for example `{owner_name}`).

| Status | Meaning |
|---|---|
| `400` | Body is not valid JSON |
| `401` | Invalid API key |
| `405` | Not POST |
| `415` | Body is not `application/json` |
| `500` | Database error |
| `502` | Groq failed: no key in Settings, key refused, unknown model, timeout, or an unusable reply. `error` says which. |

---

## GET `get_templates.php`: list the templates

```
GET https://email.demo-website.cc/get_templates.php
```

Returns the templates saved on `templates.php`, oldest first. Read-only: it doesn't change `times_used`. CORS is open like `get_businesses.php`.

| Param | Description |
|---|---|
| `active` | Optional. `1` = only templates that are **On** (the ones `get_random_template.php` picks from). |
| `id` | Optional. Only that template (`404` if there is none). |

```json
{
  "ok": true,
  "total": 2,
  "active": 1,
  "templates": [
    { "id": 3, "name": "Default (from Settings)", "subject": "...", "body": "...", "is_active": true,
      "source": "manual", "times_used": 0, "created_at": "2026-10-03 06:10:00", "updated_at": "2026-10-03 06:10:00" }
  ]
}
```

- `total` and `active` count the whole table. `templates` holds only the ones that match the filters.
- The Settings template isn't in the list unless you copied it in with **Copy the Settings template here**.

| Status | Meaning |
|---|---|
| `401` | Invalid API key |
| `404` | No template with that `id` |
| `500` | Database error |

---

## GET `get_random_template.php`: one random template

```
GET https://email.demo-website.cc/get_random_template.php?business_id=14&exclude=3
```

Returns one template that is **On**, picked at random, and adds 1 to its `times_used` (unless `count=0`). With no template On, it returns the Settings template (`id: null`, `source: "settings"`). CORS is open like `get_businesses.php`.

| Param | Description |
|---|---|
| `business_id` | Optional. Also return `filled`: the template filled in for that business, plus its addresses, ready for `send_email.php`. |
| `exclude` | Optional. Template ids to skip, comma-separated (e.g. the one used last). If that excludes every template that is On, one of them is returned anyway. |
| `count` | Optional. `0` = don't add to `times_used`. The **✉ Email** dialog uses this and counts the template when the email is sent (`template_id` on `send_email.php`) instead. |

```json
{
  "ok": true,
  "active_templates": 3,
  "template": { "id": 2, "name": "Mobile Menu Upgrade", "subject": "Boost {category} sales…", "body": "Hi {business_name} team,…", "source": "groq", "times_used": 5 },
  "filled": {
    "business_id": 14, "name": "Hattie B's Hot Chicken - Knoxville",
    "to": "info@hattieb.com, amberl@hattieb.com", "emails": ["info@hattieb.com", "amberl@hattieb.com"],
    "subject": "Boost Restaurant sales…", "body": "Hi Hattie B's Hot Chicken - Knoxville team,…"
  }
}
```

To send it: `POST send_email.php` with `business_id`, `to`, and `filled.subject` / `filled.body`.

| Status | Meaning |
|---|---|
| `401` | Invalid API key |
| `404` | No business with that `business_id` |
| `500` | Database error |

```bash
curl "https://email.demo-website.cc/get_random_template.php?business_id=14"
```

---

## GET `get_business_to_email.php`: next business to email

```
GET https://email.demo-website.cc/get_business_to_email.php?source=website
```

Used by the Gmail business mailer (`sending_email/`). Returns **one** business that:
- has an `email`,
- was never mailed (`mail_sent_at` is empty: no successful send from `send_email.php` or Gmail, for this business in any ZIP or for this address),
- hasn't been given up on (`mail_status` is empty or `error`, not `failed`),
- isn't "Permanently closed",
- whose `email` is **verified** (`valid` in `email_checks`, see [`verify_email.php`](#getpost-verify_emailphp-check-email-addresses)). Addresses not checked yet, `unknown`, `risky` and `invalid` ones are never handed out (before API 1.22 only `invalid` ones were skipped). Run **Auto verify** on `index.php` to grow the queue,
- isn't already handed out (claimed less than `lease` minutes ago).

The business is **claimed** in the same step (`mail_claimed_at` is set to now), so the next call, or another browser, gets a different one. Report the result with [`save_email_result.php`](#post-save_email_resultphp-record-a-gmail-send). If you never do, the claim runs out after `lease` minutes and the business is handed out again.
Businesses come oldest first (`ORDER BY id`). CORS is open like `get_business_without_email.php`.

### Query parameters (all optional)

| Param | Description |
|---|---|
| `zipcode` | Exact match |
| `category` | Exact match |
| `source` | `website`, `chatgpt` or `claude` (since API 1.26): only addresses found that way (`email_source`). Anything else means any. |
| `lease` | Minutes the business stays claimed, 1–120 (default `15`) |

### Response

```json
{
  "ok": true,
  "remaining": 47,
  "lease_minutes": 15,
  "business": { "id": 128, "name": "Sickies Garage Burgers & Brews", "email": "info@sickiesburgers.com", "website": "https://sickiesburgers.com/…", "mail_attempts": 0, ... },
  "recipients": ["info@sickiesburgers.com", "accessibility@sickiesburgers.com"]
}
```

- `business` has every column, like `get_businesses.php` (JSON columns decoded, `is_sponsored` a boolean, no `raw_data`). It is `null` when nothing is left.
- `recipients`: the verified (`valid`) addresses from `email` and `emails`, best first, without duplicates. The main `email` is always one of them; extra addresses that are not `valid` (yet) are left out.
- `remaining`: how many more businesses match (verified email included) and can be handed out right now, not counting this one.

| Status | Meaning |
|---|---|
| `401` | Invalid API key |
| `500` | Database error |

```bash
curl "https://email.demo-website.cc/get_business_to_email.php?lease=15"
```

---

## POST `generate_email.php`: an email for one business, written by Groq

```
POST https://email.demo-website.cc/generate_email.php
Content-Type: application/json
```

Asks Groq (key and model from Settings) to write a subject and message for **one** business from everything saved about it. **Nothing is sent or saved.** Used by the Gmail mailer before it fills Gmail's compose window. Like `generate_template.php`, it sends **no CORS headers** and accepts **JSON only**, so other websites can't spend your Groq quota through your browser (a browser extension allowed to reach the portal host can still call it).

| Field | Notes |
|---|---|
| `business_id` | Required. The `id` from `businesses`. |
| `brief` | Optional. Extra instructions (max 2,000 characters), e.g. "offer a free homepage mockup". |
| `tone` | `friendly` (default), `professional`, `short`, `casual` or `persuasive` |
| `length` | `short` (60–100 words), `medium` (default, 120–180) or `long` (200–280), not counting the signature |
| `highlight` | How the details taken from the business show in the email: `bold` (default), `highlight` (bold on a yellow background) or `none` (plain text). |
| `from_name`, `from_email`, `from_phone` | Optional. Replace `smtp_from_name`, `smtp_user` and `sender_phone` from Settings in the signature. The Gmail mailer sends the Gmail account it sends from as `from_email`. An invalid `from_email` is ignored. |

What Groq gets: name, category, Google rating and review count, price, address, city, "located in", phone, website (or that none is listed), menu and booking links, whether the Google profile is claimed, hours, status, service options, highlights (`attributes_text`, else `attributes`), accessibility and the review summary. Empty fields are left out. The Settings email (`email_subject`, `email_body`) is included as a reference for what you offer. Nothing about HTTPS or the business's email is sent. **The pitch is only organic sales:** the model is told the email is about helping the business get more customers from Google search with $0 spent on marketing or ads, to pick one or two facts that show that opportunity (no website, ranking for praised dishes or services, reviews, menu or booking links, an unclaimed Google profile), never to promise numbers or rankings, and never to mention HTTPS, SSL, security, site speed or their email. It must use only these facts, never write as if the business has no website when it has one, and end with the standard signature. A reply that contains one of the business's email addresses, or mentions HTTPS, SSL or "Not secure", counts as unusable (Groq is asked again).

Links are given as bare domains (`display_url()` in [groq.php](groq.php)): `https://www.tapntikkaaz.com/menu?utm_source=menu` becomes `tapntikkaaz.com/menu`, without `https://`, `www.`, tracking or `#…`. A Google Maps booking link is given as "yes, through Google Maps". Any full link Groq still writes in the body is cleaned the same way before it is returned; links to your own site (`OWN_SITE_HOSTS`, `wpwebdesignings.com`) are left as written.

The reply is cleaned like **Clean up** on `templates.php` and the placeholders are filled in (`fill_template()` in [mailer.php](mailer.php)). If it is unusable or leaves a blank that can't be filled in (like `{owner_name}`), Groq is asked once more.

`200`:
```json
{
  "ok": true, "business_id": 128, "name": "Sickies Garage Burgers & Brews",
  "to": "info@sickiesburgers.com, accessibility@sickiesburgers.com",
  "emails": ["info@sickiesburgers.com", "accessibility@sickiesburgers.com"],
  "subject": "Boost Sickies Garage Burgers & Brews online with a fresh site",
  "body": "Hi Sickies Garage Burgers & Brews team,\n\n…\n\n**A modern site will turn your great reputation into even more foot traffic.**\n\n…",
  "text": "… the same without the ** markers …",
  "html": "Hi <b>Sickies Garage Burgers &amp; Brews</b> team,<br>\r\n<br>\r\n… <b>A modern site …</b> …",
  "facts": ["Sickies Garage Burgers & Brews", "4.4-star", "3,000 reviews", "Wagyu burgers"],
  "highlight": "bold",
  "model": "openai/gpt-oss-120b"
}
```

- Groq is told to mark **every detail it takes from the business** (name, city, rating, review count, website, HTTPS, email domain, dishes and services from the highlights and review summary…) as `[[detail]]`, in the subject too. `highlight` decides what happens to the marks in `body`: `bold` turns them into `**detail**`, `highlight` keeps `[[detail]]`, `none` removes them. The subject never has marks or formatting. Marks inside a `**bold**` sentence are dropped (already bold), and marks around a `{placeholder}` are removed.
- `facts`: the marked details, in order, without duplicates (whatever `highlight` is).
- `body` keeps the `**bold**` and `[[highlight]]` markers (what `send_email.php` and the email log take). `text` is without them. `html` is a fragment (no `<html>` wrapper) with bold, highlights (`<span style="background-color:#fff3a3;font-weight:bold">`), clickable links and `<br>` line breaks, for pasting into a compose box.
- `to` / `emails`: like `send_email.php` `GET`.

| Status | Meaning |
|---|---|
| `400` | Body is not valid JSON |
| `401` | Invalid API key |
| `404` | No business with that `business_id` |
| `405` | Not POST |
| `415` | Body is not `application/json` |
| `422` | Missing `business_id` |
| `500` | Database error |
| `502` | Groq failed: no key in Settings, key refused, unknown model, timeout, or no usable reply after 2 tries. `error` says which. |

```bash
curl -X POST https://email.demo-website.cc/generate_email.php \
  -H "Content-Type: application/json" \
  -d '{"business_id":128,"tone":"friendly","length":"medium"}'
```

---

## POST `save_email_result.php`: record a Gmail send

```
POST https://email.demo-website.cc/save_email_result.php
Content-Type: application/json
```

Reports what happened to a business from `get_business_to_email.php`. Sends **no CORS headers** and accepts **JSON only**, like `send_email.php`.

### Body

| Field | Notes |
|---|---|
| `business_id` | Required. The `id` from `businesses`. |
| `status` | Required. `sent`, `error` or `released`. (`release: true` also means `released`.) |
| `to` | Required for `sent`. The recipients: an array, or a string separated by commas, semicolons or spaces. Cleaned like `save_business.php` emails. |
| `subject`, `body` | The email, for the log (max 255 and 20,000 characters). |
| `from_email` | The Gmail account it was sent from. |
| `error` | For `error`: what went wrong (max 2,000 characters). |
| `give_up` | For `error`: `true` = never hand this business out again (for example Gmail refused the address). |
| `extension_version` | Optional, not stored. |

What happens:

| `status` | Result |
|---|---|
| `sent` | A row in `email_log` (`status = sent`, `channel = gmail`, `from_email`). `mail_status = sent` and `mail_sent_at` = now on **every row of the same business** (same `business_key`, other ZIPs) and **every row whose `email` is one of the recipients** (any case), so none of them is handed out again. The **Sent** tag on `index.php` shows it. |
| `error` | `mail_attempts` goes up by one and `mail_error` is saved, on this row only. `mail_status` becomes `error`, so the business is handed out again after its claim runs out, or `failed` after 3 attempts or with `give_up: true`, and then it isn't handed out again. When a `subject` is sent, the attempt is also logged in `email_log` with `status = failed`. |
| `released` | Only the claim is cleared, so the business can be handed out again right away. Nothing is logged. |

To mail a business again, set its `mail_sent_at` and `mail_status` back to `NULL` (and on the other rows of the same business).

### Responses

`200` (sent):
```json
{ "ok": true, "business_id": 128, "name": "Sickies Garage Burgers & Brews", "status": "sent",
  "sent_to": "info@sickiesburgers.com, accessibility@sickiesburgers.com", "log_id": 70, "rows_updated": 1,
  "sent_at": "2026-10-08 09:15:00" }
```

`200` (error):
```json
{ "ok": true, "business_id": 128, "name": "…", "status": "error", "log_id": null, "attempts": 1, "gave_up": false }
```

`200` (released):
```json
{ "ok": true, "business_id": 128, "status": "released" }
```

| Status | Meaning |
|---|---|
| `400` | Body is not valid JSON |
| `401` | Invalid API key |
| `404` | No business with that `business_id` |
| `405` | Not POST |
| `415` | Body is not `application/json` |
| `422` | Missing `business_id`; `status` not `sent`, `error` or `released`; or `sent` without a valid address in `to` |
| `500` | Database error |

```bash
curl -X POST https://email.demo-website.cc/save_email_result.php \
  -H "Content-Type: application/json" \
  -d '{"business_id":128,"status":"sent","to":["info@sickiesburgers.com"],"subject":"…","body":"…","from_email":"you@gmail.com"}'
```

### Columns used

Added to `businesses` automatically the first time an endpoint needs them (`setup.php` adds them too):

| Column | Meaning |
|---|---|
| `mail_status` | `sent`, `error` (will be retried) or `failed` (given up) |
| `mail_attempts` | Failed sends reported for this row |
| `mail_error` | The last failure |
| `mail_claimed_at` | UTC time it was last handed out by `get_business_to_email.php` |
| `mail_sent_at` | UTC time of the last successful send, from Gmail or `send_email.php` |

When the columns are first added, every business that already has a `sent` row in `email_log` is marked as mailed (`mail_status = sent`, `mail_sent_at` = that time), on every row of the same business and every row with the same address, so the Gmail mailer doesn't email it again.

---

## GET `get_zipcode.php`: next ZIP to scrape

```
GET https://email.demo-website.cc/get_zipcode.php
```

Used by the Maps scraper (`find_business/`). Returns **one** ZIP from the `zipcodes` table that is pending (`status = 0`), **picked at random** (before API 1.21: lowest `id` first).

The ZIP is **claimed** in the same step (`status = 1`, `claimed_at` = now) and is **never handed out again**, whatever happens to it: every call gets a new ZIP. Report the result with [`update_zipcode.php`](#post-update_zipcodephp-report-a-zip). A ZIP that is never reported stays at `status = 1`; set it back to `0` to scrape it again. (Before API 1.19, an unreported or released ZIP was handed out again.)

The `zipcodes` table is imported by hand (`id`, `zipcode`, `lat`, `long`, `status`). `status` means:

| `status` | Meaning |
|---|---|
| `0` | Pending |
| `1` | Handed out, in progress |
| `2` | Done |
| `3` | Failed |
| `4` | Stopped: the run was stopped before the ZIP finished |

Only `0` is ever handed out. To scrape a ZIP again, set its `status` back to `0`.

No query parameters (`lease` is no longer used and is ignored).

### Response

```json
{
  "ok": true,
  "remaining": 33758,
  "zip": { "id": 1, "zipcode": "98112", "lat": "47.63242", "long": "-122.28732", "status": 1, "claimed_at": "2026-10-05 19:30:05" }
}
```

- `zip` is `null` when no ZIP is left.
- `remaining`: how many pending ZIPs are left, not counting this one.
- `claimed_at` is UTC.

| Status | Meaning |
|---|---|
| `401` | Invalid API key |
| `500` | Database error (for example, the `zipcodes` table is missing) |

```bash
curl "https://email.demo-website.cc/get_zipcode.php"
```

---

## POST `update_zipcode.php`: report a ZIP

```
POST https://email.demo-website.cc/update_zipcode.php
Content-Type: application/json
```

Reports what happened to a ZIP from `get_zipcode.php`.

### Body

| Field | Notes |
|---|---|
| `id` | Required. The `id` from `zipcodes`. |
| `status` | Required. `done`, `failed` or `released`. |
| `scraped` | Optional. Businesses saved for this ZIP, stored in `scraped`. |

| `status` | Result |
|---|---|
| `done` | `status = 2`, `finished_at` = now, `scraped` saved |
| `failed` | `status = 3`, `finished_at` = now, `scraped` saved |
| `released` | The run was stopped before the ZIP finished: `status = 4`, `finished_at` = now, `scraped` saved (without `scraped`, the businesses saved for this ZIP are counted). It is not handed out again. Only a ZIP that is still in progress (`status = 1`) is changed. |

```json
{ "id": 1, "status": "done", "scraped": 42 }
```

### Responses

```json
{ "ok": true, "id": 1, "zipcode": "98112", "status": "done" }
```

| Status | Meaning |
|---|---|
| `400` | Body is not valid JSON |
| `401` | Invalid API key |
| `404` | No ZIP with that `id` |
| `405` | The request did not use POST |
| `422` | Missing `id`, or `status` is not `done`, `failed` or `released` |
| `500` | Database error |

```bash
curl -X POST https://email.demo-website.cc/update_zipcode.php \
  -H "Content-Type: application/json" \
  -d '{"id":1,"status":"done","scraped":42}'
```

### Columns used

Added to `zipcodes` automatically the first time either endpoint runs (`setup.php` adds them too), along with an index on `status`:

| Column | Meaning |
|---|---|
| `claimed_at` | UTC time it was last handed out |
| `finished_at` | UTC time it was reported done or failed |
| `scraped` | Businesses saved for this ZIP |

When the columns are first added, every ZIP that already has businesses (`businesses.zip_id` = `zipcodes.id`) is marked done (`status = 2`), with `scraped` set to how many it has.

---

## GET/POST `verify_email.php`: check email addresses

```
GET https://email.demo-website.cc/verify_email.php?next=1
```

Checks whether addresses can receive mail, **without sending anything**. Used by **Verify** and **✓ Verify emails** on `index.php`. Each address goes through these steps ([email_verify.php](email_verify.php)):

1. **Format.** It must be a clean address (`clean_email()` in [db.php](db.php)).
2. **Disposable domain.** Throwaway-mail domains (mailinator.com, yopmail.com, …) are `risky`.
3. **Mail server.** The domain needs an MX record, or an A record as the fallback. No server, or a "null MX" like `example.com`'s, makes it `invalid`.
4. **Mailbox.** The portal connects to the domain's mail server on port 25, introduces itself (`EHLO`, then `MAIL FROM` with the Gmail address from Settings, or `<>` when there is none), asks it to accept the address (`RCPT TO`) and hangs up (`QUIT`) before any message. It then asks for a made-up address at the same domain: if that is accepted too, the domain is catch-all.

| `status` | Meaning |
|---|---|
| `valid` | The mail server accepts this mailbox and rejects made-up ones |
| `invalid` | Bad format, the domain takes no mail, or the server said the mailbox doesn't exist (`5.1.1` and similar). The Gmail mailer skips it; the **✉ Email** dialog leaves it out of To |
| `risky` | Catch-all domain, full mailbox or disposable domain: it may bounce |
| `unknown` | The server wouldn't say: it blocked the check (for example a home internet IP on the Spamhaus PBL, common with Yahoo, GoDaddy and GMX), asked us to try later, or timed out. Checked again later, see below |

A server that rejects *us* (IP reputation, policy, `5.7.x`) never makes an address `invalid`; only a clear "no such mailbox" does. Gmail and Google Workspace answer from a home connection; many other providers don't, so expect some `unknown`.

### Parameters (GET or POST form fields)

| Param | Description |
|---|---|
| `email` | One address, or up to 5 separated by commas |
| `business_id` | Every address of that business (`email` and `emails`, up to 5) |
| `next` | Check the next 1–5 addresses that are due, oldest business first. Due means never checked, or `unknown` with fewer than 3 tries and the last one at least 60 minutes ago. Call it again until `remaining` is `0` |
| `random` | `1` with `next`: pick the addresses at random instead of oldest business first (used by **Auto verify**) |
| `refresh` | `1`: check `email` / `business_id` again even if a result is saved (otherwise the saved one is returned) |
| `summary` | `1`: only `counts` and `remaining`; nothing is checked |

Pass one of `email`, `business_id`, `next` or `summary`. A check usually takes 1–3 seconds, but a slow server can take up to about 45.

### Response

```json
{
  "ok": true,
  "results": [
    { "email": "hopsandbarleyuc@gmail.com", "status": "valid", "reason": "The mail server confirmed this mailbox exists.",
      "mx_host": "gmail-smtp-in.l.google.com", "smtp_code": 250, "smtp_reply": "250 2.1.5 OK … - gsmtp",
      "attempts": 1, "checked_at": "2026-10-08 11:13:10", "business_id": 3 }
  ],
  "remaining": 196,
  "counts": { "valid": 3, "invalid": 0, "risky": 0, "unknown": 0 }
}
```

- `reason`: a plain-English explanation. `mx_host`, `smtp_code` and `smtp_reply` are the mail server asked and its reply to `RCPT TO` (or to the step that failed; `smtp_code` `0` = no reply). They are `null` when no server was asked.
- `business_id` is only there for `business_id` and `next`.
- `attempts`: checks in a row that ended `unknown` (1 otherwise).
- `remaining`: addresses still due. `counts`: every saved result by status.

| Status | Meaning |
|---|---|
| `401` | Invalid API key |
| `404` | No business with that `business_id` |
| `422` | No parameter, more than 5 addresses, or the business has no email |
| `500` | Database error |

No CORS headers: it is meant for the portal page.

### `email_checks` table

One row per address (lowercase): `email` (key), `status`, `reason`, `mx_host`, `smtp_code`, `smtp_reply`, `attempts`, `checked_at` (UTC). Created automatically if missing (`setup.php` creates it too).

---

## GET `setup.php`: create the tables

Open this URL once to create the `businesses`, `settings`, `email_log`, `email_templates` and `email_checks` tables from [schema.sql](schema.sql). On an existing table it adds any columns added in later versions (`email`, `emails`, `email_source`, `gpt_status`, `gpt_answer`, `gpt_attempts`, `gpt_claimed_at`, `gpt_checked_at`, `mail_status`, `mail_attempts`, `mail_error`, `mail_claimed_at`, `mail_sent_at`, `claude_status`, `claude_answer`, `claude_attempts`, `claude_claimed_at`, `claude_checked_at`; the list is `BUSINESS_ADDED_COLUMNS` in [db.php](db.php)), and `channel` and `from_email` to `email_log`. The portal pages and the endpoints that use those columns also add them when they're missing. If the `zipcodes` table has been imported, it also adds `claimed_at`, `finished_at` and `scraped` to it (`ZIPCODE_ADDED_COLUMNS`). Afterwards, delete the file or protect it with an API key.

```json
{ "ok": true, "message": "Tables \"businesses\", \"settings\", \"email_log\", \"email_templates\" and \"email_checks\" are ready in business_data",
  "added_columns": [], "zipcodes": { "added_columns": [] } }
```

`zipcodes` is `"missing: import the zipcodes table"` when the table isn't there.
