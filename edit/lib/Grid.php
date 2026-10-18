<?php
// Fill rules for the "hairline" grids (cards separated by 1px lines). Whatever the number of
// cards, every row is full: at each screen width the grid uses the column count that leaves
// the fewest empty cells (a count that divides evenly leaves none), and if a gap would remain
// the last card stretches across it. Never a lone card beside an empty grey block.
declare(strict_types=1);

namespace ThinkSmart;

final class Grid
{
    /**
     * Grids on the site: name => [list of [min viewport width, most columns that fit there]].
     * Widths come from each grid's minimum card width inside the page padding
     * (clamp(20px,4vw,56px) each side, 1px lines, 1400px max).
     */
    public const GRIDS = [
        // Claim: state cards (today's rules, 1 column below 520px)
        'states' => [[520, 2], [760, 3], [1372, 6]],
        // Home: services row, cards at least 250px wide
        'services' => [[547, 2], [820, 3], [1093, 4], [1366, 5]],
        // Contact: ways to reach us, cards at least 280px wide
        'channels' => [[612, 2], [917, 3], [1223, 4]],
        // Home: reviews, cards at least 300px wide
        'reviews' => [[700, 2], [1060, 3]],
        // Services: what we cover, tiles at least 300px wide
        'cover' => [[656, 2], [983, 3], [1310, 4]],
    ];

    /** Columns for $n cards when up to $max fit: fewest empty cells, then the most columns. */
    public static function columns(int $n, int $max): int
    {
        if ($n <= 1) return 1;
        $max = min($max, $n);
        $best = 1;
        $bestEmpty = PHP_INT_MAX;
        for ($c = (int)ceil($max / 2); $c <= $max; $c++) {
            $empty = ($c - $n % $c) % $c;
            if ($empty < $bestEmpty || ($empty === $bestEmpty && $c > $best)) { $best = $c; $bestEmpty = $empty; }
        }
        return $best;
    }

    /** CSS rules for one grid holding $n cards (lines joined for a two-space-indented <style>). */
    public static function css(string $name, int $n): string
    {
        $steps = self::GRIDS[$name] ?? null;
        if ($steps === null) throw new BuildError("Unknown grid '$name'");
        $sel = '[data-grid="' . $name . '"]';
        $lines = [];
        $prevCols = 1;
        $prevSpan = 1;
        foreach ($steps as [$width, $max]) {
            $c = self::columns($n, $max);
            $rest = $n % $c;
            $span = $rest === 0 ? 1 : $c - $rest + 1;
            if ($c !== $prevCols) {
                $lines[] = "@media (min-width: {$width}px) { $sel { grid-template-columns: repeat($c, 1fr) !important; } }";
            }
            if ($span !== $prevSpan) {
                $val = $span === 1 ? 'auto' : "span $span";
                $lines[] = "@media (min-width: {$width}px) { $sel > :last-child { grid-column: $val !important; } }";
            }
            $prevCols = $c;
            $prevSpan = $span;
        }
        return implode("\n  ", $lines);
    }
}
