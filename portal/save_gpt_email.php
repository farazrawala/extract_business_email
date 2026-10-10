<?php
/*
 * POST endpoint for the ChatGPT email finder (find_emails_from_gpt/).
 * Body: { "business_id": 11, "emails": ["a@b.com"], "answer": "...", "error": "", "release": false }
 *   - emails (or a single "email"): addresses ChatGPT gave. Invalid ones are dropped.
 *     At least one valid address => status "found", none => status "none". Both mark the business
 *     checked, so get_business_without_email.php won't hand it out again.
 *   - error: the lookup failed (timeout, ChatGPT error). The business comes back after its lease
 *     expires, until it has failed GPT_MAX_ATTEMPTS times.
 *   - release: true gives the business back right away without recording anything (run stopped).
 * Updates every row of the same business (same business_key, other ZIPs), and never replaces an
 * email that is already saved.
 */
require_once __DIR__ . '/db.php';
send_cors();
check_api_key();

const GPT_MAX_ATTEMPTS = 3;
const MAX_EMAILS_KEPT = 5;
const MAX_ANSWER_CHARS = 5000;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Use POST'], 405);
}
$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Body must be JSON'], 400);
}
$id = (int)($body['business_id'] ?? 0);
if ($id <= 0) {
    json_out(['ok' => false, 'error' => 'business_id is required'], 422);
}

$error = trim((string)($body['error'] ?? ''));
$answer = mb_substr(trim((string)($error !== '' ? $error : ($body['answer'] ?? ''))), 0, MAX_ANSWER_CHARS);

$given = array_merge((array)($body['email'] ?? []), is_array($body['emails'] ?? null) ? $body['emails'] : []);
$emails = array_slice(clean_emails($given), 0, MAX_EMAILS_KEPT); // db.php

try {
    $pdo = db();
    ensure_business_columns();
    $stmt = $pdo->prepare('SELECT id, business_key, name FROM businesses WHERE id = ?');
    $stmt->execute([$id]);
    $biz = $stmt->fetch();
    if (!$biz) json_out(['ok' => false, 'error' => "No business with id $id"], 404);

    if (!empty($body['release'])) {
        $pdo->prepare('UPDATE businesses SET gpt_claimed_at = NULL WHERE id = ? AND gpt_checked_at IS NULL')->execute([$id]);
        json_out(['ok' => true, 'business_id' => $id, 'status' => 'released']);
    }

    if ($error !== '') {
        // MySQL applies SET left to right, so gpt_checked_at sees the new gpt_attempts.
        $pdo->prepare("UPDATE businesses SET gpt_status = 'error', gpt_answer = :answer,
                           gpt_attempts = LEAST(gpt_attempts + 1, 255),
                           gpt_checked_at = IF(gpt_attempts >= :max, COALESCE(gpt_checked_at, UTC_TIMESTAMP()), gpt_checked_at)
                       WHERE id = :id")
            ->execute(['answer' => $answer, 'max' => GPT_MAX_ATTEMPTS, 'id' => $id]);
        $stmt = $pdo->prepare('SELECT gpt_attempts, gpt_checked_at FROM businesses WHERE id = ?');
        $stmt->execute([$id]);
        $after = $stmt->fetch();
        json_out([
            'ok' => true, 'business_id' => $id, 'status' => 'error',
            'attempts' => (int)$after['gpt_attempts'], 'gave_up' => $after['gpt_checked_at'] !== null,
        ]);
    }

    $status = $emails ? 'found' : 'none';
    // `email` is assigned last because the IF()s before it test the old value.
    $upd = $pdo->prepare("UPDATE businesses SET
            email_source = IF(email IS NULL OR email = '', IF(:found1, 'chatgpt', email_source), email_source),
            emails       = IF(email IS NULL OR email = '', COALESCE(:emails, emails), emails),
            email        = IF(email IS NULL OR email = '', COALESCE(:email, email), email),
            gpt_status = :status, gpt_answer = :answer,
            gpt_attempts = LEAST(gpt_attempts + 1, 255), gpt_checked_at = UTC_TIMESTAMP()
        WHERE business_key = :bk");
    $upd->execute([
        'found1' => $emails ? 1 : 0,
        'emails' => $emails ? json_encode($emails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        'email'  => $emails[0] ?? null,
        'status' => $status,
        'answer' => $answer,
        'bk'     => $biz['business_key'],
    ]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

json_out([
    'ok' => true, 'business_id' => $id, 'name' => $biz['name'], 'status' => $status,
    'email' => $emails[0] ?? null, 'emails' => $emails, 'rows_updated' => $upd->rowCount(),
]);
