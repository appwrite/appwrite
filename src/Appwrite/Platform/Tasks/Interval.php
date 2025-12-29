<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Certificate;
use DateTime;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Platform\Action;
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
          ->inject('queueForCertificates')
          ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, Certificate $queueForCertificates): void
    {
        Console::title('Interval V1');
        Console::success(APP_NAME . ' interval process v1 has started');

        $intervalDomainVerification = (int) System::getEnv('_APP_INTERVAL_DOMAIN_VERIFICATION', '60'); // 1 minute

        \go(function () use ($dbForPlatform, $queueForCertificates, $intervalDomainVerification) {
            Console::loop(function () use ($dbForPlatform, $queueForCertificates) {
                $this->verifyDomain($dbForPlatform, $queueForCertificates);
            }, $intervalDomainVerification);
        });
    }

    private function verifyDomain(Database $dbForPlatform, Certificate $queueForCertificates): void
    {
        $time = DatabaseDateTime::now();
        $fromTime = new DateTime('-3 days'); // Max 3 days old

        $rules = $dbForPlatform->find('rules', [
            Query::createdAfter(DatabaseDateTime::format($fromTime)),
            Query::equal('status', [RULE_STATUS_CREATED]), // Created but not verified yet
            Query::orderAsc('$updatedAt'), // Pick the ones waiting for another attempt for longest
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]), // Only current region
            Query::limit(30), // Reasonable pagination limit, processable within a minute
        ]);

        if (\count($rules) === 0) {
            Console::info("[{$time}] No rules for domain verification.");
            return; // No rules to verify
        }

        Console::info("[{$time}] Found " . \count($rules) . " rules for domain verification, scheduling jobs.");

        foreach ($rules as $rule) {
            $queueForCertificates
                ->setDomain(new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]))
                ->setAction(Certificate::ACTION_DOMAIN_VERIFICATION)
                ->trigger();
        }
    }
}
