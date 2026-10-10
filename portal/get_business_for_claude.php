<?php
/*
 * GET endpoint for the Claude email finder (find_emails_from_claude/).
 * Hands out ONE business that still has no email after both the Maps scrape and the ChatGPT
 * lookup (gpt_checked_at set: ChatGPT found none, or gave up after errors), and that Claude hasn't
 * checked yet. Claims it for `lease` minutes so the next call (or another worker) gets a different
 * one. Report the result with save_claude_email.php; a claim that is never reported expires and the
 * business comes back.
 * Picks at random, but businesses with fewer failed attempts come first.
 * Params (all optional): zipcode, category, lease (minutes, 1-120, default 10)
 */
require_once __DIR__ . '/db.php';
send_cors();
check_api_key();

const DEFAULT_LEASE_MINUTES = 10;
const JSON_COLUMNS = ['emails', 'hours_weekly', 'service_options', 'attributes', 'actions', 'rating_breakdown'];

$lease = min(120, max(1, (int)($_GET['lease'] ?? DEFAULT_LEASE_MINUTES)));

$where = [
    "(email IS NULL OR email = '')",
    'gpt_checked_at IS NOT NULL',
    'claude_checked_at IS NULL',
    "(business_status IS NULL OR business_status NOT LIKE '%permanently closed%')",
];
$params = [];
if (($z = trim($_GET['zipcode'] ?? '')) !== '') { $where[] = 'zipcode = :zipcode'; $params['zipcode'] = $z; }
if (($c = trim($_GET['category'] ?? '')) !== '') { $where[] = 'category = :category'; $params['category'] = $c; }
$open = implode(' AND ', $where);
$unclaimed = "(claude_claimed_at IS NULL OR claude_claimed_at < UTC_TIMESTAMP() - INTERVAL $lease MINUTE)";

try {
    $pdo = db();
    ensure_business_columns();

    // Claim and find the row in one statement: LAST_INSERT_ID(id) hands the claimed id back to
    // this connection, so two callers can never get the same business.
    $claim = $pdo->prepare("UPDATE businesses SET claude_claimed_at = UTC_TIMESTAMP(), id = LAST_INSERT_ID(id)
                            WHERE $open AND $unclaimed ORDER BY claude_attempts, RAND() LIMIT 1");
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

if ($row) {
    unset($row['raw_data']);
    foreach (JSON_COLUMNS as $c) {
        if ($row[$c] !== null) $row[$c] = json_decode($row[$c], true);
    }
    $row['is_sponsored'] = (bool)$row['is_sponsored'];
}

json_out(['ok' => true, 'remaining' => $remaining, 'lease_minutes' => $lease, 'business' => $row]);
