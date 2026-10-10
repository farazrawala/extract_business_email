<?php
/*
 * POST endpoint for the Maps scraper (find_business/): report a ZIP from get_zipcode.php.
 * Body: { "id": 25304, "status": "done" | "failed" | "released", "scraped": 42 }
 *   done     -> status 2, finished_at, scraped
 *   failed   -> status 3, finished_at (not handed out again; set status back to 0 to retry)
 *   released -> status 4 (stopped), finished_at, scraped: the run was stopped before the ZIP finished.
 *               It is not handed out again either (set status back to 0 to scrape it again).
 *               Without `scraped`, the businesses saved for this ZIP are counted.
 */
require_once __DIR__ . '/db.php';
send_cors();
check_api_key();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok' => false, 'error' => 'Use POST'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    json_out(['ok' => false, 'error' => 'Body must be JSON'], 400);
}

$id = (int)($body['id'] ?? 0);
$status = (string)($body['status'] ?? '');
$scraped = isset($body['scraped']) && is_numeric($body['scraped']) ? max(0, (int)$body['scraped']) : null;
if ($id <= 0) json_out(['ok' => false, 'error' => 'Missing id'], 422);

$sets = [
    'done'     => ['status = ' . ZIP_DONE . ', finished_at = UTC_TIMESTAMP(), scraped = ?', [$scraped]],
    'failed'   => ['status = ' . ZIP_FAILED . ', finished_at = UTC_TIMESTAMP(), scraped = ?', [$scraped]],
    // Only a ZIP that is still in progress is marked stopped, so a late release can't undo a finished one.
    'released' => ['status = ' . ZIP_STOPPED . ', finished_at = UTC_TIMESTAMP(),
                   scraped = COALESCE(?, (SELECT COUNT(*) FROM businesses WHERE zip_id = zipcodes.id))', [$scraped]],
];
if (!isset($sets[$status])) {
    json_out(['ok' => false, 'error' => 'status must be done, failed or released'], 422);
}
[$set, $params] = $sets[$status];

try {
    $pdo = db();
    ensure_zipcode_columns();
    $find = $pdo->prepare('SELECT zipcode, status FROM zipcodes WHERE id = ?');
    $find->execute([$id]);
    $zip = $find->fetch();
    if (!$zip) json_out(['ok' => false, 'error' => "No ZIP with id $id"], 404);

    $where = $status === 'released' ? ' AND status = ' . ZIP_IN_PROGRESS : '';
    $stmt = $pdo->prepare("UPDATE zipcodes SET $set WHERE id = ?$where");
    $stmt->execute([...$params, $id]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

json_out(['ok' => true, 'id' => $id, 'zipcode' => $zip['zipcode'], 'status' => $status]);
