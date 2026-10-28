<?php

namespace Appwrite\Schedule\Source;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Schedule\Source;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Trigger\Interval;
use Utopia\Schedule\Trigger\Shifted;

/**
 * One evaluation schedule per enabled alert rule in the region. A rule keeps
 * its slot across restarts: it is shifted by `crc32(id) mod interval` seconds.
 *
 * The interval is the row version: the worker reads the rule itself, so an
 * edit or an evaluation leaves the schedule alone, while a rule whose interval
 * changes moves onto it at the next snapshot.
 */
final class Alerts implements Source
{
    private const int PAGE = 1000;

    /**
     * @param \Closure(list<Document>): array<string, int> $intervals seconds between evaluations, by rule ID, for one page of rules; a rule left out is not scheduled
     */
    public function __construct(
        private readonly Database $dbForPlatform,
        private readonly string $region,
        private readonly \Closure $intervals,
    ) {
    }

    /**
     * @return iterable<Row>
     */
    #[\Override]
    public function snapshot(): iterable
    {
        $cursor = null;

        do {
            $queries = [
                Query::equal('region', [$this->region]),
                Query::equal('enabled', [true]),
                Query::orderAsc('$sequence'),
                Query::limit(self::PAGE),
            ];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }

            $rules = $this->dbForPlatform->find('alertRules', $queries);
            $intervals = ($this->intervals)($rules);

            foreach ($rules as $rule) {
                if (!isset($intervals[$rule->getId()])) {
                    continue;
                }

                $interval = \max(1, $intervals[$rule->getId()]);

                yield new Row(
                    id: $rule->getId(),
                    version: (string) $interval,
                    data: [
                        'interval' => $interval,
                        'projectId' => (string) $rule->getAttribute('projectId'),
                        'projectInternalId' => (string) $rule->getAttribute('projectInternalId'),
                    ],
                );
            }

            $cursor = \end($rules) ?: null;
        } while (\count($rules) === self::PAGE);
    }

    #[\Override]
    public function make(Row $row): Entry
    {
        $interval = (int) $row->data['interval'];

        return new Entry(
            new Shifted(new Interval($interval), \abs(\crc32($row->id)) % $interval),
            [
                'ruleId' => $row->id,
                'project' => new Document([
                    '$id' => $row->data['projectId'],
                    '$sequence' => $row->data['projectInternalId'],
                ]),
            ],
        );
    }
}
