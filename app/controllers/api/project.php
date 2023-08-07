<?php

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::get('/v1/project/usage')
    ->desc('Get usage stats for a project')
    ->groups(['api', 'usage'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_PROJECT)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, Response $response, Database $dbForProject) {

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_NETWORK_REQUESTS,
            METRIC_NETWORK_INBOUND,
            METRIC_NETWORK_OUTBOUND,
            METRIC_EXECUTIONS,
            METRIC_DOCUMENTS,
            METRIC_DATABASES,
            METRIC_USERS,
            METRIC_BUCKETS,
            METRIC_FILES_STORAGE
        ];

        Authorization::skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('period', [$period]),
                    Query::equal('metric', [$metric]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);

                $stats[$metric] = [];
                foreach ($results as $result) {
                    $stats[$metric][$result->getAttribute('time')] = [
                        'value' => $result->getAttribute('value'),
                    ];
                }
            }
        });


        $format = match ($days['period']) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

    foreach ($metrics as $metric) {
        $usage[$metric] = [];
        $leap = time() - ($days['limit'] * $days['factor']);
        while ($leap < time()) {
            $leap += $days['factor'];
            $formatDate = date($format, $leap);
            $usage[$metric][] = [
                'value' => $stats[$metric][$formatDate]['value'] ?? 0,
                'date' => $formatDate,
            ];
        }
    }


        $response->dynamic(new Document([
            'range' => $range,
            'requestsTotal' => ($usage[$metrics[0]]),
            'network' => ($usage[$metrics[1]] + $usage[$metrics[2]]),
            'executionsTotal' => $usage[$metrics[3]],
            'documentsTotal' => $usage[$metrics[4]],
            'databasesTotal' => $usage[$metrics[5]],
            'usersTotal' => $usage[$metrics[6]],
            'bucketsTotal' => $usage[$metrics[7]],
            'filesStorage' => $usage[$metrics[8]],
        ]), Response::MODEL_USAGE_PROJECT);
    });


// Variables

App::post('/v1/project/variables')
    ->desc('Create Variable')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('audits.event', 'variable.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'createVariable')
    ->label('sdk.description', '/docs/references/project/create-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE)
    ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.', false)
    ->param('value', null, new Text(8192), 'Variable value. Max length: 8192 chars.', false)
    ->inject('project')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (string $key, string $value, Document $project, Response $response, Database $dbForProject, Database $dbForConsole) {
        $variableId = ID::unique();

        $variable = new Document([
            '$id' => $variableId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'resourceInternalId' => '',
            'resourceId' => '',
            'resourceType' => 'project',
            'key' => $key,
            'value' => $value,
            'search' => implode(' ', [$variableId, $key, 'project']),
        ]);

        try {
            $variable = $dbForProject->createDocument('variables', $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }
        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $dbForProject->deleteCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::get('/v1/project/variables')
    ->desc('List Variables')
    ->groups(['api'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'listVariables')
    ->label('sdk.description', '/docs/references/project/list-variables.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE_LIST)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (Response $response, Database $dbForProject) {
        $variables = $dbForProject->find('variables', [
            Query::equal('resourceType', ['project']),
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        $response->dynamic(new Document([
            'variables' => $variables,
            'total' => \count($variables),
        ]), Response::MODEL_VARIABLE_LIST);
    });

App::get('/v1/project/variables/:variableId')
    ->desc('Get Variable')
    ->groups(['api'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'getVariable')
    ->label('sdk.description', '/docs/references/project/get-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE)
    ->param('variableId', '', new UID(), 'Variable unique ID.', false)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->action(function (string $variableId, Response $response, Document $project, Database $dbForProject) {
        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceType') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::put('/v1/project/variables/:variableId')
    ->desc('Update Variable')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'updateVariable')
    ->label('sdk.description', '/docs/references/project/update-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE)
    ->param('variableId', '', new UID(), 'Variable unique ID.', false)
    ->param('key', null, new Text(255), 'Variable key. Max length: 255 chars.', false)
    ->param('value', null, new Text(8192), 'Variable value. Max length: 8192 chars.', true)
    ->inject('project')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (string $variableId, string $key, ?string $value, Document $project, Response $response, Database $dbForProject, Database $dbForConsole) {
        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceType') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $variable
            ->setAttribute('key', $key)
            ->setAttribute('value', $value ?? $variable->getAttribute('value'))
            ->setAttribute('search', implode(' ', [$variableId, $key, 'project']));

        try {
            $dbForProject->updateDocument('variables', $variable->getId(), $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }
        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $dbForProject->deleteCachedDocument('projects', $project->getId());

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::delete('/v1/project/variables/:variableId')
    ->desc('Delete Variable')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'deleteVariable')
    ->label('sdk.description', '/docs/references/project/delete-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('variableId', '', new UID(), 'Variable unique ID.', false)
    ->inject('project')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $variableId, Document $project, Response $response, Database $dbForProject) {
        $variable = $dbForProject->getDocument('variables', $variableId);
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceType') !== 'project') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $dbForProject->deleteDocument('variables', $variable->getId());
        $dbForProject->deleteCachedDocument('projects', $project->getId());

        $response->noContent();
    });
