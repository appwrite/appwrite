<?php

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Network\Validator\Domain as DomainValidator;
use Appwrite\Network\Validator\URL;
use Appwrite\Utopia\Database\Validator\Queries\Rules;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Database\ID;
use Utopia\Domains\Domain;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::post('/v1/proxy/rules')
    ->groups(['api', 'proxy'])
    ->desc('Create Rule')
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].create')
    ->label('audits.event', 'rule.create')
    ->label('audits.resource', 'rule/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'proxy')
    ->label('sdk.method', 'createRule')
    ->label('sdk.description', '/docs/references/proxy/create-rule.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROXY_RULE)
    ->param('domain', null, new DomainValidator(), 'Domain name.')
    ->param('resourceType', null, new WhiteList(['api', 'function']), 'Action definition for the rule. Possible values are "api", "function", or "redirect"')
    ->param('resourceId', '', new UID(), 'ID of resource for the action type. If resourceType is "api" or "url", leave empty. If resourceType is "function", provide ID of the function.', true)
    ->inject('response')
    ->inject('project')
    ->inject('events')
    ->inject('dbForConsole')
    ->inject('dbForProject')
    ->action(function (string $domain, string $resourceType, string $resourceId, Response $response, Document $project, Event $events, Database $dbForConsole, Database $dbForProject) {
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
                $message = "Domain already assigned to different project.";
            }

            throw new Exception(Exception::RULE_ALREADY_EXISTS, $message);
        }

        $resourceInternalId = '';

        if ($resourceType == 'function') {
            if (empty($resourceId)) {
                throw new Exception(Exception::RULE_RESOURCE_ID_MISSING);
            }

            $function = $dbForProject->getDocument('functions', $resourceId);

            if ($function->isEmpty()) {
                throw new Exception(Exception::RULE_RESOURCE_ID_NOT_FOUND);
            }

            $resourceInternalId = $function->getInternalId();
        }

        $domain = new Domain($domain);

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
        $functionsDomain = App::getEnv('_APP_DOMAIN_FUNCTIONS', 'disabled');
        if ($functionsDomain !== 'disabled' && \str_ends_with($domain->get(), $functionsDomain)) {
            $status = 'verified';
        }

        if ($status === 'created') {
            $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));
            $validator = new CNAME($target->get()); // Verify Domain with DNS records

            if ($validator->isValid($domain->get())) {
                $status = 'verifying';

                $event = new Certificate();
                $event
                    ->setDomain(new Document([
                        'domain' => $rule->getAttribute('domain')
                    ]))
                    ->trigger();
            }
        }

        $rule->setAttribute('status', $status);
        $rule = $dbForConsole->createDocument('rules', $rule);

        $events->setParam('ruleId', $rule->getId());

        $rule->setAttribute('logs', '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($rule, Response::MODEL_PROXY_RULE);
    });


App::get('/v1/proxy/rules')
    ->groups(['api', 'proxy'])
    ->desc('List Rules')
    ->label('scope', 'rules.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
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
        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        $queries[] = Query::equal('projectInternalId', [$project->getInternalId()]);

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
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

App::get('/v1/proxy/rules/:ruleId')
    ->groups(['api', 'proxy'])
    ->desc('Get Rule')
    ->label('scope', 'rules.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
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

App::delete('/v1/proxy/rules/:ruleId')
    ->groups(['api', 'proxy'])
    ->desc('Delete Rule')
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].delete')
    ->label('audits.event', 'rules.delete')
    ->label('audits.resource', 'rule/{request.ruleId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'proxy')
    ->label('sdk.method', 'deleteRule')
    ->label('sdk.description', '/docs/references/proxy/delete-rule.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('ruleId', '', new UID(), 'Rule ID.')
    ->inject('response')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('deletes')
    ->inject('events')
    ->action(function (string $ruleId, Response $response, Document $project, Database $dbForConsole, Delete $deletes, Event $events) {
        $rule = $dbForConsole->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        if (!$dbForConsole->deleteDocument('rules', $rule->getId())) {
            throw new Exception(Exception::RULE_CONFIGURATION_MISSING);
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($rule);

        $events->setParam('ruleId', $rule->getId());

        $response->noContent();
    });

App::patch('/v1/proxy/rules/:ruleId/verification')
    ->desc('Update Rule Verification Status')
    ->groups(['api', 'proxy'])
    ->label('scope', 'rules.write')
    ->label('event', 'rules.[ruleId].update')
    ->label('audits.event', 'rule.update')
    ->label('audits.resource', 'rule/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'proxy')
    ->label('sdk.method', 'updateRuleVerification')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROXY_RULE)
    ->param('ruleId', '', new UID(), 'Rule ID.')
    ->inject('response')
    ->inject('events')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $ruleId, Response $response, Event $events, Document $project, Database $dbForConsole) {
        $rule = $dbForConsole->getDocument('rules', $ruleId);

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getInternalId()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

        if (!$target->isKnown() || $target->isTest()) {
            throw new Exception(Exception::RULE_CONFIGURATION_MISSING);
        }

        if ($rule->getAttribute('verification') === true) {
            return $response->dynamic($rule, Response::MODEL_PROXY_RULE);
        }

        $validator = new CNAME($target->get()); // Verify Domain with DNS records
        $domain = new Domain($rule->getAttribute('domain', ''));

        if (!$validator->isValid($domain->get())) {
            throw new Exception(Exception::RULE_VERIFICATION_FAILED);
        }

        $dbForConsole->updateDocument('rules', $rule->getId(), $rule->setAttribute('status', 'verifying'));

        // Issue a TLS certificate when domain is verified
        $event = new Certificate();
        $event
            ->setDomain(new Document([
                'domain' => $rule->getAttribute('domain')
            ]))
            ->trigger();

        $events->setParam('ruleId', $rule->getId());

        $certificate = $dbForConsole->getDocument('certificates', $rule->getAttribute('certificateId', ''));
        $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    });
