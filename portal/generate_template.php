<?php
/*
 * POST JSON {brief?, tone?, length?} -> a new email template written by Groq, NOT saved.
 * templates.php puts it in the editor so it can be checked before saving.
 * Like send_email.php: JSON only and no CORS headers, so other websites can't spend the Groq quota
 * through a visitor's browser.
 */
require_once __DIR__ . '/groq.php';
check_api_key();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') json_out(['ok' => false, 'error' => 'Use POST.'], 405);
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === false) {
    json_out(['ok' => false, 'error' => 'Send the body as JSON (Content-Type: application/json).'], 415);
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) json_out(['ok' => false, 'error' => 'Body is not valid JSON.'], 400);

$brief = mb_substr(trim((string)($in['brief'] ?? '')), 0, 2000);
$tone = (string)($in['tone'] ?? 'friendly');
$length = (string)($in['length'] ?? 'medium');

try {
    $s = get_settings();
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
}

try {
    $t = generate_email_template($s, $brief, $tone, $length);
} catch (RuntimeException $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 502);
}

json_out([
    'ok'      => true,
    'name'    => $t['name'],
    'subject' => $t['subject'],
    'body'    => $t['body'],
    'model'   => $s['groq_model'],
    'unknown_placeholders' => template_unknown_placeholders($t['subject'] . "\n" . $t['body']),
]);
