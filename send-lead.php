<?php
// Think Smart lead mailer — runs on the site's own PHP hosting (GoDaddy cPanel).
// The claim form and chat widget POST JSON here: {kind:'claim'|'chat', case_no?, payload:{...}}.
// Emails the lead to the business inbox. From-address is derived from the hosted
// domain because GoDaddy's relay rejects foreign From headers.

declare(strict_types=1);

const LEAD_TO  = 'thinksmartpublicadjusting@gmail.com';
const MAX_BODY = 20000; // bytes

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

function out(bool $ok, string $msg = '', int $code = 200): void {
    http_response_code($code);
    echo json_encode(['success' => $ok, 'message' => $msg]);
    exit;
}

// Lightweight health probe for uptime monitoring (sends no mail).
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['health'])) {
    out(true, 'ok');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    out(false, 'POST only', 405);
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

// Honeypot: real visitors never fill this; pretend success for bots.
if (!empty($data['_hp'])) {
    out(true, 'ok');
}

// Per-IP throttle: one send per 10 seconds.
$ip   = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$lock = sys_get_temp_dir() . '/ts-lead-' . md5($ip);
$last = @filemtime($lock);
if ($last !== false && (time() - $last) < 10) {
    out(false, 'too many requests', 429);
}
@touch($lock);

$kindIn  = (string)($data['kind'] ?? '');
$kind    = in_array($kindIn, ['chat', 'contact'], true) ? $kindIn : 'claim';
$caseNo  = preg_replace('/[^A-Za-z0-9\- ]/', '', (string)($data['case_no'] ?? ''));
$payload = (isset($data['payload']) && is_array($data['payload'])) ? $data['payload'] : [];
if ($payload === []) {
    out(false, 'missing payload', 400);
}

// Header values must never contain CR/LF (header injection); body values keep
// their newlines (chat transcripts) but drop CR.
$headerSafe = static function (string $s): string {
    return trim(preg_replace('/[\r\n\t]+/', ' ', $s) ?? '');
};

$lines   = [];
$replyTo = '';
foreach ($payload as $k => $v) {
    if (!is_scalar($v)) {
        continue;
    }
    $key = $headerSafe(substr((string)$k, 0, 80));
    $val = substr(str_replace("\r", '', trim((string)$v)), 0, 6000);
    if ($key === '' || $val === '') {
        continue;
    }
    $lines[] = $key . ": " . $val;
    if ($replyTo === '' && stripos($key, 'email') !== false) {
        $maybe = filter_var($val, FILTER_VALIDATE_EMAIL);
        if ($maybe !== false) {
            $replyTo = $maybe;
        }
    }
}
if ($lines === []) {
    out(false, 'empty payload', 400);
}

$domain = strtolower((string)($_SERVER['SERVER_NAME'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost')));
$domain = preg_replace('/[^a-z0-9.\-]/', '', $domain) ?: 'localhost';
$domain = preg_replace('/^www\./', '', $domain);

$name    = '';
foreach ($payload as $k => $v) {
    if (is_scalar($v) && stripos((string)$k, 'name') !== false) {
        $name = $headerSafe((string)$v);
        break;
    }
}

if ($kind === 'chat') {
    $subject = 'Website chat lead';
} elseif ($kind === 'contact') {
    $subject = 'Website contact message' . ($name !== '' ? ' — ' . $name : '');
} else {
    $subject = 'New claim intake' . ($name !== '' ? ' — ' . $name : '') . ($caseNo !== '' ? ' — ' . $caseNo : '');
}
$subject = $headerSafe($subject);

$body = "New website {$kind} lead\n"
      . "Received: " . gmdate('Y-m-d H:i:s') . " UTC\n"
      . ($caseNo !== '' ? "Case number: {$caseNo}\n" : '')
      . "Visitor IP: {$ip}\n"
      . str_repeat('-', 40) . "\n\n"
      . implode("\n\n", $lines) . "\n";

$headers = [
    'From'         => 'Think Smart Website <noreply@' . $domain . '>',
    'X-Mailer'     => 'ThinkSmart-Lead-Mailer',
    'Content-Type' => 'text/plain; charset=UTF-8',
];
if ($replyTo !== '') {
    $headers['Reply-To'] = $replyTo;
}

$sent = mail(LEAD_TO, $subject, $body, $headers);

out($sent, $sent ? 'ok' : 'mail() failed');
