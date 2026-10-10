<?php
/*
 * POST endpoint for the Gmail sender (sending_email/): what happened to a business from
 * get_business_to_email.php.
 * Body: { "business_id": 11, "status": "sent" | "error" | "released", "to": [...], "subject": "...",
 *         "body": "...", "from_email": "me@gmail.com", "error": "...", "give_up": false }
 *   - sent: logged in email_log (channel gmail) and the business is marked mailed on every row of the
 *     same business and every row with one of the addresses, so it's never handed out again.
 *   - error: the send failed. Logged in email_log as failed when a subject is given (an email was
 *     written). The business comes back after its lease, until it has failed MAIL_MAX_ATTEMPTS times
 *     (or at once with give_up: true, e.g. Gmail refused the address).
 *   - released: the claim is cleared right away and nothing is recorded (the run was stopped).
 * JSON only and no CORS headers, like send_email.php: other websites can't write to the log.
 */
require_once __DIR__ . '/db.php';
check_api_key();

const MAIL_MAX_ATTEMPTS = 3;
const MAX_SUBJECT = 255;
const MAX_BODY = 20000;
const MAX_TO_CHARS = 255; // email_log.to_email
const MAX_ERROR_CHARS = 2000;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'Use POST.'], 405);
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    json_out(['ok' => false, 'error' => 'Send the body as JSON (Content-Type: application/json).'], 415);
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) json_out(['ok' => false, 'error' => 'Body is not valid JSON.'], 400);

$id = (int)($in['business_id'] ?? 0);
if ($id < 1) json_out(['ok' => false, 'error' => 'business_id is required.'], 422);
$status = (string)($in['status'] ?? '');
if (!empty($in['release'])) $status = 'released';
if (!in_array($status, ['sent', 'error', 'released'], true)) {
    json_out(['ok' => false, 'error' => 'status must be sent, error or released.'], 422);
}

$toList = is_array($in['to'] ?? null) ? $in['to'] : preg_split('/[\s,;]+/', (string)($in['to'] ?? ''));
$to = clean_emails($toList); // db.php: valid, lowercased, no duplicates
$toText = mb_substr(implode(', ', $to), 0, MAX_TO_CHARS);
$subject = mb_substr(trim(preg_replace('/[\r\n]+/', ' ', (string)($in['subject'] ?? ''))), 0, MAX_SUBJECT);
$body = mb_substr((string)($in['body'] ?? ''), 0, MAX_BODY);
$from = clean_email($in['from_email'] ?? '');
$error = mb_substr(trim((string)($in['error'] ?? '')), 0, MAX_ERROR_CHARS);

if ($status === 'sent' && !$to) json_out(['ok' => false, 'error' => '"to" needs at least one valid email address.'], 422);
if ($status === 'error' && $error === '') $error = 'Unknown error';

try {
    $pdo = db();
    ensure_business_columns();
    ensure_email_log_table();
    $stmt = $pdo->prepare('SELECT id, name FROM businesses WHERE id = ?');
    $stmt->execute([$id]);
    $biz = $stmt->fetch();
    if (!$biz) json_out(['ok' => false, 'error' => "No business with id $id."], 404);

    if ($status === 'released') {
        $pdo->prepare('UPDATE businesses SET mail_claimed_at = NULL WHERE id = ? AND mail_sent_at IS NULL')->execute([$id]);
        json_out(['ok' => true, 'business_id' => $id, 'status' => 'released']);
    }

    $logId = null;
    if ($status === 'sent' || $subject !== '') {
        $pdo->prepare("INSERT INTO email_log (business_id, to_email, subject, body, status, channel, from_email, error)
                       VALUES (?, ?, ?, ?, ?, 'gmail', ?, ?)")
            ->execute([$id, $toText, $subject, $body, $status === 'sent' ? 'sent' : 'failed', $from, $status === 'sent' ? null : $error]);
        $logId = (int)$pdo->lastInsertId();
    }

    if ($status === 'sent') {
        $rows = mark_business_mailed($id, $to);
        json_out([
            'ok' => true, 'business_id' => $id, 'name' => $biz['name'], 'status' => 'sent',
            'sent_to' => $toText, 'log_id' => $logId, 'rows_updated' => $rows, 'sent_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // error. MySQL applies SET left to right, so mail_status sees the new mail_attempts.
    $pdo->prepare("UPDATE businesses SET mail_error = :error,
                       mail_attempts = LEAST(mail_attempts + 1, 255),
                       mail_status = IF(:give_up OR mail_attempts >= :max, 'failed', 'error')
                   WHERE id = :id AND mail_sent_at IS NULL")
        ->execute(['error' => $error, 'give_up' => empty($in['give_up']) ? 0 : 1, 'max' => MAIL_MAX_ATTEMPTS, 'id' => $id]);
    $stmt = $pdo->prepare('SELECT mail_attempts, mail_status FROM businesses WHERE id = ?');
    $stmt->execute([$id]);
    $after = $stmt->fetch();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

json_out([
    'ok' => true, 'business_id' => $id, 'name' => $biz['name'], 'status' => 'error', 'log_id' => $logId,
    'attempts' => (int)$after['mail_attempts'], 'gave_up' => $after['mail_status'] === 'failed',
]);
