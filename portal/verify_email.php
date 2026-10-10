<?php
/*
 * Checks whether email addresses can receive mail. Nothing is sent: email_verify.php asks each
 * address's mail server and hangs up before a message. Results are saved in `email_checks`.
 *   ?email=a@b.com,c@d.com  check these addresses (max 5)
 *   ?business_id=N          check every address of that business
 *   ?next=N                 check the next N addresses that are due (1-5): never checked, or
 *                           `unknown` and due for a retry. Call again until `remaining` is 0.
 *                           Oldest business first, or picked at random with random=1.
 *   ?summary=1              only `counts` and `remaining`; nothing is checked
 * email / business_id return a saved result as it is, unless refresh=1.
 * Response: {ok, results: [{email, status, reason, mx_host, smtp_code, smtp_reply, attempts, checked_at, business_id?}],
 *            remaining, counts: {valid, invalid, risky, unknown}}
 */
require_once __DIR__ . '/email_verify.php';
check_api_key();

const MAX_PER_REQUEST = 5;

$in = $_GET + $_POST;
$refresh = !empty($in['refresh']);

try {
    ensure_email_checks_table();
    $todo = []; // [email, business_id|null]
    if (!empty($in['summary'])) {
        // counts only
    } elseif (($list = trim((string)($in['email'] ?? ''))) !== '') {
        $emails = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', strtolower($list))))));
        if (count($emails) > MAX_PER_REQUEST) json_out(['ok' => false, 'error' => 'At most ' . MAX_PER_REQUEST . ' addresses per request.'], 422);
        foreach ($emails as $e) $todo[] = ['email' => $e, 'business_id' => null];
    } elseif (($id = (int)($in['business_id'] ?? 0)) > 0) {
        $stmt = db()->prepare('SELECT id, email, emails FROM businesses WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) json_out(['ok' => false, 'error' => "No business with id $id."], 404);
        foreach (array_slice(row_emails($row), 0, MAX_PER_REQUEST) as $e) $todo[] = ['email' => $e, 'business_id' => $id];
        if (!$todo) json_out(['ok' => false, 'error' => 'This business has no email address.'], 422);
    } elseif (isset($in['next'])) {
        [$todo] = pending_email_checks(min(MAX_PER_REQUEST, max(1, (int)$in['next'])), !empty($in['random']));
        $refresh = true;
    } else {
        json_out(['ok' => false, 'error' => 'Pass email, business_id, next or summary.'], 422);
    }

    set_time_limit(30 + 60 * count($todo)); // a slow server can take ~45s to answer
    $saved = $refresh ? [] : email_checks(array_column($todo, 'email'));
    $results = [];
    foreach ($todo as $t) {
        $r = $saved[$t['email']] ?? verify_and_save($t['email']);
        if ($t['business_id']) $r['business_id'] = $t['business_id'];
        $r['smtp_code'] = $r['smtp_code'] === null ? null : (int)$r['smtp_code'];
        $r['attempts'] = (int)($r['attempts'] ?? 1);
        $results[] = $r;
    }

    [, $remaining] = pending_email_checks(0);
    $counts = array_fill_keys(VERIFY_STATUSES, 0);
    foreach (db()->query('SELECT status, COUNT(*) n FROM email_checks GROUP BY status') as $c) $counts[$c['status']] = (int)$c['n'];
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

json_out(['ok' => true, 'results' => $results, 'remaining' => $remaining, 'counts' => $counts]);
