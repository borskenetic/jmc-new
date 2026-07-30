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
