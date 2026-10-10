<?php
/*
 * POST endpoint for the extension.
 * Body: { "extension_version": "1.1.0", "records": [ {...}, ... ] }
 *       (a bare array of records, or a single record object, is also accepted)
 * The same business for the same zip_id is updated instead of duplicated, so retries are safe.
 * Each saved record is reported as `new` (a business not saved before) or `duplicate` (the same
 * business_key is already saved, for any ZIP) in `results`, with the totals in `new` and `duplicate`.
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

if (isset($body['records']) && is_array($body['records'])) {
    $records = $body['records'];
} elseif (array_is_list_compat($body)) {
    $records = $body;
} else {
    $records = [$body];
}
$batchVersion = (string)($body['extension_version'] ?? '');

if (!$records) json_out(['ok' => true, 'received' => 0, 'inserted' => 0, 'updated' => 0, 'new' => 0, 'duplicate' => 0, 'results' => []]);
if (count($records) > MAX_RECORDS_PER_REQUEST) {
    json_out(['ok' => false, 'error' => 'Too many records (max ' . MAX_RECORDS_PER_REQUEST . ')'], 413);
}

// Column => type. str = text, int, dec = decimal, json = array/object, bool, dt = ISO date.
const COLUMNS = [
    'name' => 'str', 'category' => 'str', 'rating' => 'dec', 'review_count' => 'int',
    'price_level' => 'str', 'price_label' => 'str', 'price_per_person' => 'str',
    'address' => 'str', 'street' => 'str', 'city' => 'str', 'state' => 'str',
    'postal_code' => 'str', 'country' => 'str',
    'phone' => 'str', 'phone_e164' => 'str', 'email' => 'str', 'emails' => 'json', 'website' => 'str', 'plus_code' => 'str',
    'located_in' => 'str', 'hours_status' => 'str', 'hours' => 'str', 'hours_weekly' => 'json',
    'business_status' => 'str', 'latitude' => 'dec', 'longitude' => 'dec',
    'google_maps_url' => 'str', 'place_id' => 'str', 'cid' => 'str', 'hex_id' => 'str',
    'cid_url' => 'str', 'service_options' => 'json', 'attributes_text' => 'str',
    'attributes' => 'json', 'accessibility' => 'str', 'menu_url' => 'str',
    'reservation_url' => 'str', 'actions' => 'json', 'image_url' => 'str',
    'review_summary' => 'str', 'rating_breakdown' => 'json', 'is_claimed' => 'str',
    'is_sponsored' => 'bool', 'zipcode' => 'str', 'zip_lat' => 'dec', 'zip_long' => 'dec',
    'search_keyword' => 'str', 'search_query' => 'str', 'scraped_at' => 'dt',
    'extension_version' => 'str',
];

// A re-scrape that finds no email keeps the one already saved (from an earlier scrape or ChatGPT).
const KEEP_IF_EMPTY = ['email', 'emails', 'email_source'];

$cols = array_merge(['business_key', 'zip_id'], array_keys(COLUMNS), ['email_source', 'raw_data']);
$updates = array_map(
    fn($c) => in_array($c, KEEP_IF_EMPTY, true) ? "`$c` = COALESCE(VALUES(`$c`), `$c`)" : "`$c` = VALUES(`$c`)",
    array_slice($cols, 2)
);
$sql = 'INSERT INTO businesses (`' . implode('`, `', $cols) . '`) VALUES (:' . implode(', :', $cols) . ')'
     . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);

$inserted = 0;
$updated = 0;
$new = 0;       // businesses not saved before (any ZIP)
$duplicate = 0; // already saved, for this ZIP or another
$results = [];  // [index, name, status: new | duplicate] per saved record
$errors = [];

try {
    $pdo = db();
    ensure_business_columns();
    $stmt = $pdo->prepare($sql);
    $exists = $pdo->prepare('SELECT 1 FROM businesses WHERE business_key = ? LIMIT 1');
    $pdo->beginTransaction();
    foreach ($records as $i => $r) {
        if (!is_array($r)) {
            $errors[] = ['index' => $i, 'error' => 'Record is not an object'];
            continue;
        }
        if (empty($r['extension_version']) && $batchVersion !== '') $r['extension_version'] = $batchVersion;
        $raw = $r; // raw_data keeps exactly what was sent

        // Clean addresses (URL-encoded junk, invalid ones); `email` falls back to the first good one in `emails`.
        if (isset($r['email']) || isset($r['emails'])) {
            $emails = clean_emails(array_merge((array)($r['email'] ?? []), clean_emails($r['emails'] ?? [])));
            $r['email'] = $emails[0] ?? null;
            $r['emails'] = $emails ?: null;
        }

        $params = [
            'business_key' => business_key($r),
            'zip_id'       => substr((string)($r['zip_id'] ?? ''), 0, 32),
        ];
        foreach (COLUMNS as $col => $type) {
            $params[$col] = convert($r[$col] ?? null, $type);
        }
        $params['email_source'] = $params['email'] !== null ? 'website' : null;
        $params['raw_data'] = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $exists->execute([$params['business_key']]);
            $seen = (bool)$exists->fetchColumn();
            $exists->closeCursor();
            $stmt->execute($params);
            // MySQL rowCount: 1 = inserted, 2 = updated, 0 = unchanged
            if ($stmt->rowCount() === 1) $inserted++; else $updated++;
            $seen ? $duplicate++ : $new++;
            $results[] = ['index' => $i, 'name' => (string)($r['name'] ?? ''), 'status' => $seen ? 'duplicate' : 'new'];
        } catch (PDOException $e) {
            $errors[] = ['index' => $i, 'name' => (string)($r['name'] ?? ''), 'error' => $e->getMessage()];
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

json_out([
    'ok'       => !$errors,
    'received' => count($records),
    'inserted' => $inserted,
    'updated'  => $updated,
    'new'      => $new,
    'duplicate' => $duplicate,
    'results'  => $results,
    'errors'   => $errors,
], $errors && !$inserted && !$updated ? 422 : 200);

// ---------------------------------------------------------------------------

function array_is_list_compat(array $a): bool
{
    return $a === [] || array_keys($a) === range(0, count($a) - 1);
}

function business_key(array $r): string
{
    foreach (['hex_id', 'place_id', 'cid'] as $k) {
        $v = trim((string)($r[$k] ?? ''));
        if ($v !== '') return substr("$k:$v", 0, 191);
    }
    $url = trim((string)($r['google_maps_url'] ?? ''));
    if ($url !== '') return 'url:' . md5($url);
    return 'name:' . md5(strtolower(trim(($r['name'] ?? '') . '|' . ($r['address'] ?? ''))));
}

function convert($v, string $type)
{
    if ($type === 'json') {
        if ($v === null || $v === '' || $v === [] ) return null;
        return is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($type === 'bool') {
        return filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (is_array($v)) $v = implode(' | ', array_map('strval', $v));
    if ($v === null) return null;
    $v = trim((string)$v);
    if ($v === '') return null;

    switch ($type) {
        case 'int':
            $n = preg_replace('/\D/', '', $v);
            return $n === '' ? null : (int)$n;
        case 'dec':
            return is_numeric($v) ? $v : null;
        case 'dt':
            $ts = strtotime($v);
            return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
        default:
            return $v;
    }
}
