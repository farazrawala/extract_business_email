<?php
// Open once in the browser to create the businesses table (or add columns added in later
// versions to an existing one), then delete or protect this file.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email_verify.php';
check_api_key();

try {
    $pdo = db();
    $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
    ensure_settings_table();
    ensure_email_log_table();
    ensure_email_templates_table();
    ensure_email_checks_table();
    $added = ensure_business_columns(); // BUSINESS_ADDED_COLUMNS in db.php
    // `zipcodes` is imported by hand; when it's there, add the columns get_zipcode.php uses.
    $hasZips = (bool)$pdo->query("SHOW TABLES LIKE 'zipcodes'")->fetchColumn();
    $zipAdded = $hasZips ? ensure_zipcode_columns() : [];
    json_out([
        'ok'      => true,
        'message' => 'Tables "businesses", "settings", "email_log", "email_templates" and "email_checks" are ready in ' . DB_NAME,
        'added_columns' => $added,
        'zipcodes' => $hasZips ? ['added_columns' => $zipAdded] : 'missing: import the zipcodes table',
    ]);
} catch (Throwable $e) {
    json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
