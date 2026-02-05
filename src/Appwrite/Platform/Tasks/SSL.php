<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Event\Certificate;
use Utopia\CLI\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Domains\Domain;
use Utopia\Platform\Action;
use Utopia\System\System;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;

class SSL extends Action
{
    public static function getName(): string
    {
        return 'ssl';
    }

    public function __construct()
    {
        $this
            ->desc('Validate server certificates')
            ->param('domain', System::getEnv('_APP_DOMAIN', ''), new Hostname(), 'Domain to generate certificate for. If empty, main domain will be used.', true)
            ->param('skip-check', 'true', new Boolean(true), 'If DNS and renew check should be skipped. Defaults to true, and when true, all jobs will result in certificate generation attempt.', true)
            ->inject('console')
            ->inject('dbForPlatform')
            ->inject('queueForCertificates')
            ->callback($this->action(...));
    }

    public function action(string $domain, bool|string $skipCheck, Document $console, Database $dbForPlatform, Certificate $queueForCertificates): void
    {
        $domain = new Domain(!empty($domain) ? $domain : '');
        if (!$domain->isKnown() || $domain->isTest()) {
            Console::error('Domain is not known or is a test domain: ' . $domain->get());
            return;
        }

        $skipCheck = \strval($skipCheck) === 'true';
        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';

        $rule = $isMd5
            ? $dbForPlatform->getDocument('rules', md5($domain->get()))
            : $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$domain->get()]),
            ]);

        if ($rule->isEmpty()) {
            $owner = '';

            // Mark owner as Appwrite if its appwrite-owned domain
            $appwriteDomains = [];
            $appwriteDomainEnvs = [
                System::getEnv('_APP_DOMAIN_FUNCTIONS_FALLBACK', ''),
                System::getEnv('_APP_DOMAIN_FUNCTIONS', ''),
                System::getEnv('_APP_DOMAIN_SITES', ''),
            ];
            foreach ($appwriteDomainEnvs as $appwriteDomainEnv) {
                foreach (\explode(',', $appwriteDomainEnv) as $appwriteDomain) {
                    if (empty($appwriteDomain)) {
                        continue;
                    }
                    $appwriteDomains[] = $appwriteDomain;
                }
            }

            foreach ($appwriteDomains as $appwriteDomain) {
                if (\str_ends_with($domain->get(), $appwriteDomain)) {
                    $owner = 'Appwrite';
                    break;
                }
            }

            $ruleId = $isMd5 ? md5($domain->get()) : ID::unique();
            $rule = $dbForPlatform->createDocument('rules', new Document([
                '$id' => $ruleId,
                'domain' => $domain->get(),
                'type' => 'api',
                'status' => RULE_STATUS_CERTIFICATE_GENERATING,
                'projectId' => $console->getId(),
                'projectInternalId' => $console->getSequence(),
                'search' => implode(' ', [$ruleId, $domain->get()]),
                'owner' => $owner,
                'region' => $console->getAttribute('region')
            ]));

            Console::info('Rule ' . $rule->getId() . ' created for domain: ' . $domain->get());
        } else {
            $rule = $dbForPlatform->updateDocument('rules', $rule->getId(), new Document([
                'status' => RULE_STATUS_CERTIFICATE_GENERATING,
            ]));

            Console::info('Updated existing rule ' . $rule->getId() . ' for domain: ' . $domain->get());
        }

        $queueForCertificates
            ->setDomain(new Document([
                'domain' => $domain->get()
            ]))
            ->setSkipRenewCheck($skipCheck)
            ->trigger();

        Console::success('Scheduled a job to issue a TLS certificate for domain: ' . $domain->get());
    }
}
