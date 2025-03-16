<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules\Function;

use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\CNAME;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Platform\Action;
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

    public function __construct()
    {
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
            ->callback([$this, 'action']);
    }

    public function action(string $domain, string $functionId, string $branch, Response $response, Document $project, Certificate $queueForCertificates, Event $queueForEvents, Database $dbForPlatform, Database $dbForProject)
    {
        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');

        $deniedDomains = [
            $mainDomain,
            $sitesDomain,
            $functionsDomain,
            'localhost',
            APP_HOSTNAME_INTERNAL,
        ];

        if (\in_array($domain, $deniedDomains)) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please pick another one.');
        }

        try {
            $domain = new Domain($domain);
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain may not start with http:// or https://.');
        }

        // Apex domain prevention due to CNAME limitations
        if (empty(App::getEnv('_APP_DOMAINS_NAMESERVERS', ''))) {
            if ($domain->get() === $domain->getRegisterable()) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'The instance does not allow root-level (apex) domains.');
            }
        }

        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $function->getAttribute('deploymentId', ''));

        // TODO: @christyjacob remove once we migrate the rules in 1.7.x
        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5($domain->get()) : ID::unique();

        $status = 'created';
        if (\str_ends_with($domain->get(), $functionsDomain) || \str_ends_with($domain->get(), $sitesDomain)) {
            $status = 'verified';
        }
        if ($status === 'created') {
            $target = new Domain(System::getEnv('_APP_DOMAIN_TARGET', ''));
            $validator = new CNAME($target->get());
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
            'projectInternalId' => $project->getInternalId(),
            'domain' => $domain->get(),
            'status' => $status,
            'type' => 'deployment',
            'trigger' => 'manual',
            'deploymentId' => $deployment->isEmpty() ? '' : $deployment->getId(),
            'deploymentInternalId' => $deployment->isEmpty() ? '' : $deployment->getInternalId(),
            'deploymentResourceType' => 'function',
            'deploymentResourceId' => $function->getId(),
            'deploymentResourceInternalId' => $function->getInternalId(),
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
                    'domain' => $rule->getAttribute('domain')
                ]))
                ->trigger();
        }

        $queueForEvents->setParam('ruleId', $rule->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
