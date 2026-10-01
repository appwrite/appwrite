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
                'callback' => function (Database $dbForPlatform, callable $getProjectDB, Certificate $publisherForCertificates) {
                    $this->generateCertificate($dbForPlatform, $publisherForCertificates);
                },
                'interval' => $intervalDomainVerification * 1000,
            ],
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
     * Ask again about certificates that are still generating.
     *
     * Issuance is asynchronous. The first job often runs while the certificate
     * is still pending, and nothing else would come back to attach it to the
     * TLS configuration once it is issued. DNS already passed when the rule
     * entered this status, so the follow-up does not run that check again.
     *
     * $updatedAt is only written when a job finishes, so the age threshold has
     * to be comfortably longer than a job takes or a tick would enqueue the
     * same hostname while the previous attempt is still running. Five minutes
     * is well past that for every provider, and still well inside what a
     * customer waiting on issuance would notice.
     */
    private function generateCertificate(Database $dbForPlatform, Certificate $publisherForCertificates): void
    {
        $before = DatabaseDateTime::format(new DateTime('-5 minutes'));

        $rules = $dbForPlatform->find('rules', [
            Query::equal('status', [RULE_STATUS_CERTIFICATE_GENERATING]),
            Query::lessThan('$updatedAt', $before),
            Query::orderAsc('$updatedAt'),
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]),
            Query::limit(100),
        ]);

        $scanned = \count($rules);
        Span::add('scanned', $scanned);

        if ($scanned === 0) {
            Span::add('processed', 0);
            Span::add('failed', 0);
            return;
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
                    action: \Appwrite\Event\Certificate::ACTION_GENERATION,
                    skipDomainValidation: true,
                ));
                $processed++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        Span::add('processed', $processed);
        Span::add('failed', $failed);
    }
}
