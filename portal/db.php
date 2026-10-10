<?php
require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        $pdo->exec("SET time_zone = '+00:00'");
    }
    return $pdo;
}

function send_cors(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function check_api_key(): void
{
    if (API_KEY === '') return;
    $given = $_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? '');
    if (!hash_equals(API_KEY, (string)$given)) {
        json_out(['ok' => false, 'error' => 'Invalid API key'], 401);
    }
}

// ---------------------------------------------------------------------------
// Columns added to `businesses` after the first release: column => [definition, AFTER column].
// setup.php adds them; endpoints that use them call ensure_business_columns() too, so an older
// table keeps working without opening setup.php again.
// ---------------------------------------------------------------------------
const BUSINESS_ADDED_COLUMNS = [
    'email'          => ['VARCHAR(255) NULL', 'phone_e164'],
    'emails'         => ['TEXT NULL', 'email'],
    'email_source'   => ['VARCHAR(20) NULL', 'emails'],            // website | chatgpt | claude
    'gpt_status'     => ['VARCHAR(10) NULL', 'email_source'],      // found | none | error
    'gpt_answer'     => ['TEXT NULL', 'gpt_status'],               // ChatGPT's reply, or the error
    'gpt_attempts'   => ['TINYINT UNSIGNED NOT NULL DEFAULT 0', 'gpt_answer'],
    'gpt_claimed_at' => ['DATETIME NULL', 'gpt_attempts'],         // UTC, handed to a worker
    'gpt_checked_at' => ['DATETIME NULL', 'gpt_claimed_at'],       // UTC, finished (won't be handed out again)
    // Gmail sender extension (sending_email/): get_business_to_email.php, save_email_result.php.
    'mail_status'     => ['VARCHAR(10) NULL', 'gpt_checked_at'],   // sent | error | failed (gave up)
    'mail_attempts'   => ['TINYINT UNSIGNED NOT NULL DEFAULT 0', 'mail_status'],
    'mail_error'      => ['TEXT NULL', 'mail_attempts'],           // last failure
    'mail_claimed_at' => ['DATETIME NULL', 'mail_error'],          // UTC, handed to a sender
    'mail_sent_at'    => ['DATETIME NULL', 'mail_claimed_at'],     // UTC, last successful send (any channel)
    // Claude email finder (find_emails_from_claude/): get_business_for_claude.php, save_claude_email.php.
    'claude_status'     => ['VARCHAR(10) NULL', 'mail_sent_at'],      // found | mentioned | none | error
    'claude_answer'     => ['TEXT NULL', 'claude_status'],            // Claude's reply, or the error
    'claude_attempts'   => ['TINYINT UNSIGNED NOT NULL DEFAULT 0', 'claude_answer'],
    'claude_claimed_at' => ['DATETIME NULL', 'claude_attempts'],      // UTC, handed to a worker
    'claude_checked_at' => ['DATETIME NULL', 'claude_claimed_at'],    // UTC, finished (won't be handed out again)
];

/** Adds any missing BUSINESS_ADDED_COLUMNS. Returns the names added. */
function ensure_business_columns(): array
{
    static $checked = false;
    if ($checked) return [];
    $pdo = db();
    $have = $pdo->query('SHOW COLUMNS FROM businesses')->fetchAll(PDO::FETCH_COLUMN);
    $added = [];
    foreach (BUSINESS_ADDED_COLUMNS as $col => [$def, $after]) {
        if (in_array($col, $have, true)) continue;
        $pdo->exec("ALTER TABLE businesses ADD COLUMN `$col` $def AFTER `$after`");
        $added[] = $col;
        // Every email saved before this column existed came from the website scraper.
        if ($col === 'email_source') {
            $pdo->exec("UPDATE businesses SET email_source = 'website' WHERE email IS NOT NULL AND email <> ''");
        }
    }
    // Businesses mailed from the portal before mail_sent_at existed count as sent, so the Gmail
    // sender doesn't mail them again: the same business (any ZIP) and the same address.
    if (in_array('mail_sent_at', $added, true)) {
        ensure_email_log_table();
        $pdo->exec("UPDATE businesses b
                    JOIN (SELECT b2.business_key, MAX(l.sent_at) sent_at FROM email_log l
                          JOIN businesses b2 ON b2.id = l.business_id WHERE l.status = 'sent'
                          GROUP BY b2.business_key) k ON k.business_key = b.business_key
                    SET b.mail_status = 'sent', b.mail_sent_at = k.sent_at");
        $pdo->exec("UPDATE businesses b
                    JOIN email_log l ON l.status = 'sent'
                     AND FIND_IN_SET(LOWER(b.email), REPLACE(LOWER(l.to_email), ', ', ','))
                    SET b.mail_status = 'sent', b.mail_sent_at = COALESCE(b.mail_sent_at, l.sent_at)
                    WHERE b.email IS NOT NULL AND b.email <> ''");
    }
    $checked = true;
    return $added;
}

/**
 * Records a successful send (send_email.php or the Gmail sender) on every row of the same business
 * (same business_key, other ZIPs) and every row whose email is one of the recipients, so
 * get_business_to_email.php never hands them out again. Returns the rows updated.
 */
function mark_business_mailed(int $businessId, array $recipients): int
{
    ensure_business_columns();
    $pdo = db();
    $stmt = $pdo->prepare('SELECT business_key FROM businesses WHERE id = ?');
    $stmt->execute([$businessId]);
    $key = $stmt->fetchColumn();
    if ($key === false) return 0;
    $emails = array_values(array_unique(array_map(fn($e) => strtolower(trim((string)$e)), $recipients)));
    $emails = array_values(array_filter($emails, fn($e) => $e !== ''));
    $in = $emails ? ' OR LOWER(email) IN (' . implode(',', array_fill(0, count($emails), '?')) . ')' : '';
    $upd = $pdo->prepare("UPDATE businesses SET mail_status = 'sent', mail_sent_at = UTC_TIMESTAMP(),
                              mail_claimed_at = NULL, mail_error = NULL
                          WHERE business_key = ?$in");
    $upd->execute(array_merge([$key], $emails));
    return $upd->rowCount();
}

// ---------------------------------------------------------------------------
// ZIP codes handed to the Maps scraper (get_zipcode.php, update_zipcode.php).
// `zipcodes` is imported by hand (id, zipcode, lat, long, status); `status` is:
// ---------------------------------------------------------------------------
// Every ZIP is handed out once: only status 0 is ever handed out, so each fetch gets a new ZIP.
const ZIP_PENDING = 0;      // never handed out
const ZIP_IN_PROGRESS = 1;  // handed out, being scraped
const ZIP_DONE = 2;
const ZIP_FAILED = 3;
const ZIP_STOPPED = 4;      // the run was stopped before it finished (set back to 0 to scrape it again)

const ZIPCODE_ADDED_COLUMNS = [
    'claimed_at'  => ['DATETIME NULL', 'status'],          // UTC, handed to the scraper
    'finished_at' => ['DATETIME NULL', 'claimed_at'],      // UTC, reported done or failed
    'scraped'     => ['INT UNSIGNED NULL', 'finished_at'], // businesses saved for this ZIP
];

/** Adds the ZIPCODE_ADDED_COLUMNS and a status index to `zipcodes`. Returns the names added. */
function ensure_zipcode_columns(): array
{
    static $checked = false;
    if ($checked) return [];
    $pdo = db();
    $have = $pdo->query('SHOW COLUMNS FROM zipcodes')->fetchAll(PDO::FETCH_COLUMN);
    $added = [];
    foreach (ZIPCODE_ADDED_COLUMNS as $col => [$def, $after]) {
        if (in_array($col, $have, true)) continue;
        $pdo->exec("ALTER TABLE zipcodes ADD COLUMN `$col` $def AFTER `$after`");
        $added[] = $col;
    }
    // ZIPs scraped before these columns existed (zip_id is the zipcodes id) count as done.
    if (in_array('finished_at', $added, true)) {
        $pdo->exec('UPDATE zipcodes z JOIN (SELECT zip_id, COUNT(*) n FROM businesses GROUP BY zip_id) b
                    ON b.zip_id = z.id
                    SET z.status = ' . ZIP_DONE . ', z.finished_at = UTC_TIMESTAMP(), z.scraped = b.n
                    WHERE z.status = ' . ZIP_PENDING);
    }
    $keys = $pdo->query("SHOW INDEX FROM zipcodes WHERE Key_name = 'idx_status'")->fetchAll();
    if (!$keys) $pdo->exec('ALTER TABLE zipcodes ADD KEY idx_status (status, id)');
    $checked = true;
    return $added;
}

// ---------------------------------------------------------------------------
// Email clean-up for everything saved (save_business.php, save_gpt_email.php, save_claude_email.php).
// ---------------------------------------------------------------------------
const EMAIL_PATTERN = '/[a-z0-9][a-z0-9._+-]{0,63}@[a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,24}/';

/**
 * One clean, lowercase address, or null. URL-encoded text is decoded first and junk stuck to the
 * front is dropped: "04%7c01%7canniedvorak@bloominbrands.com" (04|01|anniedvorak@…) becomes
 * "anniedvorak@bloominbrands.com".
 */
function clean_email($e): ?string
{
    if (!is_scalar($e)) return null;
    $e = strtolower(trim((string)$e));
    $e = preg_replace_callback('/%([0-9a-f]{2})/', function ($m) {
        $c = chr(hexdec($m[1]));
        return $c >= ' ' && $c <= '~' ? $c : ' ';
    }, $e);
    $e = preg_replace('/^mailto:/', '', $e);
    if (!preg_match_all(EMAIL_PATTERN, $e, $m)) return null;
    $e = end($m[0]);
    return strlen($e) <= 255 && filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
}

/** clean_email() on each item; invalid ones and duplicates dropped, order kept. */
function clean_emails($list): array
{
    if (is_string($list)) $list = json_decode($list, true) ?? [$list];
    $out = [];
    foreach ((array)$list as $e) {
        $e = clean_email($e);
        if ($e !== null && !in_array($e, $out, true)) $out[] = $e;
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Settings (settings.php): one row per key in the `settings` table.
// ---------------------------------------------------------------------------
const SETTINGS_DEFAULTS = [
    'smtp_host'      => 'smtp.gmail.com',
    'smtp_port'      => '587',
    'smtp_security'  => 'starttls',   // starttls | ssl | none
    'smtp_user'      => '',
    'smtp_password'  => '',
    'smtp_from_name' => '',
    'sender_phone'   => '',
    'groq_api_key'   => '',
    'groq_model'     => 'openai/gpt-oss-120b', // used by templates.php "Generate with Groq"
    // Marketing email (index.php "Email" button). Placeholders: {business_name}, {website},
    // {city}, {category}, {from_name}, {from_email} (smtp_user), {from_phone} (sender_phone).
    'email_subject'  => 'Professional Website Design Services – Let’s Build Your Online Presence',
    // **text** is sent in bold (mailer.php).
    'email_body'     => "Dear **{business_name}** team,\n\n"
        . "I hope this email finds you well.\n\n"
        . "My name is {from_name}, and I am the founder of wpwebdesignings.com. We specialize in designing "
        . "and developing modern, fast, and mobile-friendly websites that help businesses grow online.\n\n"
        . "Whether you need:\n"
        . "- A completely new website from scratch\n"
        . "- Redesign of your existing website\n"
        . "- E-commerce / Online store\n"
        . "- Business / Portfolio / Blog website\n"
        . "- WordPress or custom website\n\n"
        . "…we can deliver a clean, professional, and conversion-focused design tailored to your brand.\n\n"
        . "**Why choose wpwebdesignings.com?**\n"
        . "✓ Clean & modern designs\n"
        . "✓ Mobile responsive\n"
        . "✓ Fast loading speed\n"
        . "✓ SEO-friendly structure\n"
        . "✓ Easy to manage\n"
        . "✓ Affordable packages\n\n"
        . "I would love to understand your requirements and share some suitable package options with you.\n\n"
        . "Would you be available for a quick 10–15 minute call this week? Or simply reply to this email "
        . "with your requirements, and I’ll send you a free quotation.\n\n"
        . "Looking forward to helping you create a strong online presence.\n\n"
        . "Best regards,\n"
        . "{from_name}\n"
        . "Founder | wpwebdesignings.com\n"
        . "{from_phone}\n"
        . "{from_email}\n"
        . "Website: https://wpwebdesignings.com",
];

function ensure_settings_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key   VARCHAR(64) NOT NULL,
        setting_value TEXT        NULL,
        updated_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** All settings, with defaults for keys not saved yet. */
function get_settings(): array
{
    ensure_settings_table();
    $saved = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_merge(SETTINGS_DEFAULTS, array_intersect_key($saved, SETTINGS_DEFAULTS));
}

/** Saves the known keys in $values; unknown keys are ignored. */
function save_settings(array $values): void
{
    ensure_settings_table();
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    foreach (array_intersect_key($values, SETTINGS_DEFAULTS) as $k => $v) {
        $stmt->execute([$k, (string)$v]);
    }
}

// ---------------------------------------------------------------------------
// Email templates (templates.php, get_random_template.php). Same placeholders as the Settings template.
// ---------------------------------------------------------------------------
function ensure_email_templates_table(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS email_templates (
        id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        name        VARCHAR(120)  NOT NULL,
        subject     VARCHAR(255)  NOT NULL,
        body        TEXT          NOT NULL,
        is_active   TINYINT(1)    NOT NULL DEFAULT 1,   -- 0 = never picked at random
        source      VARCHAR(10)   NOT NULL DEFAULT 'manual',  -- manual | groq
        times_used  INT UNSIGNED  NOT NULL DEFAULT 0,   -- picks by get_random_template.php
        created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// ---------------------------------------------------------------------------
// Email log (send_email.php): one row per send attempt.
// ---------------------------------------------------------------------------
// Columns added to `email_log` after the first release: column => [definition, AFTER column].
const EMAIL_LOG_ADDED_COLUMNS = [
    'channel'    => ['VARCHAR(10) NULL', 'status'],     // smtp (send_email.php) | gmail (sending_email/ extension)
    'from_email' => ['VARCHAR(255) NULL', 'channel'],   // Gmail account the extension sent from
];

function ensure_email_log_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db()->exec("CREATE TABLE IF NOT EXISTS email_log (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        business_id BIGINT UNSIGNED NULL,
        to_email    VARCHAR(255)    NOT NULL,
        subject     VARCHAR(255)    NOT NULL,
        body        TEXT            NULL,
        status      VARCHAR(10)     NOT NULL,          -- sent | failed
        error       TEXT            NULL,
        sent_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- UTC
        PRIMARY KEY (id),
        KEY idx_business (business_id),
        KEY idx_to_email (to_email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $have = db()->query('SHOW COLUMNS FROM email_log')->fetchAll(PDO::FETCH_COLUMN);
    foreach (EMAIL_LOG_ADDED_COLUMNS as $col => [$def, $after]) {
        if (!in_array($col, $have, true)) db()->exec("ALTER TABLE email_log ADD COLUMN `$col` $def AFTER `$after`");
    }
}
