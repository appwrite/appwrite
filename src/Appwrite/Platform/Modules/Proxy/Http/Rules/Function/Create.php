<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Function;

use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Modules\Proxy\Http\Rules\Action;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Logger\Log;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;
use Utopia\Validator\Text;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createFunctionRule';
    }

    public function __construct(...$params)
    {
        parent::__construct(...$params);

        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/proxy/rules/function')
            ->groups(['api', 'proxy'])
            ->desc('Create function rule')
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].create')
            ->label('audits.event', 'rule.create')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                group: null,
                name: 'createFunctionRule',
                description: <<<EOT
                Create a new proxy rule for executing Appwrite Function on custom domain.
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
            ->param('functionId', '', new UID(), 'ID of function to be executed.')
            ->param('branch', '', new Text(255, 0), 'Name of VCS branch to deploy changes automatically', true)
            ->inject('response')
            ->inject('project')
            ->inject('queueForCertificates')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->inject('platform')
            ->inject('log')
            ->callback($this->action(...));
    }

    public function action(string $domain, string $functionId, string $branch, Response $response, Document $project, Certificate $queueForCertificates, Event $queueForEvents, Database $dbForPlatform, Database $dbForProject, array $platform, Log $log)
    {
        $this->validateDomainRestrictions($domain, $platform);

        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');

        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $function->getAttribute('deploymentId', ''));

        // TODO: (@Meldiron) Remove after 1.7.x migration
        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5($domain) : ID::unique();
        $status = RULE_STATUS_CREATED;
        $owner = '';

        if (
            ($functionsDomain != '' && \str_ends_with($domain, $functionsDomain)) ||
            ($sitesDomain != '' && \str_ends_with($domain, $sitesDomain))
        ) {
            $status = RULE_STATUS_VERIFIED;
            $owner = 'Appwrite';
        }

        $rule = new Document([
            '$id' => $ruleId,
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getSequence(),
            'domain' => $domain,
            'status' => $status,
            'type' => 'deployment',
            'trigger' => 'manual',
            'deploymentId' => $deployment->isEmpty() ? '' : $deployment->getId(),
            'deploymentInternalId' => $deployment->isEmpty() ? '' : $deployment->getSequence(),
            'deploymentResourceType' => 'function',
            'deploymentResourceId' => $function->getId(),
            'deploymentResourceInternalId' => $function->getSequence(),
            'deploymentVcsProviderBranch' => $branch,
            'certificateId' => '',
            'search' => implode(' ', [$ruleId, $domain, $branch]),
            'owner' => $owner,
            'region' => $project->getAttribute('region')
        ]);

        if ($rule->getAttribute('status', '') === RULE_STATUS_CREATED) {
            try {
                $this->verifyRule($rule, $log);
                $rule->setAttribute('status', RULE_STATUS_CERTIFICATE_GENERATING);
            } catch (Exception $err) {
                $rule->setAttribute('logs', $err->getMessage());
            }
        }

        try {
            $rule = $dbForPlatform->createDocument('rules', $rule);
        } catch (Duplicate $e) {
            throw new Exception(Exception::RULE_ALREADY_EXISTS);
        }

        if ($rule->getAttribute('status', '') === RULE_STATUS_CERTIFICATE_GENERATING) {
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
