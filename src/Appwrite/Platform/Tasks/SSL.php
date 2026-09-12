<?php

namespace Appwrite\Platform\Tasks;

use Appwrite\Bus\Events\RuleCreated;
use Appwrite\Bus\Events\RuleUpdated;
use Appwrite\Event\Publisher\Certificate;
use Utopia\Bus\Bus;
use Utopia\Console;
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
            ->inject('publisherForCertificates')
            ->inject('bus')
            ->callback($this->action(...));
    }

    public function action(string $domain, bool|string $skipCheck, Document $console, Database $dbForPlatform, Certificate $publisherForCertificates, Bus $bus): void
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

            $bus->dispatch(new RuleCreated($rule->getArrayCopy()));

            Console::info('Rule ' . $rule->getId() . ' created for domain: ' . $domain->get());
        } else {
            $rule = $dbForPlatform->withTransaction(function () use ($dbForPlatform, $rule): ?Document {
                $current = $dbForPlatform->getDocument('rules', $rule->getId(), forUpdate: true);
                if ($current->isEmpty()
                    || $current->getSequence() !== $rule->getSequence()
                    || $current->getAttribute('domain') !== $rule->getAttribute('domain')) {
                    return null;
                }

                $certificateId = $current->getAttribute('certificateId', '');
                $certificate = empty($certificateId) ? new Document() : $dbForPlatform->getDocument('certificates', $certificateId, forUpdate: true);
                $updated = $certificate->getAttribute('updated');
                if (!empty($updated) && new \DateTime($updated) > new \DateTime('-' . APP_CERTIFICATE_GENERATION_LEASE . ' seconds')) {
                    return null;
                }

                if (!$certificate->isEmpty()) {
                    // An explicit operator retry starts a fresh attempt budget,
                    // but cannot interrupt a worker's active generation lease.
                    $dbForPlatform->updateDocument('certificates', $certificateId, new Document([
                        'attempts' => 0,
                        'updated' => null,
                    ]));
                }
                return $dbForPlatform->updateDocument('rules', $current->getId(), new Document([
                    'status' => RULE_STATUS_CERTIFICATE_GENERATING,
                ]));
            });
            if ($rule === null) {
                Console::warning('Rule for domain ' . $domain->get() . ' changed or is already generating a certificate.');
                return;
            }

            $bus->dispatch(new RuleUpdated($rule->getArrayCopy()));

            Console::info('Updated existing rule ' . $rule->getId() . ' for domain: ' . $domain->get());
        }

        $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
            project: new Document([
                '$id' => $rule->getAttribute('projectId'),
                '$sequence' => $rule->getAttribute('projectInternalId'),
            ]),
            domain: new Document([
                'domain' => $rule->getAttribute('domain'),
                'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
            ]),
            skipRenewCheck: $skipCheck,
        ));

        Console::success('Scheduled a job to issue a TLS certificate for domain: ' . $domain->get());
    }
}
