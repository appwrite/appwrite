<?php

use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Network\Validator\DNS;
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
use Utopia\Database\Query;
use Utopia\Database\Validator\Query\Cursor;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Logger\Log;
use Utopia\System\System;
use Utopia\Validator\AnyOf;
use Utopia\Validator\IP;
use Utopia\Validator\Text;

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

        $queries[] = Query::equal('projectInternalId', [$project->getSequence()]);

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

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
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

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
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

        if ($rule->isEmpty() || $rule->getAttribute('projectInternalId') !== $project->getSequence()) {
            throw new Exception(Exception::RULE_NOT_FOUND);
        }

        $targetCNAME = null;
        switch ($rule->getAttribute('type', '')) {
            case 'api':
                // For example: fra.cloud.appwrite.io
                $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_TARGET_CNAME', ''));
                break;
            case 'redirect':
                // For example: appwrite.network
                $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_SITES', ''));
                break;
            case 'deployment':
                switch ($rule->getAttribute('deploymentResourceType', '')) {
                    case 'function':
                        // For example: fra.appwrite.run
                        $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_FUNCTIONS', ''));
                        break;
                    case 'site':
                        // For example: appwrite.network
                        $targetCNAME = new Domain(System::getEnv('_APP_DOMAIN_SITES', ''));
                        break;
                    default:
                        break;
                }
                // no break
            default:
                break;
        }

        $validators = [];

        if (!is_null($targetCNAME)) {
            if ($targetCNAME->isKnown() && !$targetCNAME->isTest()) {
                $validators[] = new DNS($targetCNAME->get(), DNS::RECORD_CNAME);
            }
        }

        if ((new IP(IP::V4))->isValid(System::getEnv('_APP_DOMAIN_TARGET_A', ''))) {
            $validators[] = new DNS(System::getEnv('_APP_DOMAIN_TARGET_A', ''), DNS::RECORD_A);
        }
        if ((new IP(IP::V6))->isValid(System::getEnv('_APP_DOMAIN_TARGET_AAAA', ''))) {
            $validators[] = new DNS(System::getEnv('_APP_DOMAIN_TARGET_AAAA', ''), DNS::RECORD_AAAA);
        }

        // Validate CAA records if configured
        $caaTarget = System::getEnv('_APP_DOMAIN_TARGET_CAA', '');
        if (!empty($caaTarget)) {
            $validators[] = new DNS($caaTarget, DNS::RECORD_CAA);
        }

        if (empty($validators)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'At least one of domain targets environment variable must be configured.');
        }

        if ($rule->getAttribute('verification') === true) {
            return $response->dynamic($rule, Response::MODEL_PROXY_RULE);
        }

        $validator = new AnyOf($validators, AnyOf::TYPE_STRING);
        $domain = new Domain($rule->getAttribute('domain', ''));

        $validationStart = \microtime(true);
        if (!$validator->isValid($domain->get())) {
            $log->addExtra('dnsTiming', \strval(\microtime(true) - $validationStart));
            $log->addTag('dnsDomain', $domain->get());

            $errors = [];
            foreach ($validators as $validator) {
                if (!empty($validator->getLogs())) {
                    $errors[] = $validator->getLogs();
                }
            }

            $error = \implode("\n", $errors);
            $log->addExtra('dnsResponse', \is_array($error) ? \json_encode($error) : \strval($error));

            throw new Exception(Exception::RULE_VERIFICATION_FAILED);
        }

        if (!empty($caaTarget)) {
            try {
                $caaRecords = \dns_get_record($domain->get(), DNS_CAA);
                var_dump("in proxy.php");
                var_dump($caaRecords);

                $foundValidCAA = false;

                foreach ($caaRecords as $record) {
                    if (isset($record['value'])) {
                        $caaValue = $record['value'];

                        if ($caaValue === $caaTarget) {
                            $foundValidCAA = true;
                            break;
                        }
                    }
                }

                if (!$foundValidCAA) {
                    if (empty($caaRecords)) {
                        throw new Exception('CAA records are required but not found for domain. Expected: ' . $caaTarget);
                    } else {
                        throw new Exception('CAA record does not match expected value. Expected: ' . $caaTarget . ', Found: ' . implode(', ', array_map(function ($record) { return $record['value'] ?? 'unknown'; }, $caaRecords)));
                    }
                }

                $log->addExtra('caaValidation', 'CAA records validated successfully');
            } catch (\Throwable $th) {
                $log->addExtra('caaValidationError', $th->getMessage());
                throw new Exception(Exception::RULE_VERIFICATION_FAILED, 'CAA validation failed: ' . $th->getMessage());
            }
        }

        $dbForPlatform->updateDocument('rules', $rule->getId(), $rule->setAttribute('status', 'verifying'));

        // Issue a TLS certificate when domain is verified
        $queueForCertificates
            ->setDomain(new Document([
                'domain' => $rule->getAttribute('domain'),
                'domainType' => $rule->getAttribute('deploymentResourceType', $rule->getAttribute('type')),
            ]))
            ->trigger();

        $queueForEvents->setParam('ruleId', $rule->getId());

        $certificate = $dbForPlatform->getDocument('certificates', $rule->getAttribute('certificateId', ''));
        $rule->setAttribute('logs', $certificate->getAttribute('logs', ''));

        $response->dynamic($rule, Response::MODEL_PROXY_RULE);
    });
