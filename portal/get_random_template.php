<?php
/*
 * GET endpoint: one random active email template (templates.php).
 * Params (all optional):
 *   business_id  also return the template filled in for that business ("filled"), ready to send
 *   exclude      template ids to skip, comma-separated (e.g. the one used last time)
 *   count=0      don't add to times_used (the Email dialog counts on send instead)
 * Otherwise each call adds 1 to the template's times_used. With no active templates, the Settings template
 * is returned (id null, source "settings").
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
send_cors();
check_api_key();

$businessId = (int)($_GET['business_id'] ?? 0);
$count = ($_GET['count'] ?? '1') !== '0';
$exclude = array_values(array_filter(array_map('intval', explode(',', (string)($_GET['exclude'] ?? '')))));

try {
    $pdo = db();
    ensure_email_templates_table();
    $s = get_settings();
    $active = (int)$pdo->query('SELECT COUNT(*) FROM email_templates WHERE is_active = 1')->fetchColumn();

    $where = 'is_active = 1' . ($exclude ? ' AND id NOT IN (' . implode(',', $exclude) . ')' : '');
    $tpl = $pdo->query("SELECT id, name, subject, body, source, times_used FROM email_templates WHERE $where ORDER BY RAND() LIMIT 1")->fetch();
    // Everything excluded: still return one rather than nothing.
    if (!$tpl && $exclude && $active) {
        $tpl = $pdo->query('SELECT id, name, subject, body, source, times_used FROM email_templates WHERE is_active = 1 ORDER BY RAND() LIMIT 1')->fetch();
    }
    if ($tpl) {
        if ($count) $pdo->prepare('UPDATE email_templates SET times_used = times_used + 1 WHERE id = ?')->execute([$tpl['id']]);
        $tpl['id'] = (int)$tpl['id'];
        $tpl['times_used'] = (int)$tpl['times_used'] + ($count ? 1 : 0);
    } else {
        $tpl = ['id' => null, 'name' => 'Default (Settings)', 'subject' => $s['email_subject'], 'body' => $s['email_body'], 'source' => 'settings', 'times_used' => null];
    }

    $biz = null;
    if ($businessId > 0) {
        $stmt = $pdo->prepare('SELECT id, name, email, emails, website, city, category FROM businesses WHERE id = ?');
        $stmt->execute([$businessId]);
        $biz = $stmt->fetch();
        if (!$biz) json_out(['ok' => false, 'error' => "No business with id $businessId"], 404);
    }
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

$out = ['ok' => true, 'active_templates' => $active, 'template' => $tpl];
if ($biz) {
    $emails = business_emails($biz);
    $out['filled'] = [
        'business_id' => (int)$biz['id'],
        'name'        => $biz['name'],
        'to'          => implode(', ', $emails),
        'emails'      => $emails,
        'subject'     => fill_template($tpl['subject'], $biz, $s),
        'body'        => fill_template($tpl['body'], $biz, $s),
    ];
}
json_out($out);
