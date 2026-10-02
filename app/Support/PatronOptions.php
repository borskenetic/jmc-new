<?php

namespace App\Support;

use App\Enums\EducationalLevel;

class PatronOptions
{
    public static function educationalLevelRule(): string
    {
        return 'required|in:'.implode(',', EducationalLevel::values());
    }

    /** @return list<string> */
    public static function yearOptionsFor(?string $level): array
    {
        if ($level === null || $level === '') {
            return [];
        }

        return config("patron.year_options.{$level}", []);
    }

    /** @return list<string> */
    public static function allYearOptions(): array
    {
        $merged = [];
        foreach (config('patron.year_options', []) as $options) {
            $merged = array_merge($merged, $options);
        }

        return array_values(array_unique($merged));
    }

    /**
     * Map free-form labels (e.g. "KINDER 1", "grade 7") to the canonical year option.
     */
    public static function normalizeYearLabel(?string $year): ?string
    {
        if ($year === null) {
            return null;
        }

        $year = trim($year);
        if ($year === '' || strcasecmp($year, 'N/A') === 0) {
            return null;
        }

        foreach (self::allYearOptions() as $canonical) {
            if (strcasecmp($canonical, $year) === 0) {
                return $canonical;
            }
        }

        $compact = preg_replace('/\s+/', ' ', $year) ?? $year;
        foreach (self::allYearOptions() as $canonical) {
            if (strcasecmp($canonical, $compact) === 0) {
                return $canonical;
            }
        }

        // College shorthand: "1st", "Year 1", "First Year" → "1st Year"
        $collegeOrdinals = [
            '1st Year' => '/^(?:1st|first)(?:\s*year)?$|^year\s*1$/i',
            '2nd Year' => '/^(?:2nd|second)(?:\s*year)?$|^year\s*2$/i',
            '3rd Year' => '/^(?:3rd|third)(?:\s*year)?$|^year\s*3$/i',
            '4th Year' => '/^(?:4th|fourth)(?:\s*year)?$|^year\s*4$/i',
            '5th Year' => '/^(?:5th|fifth)(?:\s*year)?$|^year\s*5$/i',
            '6th Year' => '/^(?:6th|sixth)(?:\s*year)?$|^year\s*6$/i',
        ];
        foreach ($collegeOrdinals as $canonical => $pattern) {
            if (preg_match($pattern, $compact)) {
                return $canonical;
            }
        }

        return $year;
    }

    public static function educationalLevelForYear(?string $year): ?string
    {
        $normalized = self::normalizeYearLabel($year);
        if ($normalized === null) {
            return null;
        }

        foreach (config('patron.year_options', []) as $level => $years) {
            if (in_array($normalized, $years, true)) {
                return $level;
            }
        }

        return null;
    }
}
