<?php
/*
 * Email verification, used by verify_email.php, index.php and both senders.
 * An address is checked in steps, and nothing is ever sent to it:
 *   1. format (clean_email)
 *   2. disposable-mail domain
 *   3. the domain has a mail server (MX record, or an A record as the RFC fallback)
 *   4. SMTP: connect to that server on port 25, say who we are, ask it to accept the address
 *      (RCPT TO) and hang up before any message. A made-up address at the same domain is asked
 *      too: when that is also accepted the domain is catch-all and the real one can't be confirmed.
 * One row per address in `email_checks`. Status:
 *   valid   – the server accepts this mailbox and rejects made-up ones
 *   invalid – bad format, the domain takes no mail, or the server said the mailbox doesn't exist
 *   risky   – catch-all domain, full mailbox or disposable domain: it may bounce
 *   unknown – the server wouldn't say (blocked our IP, greylisted us, timed out). Retried later.
 */
require_once __DIR__ . '/db.php';

const VERIFY_STATUSES = ['valid', 'invalid', 'risky', 'unknown'];
const VERIFY_CONNECT_TIMEOUT = 10; // seconds, per mail server
const VERIFY_READ_TIMEOUT = 15;    // seconds, per SMTP reply (some servers wait before their greeting)
const VERIFY_MAX_MX = 2;           // mail servers tried when one can't be reached
const VERIFY_UNKNOWN_RETRIES = 3;  // `unknown` addresses are checked again up to this many times…
const VERIFY_RETRY_AFTER_MIN = 60; // …at least this many minutes apart (greylisting usually clears by then)

const DISPOSABLE_DOMAINS = [
    'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com', '10minutemail.com',
    'temp-mail.org', 'tempmail.com', 'tempmailo.com', 'yopmail.com', 'trashmail.com', 'getnada.com',
    'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com', 'mohmal.com', 'emailondeck.com',
];

function ensure_email_checks_table(): void
{
    static $checked = false;
    if ($checked) return;
    $checked = true;
    db()->exec("CREATE TABLE IF NOT EXISTS email_checks (
        email       VARCHAR(255)  NOT NULL,
        status      VARCHAR(10)   NOT NULL,             -- valid | invalid | risky | unknown
        reason      VARCHAR(255)  NOT NULL DEFAULT '',  -- plain-English explanation
        mx_host     VARCHAR(255)  NULL,                 -- mail server asked
        smtp_code   SMALLINT      NULL,                 -- its reply code to RCPT TO (0 = no reply)
        smtp_reply  VARCHAR(500)  NULL,                 -- its reply text
        attempts    TINYINT UNSIGNED NOT NULL DEFAULT 1,
        checked_at  DATETIME      NOT NULL,             -- UTC
        PRIMARY KEY (email),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** Saved checks for these addresses: lowercase email => row. Unchecked addresses are left out. */
function email_checks(array $emails): array
{
    $emails = array_values(array_unique(array_filter(array_map(fn($e) => strtolower(trim((string)$e)), $emails))));
    if (!$emails) return [];
    ensure_email_checks_table();
    $out = [];
    foreach (array_chunk($emails, 500) as $chunk) {
        $stmt = db()->prepare('SELECT * FROM email_checks WHERE email IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        foreach ($stmt as $r) $out[strtolower($r['email'])] = $r;
    }
    return $out;
}

/** The addresses in $emails that failed verification (status invalid). */
function invalid_emails(array $emails): array
{
    $checks = email_checks($emails);
    return array_values(array_filter($emails, fn($e) => ($checks[strtolower(trim((string)$e))]['status'] ?? '') === 'invalid'));
}

/** Every address of a businesses row (email + emails), clean and lowercase, primary first. */
function row_emails(array $row): array
{
    $list = json_decode((string)($row['emails'] ?? ''), true);
    return clean_emails(array_merge([(string)($row['email'] ?? '')], is_array($list) ? $list : []));
}

/**
 * Addresses still to check: never checked, or `unknown` and due for a retry. Oldest business first,
 * or in random order with $random. Returns [$limit of them as [email, business_id], how many there are in all].
 */
function pending_email_checks(int $limit, bool $random = false): array
{
    ensure_email_checks_table();
    $pdo = db();
    $done = [];
    $retryBefore = gmdate('Y-m-d H:i:s', time() - VERIFY_RETRY_AFTER_MIN * 60);
    foreach ($pdo->query('SELECT email, status, attempts, checked_at FROM email_checks') as $c) {
        $due = $c['status'] === 'unknown' && $c['attempts'] < VERIFY_UNKNOWN_RETRIES && $c['checked_at'] < $retryBefore;
        if (!$due) $done[strtolower($c['email'])] = true;
    }
    $pending = [];
    $rows = $pdo->query("SELECT id, email, emails FROM businesses WHERE email IS NOT NULL AND email <> '' ORDER BY id");
    foreach ($rows as $r) {
        foreach (row_emails($r) as $e) {
            if (!isset($done[$e]) && !isset($pending[$e])) $pending[$e] = (int)$r['id'];
        }
    }
    $emails = array_keys($pending);
    if ($random) shuffle($emails);
    $first = [];
    foreach (array_slice($emails, 0, $limit) as $e) $first[] = ['email' => $e, 'business_id' => $pending[$e]];
    return [$first, count($pending)];
}

/** Verifies one address and saves the result. Returns the saved row. */
function verify_and_save(string $email): array
{
    ensure_email_checks_table();
    $r = verify_email($email);
    db()->prepare('INSERT INTO email_checks (email, status, reason, mx_host, smtp_code, smtp_reply, attempts, checked_at)
                   VALUES (?, ?, ?, ?, ?, ?, 1, UTC_TIMESTAMP())
                   ON DUPLICATE KEY UPDATE status = VALUES(status), reason = VALUES(reason), mx_host = VALUES(mx_host),
                       smtp_code = VALUES(smtp_code), smtp_reply = VALUES(smtp_reply),
                       attempts = IF(VALUES(status) = \'unknown\', attempts + 1, 1), checked_at = VALUES(checked_at)')
        ->execute([$r['email'], $r['status'], mb_substr($r['reason'], 0, 255), $r['mx_host'], $r['smtp_code'], $r['smtp_reply'] === null ? null : mb_substr($r['smtp_reply'], 0, 500)]);
    return email_checks([$r['email']])[$r['email']] ?? $r;
}

/** Runs the checks on one address (nothing saved). */
function verify_email(string $email): array
{
    $out = ['email' => strtolower(trim($email)), 'status' => 'invalid', 'reason' => '', 'mx_host' => null, 'smtp_code' => null, 'smtp_reply' => null];
    $clean = clean_email($email);
    if ($clean === null || $clean !== $out['email']) {
        $out['reason'] = 'Not a valid email address.';
        return $out;
    }
    $domain = substr($clean, strrpos($clean, '@') + 1);
    if (in_array($domain, DISPOSABLE_DOMAINS, true)) {
        return ['status' => 'risky', 'reason' => 'Disposable (throwaway) email domain.'] + $out;
    }

    $mx = mail_servers($domain);
    if ($mx === null) {
        $out['reason'] = "The domain $domain does not accept email.";
        return $out;
    }
    if (!$mx) {
        $out['reason'] = "The domain $domain has no mail server (no MX or A record).";
        return $out;
    }

    $probe = null;
    foreach (array_slice($mx, 0, VERIFY_MAX_MX) as $host) {
        $probe = smtp_probe($host, $clean, $domain);
        $out['mx_host'] = $host;
        if (!$probe['unreachable']) break; // only try the next server when this one can't be reached
    }
    $out['smtp_code'] = $probe['code'];
    $out['smtp_reply'] = $probe['reply'];
    return [
        'status' => $probe['status'],
        'reason' => $probe['reason'],
    ] + $out;
}

/**
 * Mail servers for a domain, best first. [] when it has none, null when it says it takes no mail
 * (RFC 7505 null MX, like example.com).
 */
function mail_servers(string $domain): ?array
{
    $hosts = [];
    $weights = [];
    if (@getmxrr($domain, $hosts, $weights) && $hosts) {
        array_multisort($weights, SORT_NUMERIC, $hosts);
        $hosts = array_values(array_filter($hosts, fn($h) => $h !== '' && $h !== '.'));
        return $hosts ?: null;
    }
    // No MX: mail goes to the domain itself if it has an address (RFC 5321 §5.1).
    return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA') ? [$domain] : [];
}

/** One SMTP reply (all lines of a multi-line one): [code, text]. Code 0 = no reply / timeout. */
function verify_smtp_read($fp): array
{
    $text = '';
    while (($line = fgets($fp, 2048)) !== false) {
        $text .= $line;
        if (strlen($line) < 4 || $line[3] !== '-') break;
    }
    if ($text === '') return [0, stream_get_meta_data($fp)['timed_out'] ? 'No reply (timed out)' : 'Connection closed'];
    return [(int)substr($text, 0, 3), trim(preg_replace('/\s+/', ' ', $text))];
}

function verify_smtp_cmd($fp, string $cmd): array
{
    fwrite($fp, $cmd . "\r\n");
    return verify_smtp_read($fp);
}

/**
 * Asks $host whether it accepts $email. Returns status, reason, code + reply (to RCPT TO, or to the
 * step that failed), and unreachable = true when no SMTP conversation was possible at all.
 */
function smtp_probe(string $host, string $email, string $domain): array
{
    $res = fn($status, $reason, $code = null, $reply = null, $unreachable = false) =>
        compact('status', 'reason', 'code', 'reply', 'unreachable');

    $fp = @stream_socket_client("tcp://$host:25", $errno, $errstr, VERIFY_CONNECT_TIMEOUT);
    if (!$fp) return $res('unknown', "Could not reach the mail server $host on port 25 ($errstr).", 0, $errstr, true);
    stream_set_timeout($fp, VERIFY_READ_TIMEOUT);

    try {
        [$code, $reply] = verify_smtp_read($fp);
        if ($code !== 220) return $res('unknown', blocked_reason($reply, "The mail server $host refused the connection."), $code, $reply, $code === 0);

        [$helo, $from] = verify_identity();
        [$code, $reply] = verify_smtp_cmd($fp, "EHLO $helo");
        if ($code !== 250) [$code, $reply] = verify_smtp_cmd($fp, "HELO $helo");
        if ($code !== 250) return $res('unknown', 'The mail server did not accept our greeting.', $code, $reply);

        [$code, $reply] = verify_smtp_cmd($fp, "MAIL FROM:<$from>");
        if ($code !== 250) return $res('unknown', blocked_reason($reply, 'The mail server refused to talk to us (sender step).'), $code, $reply);

        [$code, $reply] = verify_smtp_cmd($fp, "RCPT TO:<$email>");
        if ($code >= 200 && $code < 300) {
            // Accepted. Would it accept anything? Ask for a mailbox that can't exist.
            [$fake] = verify_smtp_cmd($fp, 'RCPT TO:<' . 'no-such-user-' . bin2hex(random_bytes(6)) . "@$domain>");
            if ($fake >= 200 && $fake < 300) {
                return $res('risky', 'The domain accepts every address (catch-all), so this mailbox can\'t be confirmed.', $code, $reply);
            }
            return $res('valid', 'The mail server confirmed this mailbox exists.', $code, $reply);
        }
        if ($code >= 400 && $code < 500) {
            return $res('unknown', 'The mail server asked us to try later (greylisting or rate limit).', $code, $reply);
        }
        if ($code >= 500) return classify_rejection($code, $reply, $res);
        return $res('unknown', 'The mail server did not answer.', $code, $reply);
    } finally {
        @fwrite($fp, "QUIT\r\n");
        @fclose($fp);
    }
}

const VERIFY_BLOCKED_PATTERN = '/spamhaus|spamcop|barracuda|block ?list|black ?list|blocked|reputation|\brbl\b|dnsbl|client host|your ip|'
    . 'ip address|policy|relay|helo|reverse dns|ptr record|not permitted|too many|rate limit|try again|\b5\.7\.\d+\b|sender|\bpbl\b/i';

/** $default, or a plainer explanation when the reply says the server is blocking us rather than the address. */
function blocked_reason(string $reply, string $default): string
{
    return preg_match(VERIFY_BLOCKED_PATTERN, $reply) ? 'The mail server blocked the check (our IP or policy), not the address.' : $default;
}

/**
 * A 5xx reply to RCPT TO. Only a clear "no such mailbox" makes the address invalid: a server that is
 * blocking *us* (IP reputation, policy) says nothing about the mailbox.
 */
function classify_rejection(int $code, string $reply, callable $res): array
{
    $noUser = $res('invalid', 'The mail server said this mailbox does not exist.', $code, $reply);
    if ($code === 552 || preg_match('/\b5\.2\.2\b|mailbox (?:is )?full|over ?quota|quota exceeded/i', $reply)) {
        return $res('risky', 'The mailbox exists but is full, so mail to it bounces for now.', $code, $reply);
    }
    // Enhanced status codes (RFC 3463): 5.1.x = bad destination mailbox, 5.2.1 = mailbox disabled,
    // 5.4.1 "Recipient address rejected" = Microsoft 365's answer for a mailbox it doesn't have.
    if (preg_match('/\b5\.1\.(?:0|1|6|10)\b|\b5\.2\.1\b|\b5\.4\.1\b.*recipient address rejected/i', $reply)) return $noUser;
    if (preg_match(VERIFY_BLOCKED_PATTERN, $reply)) {
        return $res('unknown', blocked_reason($reply, ''), $code, $reply);
    }
    if (preg_match('/user unknown|unknown user|no such (?:user|recipient|mailbox)|user not found|recipient not found|unknown recipient|'
        . '(?:does not|doesn\'t|not) exist|mailbox (?:unavailable|not found|is disabled)|invalid (?:recipient|mailbox|address)|'
        . 'no mailbox|account (?:has been )?disabled|recipient address rejected|undeliverable/i', $reply)) return $noUser;
    return $res('unknown', 'The mail server rejected the check without saying why.', $code, $reply);
}

/**
 * What we call ourselves: [HELO name, MAIL FROM address]. The Gmail address from Settings when there
 * is one (and its domain, unless that is a free-mail domain, for HELO); otherwise this computer's name
 * and the empty sender <> that bounce messages use.
 */
function verify_identity(): array
{
    static $id = null;
    if ($id !== null) return $id;
    $from = '';
    try { $from = (string)(get_settings()['smtp_user'] ?? ''); } catch (Throwable $e) { /* no settings table yet */ }
    $from = clean_email($from) ?? '';
    $fromDomain = $from !== '' ? substr($from, strrpos($from, '@') + 1) : '';
    $free = ['gmail.com', 'googlemail.com', 'yahoo.com', 'outlook.com', 'hotmail.com', 'live.com', 'icloud.com', 'aol.com'];
    $host = strtolower((string)gethostname());
    $helo = $fromDomain !== '' && !in_array($fromDomain, $free, true) ? $fromDomain
        : (strpos($host, '.') !== false ? $host : 'localhost.localdomain');
    return $id = [$helo, $from];
}
