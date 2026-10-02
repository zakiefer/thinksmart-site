<?php
// Think Smart lead mailer — runs on the site's own PHP hosting (GoDaddy cPanel).
//
// Two request shapes, both answered with JSON {success, message}:
//   1. JSON POST {kind:'claim'|'chat'|'contact', case_no?, payload:{...}}  -> emails the lead.
//   2. multipart POST with photos[] (+ case_no, name)                      -> emails the photos
//      as attachments. Nothing is ever written to the web root.
//
// The From address is derived from the hosted domain because GoDaddy's relay rejects
// foreign From headers.

declare(strict_types=1);

const LEAD_TO          = 'thinksmartpublicadjusting@gmail.com';
const MAX_BODY         = 20000;            // bytes, JSON leads
const MAX_PHOTOS       = 10;
const MAX_PHOTO_BYTES  = 6 * 1024 * 1024;  // per photo
const MAX_PHOTOS_TOTAL = 15 * 1024 * 1024; // per email

function out(bool $ok, string $msg = '', int $code = 200, array $extra = []): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

// Header values must never contain CR/LF (header injection).
function header_safe(string $s): string {
    return trim((string)preg_replace('/[\r\n\t]+/', ' ', $s));
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

function from_header(): string {
    return 'Think Smart Website <noreply@' . site_domain() . '>';
}

// Per-IP rate limit: at most 10 sends per minute and 60 per hour. A plain cooldown
// would reject real visitors — chat follow-ups legitimately arrive seconds apart.
function rate_limit(string $ip): void {
    $lock = sys_get_temp_dir() . '/ts-lead-' . md5($ip);
    $now  = time();
    $fh   = @fopen($lock, 'c+');
    if ($fh === false) {
        return;
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
        if ($lastMinute >= 10 || count($hits) >= 60) {
            flock($fh, LOCK_UN);
            fclose($fh);
            out(false, 'too many requests', 429);
        }
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, implode("\n", $hits));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
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
        $val = substr(str_replace("\r", '', trim((string)$v)), 0, 6000);
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
        $subject = 'Website chat lead';
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

    $headers = [
        'From'         => from_header(),
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer'     => 'ThinkSmart-Lead-Mailer',
    ];
    if ($replyTo !== '') {
        $headers['Reply-To'] = $replyTo;
    }
    return [encode_subject($subject), $body, $headers];
}

// Validates PHP's $_FILES['photos'] entry and returns only genuine images as
// [['file' => safe attachment name, 'mime' => ..., 'data' => bytes], ...].
function collect_photos(array $files, bool $requireUploaded = true): array {
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
            'file' => 'photo-' . (count($photos) + 1) . '.' . $ext[$info[2]],
            'mime' => image_type_to_mime_type($info[2]),
            'data' => $data,
            'orig' => substr(header_safe((string)($names[$i] ?? '')), 0, 80),
        ];
    }
    return $photos;
}

// Builds a multipart/mixed email carrying the photos. Returns [subject, body, headers].
function build_photo_mail(string $caseNo, string $name, string $ip, array $photos): array {
    $boundary = 'ts-' . bin2hex(random_bytes(12));
    $subject  = 'Claim photos (' . count($photos) . ')' . ($name !== '' ? ' - ' . $name : '') . ($caseNo !== '' ? ' - ' . $caseNo : '');

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
        $body .= "--{$boundary}\n"
               . "Content-Type: {$p['mime']}; name=\"{$p['file']}\"\n"
               . "Content-Transfer-Encoding: base64\n"
               . "Content-Disposition: attachment; filename=\"{$p['file']}\"\n\n"
               . chunk_split(base64_encode($p['data']), 76, "\n");
    }
    $body .= "--{$boundary}--\n";

    $headers = [
        'From'         => from_header(),
        'MIME-Version' => '1.0',
        'Content-Type' => "multipart/mixed; boundary=\"{$boundary}\"",
        'X-Mailer'     => 'ThinkSmart-Lead-Mailer',
    ];
    return [encode_subject($subject), $body, $headers];
}

function main(): void {
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');

    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');

    // Lightweight health probe for uptime monitoring (sends no mail).
    if ($method === 'GET' && isset($_GET['health'])) {
        out(true, 'ok');
    }
    if ($method !== 'POST') {
        out(false, 'POST only', 405);
    }

    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');

    // --- Photo upload (multipart) ---
    if (isset($_FILES['photos'])) {
        if (!empty($_POST['_hp'])) {
            out(true, 'ok'); // honeypot
        }
        rate_limit($ip);
        $photos = collect_photos($_FILES['photos']);
        if ($photos === []) {
            out(false, 'no valid photos', 400);
        }
        $caseNo = (string)preg_replace('/[^A-Za-z0-9\- ]/', '', (string)($_POST['case_no'] ?? ''));
        $name   = substr(header_safe((string)($_POST['name'] ?? '')), 0, 60);
        [$subject, $body, $headers] = build_photo_mail(substr($caseNo, 0, 40), $name, $ip, $photos);
        $sent = mail(LEAD_TO, $subject, $body, $headers);
        out($sent, $sent ? 'ok' : 'mail() failed', 200, ['count' => $sent ? count($photos) : 0]);
    }

    // --- JSON lead ---
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

    rate_limit($ip);

    $kindIn  = (string)($data['kind'] ?? '');
    $kind    = in_array($kindIn, ['chat', 'contact'], true) ? $kindIn : 'claim';
    $caseNo  = substr((string)preg_replace('/[^A-Za-z0-9\- ]/', '', (string)($data['case_no'] ?? '')), 0, 40);
    $payload = (isset($data['payload']) && is_array($data['payload'])) ? $data['payload'] : [];

    $mail = build_lead_mail($kind, $caseNo, $payload, $ip);
    if ($mail === null) {
        out(false, 'missing payload', 400);
    }
    [$subject, $body, $headers] = $mail;
    $sent = mail(LEAD_TO, $subject, $body, $headers);
    out($sent, $sent ? 'ok' : 'mail() failed');
}

// The constant lets a command-line test load the builders without running a request.
if (!defined('TS_LEAD_TEST')) {
    main();
}
