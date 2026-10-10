<?php
/*
 * GET endpoint for the Maps scraper (find_business/).
 * Hands out ONE pending ZIP from `zipcodes` (status 0, picked at random) and claims it (status 1)
 * in the same step. A claimed ZIP is never handed out again, so every call gets a new one.
 * Report the result with update_zipcode.php (done, failed, or released when the run stops).
 */
require_once __DIR__ . '/db.php';
send_cors();
check_api_key();

$open = 'status = ' . ZIP_PENDING;

try {
    $pdo = db();
    ensure_zipcode_columns();

    // Claim and find the row in one statement: LAST_INSERT_ID(id) hands the claimed id back to
    // this connection, so two callers can never get the same ZIP.
    $claim = $pdo->prepare('UPDATE zipcodes SET status = ' . ZIP_IN_PROGRESS . ", claimed_at = UTC_TIMESTAMP(),
                            id = LAST_INSERT_ID(id) WHERE $open ORDER BY RAND() LIMIT 1");
    $claim->execute();
    $zip = null;
    if ($claim->rowCount() === 1) {
        $stmt = $pdo->prepare('SELECT id, zipcode, lat, `long`, status, claimed_at FROM zipcodes WHERE id = ?');
        $stmt->execute([(int)$pdo->lastInsertId()]);
        $zip = $stmt->fetch() ?: null;
    }

    $remaining = (int)$pdo->query("SELECT COUNT(*) FROM zipcodes WHERE $open")->fetchColumn();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

json_out(['ok' => true, 'remaining' => $remaining, 'zip' => $zip]);
