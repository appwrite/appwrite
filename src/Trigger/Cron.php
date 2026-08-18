<?php

declare(strict_types=1);

namespace Utopia\Schedule\Trigger;

use Utopia\Schedule\Trigger;

/**
 * Recurring trigger described by a five-field cron expression:
 * minute, hour, day of month, month, day of week.
 *
 * Supported syntax is the portable cron core: `*`, values, ranges
 * (`a-b`), steps (every n-th value over `*` or a range, `a-b/n`),
 * lists (`,`), month and day names (`JAN`, `FRI`), `7` as Sunday, and
 * the `@hourly` … `@yearly` macros. Following vixie cron, a day matches when *either* field hits
 * if both day-of-month and day-of-week are restricted, and *both* hit
 * otherwise. Quartz extensions (`L`, `W`, `#`, `?`) are rejected.
 *
 * Occurrences have minute resolution. Invalid and impossible
 * expressions (a date that can never exist, like February 31st) are
 * rejected at construction, not discovered as a silent no-op at
 * evaluation time. Around daylight-saving transitions, nonexistent
 * local times follow PHP's date normalization.
 */
final readonly class Cron implements Trigger
{
    private const array MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const array MONTHS = [
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    ];

    private const array DAYS = [
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3, 'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    ];

    /**
     * How many years ahead the occurrence search scans before declaring
     * the expression impossible. February 29th recurs within 8 years;
     * nothing a five-field expression can say waits longer.
     */
    private const int HORIZON_YEARS = 9;

    /** @var array<int, true> */
    private array $minutes;

    /** @var array<int, true> */
    private array $hours;

    /** @var array<int, true> */
    private array $daysOfMonth;

    /** @var array<int, true> */
    private array $months;

    /** @var array<int, true> */
    private array $daysOfWeek;

    private bool $dayOfMonthRestricted;

    private bool $dayOfWeekRestricted;

    /**
     * @throws \InvalidArgumentException when the expression cannot parse or never matches a date
     */
    public function __construct(string $expression)
    {
        $normalized = strtolower(trim($expression));
        $normalized = self::MACROS[$normalized] ?? $normalized;

        $fields = preg_split('/\s+/', $normalized);
        if (!\is_array($fields) || \count($fields) !== 5) {
            throw new \InvalidArgumentException("Cron expression \"{$expression}\" must have exactly five fields");
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $this->minutes = $this->parseField($minute, 0, 59, []);
        $this->hours = $this->parseField($hour, 0, 23, []);
        $this->daysOfMonth = $this->parseField($dayOfMonth, 1, 31, []);
        $this->months = $this->parseField($month, 1, 12, self::MONTHS);

        $days = $this->parseField($dayOfWeek, 0, 7, self::DAYS);
        if (isset($days[7])) {
            unset($days[7]); // both 0 and 7 mean Sunday
            $days[0] = true;
            ksort($days);
        }
        $this->daysOfWeek = $days;

        $this->dayOfMonthRestricted = $dayOfMonth !== '*';
        $this->dayOfWeekRestricted = $dayOfWeek !== '*';

        if (!$this->nextMatch(new \DateTimeImmutable()) instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException("Cron expression \"{$expression}\" never matches a date");
        }
    }

    /**
     * @return list<\DateTimeImmutable>
     */
    #[\Override]
    public function occurrencesBetween(\DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        $occurrences = [];

        $due = $this->nextMatch($start);
        while ($due instanceof \DateTimeImmutable && $due < $end) {
            $occurrences[] = $due;
            $due = $this->nextMatch($due->modify('+1 minute'));
        }

        return $occurrences;
    }

    #[\Override]
    public function recurring(): bool
    {
        return true;
    }

    /**
     * The first matching minute at or after $from, or null when nothing
     * matches within the search horizon.
     */
    private function nextMatch(\DateTimeImmutable $from): ?\DateTimeImmutable
    {
        // Occurrences are whole minutes: round any sub-minute part up.
        if ((int) $from->format('s') > 0 || (int) $from->format('u') > 0) {
            $from = $from->modify('+1 minute');
        }
        $candidate = $from->setTime((int) $from->format('G'), (int) $from->format('i'));

        $horizon = (int) $candidate->format('Y') + self::HORIZON_YEARS;

        // Each pass either returns or advances the coarsest mismatching
        // field, so sparse expressions skip months at a time instead of
        // stepping minute by minute.
        while ((int) $candidate->format('Y') <= $horizon) {
            $year = (int) $candidate->format('Y');
            $month = (int) $candidate->format('n');

            if (!isset($this->months[$month])) {
                $candidate = $month === 12
                    ? $candidate->setDate($year + 1, 1, 1)->setTime(0, 0)
                    : $candidate->setDate($year, $month + 1, 1)->setTime(0, 0);
                continue;
            }

            if (!$this->dayMatches($candidate)) {
                $candidate = $candidate->setTime(0, 0)->modify('+1 day');
                continue;
            }

            $hour = (int) $candidate->format('G');
            if (!isset($this->hours[$hour])) {
                $nextHour = $this->firstAbove($this->hours, $hour);
                $candidate = $nextHour === null
                    ? $candidate->setTime(0, 0)->modify('+1 day')
                    : $candidate->setTime($nextHour, 0);
                continue;
            }

            $minute = (int) $candidate->format('i');
            if (!isset($this->minutes[$minute])) {
                $nextMinute = $this->firstAbove($this->minutes, $minute);
                $candidate = $nextMinute === null
                    ? $candidate->setTime($hour, 0)->modify('+1 hour')
                    : $candidate->setTime($hour, $nextMinute);
                continue;
            }

            return $candidate;
        }

        return null;
    }

    private function dayMatches(\DateTimeImmutable $candidate): bool
    {
        $dayOfMonth = isset($this->daysOfMonth[(int) $candidate->format('j')]);
        $dayOfWeek = isset($this->daysOfWeek[(int) $candidate->format('w')]);

        // Vixie cron: two restricted day fields combine with OR, so
        // "0 0 13 * 5" means the 13th *or* any Friday, not Friday the 13th.
        return $this->dayOfMonthRestricted && $this->dayOfWeekRestricted
            ? $dayOfMonth || $dayOfWeek
            : $dayOfMonth && $dayOfWeek;
    }

    /**
     * @param array<int, true> $set ksorted
     */
    private function firstAbove(array $set, int $value): ?int
    {
        foreach (array_keys($set) as $member) {
            if ($member > $value) {
                return $member;
            }
        }

        return null;
    }

    /**
     * @param array<string, int> $names
     * @return array<int, true> ksorted
     *
     * @throws \InvalidArgumentException
     */
    private function parseField(string $field, int $min, int $max, array $names): array
    {
        $set = [];

        foreach (explode(',', $field) as $part) {
            $step = 1;
            $range = $part;

            if (str_contains($part, '/')) {
                [$range, $stepText] = explode('/', $part, 2);
                if (!ctype_digit($stepText) || (int) $stepText < 1) {
                    throw new \InvalidArgumentException("Invalid cron step \"{$part}\"");
                }
                $step = (int) $stepText;
            }

            if ($range === '*') {
                $low = $min;
                $high = $max;
            } elseif (str_contains($range, '-')) {
                [$lowText, $highText] = explode('-', $range, 2);
                $low = $this->value($lowText, $names);
                $high = $this->value($highText, $names);
                if ($low > $high) {
                    throw new \InvalidArgumentException("Invalid cron range \"{$part}\"");
                }
            } else {
                if ($step > 1) {
                    // Steps apply to * or to a range, as in vixie cron.
                    throw new \InvalidArgumentException("Invalid cron step \"{$part}\"");
                }
                $low = $this->value($range, $names);
                $high = $low;
            }

            if ($low < $min || $high > $max) {
                throw new \InvalidArgumentException("Cron value \"{$part}\" is out of range {$min}-{$max}");
            }

            for ($value = $low; $value <= $high; $value += $step) {
                $set[$value] = true;
            }
        }

        if ($set === []) {
            throw new \InvalidArgumentException("Empty cron field \"{$field}\"");
        }

        ksort($set);

        return $set;
    }

    /**
     * @param array<string, int> $names
     *
     * @throws \InvalidArgumentException
     */
    private function value(string $text, array $names): int
    {
        if (ctype_digit($text)) {
            return (int) $text;
        }

        $name = strtoupper($text);
        if (isset($names[$name])) {
            return $names[$name];
        }

        throw new \InvalidArgumentException("Invalid cron value \"{$text}\"");
    }
}
