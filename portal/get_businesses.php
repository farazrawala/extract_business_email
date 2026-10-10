<?php
/*
 * GET endpoint to read saved businesses.
 * Params (all optional): zipcode, category, q (name or email search), page (1+), limit (1-500)
 */
require_once __DIR__ . '/db.php';
send_cors();
check_api_key();

$where = [];
$params = [];
if (($z = trim($_GET['zipcode'] ?? '')) !== '') { $where[] = 'zipcode = :zipcode'; $params['zipcode'] = $z; }
if (($c = trim($_GET['category'] ?? '')) !== '') { $where[] = 'category = :category'; $params['category'] = $c; }
if (($q = trim($_GET['q'] ?? '')) !== '') { $where[] = '(name LIKE :q OR email LIKE :q2)'; $params['q'] = $params['q2'] = "%$q%"; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$limit = min(500, max(1, (int)($_GET['limit'] ?? 50)));
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

const JSON_COLUMNS = ['emails', 'hours_weekly', 'service_options', 'attributes', 'actions', 'rating_breakdown'];

try {
    $count = db()->prepare("SELECT COUNT(*) FROM businesses $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = db()->prepare("SELECT * FROM businesses $whereSql ORDER BY id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

foreach ($rows as &$row) {
    unset($row['raw_data']);
    foreach (JSON_COLUMNS as $c) {
        if ($row[$c] !== null) $row[$c] = json_decode($row[$c], true);
    }
    $row['is_sponsored'] = (bool)$row['is_sponsored'];
}

json_out(['ok' => true, 'total' => $total, 'page' => $page, 'limit' => $limit, 'data' => $rows]);
