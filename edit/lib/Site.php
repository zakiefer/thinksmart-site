<?php
// What the site knows beyond the stored words: the pages, the chips (settings shown inside
// text), the 50 states plus DC, and values worked out from the content before a build.
declare(strict_types=1);

namespace ThinkSmart;

final class Site
{
    /** Built pages: template file => output file, page variable. */
    public const PAGES = [
        'Home' => 'Home.dc.html', 'About' => 'About.dc.html', 'Services' => 'Services.dc.html',
        'FAQ' => 'FAQ.dc.html', 'Contact' => 'Contact.dc.html', 'Claim' => 'Claim.dc.html',
        'ChatWidget' => 'ChatWidget.dc.html', 'NotFound' => '404.html', 'Index' => 'index.html',
        'Sitemap' => 'sitemap.xml',
    ];

    public const STATES = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia',
        'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois',
        'IN' => 'Indiana', 'IA' => 'Iowa', 'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana',
        'ME' => 'Maine', 'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
        'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada',
        'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico', 'NY' => 'New York',
        'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio', 'OK' => 'Oklahoma', 'OR' => 'Oregon',
        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina', 'SD' => 'South Dakota',
        'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia',
        'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /**
     * State-card name sizes. Names are 34px; a single word wider than the narrowest card
     * (157px of text room: 6 cards across at 1372px) gets a smaller size so it never clips.
     * Measured in Chromium and WebKit (Big Shoulders Display 700, uppercase): Massachusetts
     * 183.7px and Pennsylvania 158.6px at 34px; every other name's longest word is 150px or less.
     */
    public const STATE_FONT = ['MA' => 27, 'PA' => 32];

    /** Callback promise choices: long form (after "call you back") and short capitals form. */
    public const CALLBACK = [
        '24h' => ['within 24 hours', 'WITHIN 24H'],
        'sameday' => ['the same business day', 'SAME DAY'],
        'nextday' => ['by the next business day', 'NEXT BUSINESS DAY'],
        '48h' => ['within 48 hours', 'WITHIN 48H'],
    ];

    /** Chips: name => plain label shown on the chip in the editor. */
    public const REFS = [
        'phone' => 'Main phone', 'phone2' => 'Second phone', 'email' => 'Email shown on the site',
        'address' => 'Office address', 'addressFooter' => 'Office address',
        'callback' => 'Callback promise', 'callbackShort' => 'Callback promise (short)',
        'price' => 'Stand-alone estimate price', 'adjuster' => 'Adjuster name', 'adjusterTitle' => 'Adjuster title',
        'stateCodes' => 'Your states (short)', 'stateNames' => 'Your states', 'otherStates' => 'Other states',
        'guaranteeShort' => 'Guarantee (short)', 'guaranteeLong' => 'Guarantee (long)',
        'servicesCount' => 'Number of services', 'noticeUntil' => 'Notice end date',
    ];

    private const NUMBER_WORDS = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve'];

    /** Resolver used by templates and rich text: chip name => text as visitors read it. */
    public static function refResolver(): callable
    {
        return static function (string $name, array $scope): string {
            $root = $scope['root'] ?? $scope;
            return self::ref($name, $root);
        };
    }

    public static function ref(string $name, array $root): string
    {
        $site = $root['site'] ?? [];
        switch ($name) {
            case 'phone': return self::need($site, 'phone');
            case 'phone2': return trim((string)($site['phone2'] ?? '')) !== '' ? (string)$site['phone2'] : self::need($site, 'phone');
            case 'email': return self::need($site, 'email');
            case 'address':
                $a = $site['address'] ?? [];
                return "{$a['street']}, {$a['city']}, {$a['region']} {$a['zip']}";
            case 'addressFooter':
                $a = $site['address'] ?? [];
                return "{$a['street']} · {$a['city']}, {$a['region']} {$a['zip']}";
            case 'callback': return self::CALLBACK[$site['callback'] ?? '24h'][0];
            case 'callbackShort': return self::CALLBACK[$site['callback'] ?? '24h'][1];
            case 'price': return '$' . (int)($site['price'] ?? 0);
            case 'adjuster': return (string)($site['adjuster']['name'] ?? '');
            case 'adjusterTitle': return (string)($site['adjuster']['title'] ?? '');
            case 'stateCodes': return self::joinList(array_column(self::states($root), 'code'), ' & ');
            case 'stateNames': return self::joinList(array_column(self::states($root), 'name'), ' and ');
            case 'otherStates':
                $served = array_column(self::states($root), 'code');
                $codes = array_values(array_filter($root['claim']['byPhone']['otherStates'] ?? [], fn($c) => !in_array($c, $served, true)));
                return implode(', ', array_map(fn($c) => self::STATES[$c] ?? $c, $codes));
            case 'guaranteeShort': return (string)($site['guarantee']['short'] ?? '');
            case 'guaranteeLong': return (string)($site['guarantee']['long'] ?? '');
            case 'noticeUntil': return Notice::untilText($root['notice'] ?? []);
            case 'servicesCount':
                $n = count(array_filter($root['services']['items'] ?? [], fn($s) => empty($s['hidden'])));
                return self::NUMBER_WORDS[$n] ?? (string)$n;
        }
        throw new BuildError("Unknown chip '$name'");
    }

    private static function need(array $a, string $k): string
    {
        if (!isset($a[$k]) || trim((string)$a[$k]) === '') throw new BuildError("The setting '$k' is empty");
        return (string)$a[$k];
    }

    /** "A, B and C" (last joiner given). */
    public static function joinList(array $items, string $last): string
    {
        $items = array_values($items);
        $n = count($items);
        if ($n === 0) return '';
        if ($n === 1) return $items[0];
        return implode(', ', array_slice($items, 0, -1)) . $last . $items[$n - 1];
    }

    /** Visible states with their names. */
    public static function states(array $root): array
    {
        $out = [];
        foreach ($root['states'] ?? [] as $s) {
            if (!empty($s['hidden'])) continue;
            $code = (string)($s['code'] ?? '');
            if (!isset(self::STATES[$code])) throw new BuildError("Unknown state '$code'");
            $out[] = $s + ['name' => self::STATES[$code]];
        }
        return $out;
    }
}
