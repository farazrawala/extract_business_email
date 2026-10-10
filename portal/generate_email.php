<?php
/*
 * POST JSON {business_id, brief?, tone?, length?, highlight?, from_name?, from_email?, from_phone?}
 *   -> one email written by Groq for that business from everything saved about it. Nothing is
 *      sent or saved. Used by the Gmail sender (sending_email/) before it fills Gmail's compose box.
 * from_* override the sender details from Settings (the Gmail sender passes the Gmail account it
 * is signed in to). Like generate_template.php: JSON only and no CORS headers, so other websites
 * can't spend the Groq quota through a visitor's browser.
 */
require_once __DIR__ . '/groq.php';
check_api_key();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'Use POST.'], 405);
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    json_out(['ok' => false, 'error' => 'Send the body as JSON (Content-Type: application/json).'], 415);
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) json_out(['ok' => false, 'error' => 'Body is not valid JSON.'], 400);

$id = (int)($in['business_id'] ?? 0);
if ($id < 1) json_out(['ok' => false, 'error' => 'business_id is required.'], 422);
$brief = mb_substr(trim((string)($in['brief'] ?? '')), 0, 2000);
$tone = (string)($in['tone'] ?? 'friendly');
$length = (string)($in['length'] ?? 'medium');
// How the details taken from the business show: highlight (bold on yellow), bold, or none.
$highlight = in_array($in['highlight'] ?? '', ['highlight', 'bold', 'none'], true) ? $in['highlight'] : 'bold';

try {
    $s = get_settings();
    $stmt = db()->prepare('SELECT * FROM businesses WHERE id = ?');
    $stmt->execute([$id]);
    $biz = $stmt->fetch();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}
if (!$biz) json_out(['ok' => false, 'error' => "No business with id $id."], 404);

// Sender overrides (one line each). An invalid from_email is ignored.
foreach (['from_name' => 'smtp_from_name', 'from_phone' => 'sender_phone'] as $k => $setting) {
    $v = trim(preg_replace('/[\r\n]+/', ' ', (string)($in[$k] ?? '')));
    if ($v !== '') $s[$setting] = mb_substr($v, 0, 120);
}
$fromEmail = trim((string)($in['from_email'] ?? ''));
if ($fromEmail !== '' && filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $s['smtp_user'] = $fromEmail;

try {
    $mail = generate_business_email($s, $biz, $brief, $tone, $length);
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 502);
}

// Groq marks every detail it took from the business [[like this]].
$body = $mail['body'];
$facts = [];
preg_match_all(MAIL_FACT, $body, $m);
foreach ($m[1] as $f) if (!in_array($f, $facts, true)) $facts[] = $f;
if ($highlight === 'bold') $body = fact_marks_to_bold($body);
elseif ($highlight === 'none') $body = strip_fact_marks($body);

$emails = business_emails($biz);
json_out([
    'ok'          => true,
    'business_id' => $id,
    'name'        => $biz['name'],
    'to'          => implode(', ', $emails),
    'emails'      => $emails,
    'subject'     => $mail['subject'],
    'body'        => $body,                       // with **bold** (and [[highlight]]) markers
    'text'        => mail_plain_text($body),      // without them
    'html'        => mail_html_fragment($body),   // bold, highlights, links and line breaks, no page wrapper
    'facts'       => $facts,                      // the details from the business used in the email
    'highlight'   => $highlight,
    'model'       => $s['groq_model'],
]);
