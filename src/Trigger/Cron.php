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
 * otherwise.
 *
 * The Quartz extensions to the two day fields are supported too, and may
 * appear inside a list alongside plain values:
 *
 * | Field        | Syntax  | Meaning                                              |
 * |--------------|---------|------------------------------------------------------|
 * | either       | `?`     | no specific value, same as `*`                       |
 * | day of month | `L`     | last day of the month                                |
 * | day of month | `L-3`   | three days before the last day                       |
 * | day of month | `LW`    | last weekday of the month                            |
 * | day of month | `15W`   | weekday nearest the 15th, never crossing the month   |
 * | day of week  | `5L`    | last Friday of the month                             |
 * | day of week  | `FRI#3` | third Friday of the month                            |
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

    /**
     * Quartz day rules that no plain set can express, each a
     * [type, first argument, second argument] tuple.
     *
     * @var list<array{string, int, int}>
     */
    private array $dayRules;

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
        $this->months = $this->parseField($month, 1, 12, self::MONTHS);

        [$daysOfMonth, $dayOfMonthRules] = $this->parseDayField($dayOfMonth, 1, 31, [], false);
        [$days, $dayOfWeekRules] = $this->parseDayField($dayOfWeek, 0, 7, self::DAYS, true);

        if (isset($days[7])) {
            unset($days[7]); // both 0 and 7 mean Sunday
            $days[0] = true;
            ksort($days);
        }
        $this->daysOfMonth = $daysOfMonth;
        $this->daysOfWeek = $days;
        $this->dayRules = [...$dayOfMonthRules, ...$dayOfWeekRules];

        $this->dayOfMonthRestricted = $dayOfMonth !== '*' && $dayOfMonth !== '?';
        $this->dayOfWeekRestricted = $dayOfWeek !== '*' && $dayOfWeek !== '?';

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

        foreach ($this->dayRules as $rule) {
            if ($this->ruleMatches($rule, $candidate)) {
                return true;
            }
        }

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
     * A day field, split into the plain value set and the Quartz rules
     * that no set can express. `?` reads as `*`.
     *
     * @param array<string, int> $names
     * @return array{array<int, true>, list<array{string, int, int}>}
     *
     * @throws \InvalidArgumentException
     */
    private function parseDayField(string $field, int $min, int $max, array $names, bool $dayOfWeek): array
    {
        if ($field === '?') {
            $field = '*';
        }

        $plain = [];
        $rules = [];

        foreach (explode(',', $field) as $part) {
            $rule = $dayOfWeek ? $this->dayOfWeekRule($part) : $this->dayOfMonthRule($part);
            if ($rule === null) {
                $plain[] = $part;
            } else {
                $rules[] = $rule;
            }
        }

        $set = $plain === [] ? [] : $this->parseField(implode(',', $plain), $min, $max, $names);

        return [$set, $rules];
    }

    /**
     * `L`, `L-n`, `LW` or `nW`, or null when the part is a plain value.
     *
     * @return array{string, int, int}|null
     *
     * @throws \InvalidArgumentException
     */
    private function dayOfMonthRule(string $part): ?array
    {
        if ($part === 'l') {
            return ['L', 0, 0];
        }

        if ($part === 'lw') {
            return ['LW', 0, 0];
        }

        if (preg_match('/^l-(\d+)$/', $part, $matches) === 1) {
            $offset = (int) $matches[1];
            if ($offset > 30) {
                throw new \InvalidArgumentException("Cron value \"{$part}\" is out of range L-1-L-30");
            }

            return ['L', $offset, 0];
        }

        if (preg_match('/^(\d+)w$/', $part, $matches) === 1) {
            $day = (int) $matches[1];
            if ($day < 1 || $day > 31) {
                throw new \InvalidArgumentException("Cron value \"{$part}\" is out of range 1-31");
            }

            return ['W', $day, 0];
        }

        return null;
    }

    /**
     * `dL` or `d#n`, or null when the part is a plain value.
     *
     * @return array{string, int, int}|null
     *
     * @throws \InvalidArgumentException
     */
    private function dayOfWeekRule(string $part): ?array
    {
        if (preg_match('/^(.+)l$/', $part, $matches) === 1) {
            return ['DOW_LAST', $this->dayOfWeekValue($matches[1]), 0];
        }

        if (preg_match('/^(.+)#(\d+)$/', $part, $matches) === 1) {
            $nth = (int) $matches[2];
            if ($nth < 1 || $nth > 5) {
                throw new \InvalidArgumentException("Cron value \"{$part}\" is out of range 1-5 nth days of week");
            }

            return ['DOW_NTH', $this->dayOfWeekValue($matches[1]), $nth];
        }

        return null;
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function dayOfWeekValue(string $text): int
    {
        $day = $this->value($text, self::DAYS);
        if ($day < 0 || $day > 7) {
            throw new \InvalidArgumentException("Cron value \"{$text}\" is out of range 0-7");
        }

        return $day === 7 ? 0 : $day; // both 0 and 7 mean Sunday
    }

    /**
     * @param array{string, int, int} $rule
     */
    private function ruleMatches(array $rule, \DateTimeImmutable $candidate): bool
    {
        $day = (int) $candidate->format('j');
        $lastDay = (int) $candidate->format('t');
        $dayOfWeek = (int) $candidate->format('w');

        return match ($rule[0]) {
            'L' => $day === $lastDay - $rule[1],
            'LW' => $day === $this->nearestWeekday($candidate, $lastDay),
            'W' => $rule[1] <= $lastDay && $day === $this->nearestWeekday($candidate, $rule[1]),
            'DOW_LAST' => $dayOfWeek === $rule[1] && $day + 7 > $lastDay,
            'DOW_NTH' => $dayOfWeek === $rule[1] && intdiv($day - 1, 7) + 1 === $rule[2],
            default => false,
        };
    }

    /**
     * The weekday nearest $day inside $candidate's month: a Saturday
     * shifts back, a Sunday forward, and neither leaves the month.
     */
    private function nearestWeekday(\DateTimeImmutable $candidate, int $day): int
    {
        $target = $candidate->setDate((int) $candidate->format('Y'), (int) $candidate->format('n'), $day);

        return match ((int) $target->format('w')) {
            6 => $day > 1 ? $day - 1 : $day + 2,
            0 => $day < (int) $candidate->format('t') ? $day + 1 : $day - 2,
            default => $day,
        };
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
