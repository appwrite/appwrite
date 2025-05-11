<?php

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\CNAME;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\Queries\Rules;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Logger\Log;
use Utopia\System\System;
use Utopia\Validator\Domain as ValidatorDomain;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::post('/v1/proxy/rules')
    ->groups(['api', 'proxy'])
    ->desc('Create rule')
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].create')
    ->label('audits.event', 'rule.create')
    ->label('audits.resource', 'rule/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'proxy',
        group: null,
        name: 'createRule',
        description: '/docs/references/proxy/create-rule.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROXY_RULE,
            )
        ]
    ))
    ->param('domain', null, new ValidatorDomain(), 'Domain name.')
    ->param('resourceType', null, new WhiteList(['api', 'function']), 'Action definition for the rule. Possible values are "api", "function"')
    ->param('resourceId', '', new UID(), 'ID of resource for the action type. If resourceType is "api", leave empty. If resourceType is "function", provide ID of the function.', true)
    ->inject('response')
    ->inject('project')
    ->inject('queueForCertificates')
    ->inject('queueForEvents')
    ->inject('dbForPlatform')
    ->inject('dbForProject')
    ->action(function (string $domain, string $resourceType, string $resourceId, Response $response, Document $project, Certificate $queueForCertificates, Event $queueForEvents, Database $dbForPlatform, Database $dbForProject) {

        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        if ($domain === $mainDomain) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'You cannot assign your main domain to specific resource. Please use subdomain or a different domain.');
        }

        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS');
        $denyListDomains = System::getEnv('_APP_CUSTOM_DOMAIN_DENY_LIST');

        if (!empty($denyListDomains)) {
            $functionsDomain .= ',' . $denyListDomains;
        }

        $deniedDomains = array_map('trim', explode(',', $functionsDomain));

        foreach ($deniedDomains as $deniedDomain) {
            if (str_ends_with($domain, $deniedDomain)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'You cannot assign your functions domain or its subdomain to a specific resource. Please use a different domain.');
            }
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
                $message = "Domain already assigned to '{$resourceType}' service";
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

        if ($resourceType == 'function') {
            if (empty($resourceId)) {
                throw new Exception(Exception::FUNCTION_NOT_FOUND);
            }

            $function = $dbForProject->getDocument('functions', $resourceId);

            if ($function->isEmpty()) {
                throw new Exception(Exception::RULE_RESOURCE_NOT_FOUND);
            }

            $resourceInternalId = $function->getInternalId();
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
            'owner' => '',
            'region' => $project->getAttribute('region')
        ]);

        $status = 'created';
        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS');
        if (!empty($functionsDomain) && \str_ends_with($domain->get(), $functionsDomain)) {
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

        $rule->setAttribute('logs', '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    });

App::get('/v1/proxy/rules')
    ->groups(['api', 'proxy'])
    ->desc('List rules')
    ->label('scope', 'rules.read')
    ->label('sdk', new Method(
        namespace: 'proxy',
        group: null,
        name: 'listRules',
        description: '/docs/references/proxy/list-rules.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROXY_RULE_LIST,
            )
        ]
    ))
    ->param('queries', [], new Rules(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Rules::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->action(function (array $queries, string $search, Response $response, Document $project, Database $dbForPlatform) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        $queries[] = Query::equal('projectInternalId', [$project->getInternalId()]);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */

            $validator = new Cursor();
            if (!$validator->isValid($cursor)) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $validator->getDescription());
            }

            $ruleId = $cursor->getValue();
            $cursorDocument = $dbForPlatform->getDocument('rules', $ruleId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Rule '{$ruleId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $rules = $dbForPlatform->find('rules', $queries);
        foreach ($rules as $rule) {
            $certificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', ''));
            $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));
            $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));
        }

        $response->dynamic(new Document([
            'rules' => $rules,
            'total' => $dbForPlatform->count('rules', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_PROXY_RULE_LIST);
    });

App::get('/v1/proxy/rules/:ruleId')
    ->groups(['api', 'proxy'])
    ->desc('Get rule')
    ->label('scope', 'rules.read')
    ->label('sdk', new Method(
        namespace: 'proxy',
        group: null,
        name: 'getRule',
        description: '/docs/references/proxy/get-rule.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROXY_RULE,
            )
        ]
    ))
    ->param('ruleId', '', new UID(), 'Rule ID.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->action(function (string $ruleId, Response $response, Document $project, Database $dbForPlatform) {
        $rule = $dbForPlatform->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $certificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', ''));
        $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));
        $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    });

App::delete('/v1/proxy/rules/:ruleId')
    ->groups(['api', 'proxy'])
    ->desc('Delete rule')
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].delete')
    ->label('audits.event', 'rules.delete')
    ->label('audits.resource', 'rule/{request.ruleId}')
    ->label('sdk', new Method(
        namespace: 'proxy',
        group: null,
        name: 'deleteRule',
        description: '/docs/references/proxy/delete-rule.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('ruleId', '', new UID(), 'Rule ID.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('queueForDeletes')
    ->inject('queueForEvents')
    ->action(function (string $ruleId, Response $response, Document $project, Database $dbForPlatform, Delete $queueForDeletes, Event $queueForEvents) {
        $rule = $dbForPlatform->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $dbForPlatform->deleteDocument('rules', $rule->getId());

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($rule);

        $queueForEvents->setParam('ruleId', $rule->getId());

        $response->noContent();
    });

App::patch('/v1/proxy/rules/:ruleId/verification')
    ->desc('Update rule verification status')
    ->groups(['api', 'proxy'])
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].update')
    ->label('audits.event', 'rule.update')
    ->label('audits.resource', 'rule/{response.$id}')
    ->label('sdk', new Method(
        namespace: 'proxy',
        group: null,
        name: 'updateRuleVerification',
        description: '/docs/references/proxy/update-rule-verification.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROXY_RULE,
            )
        ]
    ))
    ->param('ruleId', '', new UID(), 'Rule ID.')
    ->inject('response')
    ->inject('queueForCertificates')
    ->inject('queueForEvents')
    ->inject('project')
    ->inject('dbForPlatform')
    ->inject('log')
    ->action(function (string $ruleId, Response $response, Certificate $queueForCertificates, Event $queueForEvents, Document $project, Database $dbForPlatform, Log $log) {
        $rule = $dbForPlatform->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $target = new Domain(System::getEnv('_APP_DOMAIN_TARGET', ''));

        if (!$target->isKnown() || $target->isTest()) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Domain target must be configured as environment variable.');
        }

        if ($rule->getAttribute('verification') === true) {
            return $response->dynamic($rule, Response::MODEL_PROXY_RULE);
        }

        $validator = new CNAME($target->get()); // Verify Domain with DNS records
        $domain = new Domain($rule->getAttribute('domain', ''));

        $validationStart = \microtime(true);
        if (!$validator->isValid($domain->get())) {
            $log->addExtra('dnsTiming', \strval(\microtime(true) - $validationStart));
            $log->addTag('dnsDomain', $domain->get());

            $error = $validator->getLogs();
            $log->addExtra('dnsResponse', \is_array($error) ? \json_encode($error) : \strval($error));

            throw new Exception(Exception::RULE_VERIFICATION_FAILED);
        }

        $dbForPlatform->updateDocument('rules', $rule->getId(), $rule->setAttribute('status', 'verifying'));

        // Issue a TLS certificate when domain is verified
        $queueForCertificates
            ->setDomain(new Document([
                'domain' => $rule->getAttribute('domain')
            ]))
            ->trigger();

        $queueForEvents->setParam('ruleId', $rule->getId());

        $certificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', ''));
        $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    });
