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
            ->desc('Schedules interval tasks for rules verification and certificate renewal')
            ->inject('dbForPlatform')
            ->inject('queueForCertificates')
            ->callback($this->action(...));
    }

    public function action(Database $dbForPlatform, Certificate $queueForCertificates): void
    {
        Console::title('Interval V1');
        Console::success(APP_NAME . ' interval process v1 has started');

        $intervalRuleVerification = (int) System::getEnv('_APP_MAINTENANCE_INTERVAL_RULE_VERIFICATION', '60'); // 1 minute
        $intervalCertificateRenewal = (int) System::getEnv('_APP_MAINTENANCE_INTERVAL', '86400'); // 1 day

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

        $oldestToCheck = new DateTime('-3 days');

        $rules = $dbForPlatform->find('rules', [
            Query::createdAfter(DatabaseDateTime::format($oldestToCheck)), // max 3 days old
            Query::equal('status', [RULE_STATUS_VERIFICATION_FAILED]), // not verified yet
            Query::orderAsc('$updatedAt'), // Pick the ones waiting for another attempt for longest
            Query::equal('region', [System::getEnv('_APP_REGION', 'default')]), // Only current region
            Query::limit(30), // Reasonable pagination limit, processable within a minute
        ]);

        if (\count($rules) > 0) {
            Console::info("[{$time}] Found " . \count($rules) . " rules for verification, scheduling jobs.");

            foreach ($rules as $rule) {
                $queueForCertificate
                    ->setDomain(new Document([
                        'domain' => $rule->getAttribute('domain'),
                        'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                    ]))
                    ->setAction(Certificate::ACTION_VERIFICATION)
                    ->trigger();
            }
        } else {
            // Silenced because interval makes it too often
            // Console::log("[{$time}] No rules for checking verification status.");
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

        if (\count($certificates) > 0) {
            Console::info("[{$time}] Found " . \count($certificates) . " certificates for renewal, scheduling jobs.");

            foreach ($certificates as $certificate) {
                $domain = $certificate->getAttribute('domain');
                if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
                    $rule = $dbForPlatform->getDocument('rules', md5($domain));
                } else {
                    $rule = $dbForPlatform->findOne('rules', [
                        Query::equal('domain', [$domain]),
                    ]);
                }

                if ($rule->isEmpty() || $rule->getAttribute('region') !== System::getEnv('_APP_REGION', 'default')) {
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
        } else {
            Console::info("[{$time}] No certificates for renewal.");
        }
    }
}
