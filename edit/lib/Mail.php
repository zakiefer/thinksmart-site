<?php
// Emails from the editor: sign-in codes, "someone changed the phone number" alerts with an
// Undo button, new-device sign-ins. Core PHP mail() only. In development (config "mail":
// "outbox") nothing is sent: each email is written to <private>/outbox/ instead.
declare(strict_types=1);

namespace ThinkSmart;

final class Mailer
{
    private Config $cfg;

    public function __construct(Config $cfg)
    {
        $this->cfg = $cfg;
    }

    public function domain(): string
    {
        $host = strtolower((string)parse_url($this->cfg->siteUrl(), PHP_URL_HOST));
        $host = (string)preg_replace('/^www\./', '', $host);
        return $host !== '' ? $host : 'localhost';
    }

    /**
     * @param list<string> $to
     * @param array{0:string,1:string}|null $button  [words, link]
     */
    public function send(array $to, string $subject, array $paragraphs, ?array $button = null, string $kind = 'note', array $extra = []): bool
    {
        $to = array_values(array_unique(array_filter(array_map(fn($a) => self::headerSafe(strtolower(trim((string)$a))), $to),
            fn($a) => (bool)preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', $a))));
        if (!$to) return false;
        if (!$this->count($kind)) { Log::error("mail limit reached, not sent: $subject"); return false; }
        $text = implode("\n\n", $paragraphs) . ($button ? "\n\n" . $button[0] . ': ' . $button[1] : '') . "\n\n— Your website editor\n";
        $html = self::html($subject, $paragraphs, $button);
        if ($this->cfg->mailMode() === 'outbox') {
            $dir = $this->cfg->paths->private . '/outbox';
            $name = sprintf('%s-%s.json', gmdate('Ymd-His', Clock::now()), bin2hex(random_bytes(3)));
            Json::write("$dir/$name", ['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html, 'kind' => $kind, 'at' => Clock::now(), 'headers' => $extra]);
            return true;
        }
        $from = 'noreply@' . $this->domain();
        $boundary = 'ts' . bin2hex(random_bytes(8));
        $headers = implode("\r\n", array_merge([
            'From: Your website <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Auto-Response-Suppress: All',
        ], array_map(fn($k, $v) => self::headerSafe((string)$k) . ': ' . self::headerSafe((string)$v), array_keys($extra), array_values($extra))));
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "--$boundary--\r\n";
        $subj = self::subject($subject);
        $ok = @mail(implode(', ', $to), $subj, $body, $headers, '-f' . $from);
        if (!$ok) $ok = @mail(implode(', ', $to), $subj, $body, $headers);
        if (!$ok) Log::error("mail() failed: $subject");
        return $ok;
    }

    /** Well below GoDaddy's 500 a day, counted together with the lead mailer; sign-in codes keep the biggest reserve. */
    private function count(string $kind): bool
    {
        return MailCap::take($this->cfg->paths, $kind);
    }

    public static function headerSafe(string $s): string
    {
        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/', ' ', $s));
    }

    private static function subject(string $s): string
    {
        $s = self::headerSafe($s);
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** A plain, readable email that works in Gmail on a phone. */
    public static function html(string $title, array $paragraphs, ?array $button): string
    {
        $p = '';
        foreach ($paragraphs as $para) {
            $p .= '<p style="margin:0 0 14px;font-size:16px;line-height:1.5;color:#1d2128;">' . nl2br(self::esc($para)) . '</p>';
        }
        $b = '';
        if ($button) {
            $b = '<p style="margin:22px 0 8px;"><a href="' . self::esc($button[1]) . '" style="display:inline-block;background:#F09A2E;color:#0B0E13;'
                . 'font-weight:700;font-size:16px;text-decoration:none;padding:14px 22px;border-radius:6px;">' . self::esc($button[0]) . '</a></p>';
        }
        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f4f1ea;font-family:-apple-system,Segoe UI,Arial,sans-serif;">'
            . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;padding:24px;">'
            . '<p style="margin:0 0 6px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#8a6a3a;">Your website editor</p>'
            . '<h1 style="margin:0 0 16px;font-size:21px;line-height:1.3;color:#0B0E13;">' . self::esc($title) . '</h1>'
            . $p . $b . '</div></body></html>';
    }
}
