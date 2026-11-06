<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Certificates\Certificates;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Maintenance\Grid;
use Appwrite\Maintenance\Sweep;
use Utopia\Console\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\WhiteList;

class Maintenance extends Action
{
    /**
     * How close to expiry a certificate is checked whatever its renew date or
     * attempts say. LetsEncrypt renews from 30 days before expiry and Fastly from
     * about ten, so a certificate this close has stopped renewing.
     */
    private const CERTIFICATE_RENEWAL_DEADLINE = 60 * 60 * 24 * 7;

    /**
     * How long after expiry a certificate is still checked, so a late provider
     * renewal or a DNS fix still recovers it.
     */
    private const CERTIFICATE_EXPIRED_WINDOW = 60 * 60 * 24 * 30;

    /**
     * How long a renewal the operator opted out of waits before it is checked again.
     */
    private const CERTIFICATE_OPT_OUT_INTERVAL = 60 * 60 * 24;

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
        $alertsRetention = (int) System::getEnv('_APP_MAINTENANCE_RETENTION_ALERTS', '7776000'); // 90 days
        $jobInitTime = System::getEnv('_APP_MAINTENANCE_START_TIME', '00:00'); // (hour:minutes)

        $now = new \DateTime();
        $now->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $next = new Grid($jobInitTime, $interval)->next($now);
        $delay = $next->getTimestamp() - $now->getTimestamp();

        $action = function () use ($interval, $cacheRetention, $schedulesDeletionRetention, $alertsRetention, $dbForPlatform, $console, $publisherForDeletes, $publisherForCertificates, $certificateIssuer) {
            $time = DatabaseDateTime::now();

            Console::info("[{$time}] Notifying workers with maintenance tasks every {$interval} seconds");

            // Projects are maintained by schedule-maintenance, a slot at a time, so the deletes queue never takes them all at once.
            $publisherForDeletes->enqueue(new DeleteMessage(
                project: $console,
                type: DELETE_TYPE_MAINTENANCE,
            ));

            $this->notifyDeleteConnections($publisherForDeletes);
            $this->renewCertificates($dbForPlatform, $publisherForCertificates, $certificateIssuer);
            $this->notifyDeleteCache($cacheRetention, $console, $publisherForDeletes);
            $this->notifyDeleteSchedules($schedulesDeletionRetention, $publisherForDeletes);
            $this->notifyDeleteAlerts($alertsRetention, $publisherForDeletes);
            $this->notifyDeleteCSVExports($publisherForDeletes);
        };

        if ($type === 'loop') {
            Console::info('Setting loop start time to ' . $next->format("Y-m-d H:i:s.v") . '. Delaying for ' . $delay . ' seconds.');

            Console::loop(function () use ($action) {
                $action();
            }, $interval, $delay);
        } elseif ($type === 'trigger') {
            // A manual run maintains every active project now, rather than waiting for its slot.
            $this->notifyDeleteProjects($dbForPlatform, $publisherForDeletes);
            $action();
        }
    }

    private function notifyDeleteProjects(Database $dbForPlatform, DeletePublisher $publisherForDeletes): void
    {
        $since = DatabaseDateTime::addSeconds(new \DateTime(), -Sweep::INACTIVITY);

        $dbForPlatform->skipFilters(
            function () use ($dbForPlatform, $publisherForDeletes, $since): void {
                $projects = $dbForPlatform->cursor(
                    'projects',
                    [
                        Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
                        Query::greaterThanEqual('accessedAt', $since),
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

    private function renewCertificates(Database $dbForPlatform, Certificate $publisherForCertificate, Certificates $certificateIssuer): void
    {
        $time = DatabaseDateTime::now();

        $documents = $dbForPlatform->find('certificates', [
            Query::lessThan('attempts', 5), // Maximum 5 attempts
            Query::isNotNull('renewDate'),
            Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
            Query::orderAsc('renewDate'),
            Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
        ]);

        // Out of attempts or without a renew date, a renewal is never scheduled
        // again and its certificate would expire silently. One about to expire, or
        // expired within the window, is checked regardless, so the worker renews
        // it or reports why it cannot.
        $due = \array_map(fn (Document $certificate) => $certificate->getId(), $documents);
        try {
            $expiring = $dbForPlatform->find('certificates', [
                Query::greaterThan('expireDate', DatabaseDateTime::addSeconds(new \DateTime(), -self::CERTIFICATE_EXPIRED_WINDOW)),
                Query::lessThanEqual('expireDate', DatabaseDateTime::addSeconds(new \DateTime(), self::CERTIFICATE_RENEWAL_DEADLINE)),
                Query::lessThan('$updatedAt', DatabaseDateTime::addSeconds(new \DateTime(), -60 * 60 * 12)), // At most twice a day, however often maintenance runs
                Query::orderAsc('expireDate'),
                Query::limit(100),
            ]);
        } catch (QueryException $e) {
            // A schema without expireDate (the migration has not run yet) must not stop the renewals above
            Console::warning("[{$time}] Skipping certificates about to expire: " . $e->getMessage());
            $expiring = [];
        }

        foreach ($expiring as $certificate) {
            if (!\in_array($certificate->getId(), $due, true)) {
                $documents[] = $certificate;
            }
        }

        if (\count($documents) === 0) {
            Console::info("[{$time}] No certificates for renewal.");
            return;
        }

        Console::info("[{$time}] Found " . \count($documents) . " certificates for renewal, scheduling jobs.");

        $appRegion = System::getEnv('_APP_REGION', 'default');

        foreach ($documents as $certificate) {
            $rule = $this->findRule($dbForPlatform, $certificate->getAttribute('domain'));

            // Its own region renews it
            if (!$rule->isEmpty() && $rule->getAttribute('region') !== $appRegion) {
                continue;
            }

            // Without a rule, nothing renews or reuses it (a new rule starts its own), so it is
            // deleted, as deleting its rule would have done. Kept, it would fill the limits on every run.
            if ($rule->isEmpty()) {
                $dbForPlatform->deleteDocument('certificates', $certificate->getId());
                continue;
            }

            // Respect the operator opt-out: if Appwrite would not auto-issue this
            // subdomain today, it must not auto-renew it either. Keep the owner gate
            // so custom-domain renewals are never skipped. Checked again tomorrow
            // rather than every run, so renewal resumes once auto-issue is back on.
            if ($rule->getAttribute('owner') === 'Appwrite' && !$certificateIssuer->isAutoIssueEnabled($rule)) {
                $dbForPlatform->updateDocument('certificates', $certificate->getId(), new Document([
                    'renewDate' => DatabaseDateTime::addSeconds(new \DateTime(), self::CERTIFICATE_OPT_OUT_INTERVAL),
                ]));
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
        }
    }

    /**
     * The rule a certificate renews. A hostname can also hold a mail rule,
     * which has no certificate, so it is skipped. Its protocol is read from the
     * row, not queried, because regions without that attribute reject the query.
     */
    protected function findRule(Database $dbForPlatform, string $domain): Document
    {
        if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
            return $dbForPlatform->getDocument('rules', md5($domain));
        }

        $rules = $dbForPlatform->find('rules', [
            Query::equal('domain', [$domain]),
            Query::limit(10), // One row per protocol; the cap only bounds a malformed table
        ]);

        foreach ($rules as $rule) {
            if ($rule->getAttribute('protocol') !== 'smtp') {
                return $rule;
            }
        }

        return new Document();
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

    private function notifyDeleteAlerts(int $retention, DeletePublisher $publisherForDeletes): void
    {
        $publisherForDeletes->enqueue(new DeleteMessage(
            type: DELETE_TYPE_ALERTS,
            datetime: DatabaseDateTime::addSeconds(new \DateTime(), -1 * $retention),
        ));
    }
}
