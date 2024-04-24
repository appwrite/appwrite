<?php

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Utopia\Database\Validator\Queries\Rules;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Http\Http;
use Utopia\Http\Validator\Domain as ValidatorDomain;
use Utopia\Http\Validator\Text;
use Utopia\Http\Validator\WhiteList;
use Utopia\Logger\Log;
use Utopia\System\System;

Http::post('/v1/proxy/rules')
    ->groups(['api', 'proxy'])
    ->desc('Create Rule')
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].create')
    ->label('audits.event', 'rule.create')
    ->label('audits.resource', 'rule/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'proxy')
    ->label('sdk.method', 'createRule')
    ->label('sdk.description', '/docs/references/proxy/create-rule.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROXY_RULE)
    ->param('domain', null, new ValidatorDomain(), 'Domain name.')
    ->param('resourceType', null, new WhiteList(['api', 'function']), 'Action definition for the rule. Possible values are "api", "function"')
    ->param('resourceId', '', new UID(), 'ID of resource for the action type. If resourceType is "api", leave empty. If resourceType is "function", provide ID of the function.', true)
    ->inject('response')
    ->inject('project')
    ->inject('queueForCertificates')
    ->inject('queueForEvents')
    ->inject('dbForConsole')
    ->inject('dbForProject')
    ->action(function (string $domain, string $resourceType, string $resourceId, Response $response, Document $project, Certificate $queueForCertificates, Event $queueForEvents, Database $dbForConsole, Database $dbForProject) {
        $mainDomain = System::getEnv('_APP_DOMAIN', '');
        if ($domain === $mainDomain) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'You cannot assign your main domain to specific resource. Please use subdomain or a different domain.');
        }
        if ($domain === 'localhost' || $domain === APP_HOSTNAME_INTERNAL) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'This domain name is not allowed. Please pick another one.');
        }

        $document = $dbForConsole->findOne('rules', [
            Query::equal('domain', [$domain]),
        ]);

        if ($document && !$document->isEmpty()) {
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

        $ruleId = ID::unique();
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
        $rule = $dbForConsole->createDocument('rules', $rule);

        $queueForEvents->setParam('ruleId', $rule->getId());

        $rule->setAttribute('logs', '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    });

Http::get('/v1/proxy/rules')
    ->groups(['api', 'proxy'])
    ->desc('List Rules')
    ->label('scope', 'rules.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'proxy')
    ->label('sdk.method', 'listRules')
    ->label('sdk.description', '/docs/references/proxy/list-rules.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROXY_RULE_LIST)
    ->param('queries', [], new Rules(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Rules::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (array $queries, string $search, Response $response, Document $project, Database $dbForConsole) {
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
            $ruleId = $cursor->getValue();
            $cursorDocument = $dbForConsole->getDocument('rules', $ruleId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Rule '{$ruleId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $rules = $dbForConsole->find('rules', $queries);
        foreach ($rules as $rule) {
            $certificate = $dbForConsole->getDocument('certificates', $rule->getAttribute('certificateId', ''));
            $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));
            $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));
        }

        $response->dynamic(new Document([
            'rules' => $rules,
            'total' => $dbForConsole->count('rules', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_PROXY_RULE_LIST);
    });

Http::get('/v1/proxy/rules/:ruleId')
    ->groups(['api', 'proxy'])
    ->desc('Get Rule')
    ->label('scope', 'rules.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'proxy')
    ->label('sdk.method', 'getRule')
    ->label('sdk.description', '/docs/references/proxy/get-rule.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROXY_RULE)
    ->param('ruleId', '', new UID(), 'Rule ID.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $ruleId, Response $response, Document $project, Database $dbForConsole) {
        $rule = $dbForConsole->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $certificate = $dbForConsole->getDocument('certificates', $rule->getAttribute('certificateId', ''));
        $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));
        $rule->setAttribute('renewAt', $certificate->getAttribute('renewDate', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    });

Http::delete('/v1/proxy/rules/:ruleId')
    ->groups(['api', 'proxy'])
    ->desc('Delete Rule')
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].delete')
    ->label('audits.event', 'rules.delete')
    ->label('audits.resource', 'rule/{request.ruleId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'proxy')
    ->label('sdk.method', 'deleteRule')
    ->label('sdk.description', '/docs/references/proxy/delete-rule.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('ruleId', '', new UID(), 'Rule ID.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('queueForDeletes')
    ->inject('queueForEvents')
    ->action(function (string $ruleId, Response $response, Document $project, Database $dbForConsole, Delete $queueForDeletes, Event $queueForEvents) {
        $rule = $dbForConsole->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $dbForConsole->deleteDocument('rules', $rule->getId());

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($rule);

        $queueForEvents->setParam('ruleId', $rule->getId());

        $response->noContent();
    });

Http::patch('/v1/proxy/rules/:ruleId/verification')
    ->desc('Update Rule Verification Status')
    ->groups(['api', 'proxy'])
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].update')
    ->label('audits.event', 'rule.update')
    ->label('audits.resource', 'rule/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'proxy')
    ->label('sdk.method', 'updateRuleVerification')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROXY_RULE)
    ->param('ruleId', '', new UID(), 'Rule ID.')
    ->inject('response')
    ->inject('queueForCertificates')
    ->inject('queueForEvents')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('log')
    ->action(function (string $ruleId, Response $response, Certificate $queueForCertificates, Event $queueForEvents, Document $project, Database $dbForConsole, Log $log) {
        $rule = $dbForConsole->getDocument('rules', $ruleId);

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

        $dbForConsole->updateDocument('rules', $rule->getId(), $rule->setAttribute('status', 'verifying'));

        // Issue a TLS certificate when domain is verified
        $queueForCertificates
            ->setDomain(new Document([
                'domain' => $rule->getAttribute('domain')
            ]))
            ->trigger();

        $queueForEvents->setParam('ruleId', $rule->getId());

        $certificate = $dbForConsole->getDocument('certificates', $rule->getAttribute('certificateId', ''));
        $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    });
