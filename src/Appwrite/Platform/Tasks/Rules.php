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

class Rules extends Action
{
    public static function getName(): string
    {
        return 'rules';
    }

    public function __construct()
    {
        $this
          ->desc('Schedules periodic tasks for rule verification and certificate renewal')
          ->inject('dbForPlatform')
          ->inject('queueForCertificates')
          ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, Certificate $queueForCertificates): void
    {
        Console::title('Interval V1');
        Console::success(APP_NAME . ' interval process v1 has started');

        $intervalRuleVerification = (int) System::getEnv('_APP_MAINTENANCE_RULE_VERIFICATION_INTERVAL', '60'); // 1 minute
        $intervalCertificateRenewal = (int) System::getEnv('_APP_MAINTENANCE_CERTIFICATE_RENEWAL_INTERVAL', '86400'); // 1 day

        \go(function () use ($dbForPlatform, $queueForCertificates, $intervalRuleVerification) {
            Console::loop(function () use ($dbForPlatform, $queueForCertificates) {
                $this->checkRuleVerification($dbForPlatform, $queueForCertificates);
            }, $intervalRuleVerification);
        });

        \go(function () use ($dbForPlatform, $queueForCertificates, $intervalCertificateRenewal) {
            Console::loop(function () use ($dbForPlatform, $queueForCertificates) {
                $this->renewCertificates($dbForPlatform, $queueForCertificates);
            }, $intervalCertificateRenewal);
        });
    }

    private function checkRuleVerification(Database $dbForPlatform, Certificate $queueForCertificate): void
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
            Console::info("[{$time}] No rules for verification.");
            return; // No rules to verify
        }

        Console::info("[{$time}] Found " . \count($rules) . " rules for verification, scheduling jobs.");

        foreach ($rules as $rule) {
            $queueForCertificate
                ->setDomain(new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]))
                ->setAction(Certificate::ACTION_DOMAIN_VERIFICATION)
                ->trigger();
        }
    }

    private function renewCertificates(Database $dbForPlatform, Certificate $queueForCertificate): void
    {
        $time = DatabaseDateTime::now();

        $certificates = $dbForPlatform->find('certificates', [
            Query::lessThan('attempts', 5), // Maximum 5 attempts
            Query::isNotNull('renewDate'),
            Query::lessThanEqual('renewDate', $time), // includes 60 days cooldown (we have 30 days to renew)
            Query::limit(200), // Limit 200 comes from LetsEncrypt (300 orders per 3 hours, keeping some for new domains)
        ]);

        if (\count($certificates) === 0) {
            Console::info("[{$time}] No certificates for renewal.");
            return;
        }

        Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';
        $appRegion = System::getEnv('_APP_REGION', 'default');

        foreach ($certificates as $certificate) {
            $domain = $certificate->getAttribute('domain');
            $rule = $isMd5 ?
                $dbForPlatform->getDocument('rules', md5($domain)) :
                    $dbForPlatform->findOne('rules', [
                        Query::equal('domain', [$domain]),
                        Query::limit(1)
                    ]);

            if ($rule->isEmpty() || $rule->getAttribute('region') !== $appRegion) {
                continue;
            }

            $queueForCertificate
                ->setDomain(new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]))
                ->setAction(Certificate::ACTION_GENERATION)
                ->trigger();
        }
    }
}
