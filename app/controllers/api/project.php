<?php

use Appwrite\Event\Delete;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Datetime as DateTimeValidator;
use Utopia\Database\DateTime;
use Utopia\Database\Validator\UID;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::get('/v1/project/usage')
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
    ->action(function (string $startDate, string $endDate, string $period, Response $response, Database $dbForProject) {
        $stats = $total = $usage = [];
        $format = 'Y-m-d 00:00:00';
        $firstDay = (new \DateTime($startDate))->format($format);
        $lastDay = (new \DateTime($endDate))->format($format);

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
            '1h' => (new \DateTime($startDate))->diff(new \DateTime($endDate))->days * 24,
            '1d' => (new \DateTime($startDate))->diff(new \DateTime($endDate))->days
        };

        $format = match ($period) {
            '1h' => 'Y-m-d\TH:00:00.000P',
            '1d' => 'Y-m-d\T00:00:00.000P',
        };

        Authorization::skip(function () use ($dbForProject, $firstDay, $lastDay, $period, $metrics, &$total, &$stats) {
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
App::post('/v1/project/variables')
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

App::get('/v1/project/variables')
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

App::get('/v1/project/variables/:variableId')
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

App::put('/v1/project/variables/:variableId')
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

App::delete('/v1/project/variables/:variableId')
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

App::post('/v1/project/backups-policy')
    ->desc('Create backup policy')
    ->groups(['api'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    //->label('event', 'backupPolicy.[functionId].create')
    ->label('audits.event', 'backupPolicy.create')
    ->label('audits.resource', 'backupPolicy/{response.$id}')
    ->label('sdk.namespace', 'backupPolicy')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/backups-policy/create-backup-policy.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BACKUP_POLICY)
    ->param('policyId', '', new CustomId(), 'Policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Backup name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(), 'Is policy enabled? When set to \'disabled\', No backup will be taken', false)
    ->param('retention', true, new Range(1, 30), 'Days to keep backups before deletion', false)
    ->param('hours', true, new Range(1, 168), 'Backup hours rotation', false)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $policyId, string $name, bool $enabled, int $retention, int $hours, \Utopia\Swoole\Request $request, Response $response, Database $dbForProject, Document $project, Database $dbForConsole) {
        $policyId = ($policyId == 'unique()') ? ID::unique() : $policyId;

        try {
            $policy = $dbForProject->createDocument('backupsPolicy', new Document([
                '$id' => $policyId,
                'name' => $name,
                'resourceType' => BACKUP_RESOURCE_PROJECT,
                'resourceId' => $project->getId(),
                'resourceInternalId' => $project->getInternalId(),
                'enabled' => $enabled,
                'retention' => $retention,
                'hours' => $hours,
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::BACKUP_POLICY_ALREADY_EXISTS);
        }

        $schedule = Authorization::skip(
            fn () => $dbForConsole->createDocument('schedules', new Document([
                'region' => App::getEnv('_APP_REGION', 'default'), // Todo replace with projects region
                'resourceType' => BACKUP_RESOURCE_PROJECT,
                'resourceId' => $project->getId(),
                'resourceInternalId' => $project->getInternalId(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                //'schedule'  => "0 */{$hours} * * *",
                'schedule'  => "* * * * *",
                'active' => $enabled,
            ]))
        );

        $policy->setAttribute('scheduleId', $schedule->getId());
        $policy->setAttribute('scheduleInternalId', $schedule->getInternalId());

        $policy = $dbForProject->updateDocument('backupsPolicy', $policy->getId(), $policy);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($policy, Response::MODEL_BACKUP_POLICY);
    });

App::get('/v1/project/backups-policy/:policyId')
    ->groups(['api'])
    ->label('scope', 'projects.read')
    ->desc('Get backup policy')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'backupPolicy')
    ->label('sdk.method', 'getBackupsPolicy')
    ->label('sdk.description', '/docs/references/databases/get-backups-policy.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BACKUP_POLICY)
    ->param('policyId', '', new CustomId(), 'Policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (string $policyId, \Utopia\Swoole\Request $request, Response $response, Database $dbForProject) {
        $policy = $dbForProject->getDocument('backupsPolicy', $policyId);
        if ($policy->isEmpty()) {
            throw new Exception(Exception::BACKUP_POLICY_NOT_FOUND);
        }

        $response->dynamic($policy, Response::MODEL_BACKUP_POLICY);
    });

App::patch('/v1/project/backups-policy/:policyId')
    ->groups(['api'])
    ->desc('Update backup policy')
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    //->label('event', 'backupPolicy.[functionId].create')
    ->label('audits.event', 'projects.updateBackupsPolicy')
    ->label('audits.resource', 'backupPolicy/{response.$id}')
    ->label('sdk.namespace', 'backupPolicy')
    ->label('sdk.method', 'updateBackupsPolicy')
    ->label('sdk.description', '/docs/references/databases/update-backups-policy.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BACKUP_POLICY)
    ->param('policyId', '', new CustomId(), 'Policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Backup name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(), 'Is policy enabled? When set to \'disabled\', No backup will be taken', false)
    ->param('retention', true, new Range(1, 30), 'Days to keep backups before deletion', false)
    ->param('hours', true, new Range(1, 168), 'Backup hours rotation', false)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $policyId, string $name, bool $enabled, int $retention, int $hours, \Utopia\Swoole\Request $request, Response $response, Database $dbForProject, Document $project, Database $dbForConsole) {
        $policy = $dbForProject->getDocument('backupsPolicy', $policyId);
        if ($policy->isEmpty()) {
            throw new Exception(Exception::BACKUP_POLICY_NOT_FOUND);
        }

        try {
            $policy = $dbForProject->updateDocument('backupsPolicy', $policy->getId(), new Document([
                '$id' => $policy->getId(),
                'name' => $name,
                'enabled' => $enabled,
                'retention' => $retention,
                'hours' => $hours,
            ]));
        } catch (DuplicateException) {
            throw new Exception(Exception::BACKUP_POLICY_ALREADY_EXISTS);
        }

        $schedule = $dbForConsole->getDocument('schedules', $policy->getAttribute('scheduleId'));
        if ($schedule->isEmpty()) {
            throw new Exception(Exception::SCHEDULE_NOT_FOUND);
        }

        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', "0 */{$hours} * * *")
            ->setAttribute('active', $enabled);

        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response->dynamic($policy, Response::MODEL_BACKUP_POLICY);
    });

App::get('/v1/project/backups-policy')
    ->groups(['api', 'database'])
    ->desc('Get project backups policy list')
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getBackupsPolicies')
    ->label('sdk.description', '/docs/references/databases/get-backups-policies.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BACKUP_POLICY_LIST)
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (array $queries, \Utopia\Swoole\Request $request, Response $response, Database $dbForProject) {
        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('resourceType', [BACKUP_RESOURCE_PROJECT]);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });

        $cursor = reset($cursor);

        if ($cursor) {
            /** @var Query $cursor */
            $policyId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('backupsPolicy', $policyId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Collection '{$policyId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $response->dynamic(new Document([
            'backupPolicies' => $dbForProject->find('backupsPolicy', $queries),
            'total' => $dbForProject->count('backupsPolicy', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_BACKUP_POLICY_LIST);
    });

App::delete('/v1/project/backups-policy/:policyId')
    ->groups(['api', 'database'])
    ->desc('delete backups policy')
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    //->label('event', 'backupPolicy.[functionId].delete')
    ->label('audits.event', 'backups.deletePolicy')
    ->label('audits.resource', 'backupPolicy/{response.$id}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'deleteBackupsPolicy')
    ->label('sdk.description', '/docs/references/databases/delete-backups-policy.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('policyId', '', new CustomId(), 'Policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('dbForConsole')
    ->inject('queueForDeletes')
    ->action(function (string $policyId, \Utopia\Swoole\Request $request, Response $response, Database $dbForProject, Document $project, Database $dbForConsole, Delete $queueForDeletes) {
        //Todo what you do we do with backups when a db is deleted?
        $policy = $dbForProject->getDocument('backupsPolicy', $policyId);

        if ($policy->isEmpty()) {
            throw new Exception(Exception::BACKUP_POLICY_NOT_FOUND);
        }

        try {
            Authorization::skip(fn () =>  $dbForProject->deleteDocument('backupsPolicy', $policyId));
            Authorization::skip(fn () => $dbForConsole->deleteDocument('schedules', $policy->getAttribute('scheduleId')));
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (RestrictedException) {
            throw new Exception(Exception::DOCUMENT_DELETE_RESTRICTED);
        }

        $queueForDeletes
            ->setType(DELETE_TYPE_BACKUPS)
            ->setDocument($policy);

        $response->noContent();
    });

App::get('/v1/project/backups-policy/:policyId/backups')
    ->groups(['api'])
    ->desc('Get database backups by policy id')
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'getBackups')
    ->label('sdk.description', '/docs/references/databases/get-backups.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_BACKUP_LIST)
    ->param('policyId', '', new CustomId(), 'Policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('queries', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (string $policyId, array $queries, \Utopia\Swoole\Request $request, Response $response, Database $dbForProject) {
        $policy = $dbForProject->getDocument('backupsPolicy', $policyId);

        if ($policy->isEmpty()) {
            throw new Exception(Exception::BACKUP_POLICY_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('policyInternalId', [$policy->getInternalId()]);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $backupId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('backups', $backupId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Collection '{$backupId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $response->dynamic(new Document([
            'backups' => $dbForProject->find('backups', $queries),
            'total' => $dbForProject->count('backups', $queries, APP_LIMIT_COUNT),
        ]), Response::MODEL_BACKUP_LIST);
    });

App::delete('/v1/project/:databaseId/backups/:backupId')
    ->groups(['api'])
    ->desc('delete database backup')
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    //->label('event', 'backupPolicy.[functionId].delete')
    ->label('audits.event', 'backup.delete')
    ->label('audits.resource', 'backup/{response.$id}')
    ->label('abuse-key', 'ip:{ip},method:{method},url:{url},userId:{userId}')
    ->label('abuse-limit', APP_LIMIT_WRITE_RATE_DEFAULT)
    ->label('abuse-time', APP_LIMIT_WRITE_RATE_PERIOD_DEFAULT)
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'databases')
    ->label('sdk.method', 'deleteBackup')
    ->label('sdk.description', '/docs/references/databases/delete-backup.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('databaseId', '', new UID(), 'Database ID.')
    ->param('backupId', '', new CustomId(), 'Policy ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $backupId, \Utopia\Swoole\Request $request, Response $response, Database $dbForProject) {
        $backup = $dbForProject->getDocument('backups', $backupId);

        if ($backup->isEmpty()) {
            throw new Exception(Exception::BACKUP_NOT_FOUND);
        }

        // todo worker delete the backup relevant files

        try {
            Authorization::skip(fn () =>  $dbForProject->deleteDocument('backups', $backupId));
        } catch (AuthorizationException) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        } catch (RestrictedException) {
            throw new Exception(Exception::DOCUMENT_DELETE_RESTRICTED);
        }

        $response->noContent();
    });
