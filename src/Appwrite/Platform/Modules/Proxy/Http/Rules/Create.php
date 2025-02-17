<?php

namespace Appwrite\Platform\Modules\Proxy\Http\Rules;

use Appwrite\Event\Certificate;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\CNAME;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Platform\Action;
use Utopia\Platform\Scope\HTTP;
use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createRule';
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/proxy/rules')
            ->groups(['api', 'proxy'])
            ->desc('Create rule')
            ->label('scope', 'rules.write')
            ->label('event', 'rules.[ruleId].create')
            ->label('audits.event', 'rule.create')
            ->label('audits.resource', 'rule/{response.$id}')
            ->label('sdk', new Method(
                namespace: 'proxy',
                name: 'createRule',
                description: <<<EOT
                Create a new proxy rule.
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
            ->param('resourceType', null, new WhiteList(['api', 'function', 'site']), 'Action definition for the rule. Possible values are "api", "function" and "site"')
            ->param('resourceId', '', new UID(), 'ID of resource for the action type. If resourceType is "api", leave empty. If resourceType is "function", provide ID of the function.', true)
            ->inject('response')
            ->inject('project')
            ->inject('queueForCertificates')
            ->inject('queueForEvents')
            ->inject('dbForPlatform')
            ->inject('dbForProject')
            ->callback([$this, 'action']);
    }

    public function action(string $domain, string $resourceType, string $resourceId, Response $response, Document $project, Certificate $queueForCertificates, Event $queueForEvents, Database $dbForPlatform, Database $dbForProject)
    {
        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        if ($domain === $mainDomain) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'You cannot assign your main domain to specific resource. Please use subdomain or a different domain.');
        }

        $sitesDomain = System::getEnv('_APP_DOMAIN_SITES', '');
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');

        if ($domain === $functionsDomain || $domain === $sitesDomain) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'You cannot assign your functions or sites domain to a specific resource. Please use a different domain.');
        }

        if ($domain === 'localhost' || $domain === APP_HOSTNAME_INTERNAL) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please pick another one.');
        }

        // TODO: @christyjacob remove once we migrate the rules in 1.7.x
        if (System::getEnv('_APP_RULES_FORMAT') === 'md5') {
            $document = $dbForPlatform->getDocument('rules', md5($domain));
        } else {
            $document = $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$domain]),
            ]);
        }

        if (!$document->isEmpty()) {
            if ($document->getAttribute('projectId') === $project->getId()) {
                $resourceType = $document->getAttribute('resourceType');
                $resourceId = $document->getAttribute('resourceId');
                $message = "Domain already assigned to another resource.";
                if (!empty($resourceId)) {
                    $message .= " with ID '{$resourceId}'";
                }

                $message .= '.';
            } else {
                $message = 'Domain already assigned to different project.';
            }

            throw new Exception(Exception::RULE_ALREADY_EXISTS, $message);
        }

        $resourceInternalId = '';

        switch ($resourceType) {
            case 'function':
                if (empty($resourceId)) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, '$resourceId cannot be empty for resourceType "function".');
                }

                if (!\str_ends_with($domain, $functionsDomain)) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain must end with ' . $functionsDomain . ' for resourceType "function".');
                }

                $function = $dbForProject->getDocument('functions', $resourceId);

                if ($function->isEmpty()) {
                    throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
                }

                $resourceInternalId = $function->getInternalId();
                break;
            case 'site':
                if (empty($resourceId)) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, '$resourceId cannot be empty for resourceType "site".');
                }

                if (!\str_ends_with($domain, $sitesDomain)) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain must end with ' . $sitesDomain . ' for resourceType "site".');
                }

                $site = $dbForProject->getDocument('sites', $resourceId);

                if ($site->isEmpty()) {
                    throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
                }

                $resourceInternalId = $site->getInternalId();
                break;
            case 'api':
                if (\str_ends_with($domain, $functionsDomain) || \str_ends_with($domain, $sitesDomain)) {
                    throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain must not end with ' . $functionsDomain . ' or ' . $sitesDomain . ' for resourceType "api".');
                }
                break;
        }

        try {
            $domain = new Domain($domain);
        } catch (\Throwable) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Domain may not start with http:// or https://.');
        }

        // TODO: @christyjacob remove once we migrate the rules in 1.7.x
        $ruleId = System::getEnv('_APP_RULES_FORMAT') === 'md5' ? md5($domain->get()) : ID::unique();

        $rule = new Document([
            '$id' => $ruleId,
            'projectId' => $project->getId(),
            'projectInternalId' => $project->getInternalId(),
            'domain' => $domain->get(),
            'resourceType' => $resourceType,
            'resourceId' => $resourceId,
            'resourceInternalId' => $resourceInternalId,
            'certificateId' => '',
        ]);

        $status = 'created';

        if (\str_ends_with($domain->get(), $functionsDomain) || \str_ends_with($domain->get(), $sitesDomain)) {
            $status = 'verified';
        }

        if ($status === 'created') {
            $target = new Domain(System::getEnv('_APP_DOMAIN_TARGET', ''));
            $validator = new CNAME($target->get()); // Verify Domain with DNS records

            if ($validator->isValid($domain->get())) {
                $status = 'verifying';

                $queueForCertificates
                    ->setDomain(new Document([
                        'domain' => $rule->getAttribute('domain')
                    ]))
                    ->trigger();
            }
        }

        $rule->setAttribute('status', $status);
        $rule = $dbForPlatform->createDocument('rules', $rule);

        $queueForEvents->setParam('ruleId', $rule->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    }
}
