<?php
// Health: the status line ("Website working · Last lead received Tue 9:12 am"), warnings that
// name one action (certificate and domain dates go to John only), bounced lead emails, and
// self-repair: files the editor owns that were changed or damaged outside it are put back
// from a fingerprinted pristine copy in the private folder, and recorded in History.
//
//   <private>/pristine/files/…        the editor's own files as installed
//   <private>/pristine/manifest.json  path => sha256
//   <private>/health.json             when things were last checked, and what was found
//
// It never touches .htaccess, .user.ini, .well-known or cgi-bin (cPanel writes to those), nor
// media or docs.
declare(strict_types=1);

namespace ThinkSmart;

final class Health
{
    public const CHECK_EVERY = 3600;     // files: at most hourly
    public const NET_EVERY = 86400;      // certificate, domain, bounces: at most daily

    /** The files the editor owns, relative to the web root. */
    public static function ownedFiles(Paths $p): array
    {
        $out = [];
        $walk = function (string $rel) use (&$walk, &$out, $p) {
            $abs = $p->webroot . '/' . $rel;
            foreach (scandir($abs) ?: [] as $f) {
                if ($f === '.' || $f === '..') continue;
                $r = "$rel/$f";
                if (is_dir("$abs/$f")) { $walk($r); continue; }
                if (in_array($r, ['edit/.installed'], true) || str_ends_with($f, '.tmp')) continue;
                $out[] = $r;
            }
        };
        if (is_dir($p->webroot . '/edit')) $walk('edit');
        if (is_dir($p->webroot . '/vendor')) $walk('vendor');
        foreach (['send-lead.php', 'support.js'] as $f) if (is_file($p->webroot . '/' . $f)) $out[] = $f;
        sort($out);
        return $out;
    }

    private static function dir(Paths $p): string
    {
        return $p->private . '/pristine';
    }

    /** Only files really inside the web root are put back (never through a link to somewhere else). */
    private static function inside(Paths $p, string $live): bool
    {
        $root = realpath($p->webroot);
        $dir = realpath(dirname($live));
        return $root !== false && $dir !== false && ($dir === $root || str_starts_with($dir, $root . '/')) && !is_link($live);
    }

    /** Keep a fingerprinted copy of the editor's own files (the installer calls this). */
    public static function snapshotPristine(Config $cfg): void
    {
        $p = $cfg->paths;
        $tmp = self::dir($p) . '.new';
        Files::rmTree($tmp);
        $manifest = [];
        foreach (self::ownedFiles($p) as $rel) {
            $src = $p->webroot . '/' . $rel;
            if (is_link($src)) $src = (string)realpath($src);
            $data = (string)@file_get_contents($src);
            Files::writeAtomic("$tmp/files/$rel", $data);
            $manifest[$rel] = hash('sha256', $data);
        }
        Json::write("$tmp/manifest.json", ['made' => Clock::now(), 'files' => $manifest]);
        Files::rmTree(self::dir($p));
        if (!@rename($tmp, self::dir($p))) throw new BuildError('Could not keep the pristine copy');
    }

    /**
     * Put back owned files that differ from the pristine copy, and built pages that differ from
     * the last build. At most hourly unless $force. Returns the files put back.
     */
    public static function check(Config $cfg, People $people, bool $force = false): array
    {
        $p = $cfg->paths;
        $state = Json::read($p->private . '/health.json', []);
        if (!$force && (int)($state['filesAt'] ?? 0) > Clock::now() - self::CHECK_EVERY) return [];
        $fixed = Lock::with($p, 'publish', function () use ($p) {
            $fixed = [];
            // the editor's own code and the lead mailer
            $m = Json::read(self::dir($p) . '/manifest.json');
            foreach (($m['files'] ?? []) as $rel => $sha) {
                if (preg_match('#(^|/)\.(htaccess|user\.ini|well-known)|^cgi-bin/#', $rel)) continue;
                $live = $p->webroot . '/' . $rel;
                if (Files::sha($live) === $sha || !self::inside($p, $live)) continue;
                $src = self::dir($p) . '/files/' . $rel;
                if (Files::sha($src) !== $sha) continue; // the pristine copy itself is damaged: leave it
                Files::writeAtomic($live, (string)file_get_contents($src));
                $fixed[] = $rel;
            }
            // the built pages, from the last build that went live
            $j = Journal::read($p);
            if ($j && ($j['state'] ?? '') === 'done') {
                $bm = Json::read($j['build'] . '/manifest.json');
                foreach (($bm['files'] ?? []) as $file => $info) {
                    $live = $p->webroot . '/' . $file;
                    if (Files::sha($live) === $info['sha']) continue;
                    $src = $j['build'] . '/site/' . $file;
                    if (Files::sha($src) !== $info['sha']) continue;
                    Files::writeAtomic($live, (string)file_get_contents($src));
                    $fixed[] = $file;
                }
            }
            return $fixed;
        });
        $state['filesAt'] = Clock::now();
        // logs stay small: past 2 MB, keep the newest half
        foreach (['php-errors.log', 'editor-errors.log'] as $log) {
            $f = $p->private . '/' . $log;
            if (@filesize($f) > 2097152) { $t = (string)@file_get_contents($f); @file_put_contents($f, substr($t, (int)(strlen($t) / 2)), LOCK_EX); }
        }
        if ($fixed) {
            $state['repaired'] = ['at' => Clock::now(), 'files' => $fixed];
            self::record($cfg, $people, $fixed);
        }
        Json::write($p->private . '/health.json', $state, false);
        return $fixed;
    }

    /** "Repair the editor": copy every one of its own files back, and rebuild the pages from the content. */
    public static function repairEditor(Config $cfg, People $people): array
    {
        $p = $cfg->paths;
        $fixed = Lock::with($p, 'publish', function () use ($p) {
            $fixed = [];
            $m = Json::read(self::dir($p) . '/manifest.json');
            foreach (($m['files'] ?? []) as $rel => $sha) {
                $src = self::dir($p) . '/files/' . $rel;
                $live = $p->webroot . '/' . $rel;
                if (Files::sha($src) !== $sha || Files::sha($live) === $sha || !self::inside($p, $live)) continue;
                Files::writeAtomic($live, (string)file_get_contents($src));
                $fixed[] = $rel;
            }
            return $fixed;
        });
        // the pages, rebuilt from the live content (content itself is never touched)
        $pub = new Publisher($cfg, $people);
        $pub->settle();
        Lock::with($p, 'publish', function () use ($p, $pub, &$fixed) {
            $content = $pub->live->load();
            $res = (new Build($p))->build($content);
            foreach ($res['files'] as $file => $m) if (Files::sha($p->webroot . '/' . $file) !== $m['sha']) $fixed[] = $file;
            Journal::publish($p, $res);
        });
        if ($fixed) self::record($cfg, $people, $fixed, false);
        return $fixed;
    }

    /** History gets one plain line (no file names); John's email lists the files. */
    private static function record(Config $cfg, People $people, array $files, bool $mail = true): void
    {
        $pub = new Publisher($cfg, $people);
        $list = implode(', ', array_slice($files, 0, 8)) . (count($files) > 8 ? ' and ' . (count($files) - 8) . ' more' : '');
        Lock::with($cfg->paths, 'publish', function () use ($pub) {
            $pub->history->add(['at' => Clock::now(), 'by' => null, 'kind' => 'repair', 'lines' => ['Pages put back the way they should be (nothing you changed was affected)'], 'keys' => []],
                $pub->live->load(), []);
        });
        if ($mail) {
            (new Mailer($cfg))->send($people->alertEmails(), 'Your website repaired itself', [
                "Some of the website's own files were changed outside the website editor, so they were put back: $list.",
                'If a developer or GoDaddy did this on purpose, changes belong in the editor. If nobody should have, change the GoDaddy password.',
            ], null, 'alert');
        }
    }

    // ---------------------------------------------------------------- the status line

    /**
     * Plain status for the start screen and the Leads screen.
     * @return array{ok: bool, lines: list<array{level: string, text: string, href?: string}>, lastLead: ?array, mail: string, bounced: int}
     */
    public static function status(Config $cfg, bool $forJohn): array
    {
        $p = $cfg->paths;
        $state = Json::read($p->private . '/health.json', []);
        $lines = [];
        $last = Leads::last($p);
        $bounced = 0;
        foreach (array_slice(Leads::files($p), 0, 200) as $f) {
            $l = Json::read($f);
            if (is_array($l) && ($l['mail']['state'] ?? '') === 'bounced' && empty($l['seenBounce']) && (int)$l['at'] > Clock::now() - 30 * 86400) $bounced++;
        }
        if ($bounced) $lines[] = ['level' => 'bad', 'text' => "$bounced lead" . ($bounced === 1 ? '' : 's') . ' didn\'t reach your email. Open them here.', 'href' => '#/leads'];
        $held = 0;
        foreach (array_slice(Leads::files($p), 0, 50) as $f) {
            $l = Json::read($f);
            if (is_array($l) && ($l['mail']['state'] ?? '') === 'held' && (int)$l['at'] > Clock::now() - 2 * 86400) $held++;
        }
        if ($held) $lines[] = ['level' => 'warn', 'text' => "$held new lead" . ($held === 1 ? ' is' : 's are') . ' waiting here (not emailed). Open them here.', 'href' => '#/leads'];
        if ($forJohn) {
            $ssl = $state['ssl'] ?? null;
            if (is_array($ssl) && isset($ssl['days'])) {
                if ($ssl['days'] < 0) $lines[] = ['level' => 'bad', 'text' => 'The website\'s security certificate has run out. Call GoDaddy and say: my website\'s security certificate has expired.'];
                elseif ($ssl['days'] < 21) $lines[] = ['level' => 'warn', 'text' => 'The website\'s security certificate runs out on ' . Clock::local((int)$ssl['until'])->format('M j') . '. Call GoDaddy and say: my website\'s security certificate is expiring.'];
            }
            $dom = $state['domain'] ?? null;
            if (is_array($dom) && isset($dom['until']) && $dom['until'] - Clock::now() < 45 * 86400) {
                $lines[] = ['level' => $dom['until'] < Clock::now() + 7 * 86400 ? 'bad' : 'warn', 'text' => 'The domain name runs out on '
                    . Clock::local((int)$dom['until'])->format('M j, Y') . '. Ask whoever pays for GoDaddy to renew the domain.'];
            }
            $rep = $state['repaired'] ?? null;
            if (is_array($rep) && (int)$rep['at'] > Clock::now() - 7 * 86400) {
                $lines[] = ['level' => 'warn', 'text' => 'Files changed outside the editor were put back on ' . Clock::when((int)$rep['at']) . '. If nobody should have changed them, change the GoDaddy password.'];
            }
        }
        $mail = isset($state['bounceReader']) ? (string)$state['bounceReader'] : 'unknown';
        return [
            'ok' => !array_filter($lines, fn($l) => $l['level'] === 'bad'),
            'lines' => $lines,
            'lastLead' => $last ? ['at' => (int)$last['at'], 'when' => Clock::when((int)$last['at'])] : null,
            'mail' => $mail,
            'bounced' => $bounced,
        ];
    }

    /** Daily: the certificate's and the domain's dates, and bounced lead emails. Quiet on any failure. */
    public static function daily(Config $cfg, bool $force = false): void
    {
        $p = $cfg->paths;
        $state = Json::read($p->private . '/health.json', []);
        if (!$force && (int)($state['netAt'] ?? 0) > Clock::now() - self::NET_EVERY) return;
        $state['netAt'] = Clock::now();
        Json::write($p->private . '/health.json', $state, false); // one try a day, even if slow
        $host = (string)parse_url($cfg->siteUrl(), PHP_URL_HOST);
        if ($host !== '' && !$cfg->dev()) {
            $until = self::certificateUntil($host);
            if ($until !== null) $state['ssl'] = ['until' => $until, 'days' => (int)floor(($until - Clock::now()) / 86400), 'at' => Clock::now()];
            $d = self::domainUntil((string)preg_replace('/^www\./', '', $host));
            if ($d !== null) $state['domain'] = ['until' => $d, 'at' => Clock::now()];
        }
        $b = self::readBounces($cfg);
        $state['bounceReader'] = $b === null ? 'unavailable' : 'ok';
        Json::write($p->private . '/health.json', $state, false);
    }

    public static function certificateUntil(string $host): ?int
    {
        if (!function_exists('openssl_x509_parse')) return null;
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $s = @stream_socket_client("ssl://$host:443", $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) return null;
        $params = stream_context_get_params($s);
        fclose($s);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $info = $cert ? @openssl_x509_parse($cert) : false;
        return is_array($info) && isset($info['validTo_time_t']) ? (int)$info['validTo_time_t'] : null;
    }

    /** The domain's expiry date from the public registry (RDAP). */
    public static function domainUntil(string $domain): ?int
    {
        if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain) || !ini_get('allow_url_fopen')) return null;
        $ctx = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 1, 'max_redirects' => 3, 'header' => "Accept: application/rdap+json\r\n"]]);
        $raw = @file_get_contents('https://rdap.org/domain/' . $domain, false, $ctx);
        $j = is_string($raw) ? json_decode($raw, true) : null;
        foreach (($j['events'] ?? []) as $e) {
            if (($e['eventAction'] ?? '') === 'expiration' && ($t = strtotime((string)($e['eventDate'] ?? ''))) !== false) return $t;
        }
        return null;
    }

    /**
     * Bounced lead emails, read from the site's noreply@ mailbox when the hosting allows it
     * (config "bounce": {"mailbox": "{mail.example.com:993/imap/ssl}INBOX", "user": …, "password": …}).
     * Marks those leads "bounced". Null when it isn't available.
     */
    public static function readBounces(Config $cfg): ?int
    {
        $b = $cfg->get('bounce');
        if (!is_array($b) || !function_exists('imap_open') || empty($b['mailbox']) || empty($b['user'])) return null;
        if (function_exists('imap_timeout')) { @imap_timeout(IMAP_OPENTIMEOUT, 5); @imap_timeout(IMAP_READTIMEOUT, 10); }
        $box = @imap_open((string)$b['mailbox'], (string)$b['user'], (string)($b['password'] ?? ''), 0, 1);
        if (!$box) return null;
        $n = 0;
        $since = date('j-M-Y', Clock::now() - 14 * 86400);
        foreach (@imap_search($box, 'UNSEEN SINCE "' . $since . '"') ?: [] as $msg) {
            $body = (string)@imap_body($box, $msg, FT_PEEK);
            if (!preg_match_all('/X-TS-Lead:\s*(\d{8}-\d{6}-[a-f0-9]{6})/i', $body, $m)) continue;
            foreach (array_unique($m[1]) as $id) {
                $l = Leads::get($cfg->paths, $id);
                if (!$l || ($l['mail']['state'] ?? '') === 'bounced') continue;
                $l['mail']['state'] = 'bounced';
                $l['mail']['bouncedAt'] = Clock::now();
                Leads::put($cfg->paths, $l);
                $n++;
            }
            @imap_setflag_full($box, (string)$msg, '\\Seen');
        }
        @imap_close($box);
        return $n;
    }
}
