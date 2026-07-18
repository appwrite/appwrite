<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Domain;

use Appwrite\Event\Event;
use Appwrite\Event\Message\Delete as DeleteMessage;
use Appwrite\Event\Publisher\Certificate;
use Appwrite\Event\Publisher\Delete as DeletePublisher;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Usage\Context as UsageContext;
use Appwrite\Utopia\Response;
use Utopia\Console;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Logger\Log;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;

/**
 * Atomically replace a proxy rule hostname.
 *
 * Changing a domain must never temporarily consume two quota slots: the previous
 * rule is removed and the new rule is created inside a single database transaction.
 */
class Update extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'updateRuleDomain';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_PATCH)
            ->setHttpPath('/v1/proxy/rules/:ruleId/domain')
            ->desc('Update rule domain')
            ->groups(['api', 'proxy'])
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].update')
            ->label('audits.event', 'rule.update')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: 'rules',
                name: 'updateRuleDomain',
                description: <<<EOT
                Replace the domain of an existing proxy rule atomically.

                The previous rule is deleted and a new rule is created for the new domain inside a single transaction, so plan domain quota is never temporarily consumed twice. Pending DNS verification state for the previous domain is removed.
                EOT,
                auth: [AuthType::ADMIN, AuthType::KEY],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_OK,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'userId:{userId}, url:{url}')
            ->label('abuse-time', 60)
            ->param('ruleId', '', fn (Database $dbForProject) => new UID($dbForProject->getAdapter()->getMaxUIDLength()), 'Rule ID.', false, ['dbForProject'])
            ->param('domain', null, new ValidatorDomain(), 'New domain name.')
            ->inject('response')
            ->inject('project')
            ->inject('publisherForCertificates')
            ->inject('queueForEvents')
            ->inject('publisherForDeletes')
            ->inject('dbForPlatform')
            ->inject('platform')
            ->inject('log')
            ->inject('authorization')
            ->inject('usage')
            ->callback($this->action(...));
    }

    public function action(
        string $ruleId,
        string $domain,
        Response $response,
        Document $project,
        Certificate $publisherForCertificates,
        Event $queueForEvents,
        DeletePublisher $publisherForDeletes,
        Database $dbForPlatform,
        array $platform,
        Log $log,
        Authorization $authorization,
        UsageContext $usage,
    ) {
        $oldRule = $authorization->skip(fn () => $dbForPlatform->getDocument('rules', $ruleId));

        if ($oldRule->isEmpty() || $oldRule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $previousDomain = $oldRule->getAttribute('domain', '');
        if (\strtolower($previousDomain) === \strtolower($domain)) {
            if ($oldRule->getAttribute('status') === 'created') {
                $oldRule->setAttribute('status', 'unverified');
            }
            $response->dynamic($oldRule, Response::MODEL_PROXY_RULE);
            return;
        }

        $this->validateDomainRestrictions($domain, $platform);

        $quotaBefore = $this->countCustomDomains($dbForPlatform, $project, $authorization);

        $newRuleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5(\strtolower($domain)) : ID::unique();
        $status = RULE_STATUS_CREATED;
        $owner = '';

        if ($this->isAppwriteOwned($domain)) {
            $status = RULE_STATUS_VERIFIED;
            $owner = 'Appwrite';
        }

        $branch = $oldRule->getAttribute('deploymentVcsProviderBranch', '');
        $searchParts = [$newRuleId, $domain];
        if (!empty($branch)) {
            $searchParts[] = $branch;
        }

        $newRule = new Document([
            '$id' => $newRuleId,
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'domain' => $domain,
            'status' => $status,
            'type' => $oldRule->getAttribute('type'),
            'trigger' => $oldRule->getAttribute('trigger', 'manual'),
            'redirectUrl' => $oldRule->getAttribute('redirectUrl', ''),
            'redirectStatusCode' => $oldRule->getAttribute('redirectStatusCode', null),
            'deploymentId' => $oldRule->getAttribute('deploymentId', ''),
            'deploymentInternalId' => $oldRule->getAttribute('deploymentInternalId', ''),
            'deploymentResourceType' => $oldRule->getAttribute('deploymentResourceType', ''),
            'deploymentResourceId' => $oldRule->getAttribute('deploymentResourceId', ''),
            'deploymentResourceInternalId' => $oldRule->getAttribute('deploymentResourceInternalId', ''),
            'deploymentVcsProviderBranch' => $branch,
            'certificateId' => '',
            'search' => implode(' ', $searchParts),
            'owner' => $owner,
            'region' => $oldRule->getAttribute('region', $project->getAttribute('region')),
            'logs' => '',
        ]);

        if ($newRule->getAttribute('status', '') === RULE_STATUS_CREATED) {
            try {
                $this->verifyRule($newRule, $log);
                $newRule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATING);
            } catch (Exception $err) {
                $newRule->setAttribute('logs', $err->getMessage());
            }
        }

        try {
            $newRule = $authorization->skip(fn () => $dbForPlatform->withTransaction(function () use ($dbForPlatform, $oldRule, $newRule) {
                $dbForPlatform->deleteDocument('rules', $oldRule->getId());

                try {
                    return $dbForPlatform->createDocument('rules', $newRule);
                } catch (Duplicate $e) {
                    throw new Exception(Exception::RULE_ALREADY_EXISTS);
                }
            }));
        } catch (Exception $e) {
            Console::error(\sprintf(
                '[proxy] domain replace failed project=%s previous=%s new=%s quotaBefore=%d error=%s',
                $project->getId(),
                $previousDomain,
                $domain,
                $quotaBefore,
                $e->getMessage()
            ));
            throw $e;
        }

        $this->adjustDomainUsage($usage, $oldRule, -1);
        $this->adjustDomainUsage($usage, $newRule, 1);

        $publisherForDeletes->enqueue(new DeleteMessage(
            project: $project,
            type: DELETE_TYPE_DOCUMENT,
            document: $oldRule,
        ));

        if ($newRule->getAttribute('status', '') === RULE_STATUS_CERTIFICATE_GENERATING) {
            $publisherForCertificates->enqueue(new \Appwrite\Event\Message\Certificate(
                project: $project,
                domain: new Document([
                    'domain' => $newRule->getAttribute('domain'),
                    'domainType' => $newRule->getAttribute('deploymentResourceType', $newRule->getAttribute('type')),
                ]),
                action: \Appwrite\Event\Certificate::ACTION_GENERATION,
            ));
        }

        $quotaAfter = $this->countCustomDomains($dbForPlatform, $project, $authorization);

        Console::info(\sprintf(
            '[proxy] domain replaced project=%s previous=%s new=%s previousStatus=%s newStatus=%s quotaBefore=%d quotaAfter=%d',
            $project->getId(),
            $previousDomain,
            $domain,
            $oldRule->getAttribute('status', ''),
            $newRule->getAttribute('status', ''),
            $quotaBefore,
            $quotaAfter
        ));

        $log->addTag('previousDomain', $previousDomain);
        $log->addTag('newDomain', $domain);
        $log->addExtra('quotaBefore', (string) $quotaBefore);
        $log->addExtra('quotaAfter', (string) $quotaAfter);

        $queueForEvents->setParam('ruleId', $newRule->getId());

        if ($newRule->getAttribute('status') === 'created') {
            $newRule->setAttribute('status', 'unverified');
        }

        $response->dynamic($newRule, Response::MODEL_PROXY_RULE);
    }
}
