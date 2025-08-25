<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Site;

use Appwrite\Domain\Validator\AppwriteDomain;
use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\IP;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createSiteRule';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/proxy/rules/site')
            ->groups(['api', 'proxy'])
            ->desc('Create site rule')
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].create')
            ->label('audits.event', 'rule.create')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: null,
                name: 'createSiteRule',
                description: <<<EOT
                Create a new proxy rule for serving Appwrite Site on custom domain.
                EOT,
                auth: [AuthType::ADMIN],
                responses: [
                    new SDKResponse(
                        code: Response::STATUS_CODE_CREATED,
                        model: Response::MODEL_PROXY_RULE,
                    )
                ]
            ))
            ->label('abuse-limit', 10)
            ->label('abuse-key', 'userId:{userId}, url:{url}')
            ->label('abuse-time', 60)
            ->param('domain', null, new AppwriteDomain(), 'Domain name.')
            ->param('siteId', '', new UID(), 'ID of site to be executed.')
            ->param('branch', '', new Text(255, 0), 'Name of VCS branch to deploy changes automatically', true)
            ->inject('response')
            ->inject('project')
            ->inject('queueForCertificates')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->callback($this->action(...));
    }

    public function action(string $domain, string $siteId, string $branch, Response $response, Document $project, Certificate $queueForCertificates, Event $queueForEvents, Database $dbForPlatform, Database $dbForProject)
    {
        $deniedDomains = [
            'localhost',
            APP_HOSTNAME_INTERNAL
        ];

        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        $deniedDomains[] = $mainDomain;

        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        if (!empty($sitesDomain)) {
            $deniedDomains[] = $sitesDomain;
        }

        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
        if (!empty($functionsDomain)) {
            $deniedDomains[] = $functionsDomain;
        }

        $denyListDomains = System::getEnv('_APP_CUSTOM_DOMAIN_DENY_LIST', '');
        $denyListDomains = \array_map('trim', explode(',', $denyListDomains));
        foreach ($denyListDomains as $denyListDomain) {
            if (empty($denyListDomain)) {
                continue;
            }
            $deniedDomains[] = $denyListDomain;
        }

        if (\in_array($domain, $deniedDomains)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please use a different domain.');
        }


        try {
            $domain = new Domain($domain);
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain may not start with http:// or https://.');
        }

        $site = $dbForProject->getDocument('sites', $siteId);
        if ($site->isEmpty()) {
            throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $site->getAttribute('deploymentId', ''));

        // TODO: @christyjacob remove once we migrate the rules in 1.7.x
        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5($domain->get()) : ID::unique();

        $status = 'created';
        if (\str_ends_with($domain->get(), $functionsDomain) || \str_ends_with($domain->get(), $sitesDomain)) {
            $status = 'verified';
        }
        if ($status === 'created') {
            $validators = [];
            $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_TARGET_CNAME', ''));
            if ($targetCNAME->isKnown() && !$targetCNAME->isTest()) {
                $validators[] = new DNS($targetCNAME->get(), DNS::RECORD_CNAME);
            }
            if ((new IP(IP::V4))->isValid(System::getEnv('_APP_DOMAIN_TARGET_A', ''))) {
                $validators[] = new DNS(System::getEnv('_APP_DOMAIN_TARGET_A', ''), DNS::RECORD_A);
            }
            if ((new IP(IP::V6))->isValid(System::getEnv('_APP_DOMAIN_TARGET_AAAA', ''))) {
                $validators[] = new DNS(System::getEnv('_APP_DOMAIN_TARGET_AAAA', ''), DNS::RECORD_AAAA);
            }

            if (empty($validators)) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'At least one of domain targets environment variable must be configured.');
            }

            $validator = new AnyOf($validators, AnyOf::TYPE_STRING);
            if ($validator->isValid($domain->get())) {
                $status = 'verifying';
            }
        }

        $owner = '';
        if (
            ($functionsDomain != '' && \str_ends_with($domain->get(), $functionsDomain)) ||
            ($sitesDomain != '' && \str_ends_with($domain->get(), $sitesDomain))
        ) {
            $owner = 'Appwrite';
        }

        $rule = new Document([
            '$id' => $ruleId,
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'domain' => $domain->get(),
            'status' => $status,
            'type' => 'deployment',
            'trigger' => 'manual',
            'deploymentId' => $deployment->isEmpty() ? '' : $deployment->getId(),
            'deploymentInternalId' => $deployment->isEmpty() ? '' : $deployment->getSequence(),
            'deploymentResourceType' => 'site',
            'deploymentResourceId' => $site->getId(),
            'deploymentResourceInternalId' => $site->getSequence(),
            'deploymentVcsProviderBranch' => $branch,
            'certificateId' => '',
            'search' => implode(' ', [$ruleId, $domain->get(), $branch]),
            'owner' => $owner,
            'region' => $project->getAttribute('region')
        ]);

        try {
            $rule = $dbForPlatform->createDocument('rules', $rule);
        } catch (Duplicate $e) {
            throw new Exception(Exception::RULE_ALREADY_EXISTS);
        }

        if ($rule->getAttribute('status', '') === 'verifying') {
            $queueForCertificates
                ->setDomain(new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]))
                ->trigger();
        }

        $queueForEvents->setParam('ruleId', $rule->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
