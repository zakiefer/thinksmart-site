<?php
// Think Smart lead mailer — runs on the site's own PHP hosting (GoDaddy cPanel).
//
// Two request shapes, both answered with JSON {success, message}:
//   1. JSON POST {kind:'claim'|'chat'|'contact', case_no?, payload:{...}}  -> the lead.
//   2. multipart POST with photos[] (+ case_no, name)                      -> claim photos.
// Every lead is SAVED FIRST in the private folder (~/thinksmart-private/leads, never the web
// root), then emailed; the visitor sees success once it's safely saved. Leads are kept 90 days,
// photos 14. The editor shows them on its Leads screen. This file runs on its own, without the
// editor's code, so an editor problem can never stop leads.
//
// The From address is derived from the hosted domain because GoDaddy's relay rejects
// foreign From headers.

declare(strict_types=1);

ini_set('display_errors', '0');

const LEAD_TO          = 'thinksmartpublicadjusting@gmail.com'; // until the editor's settings say otherwise
const MAIL_LIMIT_LEADS = 400;  // emails a day (shared with the editor) before leads wait in the editor
const MAIL_LIMIT_NOTE  = 420;  // the one "leads are waiting" notice
const KEEP_DAYS        = 90;
const PHOTO_DAYS       = 14;
const MAX_BODY         = 20000;            // bytes, JSON leads
const MAX_PHOTOS       = 10;
const MAX_PHOTO_BYTES  = 6 * 1024 * 1024;  // per photo
const MAX_PHOTOS_TOTAL = 15 * 1024 * 1024; // per email

// ~/thinksmart-private beside the web root (or TS_PRIVATE_DIR); '' when it isn't there.
function private_dir(): string {
    $env = getenv('TS_PRIVATE_DIR');
    $dir = is_string($env) && $env !== '' ? $env : dirname(__DIR__) . '/thinksmart-private';
    return is_dir($dir) && is_writable($dir) ? rtrim($dir, '/') : '';
}

function read_json(string $file): array {
    $v = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    return is_array($v) ? $v : [];
}

// Temp file in the same folder, then rename: never a half-written lead.
function write_json(string $file, array $data): bool {
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        return false;
    }
    $tmp = $dir . '/.' . basename($file) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json . "\n", LOCK_EX) !== strlen($json) + 1 || !@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function central_day(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('America/Chicago')))->format('Y-m-d');
}

// The editor's settings: where leads go (confirmed addresses; the waiting ones while none is
// confirmed yet), the business's own numbers and emails, and whether mail goes to an outbox
// (tests and development).
function settings(): array {
    static $s = null;
    if ($s !== null) {
        return $s;
    }
    $priv = private_dir();
    $cfg = $priv !== '' ? read_json($priv . '/config.json') : [];
    $set = $priv !== '' ? read_json($priv . '/settings.json') : [];
    $flt = $priv !== '' ? read_json($priv . '/filters.json') : [];
    $to = [];
    foreach ((array)($set['leadTo'] ?? []) as $a) {
        if (is_array($a) && ($a['state'] ?? '') === 'confirmed' && filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $to[] = (string)$a['email'];
        }
    }
    if ($to === []) {
        foreach ((array)($set['leadTo'] ?? []) as $a) {
            if (is_array($a) && filter_var($a['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                $to[] = (string)$a['email'];
            }
        }
    }
    $phones = array_values(array_filter(array_map('strval', (array)($flt['ownPhones'] ?? []))));
    $emails = array_values(array_filter(array_map('strtolower', array_map('strval', (array)($flt['ownEmails'] ?? [])))));
    return $s = [
        'to' => $to !== [] ? array_slice($to, 0, 3) : [LEAD_TO],
        'ownPhones' => $phones !== [] ? $phones : ['8127745049', '8128501050'],
        'ownEmails' => array_values(array_unique(array_merge($emails, [strtolower(LEAD_TO)]))),
        'outbox' => ($cfg['mail'] ?? '') === 'outbox',
        'private' => $priv,
    ];
}

// Today's emails, counted together with the editor's (GoDaddy allows 500 a day).
function take_mail(string $kind): bool {
    $priv = settings()['private'];
    if ($priv === '') {
        return true;
    }
    $limit = $kind === 'notice' ? MAIL_LIMIT_NOTE : MAIL_LIMIT_LEADS;
    $fh = @fopen($priv . '/mail-count.json', 'c+');
    if ($fh === false) {
        return true;
    }
    $ok = true;
    if (flock($fh, LOCK_EX)) {
        $c = json_decode((string)stream_get_contents($fh), true);
        $day = central_day();
        if (!is_array($c) || ($c['day'] ?? '') !== $day) {
            $c = ['day' => $day, 'n' => 0];
        }
        if ((int)$c['n'] >= $limit) {
            $ok = false;
        } else {
            $c['n'] = (int)$c['n'] + 1;
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string)json_encode($c));
            fflush($fh);
        }
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $ok;
}

function out(bool $ok, string $msg = '', int $code = 200, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// Header values must never contain CR/LF or other control bytes (header injection;
// PHP 8's mail() also throws on NUL bytes).
function header_safe(string $s): string {
    return trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
}

// Body values keep newlines and tabs but lose every other control byte.
function body_safe(string $s): string {
    return trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', str_replace("\r", '', $s)));
}

function encode_subject(string $s): string {
    $s = header_safe($s);
    return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
}

function site_domain(): string {
    $domain = strtolower((string)($_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost')));
    $domain = (string)preg_replace('/[^a-z0-9.\-]/', '', $domain);
    $domain = (string)preg_replace('/^www\./', '', $domain);
    return $domain !== '' ? $domain : 'localhost';
}

function from_address(): string {
    return 'noreply@' . site_domain();
}

function from_header(): string {
    return 'Think Smart Website <' . from_address() . '>';
}

// Sends with the envelope sender set to the From address so SPF/DMARC line up;
// falls back to a plain send on hosts that refuse the -f switch.
function send_mail(string $subject, string $body, array $headers, ?array $to = null): bool {
    $to = $to ?? settings()['to'];
    $list = implode(', ', array_map('header_safe', $to));
    if (settings()['outbox']) {
        // development and tests: nothing is sent
        $dir = settings()['private'] . '/outbox';
        return write_json($dir . '/' . gmdate('Ymd-His') . '-lead-' . bin2hex(random_bytes(3)) . '.json',
            ['to' => $to, 'subject' => $subject, 'headers' => $headers, 'body' => $body, 'kind' => 'lead', 'at' => time()]);
    }
    if (@mail($list, $subject, $body, $headers, '-f' . from_address())) {
        return true;
    }
    return @mail($list, $subject, $body, $headers);
}

// Cross-site pages must not be able to drive this endpoint from a visitor's browser.
function require_same_origin(): void {
    $host   = strtolower((string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    $source = (string)($_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? ''));
    if ($source === '' || $host === '') {
        return;
    }
    $sourceHost = strtolower((string)parse_url($source, PHP_URL_HOST));
    if ($sourceHost !== $host) {
        out(false, 'cross-site request refused', 403);
    }
}

// Saves a lead in ~/thinksmart-private/leads/<YYYY-MM>/<id>.json. Returns the lead (with its
// id) or null when it couldn't be saved.
function store_lead(array $lead): ?array {
    $priv = settings()['private'];
    if ($priv === '') {
        return null;
    }
    $now = time();
    $lead = ['id' => gmdate('Ymd-His', $now) . '-' . bin2hex(random_bytes(3)), 'at' => $now] + $lead;
    $file = $priv . '/leads/' . substr($lead['id'], 0, 4) . '-' . substr($lead['id'], 4, 2) . '/' . $lead['id'] . '.json';
    if (!write_json($file, $lead)) {
        return null;
    }
    write_json($priv . '/leads/last.json', ['at' => $now, 'id' => $lead['id'], 'kind' => $lead['kind'] ?? '']);
    return $lead + ['_file' => $file];
}

// Records how the email went (sent / failed / held) in the saved lead.
function mark_mail(?array $lead, string $state, array $to): void {
    if ($lead === null || empty($lead['_file'])) {
        return;
    }
    $file = $lead['_file'];
    unset($lead['_file']);
    $lead['mail'] = ['state' => $state, 'at' => time(), 'to' => $to];
    write_json($file, $lead);
}

// Erases leads after 90 days and photos after 14 (now and then, on a lead request).
function tidy_leads(): void {
    $priv = settings()['private'];
    if ($priv === '' || random_int(1, 25) !== 1) {
        return;
    }
    $cut = time() - KEEP_DAYS * 86400;
    foreach (glob($priv . '/leads/[0-9][0-9][0-9][0-9]-[0-9][0-9]/*.json') ?: [] as $f) {
        if ((int)@filemtime($f) < $cut) {
            @unlink($f);
        }
    }
    foreach (glob($priv . '/leads/rate/ts-lead-*') ?: [] as $f) {
        if ((int)@filemtime($f) < time() - 7200) {
            @unlink($f);
        }
    }
    foreach (glob($priv . '/leads/held-notice-*') ?: [] as $f) {
        if ((int)@filemtime($f) < time() - 2 * 86400) {
            @unlink($f); // one "leads are waiting" email a day
        }
    }
    $pcut = time() - PHOTO_DAYS * 86400;
    foreach (glob($priv . '/leads/photos/*', GLOB_ONLYDIR) ?: [] as $d) {
        if ((int)@filemtime($d) < $pcut) {
            foreach (glob($d . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($d);
        }
    }
}

// When today's email allowance is used up, one email says leads are waiting in the editor.
function notice_held(): void {
    $priv = settings()['private'];
    if ($priv === '') {
        return;
    }
    $flag = $priv . '/leads/held-notice-' . central_day();
    if (is_file($flag) || !take_mail('notice')) {
        return;
    }
    @touch($flag);
    $body = "New leads are waiting in your website editor.\n\nToday's email allowance is used up, so new claims, messages and chats are being kept safe in the editor instead of emailed. Open the website editor and tap Leads.\n";
    send_mail(encode_subject('New leads are waiting in your website editor'), chunk_split(base64_encode($body), 76, "\n"), [
        'From' => from_header(), 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64', 'X-Mailer' => 'ThinkSmart-Lead-Mailer',
    ]);
}

// Before the editor was installed: a copy of every lead in a folder above the web root.
// Only used when the private folder isn't there (the installer moves these into the editor).
function store_copy(array $record): void {
    if (settings()['private'] !== '') {
        return;
    }
    $root = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    if ($root === '' || $root === dirname($root)) {
        return;
    }
    $dir = dirname($root) . '/thinksmart-leads';
    if (!is_dir($dir) && !@mkdir($dir, 0700)) {
        return;
    }
    $line = json_encode(['received' => gmdate('c')] + $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    if (is_string($line)) {
        @file_put_contents($dir . '/leads-' . gmdate('Y-m') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
    }
}

// Per-IP rate limit: at most 10 sends per minute and 60 per hour. A plain cooldown
// would reject real visitors — chat follow-ups legitimately arrive seconds apart.
// Over the limit, a lead is still saved (not dropped), just not emailed; returns false then.
// A flood (over 200 an hour from one address) is refused outright.
function rate_limit(string $ip): bool {
    $priv = settings()['private'];
    $dir  = $priv !== '' ? $priv . '/leads/rate' : sys_get_temp_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $lock = $dir . '/ts-lead-' . md5($ip);
    $now  = time();
    $fh   = @fopen($lock, 'c+');
    $ok   = true;
    if ($fh === false) {
        return true;
    }
    if (flock($fh, LOCK_EX)) {
        $hits = [];
        foreach (explode("\n", (string)stream_get_contents($fh)) as $stamp) {
            $stamp = (int)$stamp;
            if ($stamp > $now - 3600) {
                $hits[] = $stamp;
            }
        }
        $lastMinute = 0;
        foreach ($hits as $stamp) {
            if ($stamp > $now - 60) {
                $lastMinute++;
            }
        }
        if (count($hits) >= 200) {
            flock($fh, LOCK_UN);
            fclose($fh);
            out(false, 'too many requests', 429);
        }
        if ($lastMinute >= 10 || count($hits) >= 60) {
            $ok = false;
        }
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, implode("\n", $hits));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    return $ok;
}

// Builds the text-only lead email. Returns [subject, body, headers] or null when the
// payload has nothing usable in it.
function build_lead_mail(string $kind, string $caseNo, array $payload, string $ip): ?array {
    $lines   = [];
    $replyTo = '';
    $name    = '';
    foreach ($payload as $k => $v) {
        if (!is_scalar($v)) {
            continue;
        }
        $key = header_safe(substr((string)$k, 0, 80));
        $val = body_safe((string)$v); // total size is already bounded by MAX_BODY
        if ($key === '' || $val === '') {
            continue;
        }
        $lines[] = $key . ': ' . $val;
        if ($replyTo === '' && stripos($key, 'email') !== false) {
            $maybe = filter_var($val, FILTER_VALIDATE_EMAIL);
            if ($maybe !== false) {
                $replyTo = $maybe;
            }
        }
        if ($name === '' && stripos($key, 'name') !== false) {
            $name = substr(header_safe($val), 0, 60);
        }
    }
    if ($lines === []) {
        return null;
    }

    if ($kind === 'chat') {
        // Only the visitor's own lines, minus the business's own phone numbers and addresses.
        // No Reply-To for chat: an email in the story may belong to the insurer's adjuster.
        $stage      = is_string($payload['Stage'] ?? null) ? $payload['Stage'] : '';
        $transcript = is_string($payload['Chat transcript'] ?? null) ? $payload['Chat transcript'] : '';
        $visitor    = implode("\n", preg_grep('/^Visitor: /', explode("\n", $transcript)) ?: []);
        $visitor    = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $visitor) ?? $visitor;
        $visitor    = preg_replace('/[\x{2010}-\x{2015}\x{2212}\x{FE63}\x{FF0D}]/u', '-', $visitor) ?? $visitor;
        $visitor    = preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $visitor) ?? $visitor;
        $ownPhones  = settings()['ownPhones'];
        $contact    = '';
        if (preg_match_all('/(?:^|\D)((?:\+?1[\s.\-]*)?\(?[2-9]\d{2}\)?\s*[\s.\-\/]?\s*[2-9]\d{2}\s*[\s.\-]?\s*\d{4})(?!\d)/', $visitor, $m)) {
            foreach ($m[1] as $candidate) {
                $digits = substr((string)preg_replace('/\D/', '', $candidate), -10);
                if (!in_array($digits, $ownPhones, true)) {
                    $contact = trim($candidate);
                    break;
                }
            }
        }
        if ($contact === '' && preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,}/', $visitor, $m)) {
            foreach ($m[0] as $candidate) {
                $lower = strtolower($candidate);
                if (in_array($lower, settings()['ownEmails'], true) || substr($lower, -strlen('@' . site_domain())) === '@' . site_domain()) {
                    continue;
                }
                if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                    $contact = $candidate;
                    break;
                }
            }
        }
        $replyTo = '';
        $subject = (stripos($stage, 'follow') !== false ? 'Website chat follow-up' : 'Website chat lead')
                 . ($contact !== '' ? ' - ' . substr(header_safe($contact), 0, 60) : '');
    } elseif ($kind === 'contact') {
        $subject = 'Website contact message' . ($name !== '' ? ' - ' . $name : '');
    } else {
        $subject = 'New claim intake' . ($name !== '' ? ' - ' . $name : '') . ($caseNo !== '' ? ' - ' . $caseNo : '');
    }

    $body = "New website {$kind} lead\n"
          . 'Received: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
          . ($caseNo !== '' ? "Case number: {$caseNo}\n" : '')
          . "Visitor IP: {$ip}\n"
          . str_repeat('-', 40) . "\n\n"
          . implode("\n\n", $lines) . "\n";

    // base64 keeps every line short: mail servers bounce messages with very long lines,
    // and visitors do type whole paragraphs without a line break.
    $headers = [
        'From'                      => from_header(),
        'MIME-Version'              => '1.0',
        'Content-Type'              => 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding' => 'base64',
        'X-Mailer'                  => 'ThinkSmart-Lead-Mailer',
    ];
    if ($replyTo !== '') {
        $headers['Reply-To'] = $replyTo;
    }
    $who = ['name' => $name, 'phone' => '', 'email' => $replyTo];
    foreach ($payload as $k => $v) {
        if (is_scalar($v) && $who['phone'] === '' && preg_match('/phone|tel/i', (string)$k)) {
            $who['phone'] = substr(header_safe((string)$v), 0, 40);
        }
    }
    if ($kind === 'chat' && isset($contact) && $contact !== '') {
        if (strpos($contact, '@') !== false) {
            $who['email'] = $contact;
        } else {
            $who['phone'] = $contact;
        }
    }
    return [encode_subject($subject), chunk_split(base64_encode($body), 76, "\n"), $headers, $who];
}

// Validates PHP's $_FILES['photos'] entry and returns only genuine images as
// [['file' => safe attachment name, 'mime' => ..., 'data' => bytes], ...].
function collect_photos(array $files, bool $requireUploaded = true, int $first = 1): array {
    $names = (array)($files['name'] ?? []);
    $tmps  = (array)($files['tmp_name'] ?? []);
    $errs  = (array)($files['error'] ?? []);
    $ext   = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $photos = [];
    $total  = 0;
    foreach ($tmps as $i => $tmp) {
        if (count($photos) >= MAX_PHOTOS) {
            break;
        }
        if (!is_string($tmp) || $tmp === '' || (int)($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }
        if ($requireUploaded && !is_uploaded_file($tmp)) {
            continue;
        }
        $size = (int)@filesize($tmp);
        if ($size <= 0 || $size > MAX_PHOTO_BYTES || $total + $size > MAX_PHOTOS_TOTAL) {
            continue;
        }
        $info = @getimagesize($tmp);
        if ($info === false || !isset($ext[$info[2]])) {
            continue; // not a real image — never forward arbitrary uploads
        }
        $data = @file_get_contents($tmp);
        if (!is_string($data) || $data === '') {
            continue;
        }
        $total   += $size;
        $photos[] = [
            'file' => 'photo-' . ($first + count($photos)) . '.' . $ext[$info[2]],
            'mime' => image_type_to_mime_type($info[2]),
            'data' => $data,
            'orig' => substr(header_safe((string)($names[$i] ?? '')), 0, 80),
        ];
    }
    return $photos;
}

// Builds a multipart/mixed email carrying the photos. Returns [subject, body, headers].
function build_photo_mail(string $caseNo, string $name, string $ip, array $photos, string $partLabel = '', string $replyTo = ''): array {
    $boundary = 'ts-' . bin2hex(random_bytes(12));
    $subject  = 'Claim photos' . ($partLabel !== '' ? ' ' . $partLabel : '') . ' (' . count($photos) . ')' . ($name !== '' ? ' - ' . $name : '') . ($caseNo !== '' ? ' - ' . $caseNo : '');

    $text = 'Damage photos submitted with a website claim' . "\n"
          . 'Received: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
          . ($caseNo !== '' ? "Case number: {$caseNo}\n" : '')
          . ($name !== '' ? "Name: {$name}\n" : '')
          . "Visitor IP: {$ip}\n"
          . 'Photos attached: ' . count($photos) . "\n";

    $body = "--{$boundary}\n"
          . "Content-Type: text/plain; charset=UTF-8\n"
          . "Content-Transfer-Encoding: 8bit\n\n"
          . $text . "\n";
    foreach ($photos as $p) {
        $file  = ($caseNo !== '' ? $caseNo . '-' : '') . $p['file'];
        $body .= "--{$boundary}\n"
               . "Content-Type: {$p['mime']}; name=\"{$file}\"\n"
               . "Content-Transfer-Encoding: base64\n"
               . "Content-Disposition: attachment; filename=\"{$file}\"\n\n"
               . chunk_split(base64_encode($p['data']), 76, "\n");
    }
    $body .= "--{$boundary}--\n";

    $headers = [
        'From'         => from_header(),
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/mixed; boundary=\"{$boundary}\"",
        'X-Mailer'     => 'ThinkSmart-Lead-Mailer',
    ];
    if ($replyTo !== '') {
        $headers['Reply-To'] = $replyTo;
    }
    return [encode_subject($subject), $body, $headers];
}

// Saves claim photos in ~/thinksmart-private/leads/photos/<case>/ (kept 14 days).
function save_photos(string $folder, array $photos): array {
    $priv = settings()['private'];
    if ($priv === '' || !preg_match('/^[A-Za-z0-9_-]{1,60}$/', $folder)) {
        return [];
    }
    $dir = $priv . '/leads/photos/' . $folder;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return [];
    }
    $saved = [];
    foreach ($photos as $p) {
        if (@file_put_contents($dir . '/' . $p['file'], $p['data'], LOCK_EX) === strlen($p['data'])) {
            $saved[] = $p['file'];
        }
    }
    @touch($dir);
    return $saved;
}

function main(): void {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    if (settings()['private'] !== '') {
        // PHP's own error log stays out of the web folder
        ini_set('log_errors', '1');
        ini_set('error_log', settings()['private'] . '/php-errors.log');
    }

    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');

    // Lightweight health probe for uptime monitoring (sends no mail).
    if ($method === 'GET' && isset($_GET['health'])) {
        out(true, 'ok', 200, ['saving' => settings()['private'] !== '']);
    }
    if ($method !== 'POST') {
        out(false, 'POST only', 405);
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    require_same_origin();
    tidy_leads();

    // --- Photo upload (multipart) ---
    if (isset($_FILES['photos'])) {
        // only from the website's own pages: browsers always say where a POST comes from
        $type = (string)($_SERVER['CONTENT_TYPE'] ?? '');
        if (stripos($type, 'multipart/form-data') !== 0 || ((string)($_SERVER['HTTP_ORIGIN'] ?? '') === '' && (string)($_SERVER['HTTP_REFERER'] ?? '') === '')) {
            out(false, 'refused', 403);
        }
        if (!empty($_POST['_hp'])) {
            out(true, 'ok'); // honeypot
        }
        $mayMail = rate_limit($ip);
        $first  = max(1, min(1000, (int)($_POST['first'] ?? 1)));
        $parts  = max(1, min(50, (int)($_POST['parts'] ?? 1)));
        $part   = max(1, min($parts, (int)($_POST['part'] ?? 1)));
        $photos = collect_photos($_FILES['photos'], true, $first);
        if ($photos === []) {
            out(false, 'no valid photos', 400);
        }
        $caseIn = $_POST['case_no'] ?? '';
        $nameIn = $_POST['name'] ?? '';
        $caseNo = substr((string)preg_replace('/[^A-Za-z0-9\- ]/', '', is_string($caseIn) ? $caseIn : ''), 0, 40);
        $name   = substr(header_safe(is_string($nameIn) ? $nameIn : ''), 0, 60);
        $emailIn = $_POST['email'] ?? '';
        $replyTo = is_string($emailIn) ? (string)filter_var(header_safe($emailIn), FILTER_VALIDATE_EMAIL) : '';
        $partLabel = $parts > 1 ? "part {$part} of {$parts}" : '';
        // saved first: the visitor's "sent" means it's safe
        $folder = $caseNo !== '' ? substr((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($caseNo)), 0, 60) : 'photos-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $saved = save_photos($folder, $photos);
        $lead = store_lead(['kind' => 'photos', 'case_no' => $caseNo, 'name' => $name, 'phone' => '', 'email' => $replyTo, 'ip' => $ip,
            'payload' => $partLabel !== '' ? ['Photos' => $partLabel] : [], 'photos' => $saved, 'photoDir' => $saved ? $folder : '', 'mail' => ['state' => 'waiting']]);
        $sent = false;
        $state = 'held';
        if ($mayMail && take_mail('lead')) {
            [$subject, $body, $headers] = build_photo_mail($caseNo, $name, $ip, $photos, $partLabel, $replyTo);
            if ($lead !== null) {
                $headers['X-TS-Lead'] = $lead['id'];
            }
            $sent = send_mail($subject, $body, $headers);
            $state = $sent ? 'sent' : 'failed';
        } elseif ($mayMail) {
            notice_held();
        }
        mark_mail($lead, $state, settings()['to']);
        if ($lead === null) {
            store_copy(['kind' => 'photos', 'case_no' => $caseNo, 'name' => $name, 'ip' => $ip, 'photos' => count($photos), 'emailed' => $sent]);
        }
        $ok = $lead !== null || $sent;
        out($ok, $ok ? 'ok' : 'not saved', 200, ['count' => $ok ? count($photos) : 0]);
    }

    // --- JSON lead ---
    // A cross-site page cannot send this content type without a CORS preflight, which fails.
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
        out(false, 'JSON only', 415);
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_BODY + 1);
    if (!is_string($raw) || $raw === '') {
        out(false, 'empty body', 400);
    }
    if (strlen($raw) > MAX_BODY) {
        out(false, 'payload too large', 413);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        out(false, 'invalid JSON', 400);
    }
    if (!empty($data['_hp'])) {
        out(true, 'ok'); // honeypot: real visitors never fill this
    }

    $mayMail = rate_limit($ip);

    $kindIn  = $data['kind'] ?? '';
    $kind    = in_array($kindIn, ['chat', 'contact'], true) ? $kindIn : 'claim';
    $caseIn  = $data['case_no'] ?? '';
    $caseNo  = substr((string)preg_replace('/[^A-Za-z0-9\- ]/', '', is_string($caseIn) ? $caseIn : ''), 0, 40);
    $payload = (isset($data['payload']) && is_array($data['payload'])) ? $data['payload'] : [];

    $mail = build_lead_mail($kind, $caseNo, $payload, $ip);
    if ($mail === null) {
        out(false, 'missing payload', 400);
    }
    [$subject, $body, $headers, $who] = $mail;
    $fields = [];
    foreach ($payload as $k => $v) {
        if (is_scalar($v)) {
            $fields[substr((string)$k, 0, 80)] = substr((string)$v, 0, 20000);
        }
    }
    // saved first: the visitor's "sent" means it's safe
    $lead = store_lead(['kind' => $kind, 'case_no' => $caseNo] + $who + ['ip' => $ip, 'payload' => $fields,
        'page' => substr((string)($fields['Sent from'] ?? ''), 0, 300), 'photos' => [], 'mail' => ['state' => 'waiting']]);
    $sent = false;
    $state = 'held';
    if ($mayMail && take_mail('lead')) {
        if ($lead !== null) {
            $headers['X-TS-Lead'] = $lead['id'];
        }
        $sent = send_mail($subject, $body, $headers);
        $state = $sent ? 'sent' : 'failed';
    } elseif ($mayMail) {
        notice_held();
    }
    mark_mail($lead, $state, settings()['to']);
    if ($lead === null) {
        store_copy(['kind' => $kind, 'case_no' => $caseNo, 'ip' => $ip, 'emailed' => $sent, 'payload' => $payload]);
    }
    $ok = $lead !== null || $sent;
    out($ok, $ok ? 'ok' : 'mail() failed');
}

// The constant lets a command-line test load the builders without running a request.
if (!defined('TS_LEAD_TEST')) {
    main();
}
