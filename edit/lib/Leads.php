<?php
// Leads, the business's lifeline. send-lead.php (the public mailer, which runs on its own so an
// editor problem can never stop leads) saves every lead here FIRST, then emails it:
//
//   <private>/leads/<YYYY-MM>/<id>.json     one file per lead (kept 90 days)
//   <private>/leads/photos/<folder>/<file>  claim photos (kept 14 days)
//   <private>/leads/last.json               the newest lead, for "Last lead received …"
//   <private>/settings.json                 where leads are emailed (outside content restore)
//   <private>/mail-count.json               today's emails, shared with the editor's mail
//
// A lead file: {id, at, kind: claim|contact|chat|photos, case_no, name, phone, email, payload,
// ip, page, photos: [files], photoDir, mail: {state: sent|failed|held|bounced|waiting, at, to}}
declare(strict_types=1);

namespace ThinkSmart;

/** Today's outgoing emails, counted once for the editor and the lead mailer (GoDaddy allows 500 a day). */
final class MailCap
{
    // each kind may send while today's total is below its limit: lead emails stop first,
    // sign-in codes last
    public const LIMITS = ['lead' => 400, 'notice' => 420, 'note' => 430, 'alert' => 440, 'code' => 480];

    /** Counts one email if today's total is below the kind's limit (the same file lock as send-lead.php). */
    public static function take(Paths $p, string $kind): bool
    {
        $limit = self::LIMITS[$kind] ?? self::LIMITS['note'];
        $fh = @fopen($p->private . '/mail-count.json', 'c+');
        if ($fh === false) return true;
        $ok = true;
        if (flock($fh, LOCK_EX)) {
            $c = json_decode((string)stream_get_contents($fh), true);
            $day = Clock::local(Clock::now())->format('Y-m-d');
            if (!is_array($c) || ($c['day'] ?? '') !== $day) $c = ['day' => $day, 'n' => 0];
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

    public static function today(Paths $p): int
    {
        $c = Json::read($p->private . '/mail-count.json', []);
        return ($c['day'] ?? '') === Clock::local(Clock::now())->format('Y-m-d') ? (int)($c['n'] ?? 0) : 0;
    }
}

/** Where leads are emailed: 1–3 addresses. A new one takes over only after someone clicks the link in a test lead. */
final class LeadSettings
{
    public const DEFAULT_TO = 'thinksmartpublicadjusting@gmail.com';
    public const MAX = 3;

    public static function file(Paths $p): string
    {
        return $p->private . '/settings.json';
    }

    public static function load(Config $cfg): array
    {
        $s = Json::read(self::file($cfg->paths), []);
        if (!isset($s['leadTo']) || !is_array($s['leadTo'])) $s['leadTo'] = [];
        return $s;
    }

    private static function save(Config $cfg, array $s): void
    {
        $s['updated'] = Clock::now();
        Json::write(self::file($cfg->paths), $s);
    }

    /** The addresses leads go to now (confirmed ones; the waiting ones while nothing is confirmed yet). */
    public static function recipients(Config $cfg): array
    {
        $all = self::load($cfg)['leadTo'];
        $ok = array_values(array_map(fn($a) => $a['email'], array_filter($all, fn($a) => ($a['state'] ?? '') === 'confirmed')));
        if ($ok) return $ok;
        $waiting = array_values(array_map(fn($a) => $a['email'], $all));
        return $waiting ?: [self::DEFAULT_TO];
    }

    public static function valid(string $email): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}$/', $email) && strlen($email) <= 120;
    }

    /** Add an address (or send its test lead again); it gets leads once the link in the test lead is clicked. */
    public static function addPending(Config $cfg, string $email, ?string $by): void
    {
        $email = strtolower(trim($email));
        if (!self::valid($email)) throw new UserError('That email address doesn\'t look right.');
        Lock::with($cfg->paths, 'settings', function () use ($cfg, $email, $by) {
            $s = self::load($cfg);
            $found = false;
            foreach ($s['leadTo'] as $a) if ($a['email'] === $email) $found = true;
            if (!$found) {
                if (count($s['leadTo']) >= self::MAX) throw new UserError('Leads can go to up to ' . self::MAX . ' addresses. Remove one first.');
                $s['leadTo'][] = ['email' => $email, 'state' => 'waiting', 'added' => Clock::now(), 'by' => $by, 'confirmed' => null];
                self::save($cfg, $s);
            }
        });
        $tok = (new Tokens($cfg))->make('confirm-lead', ['email' => $email]);
        (new Mailer($cfg))->send([$email], 'Test lead from your website: please confirm', [
            'This is a test lead from the website. Leads (claims, messages and chats) will be emailed to this address once you confirm it.',
            'Tap the button to confirm. Until then, leads are kept safe in the website editor.',
        ], ['Yes, send leads here', $cfg->siteUrl() . '/edit/confirm?t=' . $tok], 'alert');
    }

    public static function confirm(Config $cfg, string $email): bool
    {
        return Lock::with($cfg->paths, 'settings', function () use ($cfg, $email) {
            $s = self::load($cfg);
            $hit = false;
            foreach ($s['leadTo'] as $i => $a) {
                if ($a['email'] === $email) { $s['leadTo'][$i]['state'] = 'confirmed'; $s['leadTo'][$i]['confirmed'] = Clock::now(); $hit = true; }
            }
            if ($hit) self::save($cfg, $s);
            return $hit;
        });
    }

    /** @return array the list before the change (for Undo) */
    public static function remove(Config $cfg, string $email): array
    {
        return Lock::with($cfg->paths, 'settings', function () use ($cfg, $email) {
            $s = self::load($cfg);
            $before = $s['leadTo'];
            $left = array_values(array_filter($s['leadTo'], fn($a) => $a['email'] !== $email));
            if (!array_filter($left, fn($a) => ($a['state'] ?? '') === 'confirmed') && array_filter($before, fn($a) => ($a['state'] ?? '') === 'confirmed')) {
                throw new UserError('Leads need at least one confirmed address. Add and confirm another one first.');
            }
            if (!$left) throw new UserError('Leads need at least one address.');
            $s['leadTo'] = $left;
            self::save($cfg, $s);
            return $before;
        });
    }

    public static function restore(Config $cfg, array $list): void
    {
        Lock::with($cfg->paths, 'settings', function () use ($cfg, $list) {
            $s = self::load($cfg);
            $s['leadTo'] = array_values($list);
            self::save($cfg, $s);
        });
    }
}

final class Leads
{
    public const KEEP_DAYS = 90;
    public const PHOTO_DAYS = 14;

    public static function dir(Paths $p): string
    {
        return $p->private . '/leads';
    }

    /** Every lead file, newest first (the last 90 days). */
    public static function files(Paths $p): array
    {
        $out = [];
        foreach (glob(self::dir($p) . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]/*.json') ?: [] as $f) $out[] = $f;
        rsort($out, SORT_STRING);
        usort($out, fn($a, $b) => strcmp(basename($b), basename($a)));
        return $out;
    }

    public static function get(Paths $p, string $id): ?array
    {
        if (!preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id)) return null;
        $f = self::dir($p) . '/' . substr($id, 0, 4) . '-' . substr($id, 4, 2) . "/$id.json";
        $l = Json::read($f);
        return is_array($l) ? $l : null;
    }

    public static function put(Paths $p, array $lead): void
    {
        $f = self::dir($p) . '/' . substr($lead['id'], 0, 4) . '-' . substr($lead['id'], 4, 2) . '/' . $lead['id'] . '.json';
        Json::write($f, $lead);
    }

    public static function newId(int $at): string
    {
        return gmdate('Ymd-His', $at) . '-' . bin2hex(random_bytes(3));
    }

    public static function last(Paths $p): ?array
    {
        $l = Json::read(self::dir($p) . '/last.json');
        return is_array($l) ? $l : null;
    }

    /** Photos of a lead (the claim and its photo batches share a case number). */
    public static function photos(Paths $p, array $lead): array
    {
        $dir = self::photoDir($p, $lead);
        if ($dir === null || !is_dir($dir)) return [];
        $out = [];
        foreach (scandir($dir) ?: [] as $f) if (preg_match('/^[A-Za-z0-9._-]+\.(jpg|png|webp|gif)$/', $f)) $out[] = $f;
        sort($out, SORT_NATURAL);
        return $out;
    }

    public static function photoDir(Paths $p, array $lead): ?string
    {
        $key = (string)($lead['photoDir'] ?? '');
        if ($key === '' && ($lead['case_no'] ?? '') !== '') $key = self::folderFor((string)$lead['case_no']);
        if ($key === '' || !preg_match('/^[A-Za-z0-9_-]{1,60}$/', $key)) return null;
        return self::dir($p) . '/photos/' . $key;
    }

    public static function folderFor(string $caseNo): string
    {
        return substr((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($caseNo)), 0, 60);
    }

    /** Erase leads older than 90 days and photos older than 14 days. */
    public static function tidy(Paths $p): void
    {
        $cut = Clock::now() - self::KEEP_DAYS * 86400;
        foreach (glob(self::dir($p) . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]/*.json') ?: [] as $f) {
            // by when the lead arrived (re-sending it later doesn't keep it longer)
            $l = Json::read($f);
            if (!is_array($l) || (int)($l['at'] ?? 0) < $cut) @unlink($f);
        }
        foreach (glob(self::dir($p) . '/held-notice-*') ?: [] as $f) if (@filemtime($f) < Clock::now() - 2 * 86400) @unlink($f);
        foreach (glob(self::dir($p) . '/[0-9][0-9][0-9][0-9]-[0-9][0-9]', GLOB_ONLYDIR) ?: [] as $d) @rmdir($d); // only when empty
        $pcut = Clock::now() - self::PHOTO_DAYS * 86400;
        foreach (glob(self::dir($p) . '/photos/*', GLOB_ONLYDIR) ?: [] as $d) {
            if (@filemtime($d) < $pcut) Files::rmTree($d);
        }
    }

    /** A plain list for the Leads screen. */
    public static function listing(Paths $p, int $max = 300): array
    {
        $out = [];
        foreach (array_slice(self::files($p), 0, $max) as $f) {
            $l = Json::read($f);
            if (!is_array($l)) continue;
            $out[] = self::summary($p, $l);
        }
        return $out;
    }

    public static function summary(Paths $p, array $l): array
    {
        $kind = (string)($l['kind'] ?? 'claim');
        return [
            'id' => $l['id'], 'at' => (int)$l['at'], 'when' => Clock::when((int)$l['at']), 'kind' => $kind,
            'what' => ['claim' => 'Claim', 'contact' => 'Message', 'chat' => 'Chat', 'photos' => 'Claim photos'][$kind] ?? 'Lead',
            'name' => (string)($l['name'] ?? ''), 'phone' => (string)($l['phone'] ?? ''), 'email' => (string)($l['email'] ?? ''),
            'caseNo' => (string)($l['case_no'] ?? ''), 'mail' => (string)($l['mail']['state'] ?? 'unknown'),
            'photos' => count($l['photos'] ?? []), 'snippet' => self::snippet($l),
        ];
    }

    private static function snippet(array $l): string
    {
        $p = $l['payload'] ?? [];
        foreach (['Chat transcript', 'description', 'Description', 'message', 'Message', 'What happened'] as $k) {
            if (isset($p[$k]) && is_string($p[$k]) && trim($p[$k]) !== '') {
                $t = trim((string)preg_replace('/\s+/', ' ', str_replace(['Visitor: ', 'Think Smart: '], ['', ''], $p[$k])));
                return strlen($t) > 140 ? substr($t, 0, 137) . '…' : $t;
            }
        }
        return '';
    }

    /** One lead in full, as plain label/value pairs (shown as text only, never as HTML). */
    public static function detail(Paths $p, array $l): array
    {
        $rows = [];
        foreach (($l['payload'] ?? []) as $k => $v) {
            if (!is_scalar($v)) continue;
            $v = trim((string)$v);
            if ($v === '' || str_starts_with((string)$k, '_')) continue;
            $rows[] = [(string)$k, $v];
        }
        return self::summary($p, $l) + ['rows' => $rows, 'photoFiles' => self::photos($p, $l), 'ip' => (string)($l['ip'] ?? ''),
            'page' => (string)($l['page'] ?? ''), 'mailTo' => $l['mail']['to'] ?? [], 'mailAt' => isset($l['mail']['at']) ? Clock::when((int)$l['mail']['at']) : ''];
    }

    /** An old lead-log line from before the editor (imported at install). */
    public static function import(Config $cfg, array $record): void
    {
        $at = isset($record['received']) ? (int)strtotime((string)$record['received']) : Clock::now();
        if ($at <= 0 || $at < Clock::now() - self::KEEP_DAYS * 86400) return;
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];
        $lead = [
            'id' => self::newId($at), 'at' => $at, 'kind' => in_array($record['kind'] ?? '', ['claim', 'contact', 'chat', 'photos'], true) ? $record['kind'] : 'claim',
            'case_no' => (string)($record['case_no'] ?? ''), 'payload' => $payload, 'ip' => (string)($record['ip'] ?? ''),
            'mail' => ['state' => !empty($record['emailed']) ? 'sent' : 'failed', 'at' => $at, 'to' => []], 'imported' => true, 'photos' => [],
        ];
        $lead += self::who($payload);
        if (($record['kind'] ?? '') === 'photos') { $lead['name'] = (string)($record['name'] ?? ''); $lead['photoCount'] = (int)($record['photos'] ?? 0); }
        self::put($cfg->paths, $lead);
    }

    /** Name, phone and email from a lead's fields (the visitor's, never the business's own). */
    public static function who(array $payload): array
    {
        $out = ['name' => '', 'phone' => '', 'email' => ''];
        foreach ($payload as $k => $v) {
            if (!is_scalar($v)) continue;
            $k = strtolower((string)$k);
            $v = trim((string)$v);
            if ($out['name'] === '' && str_contains($k, 'name')) $out['name'] = substr($v, 0, 80);
            if ($out['phone'] === '' && (str_contains($k, 'phone') || str_contains($k, 'tel'))) $out['phone'] = substr($v, 0, 40);
            if ($out['email'] === '' && str_contains($k, 'email') && filter_var($v, FILTER_VALIDATE_EMAIL)) $out['email'] = $v;
        }
        return $out;
    }
}
