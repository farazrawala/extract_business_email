<?php
/*
 * GET endpoint for the Gmail sender (sending_email/).
 * Hands out ONE business that has an email and was never mailed (no successful send from the
 * portal or from Gmail, for this business or this address), and whose email is verified
 * (status valid in verify_email.php), and claims it for `lease` minutes so
 * the next call (or another browser) gets a different one. Report the result with
 * save_email_result.php; a claim that is never reported expires and the business comes back.
 * Params (all optional): zipcode, category, source (website | chatgpt | claude), lease (minutes, 1-120, default 15)
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php'; // business_emails()
require_once __DIR__ . '/email_verify.php'; // email_checks()
send_cors();
check_api_key();

const DEFAULT_LEASE_MINUTES = 15;
const JSON_COLUMNS = ['emails', 'hours_weekly', 'service_options', 'attributes', 'actions', 'rating_breakdown'];

$lease = min(120, max(1, (int)($_GET['lease'] ?? DEFAULT_LEASE_MINUTES)));

$where = [
    "email IS NOT NULL AND email <> ''",
    'mail_sent_at IS NULL',
    "(mail_status IS NULL OR mail_status = 'error')", // 'failed' = gave up
    "(business_status IS NULL OR business_status NOT LIKE '%permanently closed%')",
    // Only verified addresses: unchecked, unknown, risky and invalid ones are never handed out.
    "EXISTS (SELECT 1 FROM email_checks c WHERE c.email = businesses.email AND c.status = 'valid')",
];
$params = [];
if (($z = trim($_GET['zipcode'] ?? '')) !== '') { $where[] = 'zipcode = :zipcode'; $params['zipcode'] = $z; }
if (($c = trim($_GET['category'] ?? '')) !== '') { $where[] = 'category = :category'; $params['category'] = $c; }
if (in_array($src = trim($_GET['source'] ?? ''), ['website', 'chatgpt', 'claude'], true)) { $where[] = 'email_source = :source'; $params['source'] = $src; }
$open = implode(' AND ', $where);
$unclaimed = "(mail_claimed_at IS NULL OR mail_claimed_at < UTC_TIMESTAMP() - INTERVAL $lease MINUTE)";

try {
    $pdo = db();
    ensure_business_columns();
    ensure_email_checks_table();

    // Claim and find the row in one statement: LAST_INSERT_ID(id) hands the claimed id back to
    // this connection, so two callers can never get the same business.
    $claim = $pdo->prepare("UPDATE businesses SET mail_claimed_at = UTC_TIMESTAMP(), id = LAST_INSERT_ID(id)
                            WHERE $open AND $unclaimed ORDER BY id LIMIT 1");
    $claim->execute($params);
    $row = null;
    if ($claim->rowCount() === 1) {
        $stmt = $pdo->prepare('SELECT * FROM businesses WHERE id = ?');
        $stmt->execute([(int)$pdo->lastInsertId()]);
        $row = $stmt->fetch() ?: null;
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM businesses WHERE $open AND $unclaimed");
    $count->execute($params);
    $remaining = (int)$count->fetchColumn();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

$recipients = [];
if ($row) {
    // Only the verified addresses (the main one always is). Extra addresses not checked yet are left out.
    $all = business_emails($row);
    $checks = email_checks($all);
    $recipients = array_values(array_filter($all, fn($e) => ($checks[strtolower($e)]['status'] ?? '') === 'valid'));
    unset($row['raw_data']);
    foreach (JSON_COLUMNS as $c) {
        if ($row[$c] !== null) $row[$c] = json_decode($row[$c], true);
    }
    $row['is_sponsored'] = (bool)$row['is_sponsored'];
}

json_out(['ok' => true, 'remaining' => $remaining, 'lease_minutes' => $lease, 'business' => $row, 'recipients' => $recipients]);
