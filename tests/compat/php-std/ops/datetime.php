<?php

// datetime.*: Date and time: date()/DateTime::format, DateTime parsing and timezones, strtotime, microtime.
//
// The driver's default timezone is UTC (date.timezone), as in Appwrite.
//
// PHP's constructors fill what a string leaves unset from the clock, which the
// Rust side takes as an argument instead. Operations that construct from a
// generated string first ask date_parse() whether it leaves the date unset;
// if so both sides report 'depends on now' rather than a value (each side
// decides with its own date_parse(), so a parser difference still shows).
// Relative formats are checked through strtotime() with a base timestamp and
// modify() of a fixed date.
//
// Their exception messages quote the input byte for byte (not always UTF-8,
// which the reply line cannot carry), so these operations return exceptions
// as values: ['exception' => class, 'message' => message].

$zone = static fn (?string $name): ?DateTimeZone => $name === null ? null : new DateTimeZone($name);
$dependsOnNow = static function (string $s): bool {
    $p = date_parse($s);

    return $p['error_count'] === 0 && ($p['year'] === false || $p['month'] === false || $p['day'] === false);
};
$now = 'depends on now';
$catching = static function (callable $call): mixed {
    try {
        return $call();
    } catch (\Exception $e) {
        return ['exception' => $e::class, 'message' => $e->getMessage()];
    }
};

return [
    'datetime.parse' => fn (array $a) => date_parse($a['s']),
    'datetime.parse_from_format' => fn (array $a) => date_parse_from_format($a['format'], $a['s']),
    'datetime.strtotime' => fn (array $a) => strtotime($a['s'], $a['base']),
    'datetime.date' => fn (array $a) => date($a['format'], $a['ts']),
    'datetime.gmdate' => fn (array $a) => gmdate($a['format'], $a['ts']),
    'datetime.create' => fn (array $a) => $catching(fn () => $dependsOnNow($a['s'])
        ? $now
        : (new DateTimeImmutable($a['s'], $zone($a['tz'] ?? null)))->format($a['format'])),
    'datetime.info' => fn (array $a) => $catching(function () use ($a, $zone, $dependsOnNow, $now) {
        if ($dependsOnNow($a['s'])) {
            return $now;
        }
        $d = new DateTimeImmutable($a['s'], $zone($a['tz'] ?? null));
        $tz = $d->getTimezone();

        return [$d->getTimestamp(), $d->getOffset(), $d->getMicrosecond(), $tz === false ? false : $tz->getName()];
    }),
    'datetime.format' => fn (array $a) => $catching(fn () => (new DateTimeImmutable($a['at']))->setTimezone(new DateTimeZone($a['tz']))->format($a['format'])),
    'datetime.modify' => fn (array $a) => $catching(fn () => $dependsOnNow($a['base'])
        ? $now
        : (new DateTimeImmutable($a['base']))->modify($a['s'])->format($a['format'])),
    'datetime.modify_mutable' => fn (array $a) => $catching(fn () => $dependsOnNow($a['base'])
        ? $now
        : (new DateTime($a['base']))->modify($a['s'])->format($a['format'])),
    'datetime.add' => fn (array $a) => $catching(fn () => $dependsOnNow($a['base'])
        ? $now
        : (new DateTimeImmutable($a['base']))->add(DateInterval::createFromDateString($a['interval']))->format($a['format'])),
    'datetime.create_from_format' => function (array $a) use ($zone) {
        $d = DateTime::createFromFormat($a['format'], $a['s'], $zone($a['tz'] ?? null));

        return $d === false ? DateTime::getLastErrors() : $d->format($a['out']);
    },
    'datetime.compare' => fn (array $a) => $catching(fn () => $dependsOnNow($a['a']) || $dependsOnNow($a['b'])
        ? $now
        : new DateTimeImmutable($a['a']) <=> new DateTimeImmutable($a['b'])),
    'datetime.timezone' => fn (array $a) => $catching(function () use ($a) {
        $z = new DateTimeZone($a['tz']);

        return [$z->getName(), $z->getOffset(new DateTimeImmutable('@' . $a['ts']))];
    }),
    'datetime.timezone_name_from_abbr' => fn (array $a) => timezone_name_from_abbr($a['abbr'], $a['offset'] ?? -1, $a['isdst'] ?? -1),
    'datetime.microtime' => fn (array $a) => [\strlen(microtime()), \is_float(microtime(true)), microtime(true) > 1e9, \is_int(time())],
];
