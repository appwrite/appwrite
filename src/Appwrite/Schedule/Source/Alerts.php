<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\Document;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger;
use Utopia\Schedule\Trigger\Interval;
use Utopia\Schedule\Trigger\Shifted;

/**
 * One evaluation schedule per alert rule, read from the rule's row in
 * `schedules`. A rule keeps its slot across restarts: it is shifted by
 * `crc32(ruleId) mod interval` seconds.
 *
 * The interval is not stored on the row: an edition may derive it from
 * something the rule does not own, such as its organization's plan. It is
 * worked out per page of rows and added to each row's version, so a rule whose
 * interval changes moves onto it at the next full snapshot.
 */
final class Alerts extends Database
{
    private const int PAGE = 1000;

    /**
     * @param \Closure(list<Document>): array<string, int> $intervals seconds between evaluations, by schedule ID, for one page of schedules; a schedule left out is not run
     */
    public function __construct(
        \Utopia\Database\Database $dbForPlatform,
        private readonly \Closure $intervals,
    ) {
        // The worker loads the rule and its project, so make() reads neither.
        parent::__construct($dbForPlatform, null, null);
    }

    #[\Override]
    public function snapshot(): iterable
    {
        yield from $this->paged(parent::snapshot());
    }

    #[\Override]
    public function since(\DateTimeImmutable $moment): iterable
    {
        yield from $this->paged(parent::since($moment));
    }

    /**
     * Nothing is looked up: the worker loads the rule and its project. An
     * organization rule belongs to no project and is sent with an empty one.
     */
    #[\Override]
    public function make(Row $row): Entry
    {
        $schedule = $row->data;
        if (!$schedule instanceof Document) {
            throw new \InvalidArgumentException('Schedule row carries no document');
        }

        $projectId = (string) $schedule->getAttribute('projectId');

        return new Entry(
            $this->trigger([
                'resourceId' => (string) $schedule->getAttribute('resourceId'),
                'interval' => (int) $schedule->getAttribute('interval'),
            ]),
            [
                'ruleId' => (string) $schedule->getAttribute('resourceId'),
                'project' => $projectId === '' ? new Document() : new Document([
                    '$id' => $projectId,
                    '$sequence' => (string) $schedule->getAttribute('projectInternalId'),
                ]),
            ],
        );
    }

    #[\Override]
    protected function type(): string
    {
        return SCHEDULE_RESOURCE_TYPE_ALERT;
    }

    #[\Override]
    protected function collection(): string
    {
        return 'alertRules';
    }

    #[\Override]
    protected function trigger(array $schedule): Trigger
    {
        $interval = \max(1, (int) $schedule['interval']);

        return new Shifted(new Interval($interval), \abs(\crc32((string) $schedule['resourceId'])) % $interval);
    }

    /**
     * @param iterable<Row> $rows
     * @return iterable<Row>
     */
    private function paged(iterable $rows): iterable
    {
        $page = [];
        foreach ($rows as $row) {
            $page[] = $row;
            if (\count($page) === self::PAGE) {
                yield from $this->withIntervals($page);
                $page = [];
            }
        }

        yield from $this->withIntervals($page);
    }

    /**
     * @param list<Row> $page
     * @return iterable<Row>
     */
    private function withIntervals(array $page): iterable
    {
        if ($page === []) {
            return;
        }

        $intervals = ($this->intervals)(\array_map(fn (Row $row): Document => $row->data, $page));

        foreach ($page as $row) {
            $interval = $intervals[$row->data->getId()] ?? null;
            $row->data->setAttribute('interval', $interval ?? 0);

            yield new Row(
                id: $row->id,
                version: $row->version . ':' . ($interval ?? ''),
                data: $row->data,
                active: $row->active && $interval !== null,
                activeFrom: $row->activeFrom,
            );
        }
    }
}
