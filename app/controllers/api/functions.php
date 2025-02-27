<?php

use Appwrite\Event\Event;
use Appwrite\Extend\Exception;
use Appwrite\Platform\Tasks\ScheduleExecutions;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

include_once __DIR__ . '/../shared/api.php';

App::delete('/v1/functions/:functionId/executions/:executionId')
    ->groups(['api', 'functions'])
    ->desc('Delete execution')
    ->label('scope', 'execution.write')
    ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
    ->label('event', 'functions.[functionId].executions.[executionId].delete')
    ->label('audits.event', 'executions.delete')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk', new Method(
        namespace: 'functions',
        name: 'deleteExecution',
        description: '/docs/references/functions/delete-execution.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('executionId', '', new UID(), 'Execution ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->inject('queueForEvents')
    ->action(function (string $functionId, string $executionId, Response $response, Database $dbForProject, Database $dbForPlatform, Event $queueForEvents) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $execution = $dbForProject->getDocument('executions', $executionId);
        if ($execution->isEmpty()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        if ($execution->getAttribute('resourceType') !== 'functions' && $execution->getAttribute('resourceInternalId') !== $function->getInternalId()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }
        $status = $execution->getAttribute('status');

        if (!in_array($status, ['completed', 'failed', 'scheduled'])) {
            throw new Exception(Exception::EXECUTION_IN_PROGRESS);
        }

        if (!$dbForProject->deleteDocument('executions', $execution->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove execution from DB');
        }

        if ($status === 'scheduled') {
            $schedule = $dbForPlatform->findOne('schedules', [
                Query::equal('resourceId', [$execution->getId()]),
                Query::equal('resourceType', [ScheduleExecutions::getSupportedResource()]),
                Query::equal('active', [true]),
            ]);

            if (!$schedule->isEmpty()) {
                $schedule
                    ->setAttribute('resourceUpdatedAt', DateTime::now())
                    ->setAttribute('active', false);

                Authorization::skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));
            }
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setPayload($response->output($execution, Response::MODEL_EXECUTION));

        $response->noContent();
    });

// Variables

App::post('/v1/functions/:functionId/variables')
    ->desc('Create variable')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.write')
    ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
    ->label('audits.event', 'variable.create')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk', new Method(
        namespace: 'functions',
        name: 'createVariable',
        description: '/docs/references/functions/create-variable.md',
        auth: [AuthType::KEY],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_VARIABLE,
            )
        ]
    ))
    ->param('functionId', '', new UID(), 'Function unique ID.', false)
    ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.', false)
    ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', false)
    ->param('secret', true, new Boolean(), 'Secret variables can be updated or deleted, but only functions can read them during build and runtime.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->action(function (string $functionId, string $key, string $value, bool $secret, Response $response, Database $dbForProject, Database $dbForPlatform) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $variableId = ID::unique();

        $variable = new Document([
            '$id' => $variableId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceInternalId' => $function->getInternalId(),
            'resourceId' => $function->getId(),
            'resourceType' => 'function',
            'key' => $key,
            'value' => $value,
            'secret' => $secret,
            'search' => implode(' ', [$variableId, $function->getId(), $key, 'function']),
        ]);

        try {
            $variable = $dbForProject->createDocument('variables', $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForPlatform->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::get('/v1/functions/:functionId/variables')
    ->desc('List variables')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
    ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
    ->label(
        'sdk',
        new Method(
            namespace: 'functions',
            name: 'listVariables',
            description: '/docs/references/functions/list-variables.md',
            auth: [AuthType::KEY],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_VARIABLE_LIST,
                )
            ],
        )
    )
    ->param('functionId', '', new UID(), 'Function unique ID.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, Response $response, Database $dbForProject) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'variables' => $function->getAttribute('vars', []),
            'total' => \count($function->getAttribute('vars', [])),
        ]), Response::MODEL_VARIABLE_LIST);
    });

App::get('/v1/functions/:functionId/variables/:variableId')
    ->desc('Get variable')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
    ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getVariable')
    ->label('sdk.description', '/docs/references/functions/get-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE)
    ->param('functionId', '', new UID(), 'Function unique ID.', false)
    ->param('variableId', '', new UID(), 'Variable unique ID.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, string $variableId, Response $response, Database $dbForProject) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);
        if (
            $variable === false ||
            $variable->isEmpty() ||
            $variable->getAttribute('resourceInternalId') !== $function->getInternalId() ||
            $variable->getAttribute('resourceType') !== 'function'
        ) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable === false || $variable->isEmpty()) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::put('/v1/functions/:functionId/variables/:variableId')
    ->desc('Update variable')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.write')
    ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
    ->label('audits.event', 'variable.update')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'updateVariable')
    ->label('sdk.description', '/docs/references/functions/update-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE)
    ->param('functionId', '', new UID(), 'Function unique ID.', false)
    ->param('variableId', '', new UID(), 'Variable unique ID.', false)
    ->param('key', null, new Text(255), 'Variable key. Max length: 255 chars.', false)
    ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', true)
    ->param('secret', null, new Boolean(), 'Secret variables can be updated or deleted, but only functions can read them during build and runtime.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->action(function (string $functionId, string $variableId, string $key, ?string $value, ?bool $secret, Response $response, Database $dbForProject, Database $dbForPlatform) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceInternalId') !== $function->getInternalId() || $variable->getAttribute('resourceType') !== 'function') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable === false || $variable->isEmpty()) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable->getAttribute('secret') === true && $secret === false) {
            throw new Exception(Exception::VARIABLE_CANNOT_UNSET_SECRET);
        }

        $variable
            ->setAttribute('key', $key)
            ->setAttribute('value', $value ?? $variable->getAttribute('value'))
            ->setAttribute('secret', $secret ?? $variable->getAttribute('secret'))
            ->setAttribute('search', implode(' ', [$variableId, $function->getId(), $key, 'function']));

        try {
            $dbForProject->updateDocument('variables', $variable->getId(), $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForPlatform->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::delete('/v1/functions/:functionId/variables/:variableId')
    ->desc('Delete variable')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.write')
    ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
    ->label('audits.event', 'variable.delete')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'deleteVariable')
    ->label('sdk.description', '/docs/references/functions/delete-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function unique ID.', false)
    ->param('variableId', '', new UID(), 'Variable unique ID.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForPlatform')
    ->action(function (string $functionId, string $variableId, Response $response, Database $dbForProject, Database $dbForPlatform) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceInternalId') !== $function->getInternalId() || $variable->getAttribute('resourceType') !== 'function') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable === false || $variable->isEmpty()) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $dbForProject->deleteDocument('variables', $variable->getId());

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForPlatform->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForPlatform->updateDocument('schedules', $schedule->getId(), $schedule));

        $response->noContent();
    });

App::get('/v1/functions/templates')
    ->groups(['api'])
    ->desc('List function templates')
    ->label('scope', 'public')
    ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
    ->label('sdk', new Method(
        namespace: 'functions',
        name: 'listTemplates',
        description: '/docs/references/functions/list-templates.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TEMPLATE_FUNCTION_LIST,
            )
        ]
    ))
    ->param('runtimes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('runtimes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of runtimes allowed for filtering function templates. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' runtimes are allowed.', true)
    ->param('useCases', [], new ArrayList(new WhiteList(['dev-tools','starter','databases','ai','messaging','utilities']), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of use cases allowed for filtering function templates. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' use cases are allowed.', true)
    ->param('limit', 25, new Range(1, 5000), 'Limit the number of templates returned in the response. Default limit is 25, and maximum limit is 5000.', true)
    ->param('offset', 0, new Range(0, 5000), 'Offset the list of returned templates. Maximum offset is 5000.', true)
    ->inject('response')
    ->action(function (array $runtimes, array $usecases, int $limit, int $offset, Response $response) {
        $templates = Config::getParam('function-templates', []);

        if (!empty($runtimes)) {
            $templates = \array_filter($templates, function ($template) use ($runtimes) {
                return \count(\array_intersect($runtimes, \array_column($template['runtimes'], 'name'))) > 0;
            });
        }

        if (!empty($usecases)) {
            $templates = \array_filter($templates, function ($template) use ($usecases) {
                return \count(\array_intersect($usecases, $template['useCases'])) > 0;
            });
        }

        $responseTemplates = \array_slice($templates, $offset, $limit);
        $response->dynamic(new Document([
            'templates' => $responseTemplates,
            'total' => \count($responseTemplates),
        ]), Response::MODEL_TEMPLATE_FUNCTION_LIST);
    });

App::get('/v1/functions/templates/:templateId')
    ->desc('Get function template')
    ->label('scope', 'public')
    ->label('resourceType', RESOURCE_TYPE_FUNCTIONS)
    ->label('sdk', new Method(
        namespace: 'functions',
        name: 'getTemplate',
        description: '/docs/references/functions/get-template.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_TEMPLATE_FUNCTION,
            )
        ]
    ))
    ->param('templateId', '', new Text(128), 'Template ID.')
    ->inject('response')
    ->action(function (string $templateId, Response $response) {
        $templates = Config::getParam('function-templates', []);

        $filtered = \array_filter($templates, function ($template) use ($templateId) {
            return $template['id'] === $templateId;
        });

        $template = array_shift($filtered);

        if (empty($template)) {
            throw new Exception(Exception::FUNCTION_TEMPLATE_NOT_FOUND);
        }

        $response->dynamic(new Document($template), Response::MODEL_TEMPLATE_FUNCTION);
    });
