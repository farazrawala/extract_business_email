<?php
/*
 * Marketing email for one business, used by the "Email" button on index.php.
 *   GET  ?business_id=N  -> the template filled in for that business (preview, nothing is sent).
 *        `to` leaves out addresses that failed verification (`invalid`); `checks` has every saved result.
 *   POST JSON {business_id, to, subject?, body?} -> sends it over SMTP (settings.php) and logs it in email_log
 *        `to`: one address, several separated by commas, or an array. One email goes to all of them.
 *        `template_id` (optional): the email_templates row used; its times_used goes up when the send succeeds.
 *        `preview: true`: return the email as it would be sent (HTML and text) without sending or logging it.
 * POST must be JSON (Content-Type: application/json). There are no CORS headers, so other websites
 * can't make a visitor's browser send mail through the portal.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email_verify.php';
check_api_key();

const MAX_SUBJECT = 255;
const MAX_BODY = 20000;
const MAX_RECIPIENTS = 10;
const MAX_TO_CHARS = 255; // email_log.to_email

function last_sent(int $id): ?string
{
    $stmt = db()->prepare("SELECT MAX(sent_at) FROM email_log WHERE business_id = ? AND status = 'sent'");
    $stmt->execute([$id]);
    return $stmt->fetchColumn() ?: null;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
        json_out(['ok' => false, 'error' => 'Send the body as JSON (Content-Type: application/json).'], 415);
    }
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) json_out(['ok' => false, 'error' => 'Body is not valid JSON.'], 400);
} elseif ($method === 'GET') {
    $in = $_GET;
} else {
    json_out(['ok' => false, 'error' => 'Use GET or POST.'], 405);
}

$id = (int)($in['business_id'] ?? 0);
if ($id < 1) json_out(['ok' => false, 'error' => 'business_id is required.'], 422);

try {
    ensure_email_log_table();
    $s = get_settings();
    $stmt = db()->prepare('SELECT id, name, email, emails, website, city, category FROM businesses WHERE id = ?');
    $stmt->execute([$id]);
    $biz = $stmt->fetch();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}
if (!$biz) json_out(['ok' => false, 'error' => "No business with id $id."], 404);

$emails = business_emails($biz);

if ($method === 'GET') {
    $smtpError = null;
    try { smtp_check_settings($s); } catch (RuntimeException $e) { $smtpError = $e->getMessage(); }
    $checks = [];
    try {
        foreach (email_checks($emails) as $e => $c) $checks[$e] = ['status' => $c['status'], 'reason' => $c['reason'], 'checked_at' => $c['checked_at']];
    } catch (Throwable $e) { /* verification is optional */ }
    $invalid = array_values(array_filter($emails, fn($e) => ($checks[strtolower($e)]['status'] ?? '') === 'invalid'));
    json_out([
        'ok'          => true,
        'business_id' => $id,
        'name'        => $biz['name'],
        'to'          => implode(', ', array_diff($emails, $invalid)),
        'emails'      => $emails,
        'invalid'     => $invalid,   // left out of `to`
        'checks'      => (object)$checks, // lowercase email => {status, reason, checked_at}
        'from'        => $s['smtp_user'],
        'subject'     => fill_template($s['email_subject'], $biz, $s),
        'body'        => fill_template($s['email_body'], $biz, $s),
        'smtp_ready'  => $smtpError === null,
        'smtp_error'  => $smtpError,
        'last_sent'   => last_sent($id),
    ]);
}

// POST: send. `to` may list several addresses (commas, semicolons or spaces), or be an array.
$toList = is_array($in['to'] ?? null) ? $in['to'] : preg_split('/[\s,;]+/', (string)($in['to'] ?? ''));
$to = [];
$badTo = [];
foreach ($toList as $a) {
    $a = trim((string)$a);
    if ($a === '') continue;
    if (!filter_var($a, FILTER_VALIDATE_EMAIL)) { $badTo[] = $a; continue; }
    if (!in_array(strtolower($a), array_map('strtolower', $to), true)) $to[] = $a;
}
$toText = implode(', ', $to);
$subject = trim(preg_replace('/[\r\n]+/', ' ', (string)($in['subject'] ?? '')));
$body = trim((string)($in['body'] ?? ''));
if ($subject === '') $subject = $s['email_subject'];
if ($body === '') $body = $s['email_body'];
// Placeholders still in the text (e.g. an API caller sending the raw template) are filled too.
$subject = fill_template($subject, $biz, $s);
$body = fill_template($body, $biz, $s);

$errors = [];
if ($badTo) $errors[] = 'Not a valid email address: ' . implode(', ', $badTo) . '.';
elseif (!$to) $errors[] = '"to" needs at least one email address.';
elseif (count($to) > MAX_RECIPIENTS) $errors[] = 'Too many recipients (max ' . MAX_RECIPIENTS . ').';
elseif (strlen($toText) > MAX_TO_CHARS) $errors[] = 'The recipient list is longer than ' . MAX_TO_CHARS . ' characters.';
if (mb_strlen($subject) > MAX_SUBJECT) $errors[] = 'Subject is longer than ' . MAX_SUBJECT . ' characters.';
if (mb_strlen($body) > MAX_BODY) $errors[] = 'Message is longer than ' . MAX_BODY . ' characters.';
// Preview: the email exactly as it would be sent (same HTML and plain text), nothing sent or logged.
if (!empty($in['preview'])) {
    json_out([
        'ok'       => true,
        'preview'  => true,
        'from'     => trim((string)$s['smtp_from_name']) !== '' ? trim($s['smtp_from_name']) . ' <' . $s['smtp_user'] . '>' : $s['smtp_user'],
        'to'       => $to,
        'subject'  => $subject,
        'html'     => mail_html($body),
        'text'     => mail_plain_text($body),
        'warnings' => $errors, // what would stop a real send
    ]);
}

if ($errors) json_out(['ok' => false, 'error' => implode(' ', $errors)], 422);

$error = null;
try {
    smtp_send($s, $to, $subject, $body);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

try {
    db()->prepare("INSERT INTO email_log (business_id, to_email, subject, body, status, channel, error) VALUES (?, ?, ?, ?, ?, 'smtp', ?)")
        ->execute([$id, $toText, $subject, $body, $error === null ? 'sent' : 'failed', $error]);
    $logId = (int)db()->lastInsertId();
} catch (Throwable $e) {
    $logId = null; // the mail result matters more than the log
}

if ($error !== null) json_out(['ok' => false, 'error' => $error, 'log_id' => $logId], 502);

// The Gmail sender (get_business_to_email.php) skips this business and these addresses from now on.
try { mark_business_mailed($id, $to); } catch (Throwable $e) { /* the mail is sent */ }

if (($templateId = (int)($in['template_id'] ?? 0)) > 0) {
    try {
        ensure_email_templates_table();
        db()->prepare('UPDATE email_templates SET times_used = times_used + 1 WHERE id = ?')->execute([$templateId]);
    } catch (Throwable $e) { /* the mail is sent; the counter isn't worth an error */ }
}
json_out(['ok' => true, 'sent_to' => $toText, 'recipients' => $to, 'subject' => $subject, 'log_id' => $logId, 'sent_at' => gmdate('Y-m-d H:i:s')]);
