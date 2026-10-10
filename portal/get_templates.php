<?php
/*
 * GET endpoint: the email templates saved on templates.php (the email_templates table).
 * Params (all optional):
 *   active=1  only templates that are On (the ones get_random_template.php picks from)
 *   id=N      just that template
 * Read-only: unlike get_random_template.php, it doesn't change times_used.
 */
require_once __DIR__ . '/db.php';
send_cors();
check_api_key();

$where = [];
$params = [];
if (!empty($_GET['active'])) $where[] = 'is_active = 1';
if (($id = (int)($_GET['id'] ?? 0)) > 0) { $where[] = 'id = ?'; $params[] = $id; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    $pdo = db();
    ensure_email_templates_table();
    $stmt = $pdo->prepare("SELECT id, name, subject, body, is_active, source, times_used, created_at, updated_at
                           FROM email_templates $whereSql ORDER BY id");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $counts = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(is_active), 0) AS active FROM email_templates')->fetch();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}
if ($id > 0 && !$rows) json_out(['ok' => false, 'error' => "No template with id $id"], 404);

foreach ($rows as &$r) {
    $r['id'] = (int)$r['id'];
    $r['is_active'] = (bool)$r['is_active'];
    $r['times_used'] = (int)$r['times_used'];
}
unset($r);

json_out([
    'ok'        => true,
    'total'     => (int)$counts['total'],   // all templates in the table
    'active'    => (int)$counts['active'],  // templates that are On
    'templates' => $rows,                   // the ones matching the filters
]);
