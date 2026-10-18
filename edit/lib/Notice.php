<?php
// The storm / closed notice bar. Start and end are entered in Indiana's Central time
// ("2026-10-20T17:00"); the visitor's browser compares them with its own clock, so the bar
// turns on and off on time without any scheduled job.
declare(strict_types=1);

namespace ThinkSmart;

final class Notice
{
    public const TZ = 'America/Chicago';
    /** Latest time a browser can represent: "no end date". */
    public const FOREVER_MS = 8640000000000000;

    public static function time(string $s): ?\DateTimeImmutable
    {
        $s = trim($s);
        if ($s === '') return null;
        $d = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $s, new \DateTimeZone(self::TZ));
        if ($d === false) throw new BuildError("Notice time '$s' is not like 2026-10-20T17:00");
        return $d;
    }

    /** [from, until] in milliseconds since 1970 (until = FOREVER_MS when open-ended). */
    public static function window(array $n): array
    {
        $from = self::time((string)($n['start'] ?? ''));
        $until = self::time((string)($n['end'] ?? ''));
        return [$from ? $from->getTimestamp() * 1000 : 0, $until ? $until->getTimestamp() * 1000 : self::FOREVER_MS];
    }

    /** What the pages need when the notice is switched on (empty when off). */
    public static function derive(array $c): array
    {
        $n = $c['notice'] ?? [];
        if (empty($n['on'])) return [];
        $kind = (string)($n['kind'] ?? 'storm');
        $m = $n[$kind] ?? null;
        if (!is_array($m)) throw new BuildError("Unknown notice '$kind'");
        [$from, $until] = self::window($n);
        $phone = (string)($c['site']['phone'] ?? '');
        $link = (string)($m['link'] ?? 'none');
        return [
            'text' => $m['text'] ?? '',
            'href' => $link === 'call' ? 'tel:' . preg_replace('/\D/', '', $phone) : ($link === 'claim' ? 'Claim.dc.html' : ''),
            'linkText' => $link === 'call' ? 'Call ' . $phone : ($link === 'claim' ? 'File online' : ''),
            'from' => $from,
            'until' => $until,
        ];
    }

    /** "Monday, Oct 26" for "John is out until …" ("further notice" with no end date). */
    public static function untilText(array $n): string
    {
        $until = self::time((string)($n['end'] ?? ''));
        return $until ? $until->format('l, M j') : 'further notice';
    }

    /** Plain status for the editor: "Off", "Showing now", "Starts Fri 5:00 pm", "Ended Sun 6:00 pm". */
    public static function status(array $n, int $nowTs): string
    {
        if (empty($n['on'])) return 'Off';
        [$from, $until] = self::window($n);
        $now = $nowTs * 1000;
        $fmt = function (int $ms) use ($nowTs): string {
            $d = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone(new \DateTimeZone(self::TZ));
            $soon = abs($ms / 1000 - $nowTs) < 6 * 86400;
            return $d->format($soon ? 'D g:i a' : 'M j, g:i a');
        };
        if ($now < $from) return 'Starts ' . $fmt($from);
        if ($now >= $until) return 'Ended ' . $fmt($until);
        return 'Showing now' . ($until < self::FOREVER_MS ? ', until ' . $fmt($until) : '');
    }
}
