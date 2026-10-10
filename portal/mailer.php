<?php
/*
 * Minimal SMTP client (no dependencies) for plain-text UTF-8 mail.
 * Supports STARTTLS (587), implicit SSL/TLS (465) or none (25), and AUTH LOGIN.
 * Uses the smtp_* values from get_settings(). Throws RuntimeException on any failure.
 */

const SMTP_TIMEOUT = 20; // seconds, for connect and each reply

/** Throws if the SMTP settings are not filled in enough to send. */
function smtp_check_settings(array $s): void
{
    $missing = [];
    foreach (['smtp_host' => 'Host', 'smtp_port' => 'Port', 'smtp_user' => 'Gmail address', 'smtp_password' => 'App password'] as $k => $label) {
        if (trim((string)($s[$k] ?? '')) === '') $missing[] = $label;
    }
    if ($missing) throw new RuntimeException('SMTP is not set up. Missing in Settings: ' . implode(', ', $missing) . '.');
}

/** Sends one email to every address in $to (already validated), all listed in the To header. */
function smtp_send(array $s, array $to, string $subject, string $body): void
{
    if (!$to) throw new RuntimeException('No recipient.');
    smtp_check_settings($s);
    $host = trim($s['smtp_host']);
    $port = (int)$s['smtp_port'];
    $security = $s['smtp_security'];
    $user = trim($s['smtp_user']);
    $fromName = trim(preg_replace('/[\r\n]+/', ' ', (string)$s['smtp_from_name']));

    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host]]);
    $remote = ($security === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, SMTP_TIMEOUT, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) throw new RuntimeException("Could not connect to $host:$port ($errstr).");
    stream_set_timeout($fp, SMTP_TIMEOUT);

    try {
        smtp_expect($fp, [220]);
        $ehloHost = gethostname() ?: 'localhost';
        smtp_cmd($fp, "EHLO $ehloHost", [250]);
        if ($security === 'starttls') {
            smtp_cmd($fp, 'STARTTLS', [220]);
            $crypto = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT : 0);
            if (!@stream_socket_enable_crypto($fp, true, $crypto)) throw new RuntimeException('STARTTLS handshake failed.');
            smtp_cmd($fp, "EHLO $ehloHost", [250]);
        }
        smtp_cmd($fp, 'AUTH LOGIN', [334]);
        smtp_cmd($fp, base64_encode($user), [334]);
        smtp_cmd($fp, base64_encode((string)$s['smtp_password']), [235], 'Login failed. Check the Gmail address and app password in Settings.');
        smtp_cmd($fp, "MAIL FROM:<$user>", [250]);
        foreach ($to as $rcpt) {
            smtp_cmd($fp, "RCPT TO:<$rcpt>", [250, 251], "The server refused the address $rcpt.");
        }
        smtp_cmd($fp, 'DATA', [354]);

        $domain = substr(strrchr($user, '@') ?: '@localhost', 1);
        $boundary = 'b_' . bin2hex(random_bytes(12));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($fromName !== '' ? smtp_encode_header($fromName) . " <$user>" : $user),
            'To: ' . implode(', ', array_map(fn($a) => "<$a>", $to)),
            'Subject: ' . smtp_encode_header(preg_replace('/[\r\n]+/', ' ', $subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
            'MIME-Version: 1.0',
            "Content-Type: multipart/alternative; boundary=\"$boundary\"",
        ];
        $body = preg_replace('/\r\n|\r|\n/', "\n", $body);
        $part = fn(string $type, string $content) => "--$boundary\r\n"
            . "Content-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($content), 76, "\r\n");
        $data = implode("\r\n", $headers) . "\r\n\r\n"
            . $part('text/plain', str_replace("\n", "\r\n", mail_plain_text($body)))
            . $part('text/html', mail_html($body))
            . "--$boundary--";
        // Headers, boundaries and base64 never start a line with ".", so no dot-stuffing is needed.
        smtp_cmd($fp, $data . "\r\n.", [250]);
        @fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/** Valid addresses for a business: `email` first, then the rest of `emails`, no duplicates. */
function business_emails(array $biz): array
{
    $list = json_decode((string)$biz['emails'], true);
    $all = array_merge([(string)$biz['email']], is_array($list) ? $list : []);
    $out = [];
    foreach ($all as $e) {
        $e = trim((string)$e);
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) && !in_array(strtolower($e), array_map('strtolower', $out), true)) $out[] = $e;
    }
    return $out;
}

/**
 * Fills the template placeholders from a businesses row and the settings.
 * {business_name}, {website} (domain only), {city}, {category}, {from_name}, {from_email}, {from_phone}.
 * A line holding only a placeholder that is empty (e.g. {from_phone} with no phone set) is dropped.
 */
function fill_template(string $tpl, array $biz, array $s): string
{
    $site = rtrim(preg_replace('#^https?://(www\.)?#i', '', (string)($biz['website'] ?? '')), '/');
    $site = preg_replace('#[/?].*$#', '', $site);
    $values = [
        '{business_name}' => trim((string)($biz['name'] ?? '')) ?: 'your business',
        '{website}'       => $site !== '' ? $site : 'your website',
        '{city}'          => trim((string)($biz['city'] ?? '')) ?: 'your area',
        '{category}'      => trim((string)($biz['category'] ?? '')) ?: 'business',
        '{from_name}'     => trim((string)($s['smtp_from_name'] ?? '')),
        '{from_email}'    => trim((string)($s['smtp_user'] ?? '')),
        '{from_phone}'    => trim((string)($s['sender_phone'] ?? '')),
    ];
    foreach ($values as $ph => $v) {
        if ($v === '') $tpl = preg_replace('/^[ \t]*' . preg_quote($ph, '/') . '[ \t]*(\r?\n|$)/m', '', $tpl);
    }
    return strtr($tpl, $values);
}

// Bold markup in templates: **text** on one line.
const MAIL_BOLD = '/\*\*([^\n*]+?)\*\*/';
// Highlight markup: [[text]] on one line, a detail taken from the business (generate_email.php).
const MAIL_FACT = '/\[\[([^\[\]\n]{1,200}?)\]\]/';
const MAIL_FACT_STYLE = 'background-color:#fff3a3;font-weight:bold';

/** Plain-text part: the message without the ** and [[ ]] markers. */
function mail_plain_text(string $body): string
{
    return preg_replace(MAIL_BOLD, '$1', strip_fact_marks($body));
}

/** The text without [[ ]] marks (and any stray, unpaired [[ or ]]). */
function strip_fact_marks(string $t): string
{
    return str_replace(['[[', ']]'], '', preg_replace(MAIL_FACT, '$1', $t));
}

/** [[detail]] -> **detail** (bold instead of highlighted). Inside a **bold** sentence it is already bold. */
function fact_marks_to_bold(string $t): string
{
    $t = preg_replace_callback('/\*\*([^\n]+?)\*\*/', fn($m) => '**' . strip_fact_marks($m[1]) . '**', $t);
    return preg_replace(MAIL_FACT, '**$1**', $t);
}

/**
 * Cleans [[ ]] marks written by Groq: bold around or inside a mark is dropped (**[[x]]**,
 * [[**x**]] -> [[x]]), and marks around a {placeholder} go (the sender details aren't facts).
 */
function tidy_fact_marks(string $t): string
{
    $t = preg_replace('/\*\*\s*(\[\[[^\[\]\n]+?\]\])\s*\*\*/', '$1', $t);
    $t = preg_replace_callback(MAIL_FACT, fn($m) => '[[' . trim(str_replace('**', '', $m[1])) . ']]', $t);
    return preg_replace('/\[\[\s*(\{[a-z_]+\})\s*\]\]/i', '$1', $t);
}

/** HTML part: escaped text, **text** in bold, links clickable, line breaks kept. */
function mail_html(string $body): string
{
    return "<!doctype html>\r\n<html><body style=\"font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222\">\r\n"
        . mail_html_fragment($body) . "\r\n</body></html>";
}

/**
 * The message as an HTML fragment (no page wrapper), e.g. to paste into Gmail's compose box:
 * **text** in bold, [[text]] highlighted (bold on a yellow background), links clickable.
 */
function mail_html_fragment(string $body): string
{
    $h = htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    // Highlights before links, so a marked address doesn't take the closing ]] into its link.
    $h = preg_replace(MAIL_FACT, '<span style="' . MAIL_FACT_STYLE . '">$1</span>', $h);
    $h = str_replace(['[[', ']]'], '', $h);
    $h = preg_replace(MAIL_BOLD, '<b>$1</b>', $h);
    $h = preg_replace('#\bhttps?://[^\s<]+#i', '<a href="$0">$0</a>', $h);
    return str_replace("\n", "<br>\r\n", $h);
}

function smtp_encode_header(string $v): string
{
    return preg_match('/[^\x20-\x7e]/', $v) ? '=?UTF-8?B?' . base64_encode($v) . '?=' : $v;
}

function smtp_cmd($fp, string $line, array $ok, string $hint = ''): string
{
    if (@fwrite($fp, $line . "\r\n") === false) throw new RuntimeException('Connection to the SMTP server was lost.');
    return smtp_expect($fp, $ok, $hint);
}

/** Reads a (possibly multi-line) reply and throws unless its code is in $ok. */
function smtp_expect($fp, array $ok, string $hint = ''): string
{
    $reply = '';
    while (($line = fgets($fp, 1024)) !== false) {
        $reply .= $line;
        if (strlen($line) < 4 || $line[3] === ' ') break;
    }
    if ($reply === '') {
        $meta = stream_get_meta_data($fp);
        throw new RuntimeException($meta['timed_out'] ? 'The SMTP server timed out.' : 'The SMTP server closed the connection.');
    }
    $code = (int)substr($reply, 0, 3);
    if (!in_array($code, $ok, true)) {
        $msg = trim(preg_replace('/\s+/', ' ', $reply));
        throw new RuntimeException(($hint !== '' ? "$hint " : '') . "Server said: $msg");
    }
    return $reply;
}
