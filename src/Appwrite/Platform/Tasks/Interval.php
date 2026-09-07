<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Publisher\Certificate;
use DateTime;
use Swoole\Coroutine\Channel;
use Swoole\Process;
use Swoole\Timer;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
use Utopia\Span\Span;
use Utopia\System\System;

class Interval extends Action
{
    public static function getName(): string
    {
        return 'interval';
    }

    public function __construct()
    {
        $this
          ->desc('Schedules tasks on regular intervals by publishing them to our queues')
          ->inject('dbForPlatform')
          ->inject('getProjectDB')
          ->inject('publisherForCertificates')
          ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, callable $getProjectDB, Certificate $publisherForCertificates): void
    {
        Console::title('Interval V1');
        Console::success(APP_NAME . ' interval process v1 has started');

        $timers = $this->runTasks($dbForPlatform, $getProjectDB, $publisherForCertificates);

        $chan = new Channel(1);
        Process::signal(SIGTERM, function () use ($chan) {
            $chan->push(true);
        });
        $chan->pop(); // Block the main process from exiting

        // Graceful shutdown when SIGTERM is received
        foreach ($timers as $timer) {
            Timer::clear($timer);
        }
    }

    public function runTasks(Database $dbForPlatform, callable $getProjectDB, Certificate $publisherForCertificates): array
    {
        $timers = [];
        $tasks = $this->getTasks();
        foreach ($tasks as $task) {
            $timers[] = Timer::tick($task['interval'], function () use ($task, $dbForPlatform, $getProjectDB, $publisherForCertificates) {
                $taskName = $task['name'];
                Span::init("interval.{$taskName}");
                $error = null;
                try {
                    $task['callback']($dbForPlatform, $getProjectDB, $publisherForCertificates);
                } catch (\Exception $e) {
                    $error = $e;
                } finally {
                    Span::current()?->finish(error: $error);
                }
            });
        }
        return $timers;
    }

    protected function getTasks(): array
    {
        $intervalDomainVerification = (int) System::getEnv('_APP_INTERVAL_DOMAIN_VERIFICATION', '120'); // 2 minutes
        $intervalCertificateGeneration = (int) System::getEnv('_APP_INTERVAL_CERTIFICATE_GENERATION', '300'); // 5 minutes

        return [
            [
                'name' => 'domainVerification',
                "callback" => function (Database $dbForPlatform, callable $getProjectDB, Certificate $publisherForCertificates) {
                    $this->verifyDomain($dbForPlatform, $publisherForCertificates);
                },
                'interval' => $intervalDomainVerification * 1000,
            ],
            [
                'name' => 'certificateGeneration',
                "callback" => function (Database $dbForPlatform, callable $getProjectDB, Certificate $publisherForCertificates) {
                    $this->generateCertificate($dbForPlatform, $publisherForCertificates);
                },
                'interval' => $intervalCertificateGeneration * 1000,
            ]
        ];
    }

    private function verifyDomain(Database $dbForPlatform, Certificate $publisherForCertificates): void
    {
        $time = DatabaseDateTime::now();
        $fromTime = new DateTime('-3 days'); // Max 3 days old

        $rules = $dbForPlatform->find('rules', [
            Query::createdAfter(DatabaseDateTime::format($fromTime)),
            Query::equal('status', [RULE_STATUS_CREATED]), // Created but not verified yet
            Query::orderAsc('$updatedAt'), // Pick the ones waiting for another attempt for longest
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]), // Only current region
            Query::limit(100), // Reasonable pagination limit
        ]);

        $scanned = \count($rules);
        Span::add("interval.domain_verification.scanned", $scanned);

        if ($scanned === 0) {
            Span::add("interval.domain_verification.processed", 0);
            Span::add("interval.domain_verification.failed", 0);
            return; // No rules to verify
        }

        $processed = 0;
        $failed = 0;

        foreach ($rules as $rule) {
            try {
                $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
                    project: new Document([
                        '$id' => $rule->getAttribute('projectId', ''),
                        '$sequence' => $rule->getAttribute('projectInternalId', 0),
                    ]),
                    domain: new Document([
                        'domain' => $rule->getAttribute('domain'),
                        'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                    ]),
                    action: \Appwrite\Event\Certificate::ACTION_DOMAIN_VERIFICATION,
                ));
                $processed++;
            } catch (\Throwable $th) {
                $failed++;
            }
        }

        Span::add("interval.domain_verification.processed", $processed);
        Span::add("interval.domain_verification.failed", $failed);
    }

    /**
     * Retry certificate generation for domains whose last attempt failed, the
     * issuance counterpart to the DNS verification retry above.
     */
    private function generateCertificate(Database $dbForPlatform, Certificate $publisherForCertificates): void
    {
        $leasedUntil = DatabaseDateTime::format(new DateTime('-' . APP_CERTIFICATE_GENERATION_LEASE . ' seconds'));
        $region = System::getEnv('_APP_REGION', 'default');
        $cursor = null;
        $scanned = $processed = $skipped = $failed = 0;

        do {
            $queries = [
                Query::equal('status', [RULE_STATUS_CERTIFICATE_GENERATION_FAILED, RULE_STATUS_CERTIFICATE_GENERATING]),
                Query::updatedBefore($leasedUntil),
                Query::equal('region', [$region]),
                Query::orderAsc('$sequence'),
                Query::limit(100),
            ];
            if ($cursor !== null) {
                $queries[] = Query::cursorAfter($cursor);
            }
            $rules = $dbForPlatform->find('rules', $queries);
            $scanned += \count($rules);

            foreach ($rules as $rule) {
                $cursor = $rule;
                try {
                    // Re-read under the row lock: another scheduler, a manual
                    // retry or a status poll may have changed this rule.
                    $claimed = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $rule, $leasedUntil, $region): ?Document {
                        $current = $dbForPlatform->getDocument('rules', $rule->getId(), forUpdate: true);
                        if ($current->isEmpty()
                            || $current->getAttribute('region') !== $region
                            || !\in_array($current->getAttribute('status'), [RULE_STATUS_CERTIFICATE_GENERATION_FAILED, RULE_STATUS_CERTIFICATE_GENERATING], true)
                            || new DateTime($current->getUpdatedAt()) >= new DateTime($leasedUntil)) {
                            return null;
                        }

                        $certificate = $dbForPlatform->getDocument('certificates', $current->getAttribute('certificateId', ''));
                        $lease = $certificate->getAttribute('updated');
                        if ($certificate->getAttribute('attempts', 0) >= APP_LIMIT_CERTIFICATE_ATTEMPTS
                            || (!empty($lease) && new DateTime($lease) >= new DateTime($leasedUntil))) {
                            return null;
                        }
                        // Delayed issuance is reconciled by the status poller.
                        // Only an expired worker lease needs a generating retry.
                        if ($current->getAttribute('status') === RULE_STATUS_CERTIFICATE_GENERATING && empty($lease)) {
                            return null;
                        }

                        return $dbForPlatform->updateDocument('rules', $current->getId(), new Document([
                            '$updatedAt' => DatabaseDateTime::now(),
                        ]));
                    });
                    if ($claimed === null || $claimed->isEmpty()) {
                        $skipped++;
                        continue;
                    }

                    $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
                        project: new Document([
                            '$id' => $claimed->getAttribute('projectId', ''),
                            '$sequence' => $claimed->getAttribute('projectInternalId', 0),
                        ]),
                        domain: new Document([
                            'domain' => $claimed->getAttribute('domain'),
                            'domainType' => $claimed->getAttribute('deploymentResourceType', $claimed->getAttribute('type')),
                        ]),
                        action: \Appwrite\Event\Certificate::ACTION_GENERATION,
                    ));
                    $processed++;
                } catch (\Throwable $th) {
                    $failed++;
                }
            }
        } while (\count($rules) === 100);

        Span::add("interval.certificate_generation.scanned", $scanned);
        Span::add("interval.certificate_generation.processed", $processed);
        Span::add("interval.certificate_generation.skipped", $skipped);
        Span::add("interval.certificate_generation.failed", $failed);
    }
}
