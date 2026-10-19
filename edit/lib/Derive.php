<?php
// Values worked out from the content before a build (never stored): the scrolling band's
// repeats and timing, Google's business info, the big statement's words, lists shown in
// more than one place, and similar.
declare(strict_types=1);

namespace ThinkSmart;

final class Derive
{
    public static function all(array $c): array
    {
        return [
            'ticker' => self::ticker($c),
            'jsonld' => self::jsonld($c),
            'statement' => self::statement($c),
            'homeFaq' => self::homeFaq($c),
            'states' => Site::states($c),
            'pdfStates' => array_values(array_filter(Site::states($c), fn($s) => !empty($s['pdf']))),
            'grid' => [
                'states' => Grid::css('states', count(Site::states($c)) + 1),
                'services' => Grid::css('services', count(self::visible($c['services']['items'] ?? []))),
                'channels' => Grid::css('channels', 4),
                'reviews' => Grid::css('reviews', count(self::reviews($c))),
                'cover' => Grid::css('cover', count(self::visible($c['services']['coverage']['tiles'] ?? [])) + 1),
            ],
            'reviews' => self::reviews($c),
            'licenseLine' => self::licenseLine($c),
            'homeTopLine' => self::homeTopLine($c),
            'notice' => Notice::derive($c),
            'homeNumbers' => self::visible(array_values(array_filter([
                isset($c['numbers']['underpaid']) ? $c['numbers']['underpaid'] + ['_src' => 'numbers.underpaid'] : null,
                isset($c['numbers']['higher']) ? $c['numbers']['higher'] + ['_src' => 'numbers.higher'] : null,
            ]))),
            'damageNextLetter' => chr(65 + count(self::visible($c['home']['damage']['cards'] ?? []))),
        ];
    }

    public static function visible(array $list): array
    {
        return array_values(array_filter($list, fn($it) => !(is_array($it) && !empty($it['hidden']))));
    }

    /**
     * Scrolling words band. Each half of the band holds the ticked phrases enough times to be
     * wider than a 2560px screen; the loop time grows with the width so the speed stays the
     * same as today's (36s for today's 9 phrases, which measure about 1290px).
     */
    public const TICKER_TARGET_PX = 2560;
    public const TICKER_TODAY_SECONDS = 36;

    public static function ticker(array $c): array
    {
        $phrases = [];
        foreach ($c['home']['marquee']['phrases'] ?? [] as $p) {
            if (is_array($p) && !empty($p['on']) && trim((string)($p['text'] ?? '')) !== '') $phrases[] = Text::upper((string)$p['text']);
        }
        $setPx = self::tickerSetWidth($phrases);
        $reps = max(1, (int)ceil(self::TICKER_TARGET_PX / max(1.0, $setPx)));
        $run = [];
        for ($r = 0; $r < $reps; $r++) foreach ($phrases as $p) $run[] = $p;
        // factor = loop time in units of "36s", so the JS prop (36 by default) keeps working
        $factor = round($reps * $setPx / self::tickerTodayWidth(), 2);
        return [
            'halves' => [1, 2],
            'run' => $run,
            'factor' => $factor,
            'seconds' => round(self::TICKER_TODAY_SECONDS * $factor, 1),
        ];
    }

    /**
     * Width of one set, as measured in Chromium and WebKit: each character of IBM Plex Mono
     * 12px with .3em letter-spacing is 10.8px, each phrase adds 36px padding and a 12.7px ✦.
     */
    public static function tickerSetWidth(array $phrases): float
    {
        $w = 0.0;
        foreach ($phrases as $p) $w += Text::length($p) * 10.8 + 36 + 12.7;
        return $w;
    }

    private static function tickerTodayWidth(): float
    {
        return self::tickerSetWidth(['WIND', 'HAIL', 'HURRICANE', 'FLOOD', 'WATER', 'FIRE & SMOKE', 'DENIED CLAIMS', 'UNDERPAID CLAIMS', 'SUPPLEMENTS']);
    }

    /** Google's business info for the Home page. */
    public static function jsonld(array $c): array
    {
        $s = $c['site'] ?? [];
        $a = $s['address'] ?? [];
        $phone = preg_replace('/\D/', '', (string)($s['phone'] ?? ''));
        $out = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => (string)($s['businessName'] ?? 'Think Smart Public Adjusting LLC'),
            'url' => 'https://www.thinksmartpublicadjusting.com/',
            'image' => 'https://www.thinksmartpublicadjusting.com/' . ($s['sharePicture'] ?? 'media/og-cover.jpg'),
            'telephone' => strlen($phone) === 10 ? '+1-' . substr($phone, 0, 3) . '-' . substr($phone, 3, 3) . '-' . substr($phone, 6) : '+' . $phone,
            'email' => (string)($s['email'] ?? ''),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => (string)($a['street'] ?? ''),
                'addressLocality' => (string)($a['city'] ?? ''),
                'addressRegion' => (string)($a['region'] ?? ''),
                'postalCode' => (string)($a['zip'] ?? ''),
                'addressCountry' => 'US',
            ],
        ];
        $same = array_values(array_map(fn($x) => (string)$x['url'], self::visible($s['social'] ?? [])));
        if ($same) $out['sameAs'] = $same;
        return $out;
    }

    /** The big statement on Home, word by word (orange words are the highlighted ones). */
    public static function statement(array $c): array
    {
        $lines = [];
        foreach ($c['home']['statement']['lines'] ?? [] as $li => $line) {
            $words = [];
            foreach (Rich::runs($line, 'big statement') as $r) {
                $text = is_string($r) ? $r : (string)($r['hl'] ?? '');
                $amber = is_array($r) && isset($r['hl']);
                foreach (preg_split('/(?<= )/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $w) $words[] = ['text' => $w, 'amber' => $amber];
            }
            if ($words) $words[count($words) - 1]['text'] = rtrim($words[count($words) - 1]['text']);
            $lines[] = ['words' => $words, '_src' => "home.statement.lines.$li"];
        }
        return $lines;
    }

    /**
     * Reviews for Home. The name is shortened ("Michael Rodriguez" -> "Michael R."), and the
     * small line under it lists what was given: town and state, claim type, month, source,
     * and "shortened" when the quote was trimmed.
     */
    public static function reviews(array $c): array
    {
        $out = [];
        foreach ($c['home']['reviews']['items'] ?? [] as $k => $r) {
            if (!is_array($r) || !empty($r['hidden'])) continue;
            $text = trim((string)($r['text'] ?? ''));
            $name = trim((string)($r['name'] ?? ''));
            // only reviews confirmed as a real customer's own words
            if ($text === '' || $name === '' || empty($r['real'])) continue;
            $meta = [];
            $place = implode(', ', array_filter([trim((string)($r['town'] ?? '')), trim((string)($r['state'] ?? ''))]));
            if ($place !== '') $meta[] = $place;
            if (trim((string)($r['claimType'] ?? '')) !== '') $meta[] = trim((string)$r['claimType']) . ' claim';
            if (preg_match('/^(\d{4})-(\d{2})$/', (string)($r['month'] ?? ''), $m)) {
                $meta[] = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][(int)$m[2]] . ' ' . $m[1];
            }
            $meta[] = (trim((string)($r['source'] ?? '')) ?: 'Google') . ' review';
            if (!empty($r['trimmed'])) $meta[] = 'shortened';
            $stars = (int)($r['stars'] ?? 0);
            $out[] = [
                '_src' => "home.reviews.items.$k",
                'text' => $text,
                'name' => self::shortName($name),
                'meta' => implode(' · ', $meta),
                'stars' => $stars >= 1 && $stars <= 5 ? $stars : null,
                'starText' => $stars >= 1 && $stars <= 5 ? str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) : '',
            ];
        }
        return $out;
    }

    /** "Michael Rodriguez" -> "Michael R."; one word stays as it is. */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name));
        if (count($parts) < 2) return $parts[0];
        $last = array_pop($parts);
        preg_match('/^\X/u', $last, $m);
        return implode(' ', $parts) . ' ' . Text::upper($m[0]) . '.';
    }

    /** Footer license line written from the states list ("Automatic from your states"). */
    public static function licenseLine(array $c): string
    {
        $names = array_column(Site::states($c), 'name');
        $who = (string)($c['site']['adjuster']['name'] ?? '');
        $where = count($names) === 1 ? 'the State of ' . $names[0] : 'the states of ' . Site::joinList($names, ' and ');
        return "PA $who works solely as an independent public adjuster under his own license with $where.";
    }

    /** Home top line written from the states list ("Automatic from your states"). */
    public static function homeTopLine(array $c): string
    {
        $s = Site::states($c);
        $where = count($s) <= 2 ? implode(' · ', array_column($s, 'name')) : implode(' · ', array_column($s, 'code'));
        return 'Licensed Public Adjusters · Policyholder Side Only · ' . $where;
    }

    /** FAQ items starred for Home, with their short versions (or the full question/answer). */
    public static function homeFaq(array $c): array
    {
        $out = [];
        foreach ($c['faq']['items'] ?? [] as $k => $it) {
            if (!is_array($it) || !empty($it['hidden']) || empty($it['home'])) continue;
            $out[] = [
                'q' => $it['homeQ'] ?? $it['q'],
                'a' => $it['homeA'] ?? ($it['a'][0]['text'] ?? ''),
                '_src' => "faq.items.$k",
                '_edit' => ['q' => "faq.items.$k." . (isset($it['homeQ']) ? 'homeQ' : 'q'), 'a' => "faq.items.$k." . (isset($it['homeA']) ? 'homeA' : 'a.0.text')],
            ];
        }
        return $out;
    }
}
