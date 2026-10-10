<?php
/*
 * Browse all saved businesses.
 * Params (all optional): q (name/phone/address/website/email), zipcode, category, min_rating, sort, dir,
 *                        page, limit, export=csv (all matching rows, no paging), cols (CSV columns),
 *                        days (range of the "New leads per day" chart: 7, 14, 30 or 90; default 30),
 *                        check (email verification of the main address: valid, invalid, risky, unknown, unchecked)
 * Every column in the table is listed in COLUMNS; the "Columns" menu hides or shows them per
 * browser (saved in localStorage), and Export CSV exports only the visible ones.
 * When API_KEY is set, open the page with ?key=<value>; it is carried through every link.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_verify.php';

$key = (string)($_GET['key'] ?? '');
if (API_KEY !== '' && !hash_equals(API_KEY, $key)) {
    http_response_code(401);
    exit('Invalid API key. Open this page with ?key=<API_KEY>.');
}

// Column => [label, type]. Order here is the order in the table and the CSV.
// Types: text, long, num, count, rating, phone, email, url, image, json, bool, mono, date,
//        location (virtual: "City, ST", not a DB column).
// `name` is always shown; everything else can be toggled. raw_data is never shown.
const COLUMNS = [
    'id'                => ['#', 'num'],
    'name'              => ['Name', 'text'],
    'email'             => ['Email', 'email'],
    'website'           => ['Website', 'url'],
    'category'          => ['Category', 'text'],
    'rating'            => ['Rating', 'rating'],
    'review_count'      => ['Reviews', 'count'],
    'phone'             => ['Phone', 'phone'],
    'location'          => ['Location', 'location'],
    'zipcode'           => ['ZIP', 'text'],
    'scraped_at'        => ['Scraped', 'date'],
    'price_level'       => ['Price level', 'text'],
    'price_label'       => ['Price label', 'text'],
    'price_per_person'  => ['Price / person', 'text'],
    'phone_e164'        => ['Phone (E.164)', 'mono'],
    'emails'            => ['All emails', 'json'],
    'email_source'      => ['Email source', 'text'],
    'gpt_status'        => ['ChatGPT result', 'text'],
    'gpt_answer'        => ['ChatGPT answer', 'long'],
    'gpt_checked_at'    => ['ChatGPT checked (UTC)', 'date'],
    'claude_status'     => ['Claude result', 'text'],
    'claude_answer'     => ['Claude answer', 'long'],
    'claude_checked_at' => ['Claude checked (UTC)', 'date'],
    'address'           => ['Address', 'long'],
    'street'            => ['Street', 'text'],
    'city'              => ['City', 'text'],
    'state'             => ['State', 'text'],
    'postal_code'       => ['Postal code', 'text'],
    'country'           => ['Country', 'text'],
    'plus_code'         => ['Plus code', 'text'],
    'located_in'        => ['Located in', 'text'],
    'hours_status'      => ['Hours status', 'text'],
    'hours'             => ['Hours', 'long'],
    'hours_weekly'      => ['Weekly hours', 'json'],
    'business_status'   => ['Business status', 'text'],
    'latitude'          => ['Latitude', 'num'],
    'longitude'         => ['Longitude', 'num'],
    'google_maps_url'   => ['Google Maps', 'url'],
    'place_id'          => ['Place ID', 'mono'],
    'cid'               => ['CID', 'mono'],
    'hex_id'            => ['Hex ID', 'mono'],
    'cid_url'           => ['CID URL', 'url'],
    'service_options'   => ['Service options', 'json'],
    'attributes_text'   => ['Attributes (text)', 'long'],
    'attributes'        => ['Attributes', 'json'],
    'accessibility'     => ['Accessibility', 'long'],
    'menu_url'          => ['Menu', 'url'],
    'reservation_url'   => ['Reservations', 'url'],
    'actions'           => ['Actions', 'json'],
    'image_url'         => ['Image', 'image'],
    'review_summary'    => ['Review summary', 'long'],
    'rating_breakdown'  => ['Rating breakdown', 'json'],
    'is_claimed'        => ['Claimed', 'text'],
    'is_sponsored'      => ['Sponsored', 'bool'],
    'zip_id'            => ['ZIP id', 'mono'],
    'zip_lat'           => ['ZIP lat', 'num'],
    'zip_long'          => ['ZIP long', 'num'],
    'search_keyword'    => ['Search keyword', 'text'],
    'search_query'      => ['Search query', 'text'],
    'extension_version' => ['Extension', 'mono'],
    'business_key'      => ['Business key', 'mono'],
    'created_at'        => ['Created (UTC)', 'date'],
    'updated_at'        => ['Updated (UTC)', 'date'],
];
const FIXED_COLUMN = 'name';
// Shown until the viewer picks their own columns (and after Reset).
const DEFAULT_COLUMNS = ['email', 'website', 'category', 'rating', 'review_count', 'phone', 'location', 'zipcode', 'scraped_at'];
const SORT_EXPR = ['location' => 'city']; // virtual columns sort by a real one
const UNSORTABLE_TYPES = ['json', 'image'];
const ASC_FIRST_TYPES = ['text', 'long', 'mono', 'url', 'phone', 'email', 'location']; // first click sorts A→Z
const PAGE_SIZES = [25, 50, 100, 250, 500];

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
// Email verification (verify_email.php): badge text per status, and the "Email check" filter.
const CHECK_LABELS = ['valid' => '✓ Valid', 'invalid' => '✗ Invalid', 'risky' => '! Risky', 'unknown' => '? Unknown', 'none' => 'Not checked'];
const CHECK_FILTERS = ['valid' => 'Valid', 'invalid' => 'Invalid', 'risky' => 'Risky', 'unknown' => 'Unknown', 'unchecked' => 'Not checked yet'];
$f = [
    'q'          => trim((string)($_GET['q'] ?? '')),
    'zipcode'    => trim((string)($_GET['zipcode'] ?? '')),
    'category'   => trim((string)($_GET['category'] ?? '')),
    'min_rating' => is_numeric($_GET['min_rating'] ?? '') ? (float)$_GET['min_rating'] : '',
    'check'      => isset(CHECK_FILTERS[$_GET['check'] ?? '']) ? $_GET['check'] : '',
];
$sortReq = (string)($_GET['sort'] ?? '');
$sort = isset(COLUMNS[$sortReq]) && !in_array(COLUMNS[$sortReq][1], UNSORTABLE_TYPES, true) ? $sortReq : 'id';
$dir = strtolower($_GET['dir'] ?? '') === 'asc' ? 'asc' : 'desc';
$limit = in_array((int)($_GET['limit'] ?? 0), PAGE_SIZES, true) ? (int)$_GET['limit'] : 50;
$page = max(1, (int)($_GET['page'] ?? 1));
const CHART_DAYS = [7, 14, 30, 90];
$days = in_array((int)($_GET['days'] ?? 0), CHART_DAYS, true) ? (int)$_GET['days'] : 30;

$where = [];
$params = [];
if ($f['q'] !== '') {
    $where[] = '(name LIKE :q1 OR phone LIKE :q2 OR address LIKE :q3 OR website LIKE :q4 OR email LIKE :q5)';
    foreach (['q1', 'q2', 'q3', 'q4', 'q5'] as $p) $params[$p] = '%' . $f['q'] . '%';
}
if ($f['zipcode'] !== '') { $where[] = 'zipcode = :zipcode'; $params['zipcode'] = $f['zipcode']; }
if ($f['category'] !== '') { $where[] = 'category = :category'; $params['category'] = $f['category']; }
if ($f['min_rating'] !== '') { $where[] = 'rating >= :min_rating'; $params['min_rating'] = $f['min_rating']; }
if ($f['check'] === 'unchecked') {
    $where[] = "email IS NOT NULL AND email <> '' AND NOT EXISTS (SELECT 1 FROM email_checks c WHERE c.email = businesses.email)";
} elseif ($f['check'] !== '') {
    $where[] = 'EXISTS (SELECT 1 FROM email_checks c WHERE c.email = businesses.email AND c.status = :check)';
    $params['check'] = $f['check'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
// NULLs last in either direction, then id as a stable tiebreaker. $sortCol is a real column.
$sortCol = SORT_EXPR[$sort] ?? $sort;
$orderSql = "ORDER BY `$sortCol` IS NULL, `$sortCol` $dir, id DESC";

/** DB columns behind a list of COLUMNS keys (virtual "location" becomes city + state). */
function db_columns(array $keys): array
{
    $out = [];
    foreach ($keys as $c) {
        foreach (COLUMNS[$c][1] === 'location' ? ['city', 'state'] : [$c] as $d) $out[$d] = true;
    }
    return array_keys($out);
}
$allDbColumns = array_merge(db_columns(array_keys(COLUMNS)), ['street']);
$selectSql = '`' . implode('`, `', array_unique($allDbColumns)) . '`';

// ---------------------------------------------------------------------------
// CSV export (only the columns passed in `cols`, else all)
// ---------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $want = array_filter(explode(',', (string)($_GET['cols'] ?? '')));
    $keys = $want ? array_values(array_filter(array_keys(COLUMNS), fn($c) => in_array($c, $want, true))) : [];
    $cols = db_columns($keys ?: array_keys(COLUMNS));
    try {
        ensure_business_columns();
        ensure_email_checks_table();
        $stmt = db()->prepare('SELECT `' . implode('`, `', $cols) . "` FROM businesses $whereSql $orderSql");
        $stmt->execute($params);
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Database error: ' . $e->getMessage());
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="businesses-' . gmdate('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
    fputcsv($out, $cols);
    while ($row = $stmt->fetch()) fputcsv($out, $row);
    exit;
}

// ---------------------------------------------------------------------------
// Page data
// ---------------------------------------------------------------------------
$error = '';
$rows = [];
$sentAt = []; // business id => last sent_at (UTC)
$emailChecks = []; // lowercase email => email_checks row, for the addresses on this page
$checkCounts = array_fill_keys(VERIFY_STATUSES, 0); // all saved checks by status
$checkPending = 0; // addresses due for a check (never checked, or unknown and due for a retry)
$total = 0;
$stats = ['total' => 0, 'zips' => 0, 'categories' => 0, 'last' => null];
$zipOptions = [];
$categoryOptions = [];
$daily = []; // 'Y-m-d' (UTC) => ['n' => new businesses, 'e' => of those with an email], oldest first
try {
    $pdo = db();
    ensure_business_columns();
    ensure_email_checks_table();
    $stats = $pdo->query('SELECT COUNT(*) AS total, COUNT(DISTINCT zipcode) AS zips,
        COUNT(DISTINCT category) AS categories, MAX(updated_at) AS last FROM businesses')->fetch();
    $zipOptions = $pdo->query('SELECT zipcode, COUNT(*) n FROM businesses WHERE zipcode IS NOT NULL
        GROUP BY zipcode ORDER BY zipcode')->fetchAll();
    $categoryOptions = $pdo->query('SELECT category, COUNT(*) n FROM businesses WHERE category IS NOT NULL
        GROUP BY category ORDER BY category')->fetchAll();

    $count = $pdo->prepare("SELECT COUNT(*) FROM businesses $whereSql");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $pages = max(1, (int)ceil($total / $limit));
    $page = min($page, $pages);
    $offset = ($page - 1) * $limit;
    $stmt = $pdo->prepare("SELECT $selectSql FROM businesses $whereSql $orderSql LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // New businesses per UTC day (created_at) for the chart, same filters as the table.
    $since = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    for ($t = strtotime($since . ' UTC'), $i = 0; $i < $days; $i++, $t += 86400) {
        $daily[gmdate('Y-m-d', $t)] = ['n' => 0, 'e' => 0];
    }
    $daySql = ($whereSql ? "$whereSql AND" : 'WHERE') . ' created_at >= :since';
    $dayStmt = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) n, SUM(email IS NOT NULL AND email <> '') e
        FROM businesses $daySql GROUP BY d");
    $dayStmt->execute($params + ['since' => $since]);
    foreach ($dayStmt as $d) {
        if (isset($daily[$d['d']])) $daily[$d['d']] = ['n' => (int)$d['n'], 'e' => (int)$d['e']];
    }

    // Last successful marketing email per business on this page (send_email.php).
    $ids = array_column($rows, 'id');
    if ($ids) {
        ensure_email_log_table();
        $in = implode(',', array_map('intval', $ids));
        $sentAt = $pdo->query("SELECT business_id, MAX(sent_at) FROM email_log
            WHERE status = 'sent' AND business_id IN ($in) GROUP BY business_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    // Email verification: results for every address on this page, and the totals for the toolbar.
    $emailChecks = email_checks(array_merge([], ...array_map('row_emails', $rows)));
    foreach ($pdo->query('SELECT status, COUNT(*) n FROM email_checks GROUP BY status') as $c) $checkCounts[$c['status']] = (int)$c['n'];
    [, $checkPending] = pending_email_checks(0);
} catch (Throwable $e) {
    $error = $e->getMessage();
    if (stripos($error, "doesn't exist") !== false) $error .= ' — open setup.php once to create the table.';
}
$pages = max(1, (int)ceil($total / $limit));

foreach ($rows as &$row) {
    foreach (COLUMNS as $c => [, $type]) {
        if ($type === 'json' && $row[$c] !== null) $row[$c] = json_decode($row[$c], true);
    }
}
unset($row);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function h($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Current query string with overrides; null removes a param. */
/** Verification badge for an address (verify_email.php): its status, or "Not checked". */
function check_badge(string $email): string
{
    global $emailChecks;
    $e = strtolower(trim($email));
    $c = $emailChecks[$e] ?? null;
    if (!$c) return '<span class="check none" data-email="' . h($e) . '" title="Not verified yet. Click Verify, or run Auto verify.">' . CHECK_LABELS['none'] . '</span>';
    $title = $c['reason'] . ' Checked ' . $c['checked_at'] . ' UTC.' . ($c['smtp_reply'] ? ' Server: ' . $c['smtp_reply'] : '');
    return '<span class="check ' . h($c['status']) . '" data-email="' . h($e) . '" title="' . h($title) . '">' . h(CHECK_LABELS[$c['status']] ?? $c['status']) . '</span>';
}

/** "2 valid · 1 unknown · 1 not checked" for these addresses (the same text checkSummary() in the page script writes). */
function check_summary(array $emails): string
{
    global $emailChecks;
    $n = array_fill_keys(array_keys(CHECK_LABELS), 0);
    foreach ($emails as $e) $n[$emailChecks[strtolower(trim((string)$e))]['status'] ?? 'none']++;
    $parts = [];
    foreach ($n as $status => $count) {
        if ($count) $parts[] = '<span class="s-' . $status . '">' . $count . ' ' . ($status === 'none' ? 'not checked' : $status) . '</span>';
    }
    return implode(' · ', $parts);
}

function url(array $over = []): string
{
    global $f, $sort, $dir, $limit, $page, $key, $days;
    $q = array_merge($f, ['sort' => $sort, 'dir' => $dir, 'limit' => $limit, 'page' => $page, 'key' => $key,
                          'days' => $days === 30 ? null : $days], $over);
    $q = array_filter($q, fn($v) => $v !== null && $v !== '');
    if (($q['sort'] ?? '') === 'id' && ($q['dir'] ?? '') === 'desc') unset($q['sort'], $q['dir']);
    if (($q['limit'] ?? 0) === 50) unset($q['limit']);
    if (($q['page'] ?? 0) === 1) unset($q['page']);
    return '?' . http_build_query($q);
}

function header_cell(string $col): string
{
    global $sort, $dir;
    [$label, $type] = COLUMNS[$col];
    if (in_array($type, UNSORTABLE_TYPES, true)) return h($label);
    $active = $sort === $col;
    $next = $active ? ($dir === 'desc' ? 'asc' : 'desc') : (in_array($type, ASC_FIRST_TYPES, true) ? 'asc' : 'desc');
    $arrow = $active ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a class="sort' . ($active ? ' on' : '') . '" href="' . h(url(['sort' => $col, 'dir' => $next, 'page' => 1])) . '">'
        . h($label) . $arrow . '</a>';
}

function safe_href($u): string
{
    $u = trim((string)$u);
    return preg_match('#^https?://#i', $u) ? h($u) : '';
}

function short_url($u): string
{
    $s = rtrim(preg_replace('#^https?://(www\.)?#i', '', (string)$u), '/');
    return strlen($s) > 40 ? substr($s, 0, 38) . '…' : $s;
}

/** Render a decoded JSON value (scalar, list or map) as HTML. */
function render_value($v): string
{
    if (is_bool($v)) return $v ? 'Yes' : 'No';
    if (!is_array($v)) return h($v);
    if (!$v) return '<span class="muted">—</span>';
    $isList = array_keys($v) === range(0, count($v) - 1);
    if ($isList && !array_filter($v, 'is_array')) {
        return implode('', array_map(fn($x) => '<span class="chip">' . h(is_bool($x) ? ($x ? 'Yes' : 'No') : $x) . '</span>', $v));
    }
    $html = '<dl class="kv">';
    foreach ($v as $k => $x) {
        $html .= ($isList ? '' : '<dt>' . h($k) . '</dt>') . '<dd>' . render_value($x) . '</dd>';
    }
    return $html . '</dl>';
}

function cell(string $col, array $r): string
{
    $type = COLUMNS[$col][1];
    if ($type === 'location') {
        $loc = implode(', ', array_filter([$r['city'], $r['state']])) ?: $r['street'];
        return $loc ? h($loc) : '<span class="muted">—</span>';
    }
    $v = $r[$col];
    if ($col === 'name') {
        $name = h($v ?: '(no name)');
        $maps = safe_href($r['google_maps_url']);
        $html = '<div class="name-cell"><button class="toggle" type="button" aria-label="Show details" aria-expanded="false">▶</button><div>'
            . '<span class="name">' . ($maps ? "<a href=\"$maps\" target=\"_blank\" rel=\"noopener\">$name</a>" : $name) . '</span>';
        if ($r['is_sponsored']) $html .= '<span class="tag">Sponsored</span>';
        if ($r['business_status'] && stripos($r['business_status'], 'closed') !== false) $html .= '<span class="tag">' . h($r['business_status']) . '</span>';
        if ($r['hours_status']) $html .= '<div class="sub">' . h($r['hours_status']) . '</div>';
        return $html . '</div></div>';
    }
    if ($type === 'bool') return $v ? 'Yes' : '<span class="muted">No</span>';
    if ($v === null || $v === '' || $v === []) return '<span class="muted">—</span>';
    switch ($type) {
        case 'rating': return '<span class="rating">★ ' . h(number_format((float)$v, 1)) . '</span>';
        case 'count':  return h(number_format((int)$v));
        case 'phone':
            $tel = $r['phone_e164'] ?: preg_replace('/[^\d+]/', '', $v);
            return '<a href="tel:' . h($tel) . '">' . h($v) . '</a>';
        case 'email':
            global $sentAt, $emailChecks;
            $others = array_values(array_filter(is_array($r['emails'] ?? null) ? $r['emails'] : [],
                fn($e) => strcasecmp(trim((string)$e), trim((string)$v)) !== 0));
            $moreHtml = '';
            if ($others) {
                $items = implode('', array_map(fn($e) => '<li><a href="mailto:' . h($e) . '">' . h($e) . '</a>' . check_badge($e) . '</li>', $others));
                $moreHtml = '<details class="more-emails"><summary title="Show the other addresses and their verification">+' . count($others) . ' more'
                    . ' <span class="more-sum">' . check_summary($others) . '</span></summary><ul>' . $items . '</ul></details>';
            }
            $sent = $sentAt[$r['id']] ?? null;
            return '<a href="mailto:' . h($v) . '">' . h($v) . '</a>' . check_badge($v)
                . (($r['email_source'] ?? '') === 'chatgpt' ? '<span class="tag" title="Found by ChatGPT. Check it before sending.">ChatGPT</span>' : '')
                . (($r['email_source'] ?? '') === 'claude' ? '<span class="tag" title="Found by Claude. Check it before sending.">Claude</span>' : '')
                . $moreHtml
                . '<div class="email-actions"><button type="button" class="send-email" data-id="' . (int)$r['id'] . '">&#9993; Email</button>'
                . '<button type="button" class="verify-email" data-id="' . (int)$r['id'] . '" title="Ask the mail server whether this address exists (nothing is sent)">Verify</button>'
                . '<span class="sent"' . ($sent ? ' title="Last sent ' . h($sent) . ' UTC"' : ' hidden') . '>Sent ' . h(substr((string)$sent, 0, 10)) . '</span></div>';
        case 'url':
            $u = safe_href($v);
            return $u ? "<a href=\"$u\" target=\"_blank\" rel=\"noopener\" title=\"$u\">" . h(short_url($v)) . '</a>' : h($v);
        case 'image':
            $u = safe_href($v);
            return $u ? "<a href=\"$u\" target=\"_blank\" rel=\"noopener\"><img class=\"thumb\" src=\"$u\" alt=\"\" loading=\"lazy\" referrerpolicy=\"no-referrer\"></a>" : h($v);
        case 'json':   return '<div class="clip">' . render_value($v) . '</div>';
        case 'long':   return '<div class="clip">' . h($v) . '</div>';
        case 'mono':   return '<span class="mono">' . h($v) . '</span>';
        case 'date':   return '<span class="sub">' . h(substr((string)$v, 0, 16)) . '</span>';
        default:       return h($v);
    }
}

// Shown in the row's expandable details panel.
$detailFields = [
    'Address'          => fn($r) => $r['address'],
    'Located in'       => fn($r) => $r['located_in'],
    'Plus code'        => fn($r) => $r['plus_code'],
    'Phone (E.164)'    => fn($r) => $r['phone_e164'],
    'Status'           => fn($r) => $r['business_status'],
    'Price'            => fn($r) => implode(' · ', array_filter([$r['price_level'], $r['price_label'], $r['price_per_person']])),
    'Hours'            => fn($r) => $r['hours_status'],
    'Weekly hours'     => fn($r) => $r['hours_weekly'],
    'Service options'  => fn($r) => $r['service_options'],
    'Attributes'       => fn($r) => $r['attributes'] ?: $r['attributes_text'],
    'Accessibility'    => fn($r) => $r['accessibility'],
    'Review summary'   => fn($r) => $r['review_summary'],
    'Rating breakdown' => fn($r) => $r['rating_breakdown'],
    'Claimed'          => fn($r) => $r['is_claimed'],
    'Sponsored'        => fn($r) => $r['is_sponsored'] ? 'Yes' : null,
    'Coordinates'      => fn($r) => $r['latitude'] !== null ? $r['latitude'] . ', ' . $r['longitude'] : null,
    'Place ID'         => fn($r) => $r['place_id'],
    'CID'              => fn($r) => $r['cid'],
    'Hex ID'           => fn($r) => $r['hex_id'],
    'Search'           => fn($r) => $r['search_query'],
    'ZIP id'           => fn($r) => $r['zip_id'],
    'Extension'        => fn($r) => $r['extension_version'],
    'Scraped (UTC)'    => fn($r) => $r['scraped_at'],
    'Saved / updated'  => fn($r) => $r['created_at'] . ' / ' . $r['updated_at'],
];
$linkFields = ['menu_url' => 'Menu', 'reservation_url' => 'Reservations', 'cid_url' => 'CID link', 'google_maps_url' => 'Google Maps'];

$cellClass = [
    'num' => 'num', 'count' => 'num', 'rating' => 'num', 'phone' => 'num', 'date' => 'num',
    'long' => 'wide', 'json' => 'wide', 'mono' => 'nowrap',
];

$from = $total ? ($page - 1) * $limit + 1 : 0;
$to = min($total, $page * $limit);
$filtered = $f['q'] !== '' || $f['zipcode'] !== '' || $f['category'] !== '' || $f['min_rating'] !== '' || $f['check'] !== '';
$toggleable = array_values(array_filter(array_keys(COLUMNS), fn($c) => $c !== FIXED_COLUMN));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Business Listings</title>
<style>
:root {
  --bg: #f6f7f9; --panel: #fff; --text: #1d2330; --muted: #6b7280; --line: #e3e6eb;
  --accent: #2f6fdf; --accent-soft: #e8f0fd; --star: #c27c0e; --row-hover: #f9fafc;
  --error-bg: #fdecec; --error: #a12828; --shadow: 0 8px 28px rgba(20, 30, 50, .14);
  --ok-bg: #e7f5ec; --ok: #1d6b3a; --chart-bar: #2f6fdf; --warn-bg: #fdf3e1; --warn: #8a5a00;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #12151b; --panel: #1a1e26; --text: #e5e8ee; --muted: #959cab; --line: #2b313c;
    --accent: #6ea2ff; --accent-soft: #1f2b44; --star: #f0b54a; --row-hover: #20252f;
    --error-bg: #3a1d1d; --error: #ffb3b3; --shadow: 0 8px 28px rgba(0, 0, 0, .5);
    --ok-bg: #173323; --ok: #9fe0b5; --chart-bar: #4f8cf0; --warn-bg: #3a2c12; --warn: #f3c674;
  }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
.wrap { max-width: 1400px; margin: 0 auto; padding: 20px 16px 40px; }
header { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 8px 16px; margin-bottom: 16px; }
h1 { font-size: 22px; margin: 0; }
header .btn { margin-right: auto; }
.stats { display: flex; flex-wrap: wrap; gap: 6px 18px; color: var(--muted); }
.stats b { color: var(--text); font-variant-numeric: tabular-nums; }
form.filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: end; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 12px; margin-bottom: 12px; }
.field { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.field label { font-size: 12px; color: var(--muted); }
.field.grow { flex: 1 1 220px; }
input, select, button, .btn { font: inherit; color: var(--text); background: var(--bg); border: 1px solid var(--line); border-radius: 7px; padding: 6px 9px; max-width: 100%; }
button, .btn { cursor: pointer; background: var(--panel); white-space: nowrap; display: inline-flex; align-items: center; gap: 6px; }
button.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.btn:hover, button:hover { text-decoration: none; border-color: var(--accent); }
.bar { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; margin: 8px 0; color: var(--muted); }
.actions { display: flex; gap: 8px; align-items: center; }

/* Columns menu */
.colmenu { position: relative; }
.colmenu .panel { display: none; position: absolute; right: 0; top: calc(100% + 6px); z-index: 20; width: 280px; max-width: calc(100vw - 32px); background: var(--panel); border: 1px solid var(--line); border-radius: 10px; box-shadow: var(--shadow); color: var(--text); }
.colmenu.open .panel { display: block; }
.panel-head { display: flex; justify-content: space-between; align-items: center; padding: 12px 14px 8px; font-weight: 600; }
.panel-head .link { font-weight: 400; font-size: 13px; background: none; border: 0; padding: 0; color: var(--accent); }
.panel-search { padding: 0 14px 8px; }
.panel-search input { width: 100%; }
.panel-list { max-height: 340px; overflow-y: auto; padding: 0 6px 6px; }
.panel-list label { display: flex; align-items: center; gap: 9px; padding: 5px 8px; border-radius: 6px; cursor: pointer; }
.panel-list label:hover { background: var(--row-hover); }
.panel-list input { accent-color: var(--accent); width: 16px; height: 16px; margin: 0; }
.panel-foot { display: flex; justify-content: space-between; padding: 8px 14px 12px; border-top: 1px solid var(--line); font-size: 13px; }
.panel-foot button { background: none; border: 0; padding: 0; color: var(--accent); }
.badge { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }

/* Table */
.table-wrap { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; overflow-x: auto; }
table { border-collapse: separate; border-spacing: 0; width: 100%; }
th, td { padding: 9px 10px; text-align: left; vertical-align: top; border-bottom: 1px solid var(--line); background: var(--panel); }
th { font-size: 12px; font-weight: 600; color: var(--muted); white-space: nowrap; position: sticky; top: 0; z-index: 2; }
th a.sort { color: inherit; }
th a.sort.on { color: var(--text); }
/* Name stays visible while scrolling sideways (when many columns are on) */
th[data-col="name"], td[data-col="name"] { position: sticky; left: 0; z-index: 1; min-width: 200px; max-width: 280px; }
th[data-col="name"] { z-index: 3; padding-left: 34px; }
tr.main { cursor: pointer; }
tr.main:hover td { background: var(--row-hover); }
.name-cell { display: flex; gap: 6px; align-items: flex-start; }
.toggle { border: 0; background: none; padding: 2px 4px 0; color: var(--muted); font-size: 11px; transition: transform .15s; }
tr.main.open .toggle { transform: rotate(90deg); }
tr.detail { display: none; }
tr.detail.open { display: table-row; }
tr.detail > td { background: var(--bg); padding: 14px 16px; max-width: none; }
.detail-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 12px 24px; max-width: 1340px; }
.detail-grid .item { min-width: 0; overflow-wrap: anywhere; }
.detail-grid .item h4 { margin: 0 0 3px; font-size: 12px; font-weight: 600; color: var(--muted); }
.detail-img { max-width: 240px; max-height: 160px; border-radius: 8px; display: block; }
td.num { font-variant-numeric: tabular-nums; white-space: nowrap; }
td.nowrap { white-space: nowrap; }
td.wide { min-width: 220px; max-width: 340px; }
td { max-width: 260px; overflow-wrap: anywhere; }
.clip { max-height: 120px; overflow-y: auto; }
.name { font-weight: 600; }
.sub { color: var(--muted); font-size: 12px; }
.mono { font-family: ui-monospace, Consolas, monospace; font-size: 12px; }
.rating { color: var(--star); font-weight: 600; }
.muted { color: var(--muted); }
.tag { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--accent-soft); color: var(--accent); margin-left: 4px; vertical-align: 1px; }
.thumb { width: 56px; height: 42px; object-fit: cover; border-radius: 5px; display: block; }
.chip { display: inline-block; padding: 1px 8px; margin: 0 4px 4px 0; border: 1px solid var(--line); border-radius: 999px; font-size: 12px; }
dl.kv { margin: 0; display: grid; grid-template-columns: max-content 1fr; gap: 2px 10px; font-size: 13px; }
dl.kv dt { color: var(--muted); }
dl.kv dd { margin: 0; }
.pager { display: flex; flex-wrap: wrap; gap: 4px; }
.pager a, .pager span { padding: 4px 10px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); }
.pager span.cur { background: var(--accent); border-color: var(--accent); color: #fff; }
.pager span.gap { border: 0; background: none; }
.empty { padding: 40px 16px; text-align: center; color: var(--muted); }
.error { background: var(--error-bg); color: var(--error); border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }

/* New leads per day */
.chart-card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 14px 16px 10px; margin-bottom: 12px; }
.chart-head { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 8px 24px; margin-bottom: 6px; }
.chart-head h2 { font-size: 15px; margin: 0; }
.chart-figs { display: flex; flex-wrap: wrap; gap: 4px 18px; color: var(--muted); margin-right: auto; }
.chart-figs b { color: var(--text); font-size: 18px; font-variant-numeric: tabular-nums; margin-right: 3px; }
.seg { display: inline-flex; border: 1px solid var(--line); border-radius: 7px; overflow: hidden; }
.seg a { padding: 3px 10px; color: var(--muted); font-size: 13px; }
.seg a + a { border-left: 1px solid var(--line); }
.seg a.on { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
.seg a:hover { text-decoration: none; color: var(--text); }
.chart { position: relative; height: 190px; }
.chart svg { display: block; width: 100%; height: 100%; overflow: visible; }
.chart .grid { stroke: var(--line); stroke-width: 1; }
.chart .axis-text { fill: var(--muted); font-size: 11px; font-variant-numeric: tabular-nums; }
.chart .value-text { fill: var(--text); font-size: 11px; font-weight: 600; font-variant-numeric: tabular-nums; }
.chart .mark, .chart text { pointer-events: none; }
.chart .mark { fill: var(--chart-bar); }
.chart .hit { fill: transparent; }
.chart .hit.on { fill: var(--row-hover); }
.chart-tip { position: absolute; pointer-events: none; background: var(--panel); border: 1px solid var(--line); border-radius: 8px; box-shadow: var(--shadow); padding: 6px 10px; font-size: 12px; white-space: nowrap; transform: translate(-50%, calc(-100% - 8px)); display: none; z-index: 5; }
.chart-tip b { font-variant-numeric: tabular-nums; }
.chart-empty { display: flex; align-items: center; justify-content: center; height: 100%; color: var(--muted); }
details.chart-table { margin-top: 4px; font-size: 13px; }
details.chart-table summary { cursor: pointer; color: var(--muted); width: max-content; }
details.chart-table .table-wrap { max-height: 260px; overflow-y: auto; margin-top: 6px; }
details.chart-table td, details.chart-table th { padding: 5px 10px; }

/* Email button + dialog */
.email-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin-top: 4px; }
.send-email { font-size: 12px; padding: 2px 9px; border-radius: 999px; color: var(--accent); border-color: var(--accent); }
.verify-email { font-size: 12px; padding: 2px 9px; border-radius: 999px; color: var(--muted); }
.check { display: inline-block; font-size: 11px; padding: 1px 6px; border-radius: 4px; margin-left: 4px; vertical-align: 1px; white-space: nowrap; cursor: help; }
.check.valid { background: var(--ok-bg); color: var(--ok); }
.check.invalid { background: var(--error-bg); color: var(--error); }
.check.risky { background: var(--warn-bg); color: var(--warn); }
.check.unknown { background: var(--bg); color: var(--muted); border: 1px solid var(--line); }
.check.none { color: var(--muted); border: 1px dashed var(--line); }
.more-emails { margin-top: 3px; font-size: 12px; }
.more-emails summary { cursor: pointer; color: var(--muted); width: max-content; max-width: 100%; }
.more-emails ul { list-style: none; margin: 4px 0 0; padding: 0 0 0 10px; border-left: 2px solid var(--line); display: grid; gap: 3px; }
.more-emails li { overflow-wrap: anywhere; }
.more-sum .s-valid { color: var(--ok); }
.more-sum .s-invalid { color: var(--error); }
.more-sum .s-risky { color: var(--warn); }
.verify-sum { font-size: 13px; }
header .btn:has(+ .auto-verify) { margin-right: 0; } /* Auto verify sits right after Templates */
.auto-verify { display: flex; align-items: center; gap: 8px; min-width: 0; margin-right: auto; font-size: 13px; color: var(--muted); }
#auto-verify.on { border-color: var(--accent); color: var(--accent); }
.auto-status { max-width: 460px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.auto-status b { color: var(--text); font-weight: 600; }
.auto-status .check { margin-left: 0; }
.verify-sum b { color: var(--text); font-variant-numeric: tabular-nums; }
.sent { font-size: 11px; padding: 1px 6px; border-radius: 4px; background: var(--ok-bg); color: var(--ok); white-space: nowrap; }
dialog#mail { width: 600px; max-width: calc(100vw - 32px); border: 1px solid var(--line); border-radius: 12px; padding: 0; background: var(--panel); color: var(--text); box-shadow: var(--shadow); }
dialog#mail::backdrop { background: rgba(10, 15, 25, .45); }
#mail form { display: flex; flex-direction: column; gap: 10px; padding: 16px 18px 18px; }
#mail h2 { font-size: 17px; margin: 0; }
#mail .field input, #mail .field select, #mail textarea { width: 100%; }
#mail textarea { resize: vertical; line-height: 1.5; min-height: 220px; }
#mail .foot { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
#mail .foot .btns { display: flex; gap: 8px; margin-left: auto; }
#mail .preview-box { border: 1px solid var(--line); border-radius: 8px; overflow: hidden; }
#mail .pv-head { display: grid; gap: 2px; padding: 8px 10px; font-size: 13px; background: var(--bg); border-bottom: 1px solid var(--line); }
#mail .pv-head span { display: inline-block; width: 60px; color: var(--muted); }
#mail .pv-head b { font-weight: 500; word-break: break-word; }
#mail #pv-warn { margin: 8px 10px 0; }
#pv-frame { display: block; width: 100%; height: 340px; border: 0; background: #fff; }
#mail .foot-left { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
#mail .tpl { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 12px; color: var(--muted); }
#mail .tpl b { color: var(--text); font-weight: 600; }
#mail .tpl button { font-size: 12px; padding: 2px 9px; border-radius: 999px; color: var(--accent); border-color: var(--accent); }
#mail .note { border-radius: 8px; padding: 8px 10px; font-size: 13px; }
#mail .note.error { margin: 0; }
#mail .note.warn { background: var(--accent-soft); }
#mail .note.ok { background: var(--ok-bg); color: var(--ok); }
button:disabled { opacity: .6; cursor: default; }
@media (max-width: 760px) {
  .field { flex: 1 1 140px; }
  th[data-col="name"], td[data-col="name"] { min-width: 140px; }
}
</style>
<style id="col-hide"></style>
<script>
// Apply the saved column choice before the table renders, so there is no flash.
// Stores the shown columns; nothing saved (or Reset) means DEFAULT_COLUMNS.
const COL_STORE = 'portal.visibleColumns';
const TOGGLEABLE = <?= json_encode($toggleable) ?>;
const DEFAULT_SHOWN = <?= json_encode(DEFAULT_COLUMNS) ?>;
function readShown() {
  try {
    const v = JSON.parse(localStorage.getItem(COL_STORE));
    if (Array.isArray(v)) return v;
  } catch (e) { /* storage blocked or bad JSON */ }
  return DEFAULT_SHOWN;
}
function applyShown(shown) {
  document.getElementById('col-hide').textContent = TOGGLEABLE.filter((c) => !shown.includes(c))
    .map((c) => `[data-col="${CSS.escape(c)}"]{display:none}`).join('\n');
}
applyShown(readShown());
</script>
</head>
<body>
<div class="wrap">
  <header>
    <h1>Business Listings</h1>
    <a class="btn" href="templates.php<?= $key !== '' ? '?key=' . h(rawurlencode($key)) : '' ?>">&#9993; Templates</a>
    <div class="auto-verify">
      <button type="button" id="auto-verify" title="Check one random unchecked email, wait 5 seconds, then the next. Keeps going until you stop it. Nothing is sent.">&#9654; Auto verify</button>
      <span class="auto-status" id="auto-status" aria-live="polite" hidden></span>
    </div>
    <a class="btn" href="settings.php<?= $key !== '' ? '?key=' . h(rawurlencode($key)) : '' ?>">&#9881; Settings</a>
    <div class="stats">
      <span><b><?= number_format((int)$stats['total']) ?></b> businesses</span>
      <span><b><?= number_format((int)$stats['zips']) ?></b> ZIP codes</span>
      <span><b><?= number_format((int)$stats['categories']) ?></b> categories</span>
      <?php if ($stats['last']): ?><span>Last update <b><?= h($stats['last']) ?></b> UTC</span><?php endif; ?>
    </div>
  </header>

  <?php if ($error): ?><div class="error">Database error: <?= h($error) ?></div><?php endif; ?>

  <form class="filters" method="get">
    <?php if ($key !== ''): ?><input type="hidden" name="key" value="<?= h($key) ?>"><?php endif; ?>
    <input type="hidden" name="sort" value="<?= h($sort) ?>">
    <input type="hidden" name="dir" value="<?= h($dir) ?>">
    <?php if ($days !== 30): ?><input type="hidden" name="days" value="<?= $days ?>"><?php endif; ?>
    <div class="field grow">
      <label for="q">Search</label>
      <input id="q" name="q" type="search" value="<?= h($f['q']) ?>" placeholder="Name, phone, address or website">
    </div>
    <div class="field">
      <label for="zipcode">ZIP code</label>
      <select id="zipcode" name="zipcode">
        <option value="">All</option>
        <?php foreach ($zipOptions as $o): ?>
          <option value="<?= h($o['zipcode']) ?>"<?= $o['zipcode'] === $f['zipcode'] ? ' selected' : '' ?>><?= h($o['zipcode']) ?> (<?= (int)$o['n'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="category">Category</label>
      <select id="category" name="category">
        <option value="">All</option>
        <?php foreach ($categoryOptions as $o): ?>
          <option value="<?= h($o['category']) ?>"<?= $o['category'] === $f['category'] ? ' selected' : '' ?>><?= h($o['category']) ?> (<?= (int)$o['n'] ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="min_rating">Min rating</label>
      <select id="min_rating" name="min_rating">
        <option value="">Any</option>
        <?php foreach ([3, 3.5, 4, 4.5] as $r): ?>
          <option value="<?= $r ?>"<?= $f['min_rating'] !== '' && (float)$f['min_rating'] === (float)$r ? ' selected' : '' ?>><?= number_format($r, 1) ?>+</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="check">Email check</label>
      <select id="check" name="check">
        <option value="">Any</option>
        <?php foreach (CHECK_FILTERS as $v => $label): ?>
          <option value="<?= h($v) ?>"<?= $v === $f['check'] ? ' selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="limit">Per page</label>
      <select id="limit" name="limit">
        <?php foreach (PAGE_SIZES as $n): ?>
          <option value="<?= $n ?>"<?= $n === $limit ? ' selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="primary" type="submit">Filter</button>
    <?php if ($filtered): ?><a class="btn" href="<?= h(url(['q' => null, 'zipcode' => null, 'category' => null, 'min_rating' => null, 'check' => null, 'page' => 1])) ?>">Reset</a><?php endif; ?>
  </form>

  <?php
  $dayTotal = array_sum(array_column($daily, 'n'));
  $dayEmails = array_sum(array_column($daily, 'e'));
  $today = $daily ? end($daily)['n'] : 0;
  ?>
  <section class="chart-card" aria-labelledby="chart-title">
    <div class="chart-head">
      <div>
        <h2 id="chart-title">New leads per day</h2>
        <div class="sub">Businesses added, by UTC day<?= $filtered ? ', matching the filters' : '' ?></div>
      </div>
      <div class="chart-figs">
        <span><b><?= number_format($dayTotal) ?></b> in <?= $days ?> days</span>
        <span><b><?= number_format($today) ?></b> today</span>
        <span><b><?= number_format($dayEmails) ?></b> with email</span>
      </div>
      <div class="seg" role="group" aria-label="Chart range">
        <?php foreach (CHART_DAYS as $d): ?>
          <a href="<?= h(url(['days' => $d === 30 ? null : $d])) ?>"<?= $d === $days ? ' class="on" aria-current="true"' : '' ?>><?= $d ?>d</a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="chart" id="leadchart" data-days="<?= h(json_encode(array_map(
        fn($d, $v) => ['d' => $d, 'n' => $v['n'], 'e' => $v['e']], array_keys($daily), $daily))) ?>"></div>
    <details class="chart-table">
      <summary>Show as table</summary>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Day (UTC)</th><th>New leads</th><th>With email</th></tr></thead>
          <tbody>
            <?php foreach (array_reverse($daily, true) as $d => $v): ?>
              <tr><td class="nowrap"><?= h($d) ?></td><td class="num"><?= number_format($v['n']) ?></td><td class="num"><?= number_format($v['e']) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </section>

  <div class="bar">
    <span><?= $total ? 'Showing ' . number_format($from) . '–' . number_format($to) . ' of ' . number_format($total) : 'No results' ?><?= $filtered ? ' (filtered)' : '' ?></span>
    <div class="actions">
      <span class="verify-sum" id="verify-sum" title="Email verification results for all saved addresses"></span>
      <button type="button" id="verify-all" title="Check every address not checked yet, one at a time. Nothing is sent.">&#10003; Verify emails <span class="badge" id="verify-pending"><?= number_format($checkPending) ?></span></button>
      <div class="colmenu" id="colmenu">
        <button type="button" id="colbtn" aria-haspopup="true" aria-expanded="false">
          <svg width="15" height="15" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="1.5" y="2.5" width="13" height="11" rx="1.5"/><path d="M6 2.5v11M10 2.5v11"/></svg>
          Columns <span class="badge" id="colcount"></span>
        </button>
        <div class="panel" role="dialog" aria-label="Show columns">
          <div class="panel-head">Show columns <button type="button" class="link" id="colreset">Reset</button></div>
          <div class="panel-search"><input type="search" id="colfind" placeholder="Find a column…" autocomplete="off"></div>
          <div class="panel-list" id="collist">
            <?php foreach ($toggleable as $c): ?>
              <label><input type="checkbox" value="<?= h($c) ?>" checked> <?= h(COLUMNS[$c][0]) ?></label>
            <?php endforeach; ?>
          </div>
          <div class="panel-foot">
            <button type="button" id="colall">Show all</button>
            <button type="button" id="colnone">Hide all</button>
          </div>
        </div>
      </div>
      <?php if ($total): ?><a class="btn" id="export" href="<?= h(url(['export' => 'csv', 'page' => null])) ?>">Export CSV</a><?php endif; ?>
    </div>
  </div>

  <div class="table-wrap">
    <?php if (!$rows): ?>
      <div class="empty"><?= $filtered ? 'No businesses match these filters.' : 'No businesses saved yet. Run the extension to add some.' ?></div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <?php foreach (COLUMNS as $c => $_): ?><th data-col="<?= h($c) ?>"><?= header_cell($c) ?></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="main">
          <?php foreach (COLUMNS as $c => [, $type]): ?><td data-col="<?= h($c) ?>"<?= isset($cellClass[$type]) ? ' class="' . $cellClass[$type] . '"' : '' ?>><?= cell($c, $r) ?></td><?php endforeach; ?>
        </tr>
        <tr class="detail">
          <td colspan="<?= count(COLUMNS) ?>">
            <div class="detail-grid">
              <?php if ($img = safe_href($r['image_url'])): ?>
                <div class="item"><img class="detail-img" src="<?= $img ?>" alt="" loading="lazy" referrerpolicy="no-referrer"></div>
              <?php endif; ?>
              <?php foreach ($detailFields as $label => $get):
                  $v = $get($r);
                  if ($v === null || $v === '' || $v === []) continue; ?>
                <div class="item"><h4><?= h($label) ?></h4><?= render_value($v) ?></div>
              <?php endforeach; ?>
              <?php
                $links = [];
                foreach ($linkFields as $col => $label) {
                    if ($u = safe_href($r[$col])) $links[] = '<a href="' . $u . '" target="_blank" rel="noopener">' . h($label) . '</a>';
                }
                if ($links): ?>
                <div class="item"><h4>Links</h4><?= implode(' · ', $links) ?></div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <?php if ($pages > 1): ?>
  <div class="bar">
    <span>Page <?= $page ?> of <?= number_format($pages) ?></span>
    <nav class="pager">
      <?php
        if ($page > 1) echo '<a href="' . h(url(['page' => $page - 1])) . '">‹ Prev</a>';
        $last = 0;
        foreach (array_unique([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $pages]) as $p) {
            if ($p < 1 || $p > $pages) continue;
            if ($last && $p > $last + 1) echo '<span class="gap">…</span>';
            echo $p === $page ? '<span class="cur">' . $p . '</span>' : '<a href="' . h(url(['page' => $p])) . '">' . $p . '</a>';
            $last = $p;
        }
        if ($page < $pages) echo '<a href="' . h(url(['page' => $page + 1])) . '">Next ›</a>';
      ?>
    </nav>
  </div>
  <?php endif; ?>
</div>

<dialog id="mail" aria-labelledby="mail-title">
  <form id="mail-form" method="dialog">
    <h2 id="mail-title">Email</h2>
    <div class="note warn" id="mail-warn" hidden></div>
    <div class="field">
      <label for="mail-to">To <span class="sub">(separate several addresses with commas)</span></label>
      <input id="mail-to" type="email" multiple required>
    </div>
    <div id="mail-edit">
      <div class="field">
        <label for="mail-subject">Subject</label>
        <input id="mail-subject" type="text" maxlength="255" required>
      </div>
      <div class="field" style="margin-top:10px">
        <label for="mail-body">Message <span class="sub">(**text** is sent in bold)</span></label>
        <textarea id="mail-body" rows="10" required></textarea>
      </div>
    </div>
    <div class="preview-box" id="mail-preview" hidden>
      <div class="pv-head">
        <div><span>From</span> <b id="pv-from"></b></div>
        <div><span>To</span> <b id="pv-to"></b></div>
        <div><span>Subject</span> <b id="pv-subject"></b></div>
      </div>
      <div class="note error" id="pv-warn" hidden></div>
      <iframe id="pv-frame" sandbox title="Email preview"></iframe>
    </div>
    <div class="note" id="mail-status" hidden></div>
    <div class="foot">
      <div class="foot-left">
        <span class="sub" id="mail-from"></span>
        <span class="tpl" id="mail-tpl" hidden>Template: <b id="mail-tpl-name"></b>
          <button type="button" id="mail-tpl-next" title="Load a different random template that is On">&#8635; Change template</button>
        </span>
      </div>
      <div class="btns">
        <button type="button" id="mail-preview-btn" title="See the email as it will be received">&#128065; Preview</button>
        <button type="button" id="mail-cancel">Cancel</button>
        <button type="submit" class="primary" id="mail-send">Send</button>
      </div>
    </div>
  </form>
</dialog>

<script>
// Email button: load the filled-in template, let it be edited, then send through send_email.php.
(() => {
  const KEY = <?= json_encode($key) ?>;
  const SETTINGS_URL = <?= json_encode('settings.php' . ($key !== '' ? '?key=' . rawurlencode($key) : '')) ?>;
  const api = (file, params = {}) => file + '?' + new URLSearchParams({ ...params, ...(KEY ? { key: KEY } : {}) });
  const endpoint = (params = {}) => api('send_email.php', params);
  const dlg = document.getElementById('mail');
  const $ = (id) => document.getElementById(id);
  const sendBtn = $('mail-send');
  let current = null; // { id, button }

  function status(text, kind) {
    const el = $('mail-status');
    el.hidden = !text;
    el.className = 'note ' + (kind || '');
    el.textContent = text || '';
  }
  function warn(html) {
    $('mail-warn').hidden = !html;
    $('mail-warn').innerHTML = html || '';
  }
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  // A random template that is On (templates.php), filled in for the business. count=0: it's only
  // counted as used when the email is sent. Falls back to the Settings template when none are On.
  async function fetchTemplate(exclude) {
    const params = { business_id: current.id, count: 0 };
    if (exclude) params.exclude = exclude;
    const res = await fetch(api('get_random_template.php', params));
    const d = await res.json();
    if (!d.ok) throw new Error(d.error || 'Could not load a template.');
    return d;
  }

  function showTemplate(t) {
    current.templateId = t.template.id;
    current.loaded = { subject: t.filled.subject, body: t.filled.body };
    $('mail-subject').value = t.filled.subject;
    $('mail-body').value = t.filled.body;
    $('mail-tpl-name').textContent = t.template.id ? t.template.name : 'Settings template (no template is On)';
    $('mail-tpl').hidden = false;
    const canChange = t.active_templates > 1;
    $('mail-tpl-next').disabled = !canChange;
    $('mail-tpl-next').title = canChange ? 'Load a different random template that is On'
      : 'Turn on at least 2 templates on the Templates page to switch between them';
  }

  $('mail-tpl-next').addEventListener('click', async () => {
    if (!current) return;
    const edited = current.loaded && ($('mail-subject').value !== current.loaded.subject || $('mail-body').value !== current.loaded.body);
    if (edited && !confirm('Load a different template? Your changes to the subject and message will be lost.')) return;
    const btn = $('mail-tpl-next');
    btn.disabled = true;
    try {
      showTemplate(await fetchTemplate(current.templateId));
      status('');
      if (previewing) await showPreview();
    } catch (e) {
      status(e.message, 'error');
      btn.disabled = false;
    }
  });

  // Preview: send_email.php renders the email with the same code that sends it (nothing is sent).
  let previewing = false;
  function setPreview(on) {
    previewing = on;
    $('mail-edit').hidden = on;
    $('mail-preview').hidden = !on;
    $('mail-preview-btn').innerHTML = on ? '&#9998; Edit' : '&#128065; Preview';
    if (!on) $('pv-frame').srcdoc = '';
  }
  async function showPreview() {
    const btn = $('mail-preview-btn');
    btn.disabled = true;
    try {
      const res = await fetch(endpoint(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          business_id: Number(current.id), preview: true,
          to: $('mail-to').value.trim(), subject: $('mail-subject').value, body: $('mail-body').value,
        }),
      });
      const d = await res.json();
      if (!d.ok) throw new Error(d.error || 'Could not build the preview.');
      $('pv-from').textContent = d.from || '(no Gmail address in Settings)';
      $('pv-to').textContent = d.to.join(', ') || '(nobody)';
      $('pv-subject').textContent = d.subject;
      $('pv-warn').hidden = !d.warnings.length;
      $('pv-warn').textContent = d.warnings.length ? 'Sending would fail: ' + d.warnings.join(' ') : '';
      $('pv-frame').srcdoc = d.html;
      setPreview(true);
      status('');
    } catch (e) {
      status(e.message, 'error');
    } finally {
      btn.disabled = false;
    }
  }
  $('mail-preview-btn').addEventListener('click', () => (previewing ? setPreview(false) : showPreview()));

  async function open(button) {
    current = { id: button.dataset.id, button, templateId: null, loaded: null };
    $('mail-title').textContent = 'Email';
    $('mail-to').value = $('mail-subject').value = $('mail-body').value = '';
    $('mail-from').textContent = '';
    $('mail-tpl').hidden = true;
    setPreview(false);
    warn('');
    status('Loading…');
    sendBtn.disabled = true;
    dlg.showModal();
    try {
      const [d, t] = await Promise.all([
        fetch(endpoint({ business_id: current.id })).then((r) => r.json()),
        fetchTemplate(),
      ]);
      if (!d.ok) throw new Error(d.error || 'Could not load the template.');
      $('mail-title').textContent = 'Email ' + (d.name || 'business');
      $('mail-to').value = d.to; // every address found, comma-separated
      showTemplate(t);
      $('mail-from').textContent = d.from ? 'From ' + d.from : '';
      const notes = [];
      if (!d.smtp_ready) notes.push(`${esc(d.smtp_error)} <a href="${esc(SETTINGS_URL)}">Open Settings</a>`);
      if (d.last_sent) notes.push(`Already emailed on ${esc(d.last_sent)} UTC.`);
      if (d.invalid.length) notes.push(`Left out of To: ${d.invalid.map((e) => `<b>${esc(e)}</b>`).join(', ')}. ${esc(d.checks[d.invalid[0].toLowerCase()].reason)} Add it back if you still want to try.`);
      const inTo = d.emails.length - d.invalid.length;
      if (inTo > 1) notes.push(`${inTo} addresses found. All of them are in To and get the same email. Remove any you don't want.`);
      warn(notes.join('<br>'));
      status('');
      sendBtn.disabled = !d.smtp_ready;
    } catch (e) {
      status(e.message, 'error');
    }
  }

  document.querySelectorAll('.send-email').forEach((b) => b.addEventListener('click', () => open(b)));
  $('mail-cancel').addEventListener('click', () => dlg.close());

  $('mail-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!current) return;
    sendBtn.disabled = true;
    status('Sending…');
    try {
      const res = await fetch(endpoint(), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          business_id: Number(current.id),
          to: $('mail-to').value.trim(),
          subject: $('mail-subject').value,
          body: $('mail-body').value,
          template_id: current.templateId, // counted as used on a successful send
        }),
      });
      const d = await res.json();
      if (!d.ok) throw new Error(d.error || 'Sending failed.');
      status('Sent to ' + d.sent_to + '.', 'ok');
      const tag = current.button.parentElement.querySelector('.sent');
      tag.hidden = false;
      tag.textContent = 'Sent ' + d.sent_at.slice(0, 10);
      tag.title = 'Last sent ' + d.sent_at + ' UTC';
      setTimeout(() => dlg.close(), 1200);
    } catch (err) {
      status(err.message, 'error');
      sendBtn.disabled = false;
    }
  });
})();

// Email verification (verify_email.php). Verify on a row checks that business's addresses again;
// "Verify emails" checks every address that is due, one request at a time, until none are left.
(() => {
  const KEY = <?= json_encode($key) ?>;
  const LABELS = <?= json_encode(CHECK_LABELS, JSON_UNESCAPED_UNICODE) ?>;
  const api = (params) => 'verify_email.php?' + new URLSearchParams({ ...params, ...(KEY ? { key: KEY } : {}) });
  const allBtn = document.getElementById('verify-all');
  const sumEl = document.getElementById('verify-sum');
  const num = (n) => Number(n).toLocaleString();
  const setLabel = (text) => { allBtn.firstChild.nodeValue = text; }; // the badge after it stays

  function paint(r) {
    const title = `${r.reason} Checked ${r.checked_at} UTC.${r.smtp_reply ? ' Server: ' + r.smtp_reply : ''}`;
    document.querySelectorAll(`.check[data-email="${CSS.escape(r.email)}"]`).forEach((el) => {
      el.className = 'check ' + r.status;
      el.textContent = LABELS[r.status] || r.status;
      el.title = title;
      el.hidden = false;
    });
    document.querySelectorAll('.more-emails').forEach((d) => {
      if (d.querySelector(`.check[data-email="${CSS.escape(r.email)}"]`)) d.querySelector('.more-sum').innerHTML = checkSummary(d);
    });
  }

  /** Same text as check_summary() in PHP, counted from the badges in one "+N more" list. */
  function checkSummary(details) {
    const n = {};
    Object.keys(LABELS).forEach((k) => { n[k] = 0; });
    details.querySelectorAll('li .check').forEach((el) => {
      const status = Object.keys(LABELS).find((k) => el.classList.contains(k)) || 'none';
      n[status] += 1;
    });
    return Object.keys(n).filter((k) => n[k])
      .map((k) => `<span class="s-${k}">${n[k]} ${k === 'none' ? 'not checked' : k}</span>`).join(' · ');
  }

  function summary(c, remaining) {
    sumEl.innerHTML = c.valid + c.invalid + c.risky + c.unknown
      ? `<b>${num(c.valid)}</b> valid · <b>${num(c.invalid)}</b> invalid · <b>${num(c.risky)}</b> risky · <b>${num(c.unknown)}</b> unknown`
      : '';
    document.getElementById('verify-pending').textContent = num(remaining);
  }
  summary(<?= json_encode($checkCounts) ?>, <?= (int)$checkPending ?>);

  async function call(params) {
    const res = await fetch(api(params));
    let d;
    try { d = await res.json(); } catch (e) { throw new Error(`verify_email.php answered HTTP ${res.status} without JSON.`); }
    if (!d.ok) throw new Error(d.error || 'Verification failed.');
    d.results.forEach(paint);
    summary(d.counts, d.remaining);
    return d;
  }

  document.querySelectorAll('.verify-email').forEach((b) => b.addEventListener('click', async () => {
    b.disabled = true;
    b.textContent = 'Checking…';
    try {
      await call({ business_id: b.dataset.id, refresh: 1 });
    } catch (e) {
      alert(e.message);
    } finally {
      b.disabled = false;
      b.textContent = 'Verify';
    }
  }));

  let running = false;
  allBtn.addEventListener('click', async () => {
    if (running) { // stop after the address being checked now
      running = false;
      allBtn.disabled = true;
      setLabel('Stopping… ');
      return;
    }
    running = true;
    autoBtn.disabled = true; // one loop at a time
    let checked = 0;
    setLabel('■ Stop ');
    allBtn.title = 'Stop after the address being checked now';
    try {
      while (running) {
        const d = await call({ next: 1 });
        checked += d.results.length;
        setLabel(`■ Stop (${num(checked)} checked) `);
        if (!d.results.length || !d.remaining) break;
      }
    } catch (e) {
      alert('Verification stopped: ' + e.message);
    }
    running = false;
    autoBtn.disabled = false;
    allBtn.disabled = auto;
    allBtn.title = 'Check every address not checked yet, one at a time. Nothing is sent.';
    setLabel('✓ Verify emails ');
  });

  // Auto verify: one random address that is due, a AUTO_PAUSE-second break, the next one, and so on.
  // It keeps going until stopped, also after the page reloads (remembered in localStorage). When
  // nothing is due (or on an error) it looks again every IDLE_PAUSE seconds, so new leads get checked too.
  const AUTO_PAUSE = 5;
  const IDLE_PAUSE = 60;
  const AUTO_STORE = 'autoVerify';
  const autoBtn = document.getElementById('auto-verify');
  const autoStatus = document.getElementById('auto-status');
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  let auto = false;
  let wake = null; // ends the current break early (Stop)
  let autoChecked = 0;
  let autoRun = 0; // a Stop + Start during a check must not leave two loops running

  /** Waits `seconds`, showing "next in Ns" after `html`. Resolves early when stopped. */
  function pause(seconds, html) {
    return new Promise((resolve) => {
      let left = seconds;
      const show = () => { autoStatus.innerHTML = `${html} · next in ${left}s`; };
      show();
      const timer = setInterval(() => { left -= 1; if (left <= 0) done(); else show(); }, 1000);
      const done = () => { clearInterval(timer); wake = null; resolve(); };
      wake = done;
    });
  }

  async function autoLoop(run) {
    while (auto && run === autoRun) {
      autoStatus.textContent = 'Checking…';
      let wait = AUTO_PAUSE;
      let html;
      try {
        const d = await call({ next: 1, random: 1 });
        const r = d.results[0];
        if (r) {
          autoChecked += 1;
          html = `<b>${esc(r.email)}</b> <span class="check ${esc(r.status)}">${esc(LABELS[r.status] || r.status)}</span>`
            + ` · ${num(autoChecked)} checked, ${num(d.remaining)} left`;
        } else {
          wait = IDLE_PAUSE;
          html = 'All addresses checked';
        }
      } catch (e) {
        wait = IDLE_PAUSE;
        html = `<span class="check invalid">Error</span> ${esc(e.message)}`;
      }
      if (auto && run === autoRun) await pause(wait, html);
    }
  }

  function setAuto(on) {
    auto = on;
    try { on ? localStorage.setItem(AUTO_STORE, '1') : localStorage.removeItem(AUTO_STORE); } catch (e) { /* private mode */ }
    autoBtn.classList.toggle('on', on);
    autoBtn.innerHTML = on ? '&#9632; Stop auto verify' : '&#9654; Auto verify';
    autoStatus.hidden = !on;
    allBtn.disabled = on || running; // one loop at a time
    autoRun += 1;
    if (wake) wake();
    if (on) {
      autoChecked = 0;
      autoLoop(autoRun);
    }
  }

  autoBtn.addEventListener('click', () => setAuto(!auto));
  let resume = false;
  try { resume = localStorage.getItem(AUTO_STORE) === '1'; } catch (e) { /* private mode */ }
  if (resume) setAuto(true);
})();

(() => {
  const ALL = <?= json_encode(array_keys(COLUMNS)) ?>;
  const FIXED = <?= json_encode(FIXED_COLUMN) ?>;
  const menu = document.getElementById('colmenu');
  const btn = document.getElementById('colbtn');
  const boxes = [...document.querySelectorAll('#collist input')];
  const exportLink = document.getElementById('export');
  const exportBase = exportLink ? exportLink.getAttribute('href') : '';

  function save(shown) {
    try { localStorage.setItem(COL_STORE, JSON.stringify(shown)); } catch (e) { /* private mode */ }
  }
  function sync(persist = true) {
    const shown = boxes.filter((b) => b.checked).map((b) => b.value);
    applyShown(shown);
    if (persist) save(shown);
    document.getElementById('colcount').textContent = `${shown.length}/${boxes.length}`;
    if (exportLink) {
      const cols = ALL.filter((c) => c === FIXED || shown.includes(c));
      exportLink.href = shown.length === boxes.length ? exportBase : `${exportBase}&cols=${encodeURIComponent(cols.join(','))}`;
    }
  }
  function setAll(checked) { boxes.forEach((b) => { b.checked = checked; }); sync(); }

  const shown = readShown();
  boxes.forEach((b) => { b.checked = shown.includes(b.value); b.addEventListener('change', () => sync()); });
  sync(false);

  btn.addEventListener('click', () => {
    const open = menu.classList.toggle('open');
    btn.setAttribute('aria-expanded', String(open));
    if (open) document.getElementById('colfind').focus();
  });
  document.addEventListener('click', (e) => {
    if (!menu.contains(e.target)) { menu.classList.remove('open'); btn.setAttribute('aria-expanded', 'false'); }
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && menu.classList.contains('open')) { menu.classList.remove('open'); btn.focus(); }
  });
  document.getElementById('colreset').addEventListener('click', () => {
    try { localStorage.removeItem(COL_STORE); } catch (e) { /* private mode */ }
    boxes.forEach((b) => { b.checked = DEFAULT_SHOWN.includes(b.value); });
    sync(false);
  });
  document.getElementById('colall').addEventListener('click', () => setAll(true));
  document.getElementById('colnone').addEventListener('click', () => setAll(false));
  document.getElementById('colfind').addEventListener('input', (e) => {
    const q = e.target.value.trim().toLowerCase();
    boxes.forEach((b) => { b.parentElement.style.display = b.parentElement.textContent.toLowerCase().includes(q) ? '' : 'none'; });
  });

  // Click a row (outside its links) to show or hide its details.
  document.querySelectorAll('tr.main').forEach((tr) => {
    tr.addEventListener('click', (e) => {
      if (e.target.closest('a, .email-actions, .more-emails')) return;
      const open = tr.classList.toggle('open');
      tr.nextElementSibling.classList.toggle('open', open);
      tr.querySelector('.toggle').setAttribute('aria-expanded', String(open));
    });
  });

  // Changing a filter dropdown applies it right away.
  document.querySelectorAll('form.filters select').forEach((s) => {
    s.addEventListener('change', () => s.form.submit());
  });
})();
</script>
<script>
// "New leads per day" column chart: one series, hover tooltip, redrawn on resize.
(() => {
  const box = document.getElementById('leadchart');
  if (!box) return;
  const data = JSON.parse(box.dataset.days || '[]');
  const NS = 'http://www.w3.org/2000/svg';
  const M = { top: 18, right: 4, bottom: 22, left: 34 };
  const num = (v) => v.toLocaleString('en-US');
  const fmtDay = (d, opts) => new Date(d + 'T00:00:00Z').toLocaleDateString('en-US', { timeZone: 'UTC', ...opts });
  const el = (tag, attrs, parent) => {
    const n = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) n.setAttribute(k, v);
    parent.appendChild(n);
    return n;
  };
  // Axis top that splits into 4 clean steps (4, 8, 20, 40, 100, ...).
  const niceMax = (v) => {
    if (v <= 4) return 4;
    const step = Math.pow(10, Math.floor(Math.log10(v / 4)));
    return [1, 2, 2.5, 5, 10].map((m) => m * step).find((u) => u * 4 >= v) * 4;
  };
  // Column with a 4px rounded top and a square base.
  const column = (x, y, w, h) => {
    const r = Math.min(4, w / 2, h);
    return `M${x},${y + h}V${y + r}Q${x},${y} ${x + r},${y}H${x + w - r}Q${x + w},${y} ${x + w},${y + r}V${y + h}Z`;
  };

  const tip = document.createElement('div');
  tip.className = 'chart-tip';

  function draw() {
    box.textContent = '';
    if (!data.some((d) => d.n > 0)) {
      box.innerHTML = '<div class="chart-empty">No new leads in this range.</div>';
      return;
    }
    const W = box.clientWidth, H = box.clientHeight;
    const iw = W - M.left - M.right, ih = H - M.top - M.bottom;
    const max = niceMax(Math.max(...data.map((d) => d.n)));
    const y = (v) => M.top + ih - (v / max) * ih;
    const band = iw / data.length;
    const bw = Math.max(2, Math.min(24, band - 2));
    const svg = el('svg', { viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': 'New leads per day' }, box);

    for (let i = 0; i <= 4; i++) {
      const v = (max / 4) * i, yy = Math.round(y(v)) + 0.5;
      el('line', { class: 'grid', x1: M.left, x2: W - M.right, y1: yy, y2: yy }, svg);
      el('text', { class: 'axis-text', x: M.left - 6, y: yy + 4, 'text-anchor': 'end' }, svg).textContent = num(v);
    }

    // Date labels at least ~56px apart, always including the last day (today). Only the peak gets a value label.
    const every = Math.max(1, Math.ceil(56 / band));
    const peak = data.reduce((a, d, i) => (d.n > data[a].n ? i : a), 0);
    data.forEach((d, i) => {
      const x0 = M.left + i * band;
      const hit = el('rect', { class: 'hit', x: x0, y: M.top, width: band, height: ih + M.bottom }, svg);
      if (d.n > 0) el('path', { class: 'mark', d: column(x0 + (band - bw) / 2, y(d.n), bw, y(0) - y(d.n)) }, svg);
      if (i === peak) {
        el('text', { class: 'value-text', x: x0 + band / 2, y: y(d.n) - 5, 'text-anchor': 'middle' }, svg).textContent = num(d.n);
      }
      if ((data.length - 1 - i) % every === 0) {
        el('text', { class: 'axis-text', x: x0 + band / 2, y: H - 6, 'text-anchor': 'middle' }, svg)
          .textContent = fmtDay(d.d, { month: 'short', day: 'numeric' });
      }
      hit.addEventListener('mouseenter', () => {
        svg.querySelectorAll('.hit.on').forEach((n) => n.classList.remove('on'));
        hit.classList.add('on');
        tip.innerHTML = `${fmtDay(d.d, { weekday: 'short', month: 'short', day: 'numeric' })}<br>`
          + `<b>${num(d.n)}</b> new lead${d.n === 1 ? '' : 's'} · <b>${num(d.e)}</b> with email`;
        tip.style.display = 'block';
        const half = tip.offsetWidth / 2;
        tip.style.left = Math.min(Math.max(x0 + band / 2, half), W - half) + 'px';
        tip.style.top = Math.max(y(d.n), M.top + 30) + 'px';
      });
    });
    svg.addEventListener('mouseleave', () => {
      tip.style.display = 'none';
      svg.querySelectorAll('.hit.on').forEach((n) => n.classList.remove('on'));
    });
    box.appendChild(tip);
  }

  draw();
  let lastW = box.clientWidth;
  new ResizeObserver(() => { if (box.clientWidth !== lastW) { lastW = box.clientWidth; draw(); } }).observe(box);
})();
</script>
</body>
</html>
