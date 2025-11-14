<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Redirect;

use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Action;
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
use Utopia\Logger\Log;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createRedirectRule';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/proxy/rules/redirect')
            ->groups(['api', 'proxy'])
            ->desc('Create Redirect rule')
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].create')
            ->label('audits.event', 'rule.create')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: null,
                name: 'createRedirectRule',
                description: <<<EOT
                Create a new proxy rule for to redirect from custom domain to another domain.
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
            ->param('domain', null, new ValidatorDomain(), 'Domain name.')
            ->param('url', null, new URL(), 'Target URL of redirection')
            ->param('statusCode', null, new WhiteList([301, 302, 307, 308]), 'Status code of redirection')
            ->param('resourceId', '', new UID(), 'ID of parent resource.')
            ->param('resourceType', '', new WhiteList(['site', 'function']), 'Type of parent resource.')
            ->inject('response')
            ->inject('project')
            ->inject('queueForCertificates')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('log')
            ->callback($this->action(...));
    }

    public function action(
        string $domain,
        string $url,
        int $statusCode,
        string $resourceId,
        string $resourceType,
        Response $response,
        Document $project,
        Certificate $queueForCertificates,
        Event $queueForEvents,
        Database $dbForPlatform,
        Database $dbForProject,
        Log $log
    ) {
        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');

        $restrictions = [];
        if (!empty($sitesDomain)) {
            $domainLevel = \count(\explode('.', $sitesDomain));
            $restrictions[] = ValidatorDomain::createRestriction($sitesDomain, $domainLevel + 1, ['commit-', 'branch-']);
        }
        if (!empty($functionsDomain)) {
            $domainLevel = \count(\explode('.', $functionsDomain));
            $restrictions[] = ValidatorDomain::createRestriction($functionsDomain, $domainLevel + 1);
        }
        $validator = new ValidatorDomain($restrictions);

        if (!$validator->isValid($domain)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please use a different domain.');
        }

        $deniedDomains = [
            'localhost',
            APP_HOSTNAME_INTERNAL
        ];

        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        $deniedDomains[] = $mainDomain;

        if (!empty($sitesDomain)) {
            $deniedDomains[] = $sitesDomain;
        }

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

        $collection = match ($resourceType) {
            'site' => 'sites',
            'function' => 'functions'
        };
        $resource = $dbForProject->getDocument($collection, $resourceId);
        if ($resource->isEmpty()) {
            throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
        }

        // TODO: @christyjacob remove once we migrate the rules in 1.7.x
        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5($domain->get()) : ID::unique();

        $status = RULE_STATUS_VERIFICATION_FAILED;
        if (\str_ends_with($domain->get(), $functionsDomain) || \str_ends_with($domain->get(), $sitesDomain)) {
            $status = RULE_STATUS_SUCCESSFUL;
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
            'type' => 'redirect',
            'trigger' => 'manual',
            'redirectUrl' => $url,
            'redirectStatusCode' => $statusCode,
            'deploymentResourceType' => $resourceType,
            'deploymentResourceId' => $resource->getId(),
            'deploymentResourceInternalId' => $resource->getSequence(),
            'certificateId' => '',
            'search' => implode(' ', [$ruleId, $domain->get()]),
            'owner' => $owner,
            'region' => $project->getAttribute('region')
        ]);

        if ($rule->getAttribute('status', '') === RULE_STATUS_VERIFICATION_FAILED) {
            try {
                $this->verifyRule($rule, $log);
                $rule->setAttribute('status', RULE_STATUS_GENERATING_CERTIFICATE);
            } catch (Exception $err) {
                $rule->setAttribute('logs', $err->getMessage());
            }
        }

        try {
            $rule = $dbForPlatform->createDocument('rules', $rule);
        } catch (Duplicate $e) {
            throw new Exception(Exception::RULE_ALREADY_EXISTS);
        }

        if ($rule->getAttribute('status', '') === RULE_STATUS_GENERATING_CERTIFICATE) {
            $queueForCertificates
                ->setDomain(new Document([
                    'domain' => $rule->getAttribute('domain'),
                    'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
                ]))
                ->trigger();
        }

        $queueForEvents->setParam('ruleId', $rule->getId());

        $certificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', ''));

        // Merge logs: priority to certificate logs if both have values, otherwise use whichever is not empty
        $ruleLogs = $rule->getAttribute('logs', '');
        $certificateLogs = $certificate->getAttribute('logs', '');
        $logs = '';
        if (!empty($certificateLogs) && !empty($ruleLogs)) {
            $logs = $certificateLogs; // Certificate logs have priority
        } elseif (!empty($certificateLogs)) {
            $logs = $certificateLogs;
        } elseif (!empty($ruleLogs)) {
            $logs = $ruleLogs;
        }
        $rule->setAttribute('logs', $logs);
        $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
