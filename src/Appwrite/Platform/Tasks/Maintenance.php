<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Certificates\Certificates;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Grid;
use DateInterval;
use DateTime;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Maintenance extends Action
{
    private const int CERTIFICATES_PAGE = 100;

    private const int CERTIFICATES_LIMIT = 200; // Let's Encrypt allows 300 orders per 3 hours, so this keeps some for new domains

    public static function getName(): string
    {
        return 'maintenance';
    }

    public function __construct()
    {
        $this
            ->desc('Schedules maintenance tasks and publishes them to our queues')
            ->param('type', 'loop', new WhiteList(['loop', 'trigger']), 'How to run task. "loop" is meant for container entrypoint, and "trigger" for manual execution.')
            ->inject('dbForPlatform')
            ->inject('console')
            ->inject('publisherForCertificates')
            ->inject('certificateIssuer')
            ->inject('publisherForDeletes')
            ->callback($this->action(...));
    }

    public function action(string $type, Database $dbForPlatform, Document $console, Certificate $publisherForCertificates, Certificates $certificateIssuer, DeletePublisher $publisherForDeletes): void
    {
        Console::title('Maintenance V1');
        Console::success(APP_NAME . ' maintenance process v1 has started');

        $interval = (int) System::getEnv('_APP_MAINTENANCE_INTERVAL', '86400'); // 1 day
        $cacheRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_CACHE', '2592000'); // 30 days
        $schedulesDeletionRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_SCHEDULES', '86400'); // 1 Day
        $jobInitTime = System::getEnv('_APP_MAINTENANCE_START_TIME', '00:00'); // (hour:minutes)

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $next = new Grid($jobInitTime, $interval)->next($now);
        $delay = $next->getTimestamp() - $now->getTimestamp();

        $action = function () use ($interval, $cacheRetention, $schedulesDeletionRetention, $dbForPlatform, $console, $publisherForDeletes, $publisherForCertificates, $certificateIssuer) {
            $time = DatabaseDateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");

            // Iterate through project only if it was accessed in last 30 days
            $dateInterval  = DateInterval::createFromDateString('30 days');
            $before30days = (new DateTime())->sub($dateInterval);

            $dbForPlatform->skipFilters(
                function () use ($dbForPlatform, $publisherForDeletes, $before30days): void {
                    $projects = $dbForPlatform->cursor(
                        'projects',
                        [
                            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                            Query::greaterThanEqual('accessedAt', DatabaseDateTime::format($before30days)),
                            Query::orderAsc('$sequence'), // accessedAt Can be updated during iteration
                        ],
                        batchSize: 1000,
                    );

                    foreach ($projects as $project) {
                        $publisherForDeletes->enqueue(new DeleteMessage(
                            project: $project,
                            type: DELETE_TYPE_MAINTENANCE,
                        ));
                    }
                },
                APP_PROJECTS_SUBQUERIES
            );

            $publisherForDeletes->enqueue(new DeleteMessage(
                project: $console,
                type: DELETE_TYPE_MAINTENANCE,
            ));

            $this->notifyDeleteConnections($publisherForDeletes);
            $this->renewCertificates($dbForPlatform, $publisherForCertificates, $certificateIssuer);
            $this->notifyDeleteCache($cacheRetention, $console, $publisherForDeletes);
            $this->notifyDeleteSchedules($schedulesDeletionRetention, $publisherForDeletes);
            $this->notifyDeleteCSVExports($publisherForDeletes);
        };

        if ($type === 'loop') {
            Console::info('Setting loop start time to ' . $next->format("Y-m-d H:i:s.v") . '. Delaying for ' . $delay . ' seconds.');

            Console::loop(function () use ($action) {
                $action();
            }, $interval, $delay);
        } elseif ($type === 'trigger') {
            $action();
        }
    }

    private function notifyDeleteConnections(DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_REALTIME,
            datetime: DatabaseDateTime::addSeconds(new \DateTime(), -60),
        ));
    }

    private function notifyDeleteCSVExports(DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(type: DELETE_TYPE_CSV_EXPORTS));
    }

    /**
     * Enqueues the certificates due for renewal, oldest due first, until the per-run limit. A row the worker cannot
     * act on stays due and keeps its place at the front, so it is skipped without counting toward the limit.
     */
    private function renewCertificates(Database $dbForPlatform, Certificate $publisherForCertificate, Certificates $certificateIssuer): void
    {
        $time = DatabaseDateTime::now();
        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
        $region = System::getEnv('_APP_REGION', 'default');

        $queries = [
            Query::isNotNull('renewDate'),
            Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
            // Maximum 5 attempts. A row the worker created while waiting on its provider has no attempts yet.
            Query::or([Query::isNull('attempts'), Query::lessThan('attempts', 5)]),
        ];

        $due = $dbForPlatform->count('certificates', $queries);
        $enqueued = 0;
        $skipped = ['failed' => 0, 'orphaned' => 0, 'superseded' => 0, 'region' => 0, 'disabled' => 0];
        $cursor = null;

        while ($enqueued < self::CERTIFICATES_LIMIT) {
            $certificates = $dbForPlatform->find('certificates', [
                ...$queries,
                Query::orderAsc('renewDate'),
                Query::select(['$id', '$sequence', 'domain', 'renewDate']),
                Query::limit(self::CERTIFICATES_PAGE),
                ...($cursor === null ? [] : [Query::cursorAfter($cursor)]),
            ]);

            if ($certificates === []) {
                break;
            }

            $domains = \array_map(fn (Document $certificate): string => $certificate->getAttribute('domain', ''), $certificates);

            // One read for the page's rules, so walking past skipped rows costs two reads per page, not one per row
            $rules = [];
            foreach ($dbForPlatform->find('rules', [
                $isMd5 ? Query::equal('$id', \array_map(md5(...), $domains)) : Query::equal('domain', $domains),
                Query::limit(\count($domains)),
            ]) as $rule) {
                $rules[$isMd5 ? $rule->getId() : $rule->getAttribute('domain')] ??= $rule;
            }

            foreach ($certificates as $certificate) {
                if ($enqueued >= self::CERTIFICATES_LIMIT) {
                    break;
                }

                $domain = $certificate->getAttribute('domain', '');
                $rule = $rules[$isMd5 ? \md5($domain) : $domain] ?? new Document();

                $reason = $certificateIssuer->getRenewalSkipReason($certificate, $rule, $region);
                if ($reason !== null) {
                    $skipped[$reason]++;
                    continue;
                }

                $publisherForCertificate->enqueue(new \Appwrite\Event\Message\Certificate(
                    project: new Document([
                        '$id' => $rule->getAttribute('projectId', ''),
                        '$sequence' => $rule->getAttribute('projectInternalId', 0),
                    ]),
                    domain: new Document([
                        'domain' => $rule->getAttribute('domain'),
                        'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                    ]),
                    action: \Appwrite\Event\Certificate::ACTION_GENERATION,
                ));
                $enqueued++;
            }

            if (\count($certificates) < self::CERTIFICATES_PAGE) {
                break;
            }

            $cursor = \end($certificates);
        }

        // Rows renewed or deleted during the walk leave the count, so only a walk the limit stopped leaves rows unchecked
        $unchecked = $enqueued >= self::CERTIFICATES_LIMIT ? \max(0, $due - $enqueued - \array_sum($skipped)) : 0;

        Console::info("[{$time}] Certificates for renewal: {$due} due, {$enqueued} enqueued, {$unchecked} not checked; skipped {$skipped['failed']} that failed verification (waiting for a manual retry), {$skipped['orphaned']} without a rule, {$skipped['superseded']} superseded, {$skipped['region']} in another region, {$skipped['disabled']} with auto-issue off.");

        if ($unchecked > 0) {
            Console::warning("[{$time}] Certificate renewal limit of " . self::CERTIFICATES_LIMIT . " reached; {$unchecked} due certificates were not checked this run.");
        }
    }

    private function notifyDeleteCache(int $interval, Document $console, DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $console,
            type: DELETE_TYPE_CACHE_BY_TIMESTAMP,
            datetime: DatabaseDateTime::addSeconds(new \DateTime(), -1 * $interval),
        ));
    }

    private function notifyDeleteSchedules(int $interval, DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_SCHEDULES,
            datetime: DatabaseDateTime::addSeconds(new \DateTime(), -1 * $interval),
        ));
    }
}
