<?php

declare(strict_types=1);

namespace Tests\Unit\Schedule\Source;

use Appwrite\Schedule\Source\Chores;
use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Row;

final class ChoresTest extends TestCase
{
    /** @var list<string> */
    private const array IDS = ['projects', 'console', 'certificates', 'cache'];

    public function testEveryChoreBecomesItsOwnSchedule(): void
    {
        $scheduler = new Scheduler(source: new Chores(self::IDS, 60), syncSeconds: 60);
        $scheduler->reconcile(true);

        $this->assertSame(\count(self::IDS), $scheduler->count());
    }

    /**
     * The whole point of one schedule per chore: the chores behind a failing
     * one still run. As a single closure, the first throw skipped the rest.
     */
    public function testAFailingChoreDoesNotSkipTheOthers(): void
    {
        $ran = [];

        $scheduler = new Scheduler(source: new Chores(self::IDS, 1), syncSeconds: 60, recoverSeconds: 1);
        $scheduler->run(function (array $due) use (&$ran, $scheduler): void {
            foreach ($due as $occurrence) {
                try {
                    if ($occurrence->id === 'console') {
                        throw new \RuntimeException('chore failed');
                    }

                    $ran[] = $occurrence->id;
                } catch (\Throwable) {
                    // Contained, as Maintenance::chore() does.
                }
            }

            $scheduler->stop();
        });

        $this->assertContains('certificates', $ran);
        $this->assertContains('cache', $ran);
        $this->assertNotContains('console', $ran);
    }

    /**
     * A sleep-based loop phases only its first run and then drifts by however
     * long each run takes. An anchored grid does not.
     */
    public function testTheGridStaysPinnedToTheAnchor(): void
    {
        $anchor = new \DateTimeImmutable('2026-01-01 00:00:00');
        $entry = (new Chores(['projects'], 86400, $anchor))->make(new Row(id: 'projects', version: '1'));

        $dues = $entry->trigger->occurrencesBetween(
            new \DateTimeImmutable('2026-06-01 12:00:00'),
            new \DateTimeImmutable('2026-06-04 12:00:00'),
        );

        $this->assertNotEmpty($dues);

        foreach ($dues as $due) {
            $this->assertSame('00:00:00', $due->format('H:i:s'));
        }
    }

    /**
     * A maintenance run enqueues a delete for every project in the region, so
     * a missed day must not be replayed on every restart.
     */
    public function testAMissedRunIsNotReplayedOnStartup(): void
    {
        $anchor = new \DateTimeImmutable('2026-01-01 00:00:00');

        $scheduler = new Scheduler(source: new Chores(self::IDS, 86400, $anchor), syncSeconds: 86400);
        $scheduler->reconcile(true);

        $this->assertSame([], $scheduler->tick());
    }
}
