<?php

declare(strict_types=1);

/**
 * Scheduler throughput at production scale: 10,000 mixed schedules.
 * Simulated time drives the scheduler through a TestClock while wall
 * time is measured, so a run is deterministic in behavior and honest
 * about cost. Informational only — correctness lives in the test suite.
 */

require __DIR__ . '/../../vendor/autoload.php';

use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store\Memory as MemoryStore;
use Utopia\Schedule\Trigger\Cron;
use Utopia\Schedule\Trigger\Interval;

const SCHEDULES = 10_000;
const TICKS = 30;
const INTERVAL = 60;

$expressions = ['* * * * *', '*/5 * * * *', '*/15 * * * *', '0 * * * *', '30 9 * * MON-FRI', '0 0 1 * *'];
$intervals = [30, 60, 300, 900, 3600];

$rows = [];
for ($i = 0; $i < SCHEDULES; ++$i) {
    $rows[] = new Row("s{$i}", 'v1', $i);
}

$source = new readonly class ($rows, $expressions, $intervals) implements Source {
    /**
     * @param list<Row> $rows
     * @param list<string> $expressions
     * @param list<int> $intervals
     */
    public function __construct(
        private array $rows,
        private array $expressions,
        private array $intervals,
    ) {}

    public function snapshot(): iterable
    {
        return $this->rows;
    }

    public function make(Row $row): Entry
    {
        $index = is_int($row->data) ? $row->data : 0;

        // 30% cron, 70% interval — roughly the shape of a real fleet.
        return $index % 10 < 3
            ? new Entry(new Cron($this->expressions[$index % count($this->expressions)]))
            : new Entry(new Interval($this->intervals[$index % count($this->intervals)]));
    }
};

$clock = new TestClock(new DateTimeImmutable('2026-08-18 12:00:30.250000'));
$scheduler = new Scheduler(
    source: $source,
    store: new MemoryStore(),
    tickSeconds: INTERVAL,
    leaseSeconds: 600,
    clock: $clock,
);

/** @var list<array{string, string, float, float}> $results */
$results = [];

$measure = function (string $label, string $scale, callable $work, int $times = 1) use (&$results): void {
    $durations = [];
    for ($i = 0; $i < $times; ++$i) {
        $start = hrtime(true);
        $work($i);
        $durations[] = (hrtime(true) - $start) / 1e6;
    }
    sort($durations);

    $results[] = [$label, $scale, $durations[(int) floor(count($durations) * 0.50)], $durations[count($durations) - 1]];
};

$measure('reconcile: full snapshot, cold (every row made)', SCHEDULES . ' rows', fn() => $scheduler->reconcile());
$measure('reconcile: full snapshot, warm (version diff)', SCHEDULES . ' rows', fn() => $scheduler->reconcile(full: true), 5);

$occurrences = 0;
$measure('tick + commit (one minute of coverage)', SCHEDULES . ' schedules', function () use ($scheduler, $clock, &$occurrences): void {
    $occurrences += count($scheduler->tick());
    $scheduler->commit();
    $clock->advance((float) INTERVAL);
}, TICKS);

$sparse = new Cron('0 12 29 2 *'); // leap day: the worst-case field-skipping search
$start = new DateTimeImmutable('2026-08-18 12:00:30.250000');
$found = 0;
$measure('cron next occurrence: leap-day expression', '1000 windows', function () use ($sparse, $start, &$found): void {
    for ($i = 0; $i < 1000; ++$i) {
        $found += count($sparse->occurrencesBetween($start, $start->modify('+60 seconds')));
    }
});

if ($found !== 0) {
    fwrite(STDERR, "leap-day expression matched inside a 60s window: the benchmark is measuring the wrong thing\n");
    exit(1);
}

$cores = trim((string) shell_exec('nproc 2>/dev/null || sysctl -n hw.ncpu 2>/dev/null'));

$table = "| stage | scale | p50 ms | max ms |\n|---|---|---|---|\n";
foreach ($results as [$label, $scale, $p50, $max]) {
    $table .= sprintf("| %s | %s | %.2f | %.2f |\n", $label, $scale, $p50, $max);
}

// A tick must finish well inside its interval, or coverage falls behind.
$duty = $results[2][2] / (INTERVAL * 1000) * 100;

$section = sprintf(
    "### schedule — dispatch and reconcile at scale (%s cores, %d schedules: 30%% cron / 70%% interval, %d ticks)\n\n%s\n"
        . "%d occurrences dispatched, %.0f per tick average. Selecting one minute of coverage spends %.3f%% of the %ds tick interval.\n",
    $cores === '' ? '?' : $cores,
    SCHEDULES,
    TICKS,
    $table,
    $occurrences,
    $occurrences / TICKS,
    $duty,
    INTERVAL,
);

echo "\n" . $section;

// GITHUB_STEP_SUMMARY: the run's own job summary.
// BENCH_REPORT: shared file a bench script appends its section to, so a
// caller (the Benchmark workflow) can collect every package into one place.
foreach (['GITHUB_STEP_SUMMARY', 'BENCH_REPORT'] as $variable) {
    $path = getenv($variable);
    if (is_string($path) && $path !== '') {
        file_put_contents($path, $section . "\n", FILE_APPEND);
    }
}
