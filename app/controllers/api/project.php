<?php

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Response;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DateTimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Http\Http;
use Utopia\Http\Validator\Text;
use Utopia\Http\Validator\WhiteList;

Http::get('/v1/project/usage')
    ->desc('Get project usage stats')
    ->groups(['api', 'usage'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_PROJECT)
    ->param('startDate', '', new DateTimeValidator(), 'Starting date for the usage')
    ->param('endDate', '', new DateTimeValidator(), 'End date for the usage')
    ->param('period', '1d', new WhiteList(['1h', '1d']), 'Period used', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('auth')
    ->action(function (string $startDate, string $endDate, string $period, Response $response, Database $dbForProject, Authorization $auth) {
        $stats = $total = $usage = [];
        $format = 'Y-m-d 00:00:00';
        $firstDay = (new DateTime($startDate))->format($format);
        $lastDay = (new DateTime($endDate))->format($format);

        $metrics = [
            'total' => [
                METRIC_EXECUTIONS,
                METRIC_DOCUMENTS,
                METRIC_DATABASES,
                METRIC_USERS,
                METRIC_BUCKETS,
                METRIC_FILES_STORAGE
            ],
            'period' => [
                METRIC_NETWORK_REQUESTS,
                METRIC_NETWORK_INBOUND,
                METRIC_NETWORK_OUTBOUND,
                METRIC_USERS,
                METRIC_EXECUTIONS
            ]
        ];

        $factor = match ($period) {
            '1h' => 3600,
            '1d' => 86400,
        };

        $limit = match ($period) {
            '1h' => (new DateTime($startDate))->diff(new DateTime($endDate))->days * 24,
            '1d' => (new DateTime($startDate))->diff(new DateTime($endDate))->days
        };

        $format = match ($period) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        $auth->skip(function () use ($dbForProject, $firstDay, $lastDay, $period, $metrics, &$total, &$stats) {
            foreach ($metrics['total'] as $metric) {
                $result = $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);
                $total[$metric] = $result['value'] ?? 0;
            }

            foreach ($metrics['period'] as $metric) {
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::greaterThanEqual('time', $firstDay),
                    Query::lessThan('time', $lastDay),
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

        $now = time();
        foreach ($metrics['period'] as $metric) {
            $usage[$metric] = [];
            $leap = $now - ($limit * $factor);
            while ($leap < $now) {
                $leap += $factor;
                $formatDate = date($format, $leap);
                $usage[$metric][] = [
                    'value' => $stats[$metric][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $executionsBreakdown = array_map(function ($function) use ($dbForProject) {
            $id = $function->getId();
            $name = $function->getAttribute('name');
            $metric = str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS);
            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('functions'));

        $bucketsBreakdown = array_map(function ($bucket) use ($dbForProject) {
            $id = $bucket->getId();
            $name = $bucket->getAttribute('name');
            $metric = str_replace('{bucketInternalId}', $bucket->getInternalId(), METRIC_BUCKET_ID_FILES_STORAGE);
            $value = $dbForProject->findOne('stats', [
                Query::equal('metric', [$metric]),
                Query::equal('period', ['inf'])
            ]);

            return [
                'resourceId' => $id,
                'name' => $name,
                'value' => $value['value'] ?? 0,
            ];
        }, $dbForProject->find('buckets'));

        // merge network inbound + outbound
        $projectBandwidth = [];
        foreach ($usage[METRIC_NETWORK_INBOUND] as $item) {
            $projectBandwidth[$item['date']] ??= 0;
            $projectBandwidth[$item['date']] += $item['value'];
        }

        foreach ($usage[METRIC_NETWORK_OUTBOUND] as $item) {
            $projectBandwidth[$item['date']] ??= 0;
            $projectBandwidth[$item['date']] += $item['value'];
        }


        $network = [];
        foreach ($projectBandwidth as $date => $value) {
            $network[] = [
                'date' => $date,
                'value' => $value
            ];
        }

        $response->dynamic(new Document([
            'requests' => ($usage[METRIC_NETWORK_REQUESTS]),
            'network' => $network,
            'users' => ($usage[METRIC_USERS]),
            'executions' => ($usage[METRIC_EXECUTIONS]),
            'executionsTotal' => $total[METRIC_EXECUTIONS],
            'documentsTotal' => $total[METRIC_DOCUMENTS],
            'databasesTotal' => $total[METRIC_DATABASES],
            'usersTotal' => $total[METRIC_USERS],
            'bucketsTotal' => $total[METRIC_BUCKETS],
            'filesStorageTotal' => $total[METRIC_FILES_STORAGE],
            'executionsBreakdown' => $executionsBreakdown,
            'bucketsBreakdown' => $bucketsBreakdown
        ]), Response::MODEL_USAGE_PROJECT);
    });


// Variables
Http::post('/v1/project/variables')
    ->desc('Create Variable')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('audits.event', 'variable.create')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'createVariable')
    ->label('sdk.description', '/docs/references/project/create-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE)
    ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.', false)
    ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', false)
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

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    });

Http::get('/v1/project/variables')
    ->desc('List Variables')
    ->groups(['api'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
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

Http::get('/v1/project/variables/:variableId')
    ->desc('Get Variable')
    ->groups(['api'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
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

Http::put('/v1/project/variables/:variableId')
    ->desc('Update Variable')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'project')
    ->label('sdk.method', 'updateVariable')
    ->label('sdk.description', '/docs/references/project/update-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE)
    ->param('variableId', '', new UID(), 'Variable unique ID.', false)
    ->param('key', null, new Text(255), 'Variable key. Max length: 255 chars.', false)
    ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', true)
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

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    });

Http::delete('/v1/project/variables/:variableId')
    ->desc('Delete Variable')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
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

        $dbForProject->deleteDocument('variables', $variable->getId());

        $functions = $dbForProject->find('functions', [
            Query::limit(APP_LIMIT_SUBQUERY)
        ]);

        foreach ($functions as $function) {
            $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));
        }

        $response->noContent();
    });
