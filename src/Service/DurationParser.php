<?php

declare(strict_types=1);

namespace App\Service;

class DurationParser
{
    /** @var array<string, float> */
    private const DURATION_UNIT_MULTIPLIERS = [
        'd' => 86400.0, 'day' => 86400.0, 'days' => 86400.0,
        'h' => 3600.0, 'hour' => 3600.0, 'hours' => 3600.0,
        'm' => 60.0, 'min' => 60.0, 'minute' => 60.0, 'minutes' => 60.0,
    ];

    /**
     * Parses human-friendly duration (e.g. 1d, 0.5d, 1 day, 2h, 30m) to seconds.
     * Supports: d/day/days, h/hour/hours, m/min/minute/minutes. Accepts decimals (e.g. 0.5d).
     *
     * @return int|null Seconds, or null if input is invalid
     */
    public function parseToSeconds(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^(\d+(?:\.\d+)?)\s*(d|day|days|h|hour|hours|m|min|minute|minutes)\s*$/i', $value, $m)) {
            return null;
        }
        $num = (float) $m[1];
        $unit = strtolower($m[2]);
        $multiplier = $this->unitToSecondsMultiplier($unit);
        if ($multiplier === null) {
            return null;
        }
        $seconds = (int) round($num * $multiplier);

        return $seconds >= 0 ? $seconds : null;
    }

    protected function unitToSecondsMultiplier(string $unit): ?float
    {
        return self::DURATION_UNIT_MULTIPLIERS[$unit] ?? null;
    }
}
