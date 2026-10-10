<?php
/*
 * Groq chat API (OpenAI-compatible) and the email template generator used by templates.php.
 * Key and model come from Settings (groq_api_key, groq_model).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php'; // fill_template()

const GROQ_CHAT_URL = 'https://api.groq.com/openai/v1/chat/completions';
const GROQ_TIMEOUT = 90;

// Placeholders the mailer fills in (mailer.php fill_template()).
const TEMPLATE_PLACEHOLDERS = ['{business_name}', '{website}', '{city}', '{category}', '{from_name}', '{from_email}', '{from_phone}'];

/** Sends a chat request and returns the reply text. Throws RuntimeException with a readable message. */
function groq_chat(array $s, array $messages, array $extra = []): string
{
    $key = trim((string)($s['groq_api_key'] ?? ''));
    if ($key === '') throw new RuntimeException('No Groq API key. Add it in Settings.');
    $model = trim((string)($s['groq_model'] ?? '')) ?: SETTINGS_DEFAULTS['groq_model'];

    $payload = ['model' => $model, 'messages' => $messages] + $extra;
    if (str_starts_with($model, 'openai/gpt-oss')) $payload += ['reasoning_effort' => 'low'];

    $ch = curl_init(GROQ_CHAT_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT        => GROQ_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $netError = curl_error($ch);
    curl_close($ch);

    if ($raw === false) throw new RuntimeException("Could not reach Groq: $netError");
    $data = json_decode((string)$raw, true);
    if ($status !== 200) {
        $msg = $data['error']['message'] ?? substr((string)$raw, 0, 300);
        if ($status === 401) $msg = 'Groq refused the API key. Check it in Settings.';
        if ($status === 404 || ($status === 400 && stripos((string)$msg, 'model') !== false)) $msg .= " (model: $model, change it in Settings)";
        throw new RuntimeException("Groq error ($status): $msg");
    }
    $text = $data['choices'][0]['message']['content'] ?? null;
    if (!is_string($text) || trim($text) === '') throw new RuntimeException('Groq returned an empty reply. Try again.');
    return $text;
}

/**
 * Turns chat-style output into the portal's template format: [Your Name]-style blanks become
 * placeholders, "### Heading" and single *stars* go, bullets become "- ", extra blank lines go.
 * **bold** is kept (the mailer sends it in bold).
 */
function clean_template_text(string $t, bool $multiline = true): string
{
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    $blanks = [
        '/\[(?:your\s+)?(?:full\s+)?name\]/i'                                   => '{from_name}',
        '/\[your\s+(?:e-?mail)(?:\s+address)?\]/i'                              => '{from_email}',
        '/\[your\s+(?:phone|contact)(?:\s+number)?\]/i'                         => '{from_phone}',
        '/\[(?:recipient\'?s?\s+|their\s+|client\'?s?\s+)?(?:business|company|restaurant)(?:\s+name)?\]/i' => '{business_name}',
        '/\[(?:their\s+|business\s+)?(?:website|domain)(?:\s+url)?\]/i'         => '{website}',
        '/\[(?:their\s+)?(?:city|location|area)\]/i'                            => '{city}',
        '/\[(?:their\s+)?(?:category|industry|business\s+type)\]/i'             => '{category}',
    ];
    $t = preg_replace(array_keys($blanks), array_values($blanks), $t);
    if (!$multiline) return trim(preg_replace('/\s+/', ' ', preg_replace('/(?<!\*)\*(?!\*)|^#+\s*/', '', $t)));

    $t = preg_replace('/^[ \t]*#{1,6}[ \t]*(.+?)[ \t]*#*$/m', '**$1**', $t);          // headings -> bold line
    $t = preg_replace('/\*{4,}/', '**', $t);                                           // ****x**** from bold headings
    $t = preg_replace('/(?<![*\w])\*(?!\*)([^*\n]+?)(?<!\*)\*(?![*\w])/', '$1', $t);   // *italic* -> plain
    $t = preg_replace('/^[ \t]*(?:[•●▪◦‣]|\*(?!\*))[ \t]*/mu', '- ', $t);             // bullets
    $t = preg_replace('/[ \t]+$/m', '', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", $t);
    return trim($t);
}

/** Placeholder-looking text the mailer won't fill: unknown {names} and leftover [Blanks]. */
function template_unknown_placeholders(string $t): array
{
    preg_match_all('/\{[a-z_]+\}|\[(?:your|their|recipient|business|company)[^\]\n]{0,40}\]/i', $t, $m);
    return array_values(array_unique(array_diff($m[0], TEMPLATE_PLACEHOLDERS)));
}

// Signature every generated email ends with; the sender placeholders are filled by fill_template().
const EMAIL_SIGNATURE_RULE = 'End with this signature, each on its own line: a closing ("Best regards,"), {from_name}, "Founder | wpwebdesignings.com", {from_phone}, {from_email}, "Website: https://wpwebdesignings.com".';

// Our own site: its links (the signature) are left as written; every other link is shown as a bare domain.
const OWN_SITE_HOSTS = ['wpwebdesignings.com'];

/**
 * A business's link as it should read in an email: domain and path only, without https://, www.,
 * tracking (?utm_source=…) or #fragment. "https://www.tapntikkaaz.com/menu?utm_source=menu" -> "tapntikkaaz.com/menu".
 */
function display_url(string $url): string
{
    $url = trim($url);
    $p = parse_url(preg_match('~^https?://~i', $url) ? $url : "http://$url");
    if (empty($p['host'])) return $url;
    return preg_replace('/^www\./', '', strtolower($p['host'])) . rtrim($p['path'] ?? '', '/');
}

/** display_url() on every link in an email body, except links to OWN_SITE_HOSTS. */
function clean_email_links(string $text): string
{
    return preg_replace_callback('~\bhttps?://[^\s<>()\[\]"\']+~i', function ($m) {
        // Punctuation right after a link ("…/menu)." or "site.com,") isn't part of it.
        $url = rtrim($m[0], '.,;:!?');
        $tail = substr($m[0], strlen($url));
        $host = preg_replace('/^www\./', '', strtolower((string)parse_url($url, PHP_URL_HOST)));
        if ($host === '' || in_array($host, OWN_SITE_HOSTS, true)) return $m[0];
        return display_url($url) . $tail;
    }, $text);
}

const TEMPLATE_TONES =['friendly' => 'Friendly', 'professional' => 'Professional', 'short' => 'Short and direct', 'casual' => 'Casual', 'persuasive' => 'Persuasive'];
const TEMPLATE_LENGTHS = ['short' => 'Short (60–100 words)', 'medium' => 'Medium (120–180 words)', 'long' => 'Long (200–280 words)'];

/** Asks Groq for a new template. Returns ['name' => …, 'subject' => …, 'body' => …], already cleaned. */
function generate_email_template(array $s, string $brief, string $tone, string $length): array
{
    $tone = TEMPLATE_TONES[$tone] ?? TEMPLATE_TONES['friendly'];
    $length = TEMPLATE_LENGTHS[$length] ?? TEMPLATE_LENGTHS['medium'];
    $placeholders = implode(', ', TEMPLATE_PLACEHOLDERS);
    $signature = EMAIL_SIGNATURE_RULE;

    $system = <<<TXT
You write cold outreach emails that a web design agency sends to local businesses found on Google Maps.
Return ONLY a JSON object: {"name": "...", "subject": "...", "body": "..."}
- name: 2 to 5 words describing this template's angle, for a list of templates.
- subject: one line, under 80 characters, no emojis, not spammy (no ALL CAPS, no "FREE!!!").
- body: plain text email. Use \\n for line breaks and a blank line between paragraphs.

Placeholders (write them exactly, with the curly braces; they are filled in per business when sending):
$placeholders
- {business_name}: the business's name. {website}: its domain. {city}: its city. {category}: e.g. "Restaurant".
- {from_name}, {from_email}, {from_phone}: the sender. Never use [Your Name]-style blanks.
- Do not invent other placeholders.

Formatting rules for the body:
- No Markdown except **bold** on a few words (one line only). No # headings, no *single stars*, no links in [text](url) form.
- Lists use "- " at the start of the line.
- Greet with the business: e.g. "Hi {business_name} team,".
- {$signature}
- One clear call to action (a short call, or reply for a free quote).
- Don't claim things you can't know about the business (no fake compliments about their reviews or site).
TXT;

    $user = "Write a new template.\nTone: $tone\nLength of the body (not counting the signature): $length\n";
    if (trim($brief) !== '') $user .= "What to focus on:\n" . trim($brief) . "\n";
    $user .= "\nFor reference, this is the current default template. Write something clearly different in wording and angle, not a copy:\n\n"
        . $s['email_subject'] . "\n\n" . $s['email_body'];

    $reply = groq_chat($s, [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $user],
    ], ['temperature' => 0.9, 'max_completion_tokens' => 4000, 'response_format' => ['type' => 'json_object']]);

    // JSON mode should return a bare object; tolerate a fenced or wrapped one anyway.
    $json = json_decode($reply, true);
    if (!is_array($json) && preg_match('/\{.*\}/s', $reply, $m)) $json = json_decode($m[0], true);
    if (!is_array($json) || trim((string)($json['body'] ?? '')) === '' || trim((string)($json['subject'] ?? '')) === '') {
        throw new RuntimeException('Groq did not return a usable template. Try again.');
    }
    return [
        'name'    => mb_substr(clean_template_text((string)($json['name'] ?? ''), false) ?: 'Groq template', 0, 120),
        'subject' => mb_substr(clean_template_text((string)$json['subject'], false), 0, 255),
        'body'    => clean_template_text((string)$json['body']),
    ];
}

// ---------------------------------------------------------------------------
// One email written for one business from all its saved details (generate_email.php, Gmail sender).
// ---------------------------------------------------------------------------
const FREE_MAIL_DOMAINS = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com', 'live.com', 'msn.com', 'comcast.net', 'att.net', 'ymail.com', 'me.com'];

/** "Key: value" lines describing the business, for the prompt. Empty fields are left out. */
function business_facts(array $b): string
{
    $str = function ($v): string {
        if (is_string($v) && $v !== '' && ($v[0] === '[' || $v[0] === '{')) $v = json_decode($v, true) ?? $v;
        if (is_array($v)) {
            $parts = [];
            array_walk_recursive($v, function ($x, $k) use (&$parts) {
                if ($x === true) $parts[] = (string)$k;
                elseif (is_scalar($x) && $x !== false && trim((string)$x) !== '') $parts[] = is_string($k) ? "$k: $x" : (string)$x;
            });
            $v = implode(', ', $parts);
        }
        return trim(preg_replace('/\s+/', ' ', (string)$v));
    };
    $f = fn($k) => $b[$k] ?? '';
    $site = trim((string)$f('website'));
    $rating = (string)$f('rating') !== '' ? $f('rating') . ' stars' . ($f('review_count') ? ' from ' . number_format((int)$f('review_count')) . ' Google reviews' : '') : '';

    $facts = [
        'Business name'          => $f('name'),
        'Category'               => $f('category'),
        'Google rating'          => $rating,
        'Price'                  => $f('price_label') ?: $f('price_level'),
        'Address'                => $f('address') ?: implode(', ', array_filter([$f('street'), $f('city'), $f('state'), $f('postal_code')])),
        'City'                   => $f('city'),
        'Located in'             => $f('located_in'),
        'Phone'                  => $f('phone'),
        'Website'                => $site !== '' ? display_url($site) : 'NONE (no website listed on their Google profile)',
        // No HTTPS or email facts: the email is only about organic sales (see generate_business_email()).
        'Online menu link'       => $f('menu_url') ? display_url($f('menu_url')) : '',
        // Google's own booking pages (google.com/maps/reserve/…) mean nothing to the reader as a link.
        'Online booking link'    => !$f('reservation_url') ? ''
            : (preg_match('~^(?:https?://)?(?:www\.)?google\.[a-z.]+/maps/reserve~i', $f('reservation_url')) ? 'yes, through Google Maps' : display_url($f('reservation_url'))),
        'Google profile claimed' => $f('is_claimed'),
        'Hours'                  => $f('hours') ?: $f('hours_status'),
        'Status'                 => $f('business_status'),
        'Service options'        => $f('service_options'),
        // attributes_text often starts with its own "Highlights:" label.
        'Highlights'             => preg_replace('/^\s*Highlights:\s*/i', '', $str($f('attributes_text') ?: $f('attributes'))),
        'Accessibility'          => $f('accessibility'),
        'Review summary'         => $f('review_summary'),
    ];
    $lines = [];
    foreach ($facts as $k => $v) {
        $v = $str($v);
        if ($v !== '') $lines[] = "$k: " . mb_substr($v, 0, 600);
    }
    return implode("\n", $lines);
}

/**
 * Asks Groq for an email written for this business ($b = a businesses row). The sender placeholders
 * are filled from $s (smtp_from_name, smtp_user, sender_phone; override them in $s first).
 * Returns ['subject' => …, 'body' => …] ready to send. The body may contain **bold**, and every
 * detail taken from the business is marked [[like this]] (mailer.php shows it highlighted;
 * generate_email.php turns the marks into bold or drops them, as asked).
 */
function generate_business_email(array $s, array $b, string $brief = '', string $tone = 'friendly', string $length = 'medium'): array
{
    $tone = TEMPLATE_TONES[$tone] ?? TEMPLATE_TONES['friendly'];
    $length = TEMPLATE_LENGTHS[$length] ?? TEMPLATE_LENGTHS['medium'];
    $signature = EMAIL_SIGNATURE_RULE;

    $system = <<<TXT
You write a cold outreach email that a web design agency (wpwebdesignings.com) sends to ONE local business.
You get the facts we know about the business. Write the email for that business, not a generic template.
Return ONLY a JSON object: {"subject": "...", "body": "..."}
- subject: one line, under 70 characters, specific to the business (its name or what it does) and about getting more customers from Google, no emojis, not spammy (no ALL CAPS, no "FREE!!!").
- body: plain text email. Use \\n for line breaks and a blank line between paragraphs.

The pitch (the ONLY thing this email is about):
- We help the business get more customers and sales from organic search, with $0 spent on marketing or ads: people in their city searching Google for what they offer find them, see why they're good, and order, book, call or visit.
- Pick the one or two facts that best show this opportunity for THIS business and use them naturally. For example:
  - no website listed -> people searching for their kind of business in their city can't find them; a site that ranks brings those customers in for free.
  - they have a website -> help it show up higher on Google for searches like "<dish or service> in <city>", so more searchers become customers without paying for ads.
  - a strong rating with many reviews -> put that reputation to work so searchers choose them.
  - dishes, products or services people praise (highlights, review summary) -> get found for exactly those searches.
  - no online menu or booking link -> let searchers see the menu or book straight from Google.
  - Google profile not claimed -> claiming it is a free way to show up better.
- Talk about what the business cares about: more customers, orders, bookings and calls, at $0 ad spend. Never promise numbers, rankings or guarantees.
- Never mention HTTPS, SSL, security, "Not secure", site speed or their email address or email provider. Never criticise their current website.
- When they have a website, never write as if they don't.

Personalising:
- Greet the business by name ("Hi <name> team,"). Write the real name, city and details directly in the text.
- Only use facts from the list. Never invent anything (no owner names, no claims about how their current site looks, loads or ranks, no made-up numbers).
- Don't say you collected their data. "I came across <name> on Google" is fine.
- Never write the business's email address, or any part of it.

Formatting rules for the body:
- Wrap EVERY detail you take from the facts list in double square brackets, every time you use it, in the subject too: the business name, city, category, rating, number of reviews, price, website, menu or booking link, hours, service options, highlights and things from the review summary. Examples: "Hi [[Sarma Restaurant]] team", "your [[4.6-star]] rating from over [[2,800 reviews]]", "your [[tapas-style dishes]]", "people searching for [[fresh seafood]] in [[Somerville]]". Each dish, product, service or feature you name from the highlights or review summary is a detail too, marked on its own: "[[Black Sea cornbread]] and [[lamb sliders]]", "your [[creative cocktails]]". Wrap only the detail itself (a few words), never a whole sentence, and never the sender placeholders or the signature.
- Otherwise no Markdown except **bold** on one short key sentence at most. No # headings, no *single stars*, no links in [text](url) form.
- Write the business's website and links exactly as in the facts list (bare domain like "example.com" or "example.com/menu"): never add https://, www. or ?utm_source tracking.
- Lists use "- " at the start of the line (a short list of 2 to 4 items is fine, not required).
- The only placeholders allowed are {from_name}, {from_email} and {from_phone} (the sender), written exactly like that. Never use [Your Name]-style blanks or any other {placeholder}.
- {$signature}
- One clear call to action (a short call this week, or reply for a free plan to get more customers from Google).
TXT;

    $user = "Facts about the business:\n" . business_facts($b)
        . "\n\nTone: $tone\nLength of the body (not counting the signature): $length\n";
    if (trim($brief) !== '') $user .= "Extra instructions from the sender:\n" . trim($brief) . "\n";
    $user .= "\nWhat the agency does (our standard email, for reference only; keep the organic-sales pitch above, write something new and specific to this business, don't copy it):\n\n"
        . $s['email_subject'] . "\n\n" . $s['email_body'];

    // A reply that isn't usable, or leaves blanks the mailer can't fill, gets one more try.
    $lastError = 'Groq did not return a usable email. Try again.';
    for ($try = 1; $try <= 2; $try++) {
        $reply = groq_chat($s, [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ], ['temperature' => 0.8, 'max_completion_tokens' => 4000, 'response_format' => ['type' => 'json_object']]);

        $json = json_decode($reply, true);
        if (!is_array($json) && preg_match('/\{.*\}/s', $reply, $m)) $json = json_decode($m[0], true);
        if (!is_array($json) || trim((string)($json['body'] ?? '')) === '' || trim((string)($json['subject'] ?? '')) === '') continue;

        // Subjects have no formatting: the [[detail]] marks are dropped there.
        $subject = mb_substr(strip_fact_marks(fill_template(clean_template_text(tidy_fact_marks((string)$json['subject']), false), $b, $s)), 0, 255);
        $body = clean_email_links(fill_template(clean_template_text(tidy_fact_marks((string)$json['body'])), $b, $s));
        $unknown = template_unknown_placeholders($subject . "\n" . strip_fact_marks($body));
        if ($unknown) {
            $lastError = 'Groq left blanks that can\'t be filled in: ' . implode(', ', $unknown);
            continue;
        }
        // Never the business's own address (it isn't in the facts, but the model could still guess it).
        $text = strtolower($subject . "\n" . strip_fact_marks($body));
        if (array_filter(business_emails($b), fn($e) => strpos($text, strtolower($e)) !== false)) {
            $lastError = 'Groq wrote the business\'s email address in the email. Try again.';
            continue;
        }
        // Off-topic: HTTPS / SSL / "Not secure" (the "https://" of a link doesn't count).
        if (preg_match('~\bhttps\b(?!://)|\bssl\b|not secure~i', $text)) {
            $lastError = 'Groq wrote about HTTPS or security, which this email must not mention. Try again.';
            continue;
        }
        return ['subject' => $subject, 'body' => $body];
    }
    throw new RuntimeException($lastError);
}
