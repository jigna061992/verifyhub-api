<?php

namespace App\Services;

/**
 * Compares two person names and returns a score from 0 to 100.
 * Handles different word order ("KUMAR RAMESH" = "RAMESH KUMAR"), titles (MR, SHRI...),
 * punctuation and initials ("R KUMAR" matches "RAMESH KUMAR").
 */
final class NameMatcher
{
    private const TITLES = ['MR', 'MRS', 'MS', 'MISS', 'SHRI', 'SHREE', 'SRI', 'SMT', 'KUMARI', 'DR', 'LATE'];

    public static function score(string $a, string $b): int
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);

        if (! $ta || ! $tb) {
            return 0;
        }

        $sortedA = $ta;
        $sortedB = $tb;
        sort($sortedA);
        sort($sortedB);

        similar_text(implode(' ', $sortedA), implode(' ', $sortedB), $percent);
        $score = (int) round($percent);

        // Every word of the shorter name is found in the longer name (initials allowed).
        // Needs at least 2 words, so a single common first name is not a match by itself.
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        if (count($short) >= 2 && self::containsAll($short, $long)) {
            $score = max($score, 90);
        }

        return min($score, 100);
    }

    private static function tokens(string $name): array
    {
        $name = preg_replace('/[^A-Z ]/', ' ', strtoupper($name));
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn ($w) => ! in_array($w, self::TITLES, true)));
    }

    private static function containsAll(array $short, array $long): bool
    {
        foreach ($short as $word) {
            $found = false;

            foreach ($long as $i => $candidate) {
                if ($candidate === $word || (strlen($word) === 1 && $candidate[0] === $word)) {
                    unset($long[$i]);
                    $found = true;
                    break;
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }
}
