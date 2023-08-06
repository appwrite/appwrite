<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Event\Build;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Usage;
use Appwrite\Event\Validator\Event as ValidatorEvent;
use Appwrite\Utopia\Response\Model\Rule;
use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Messaging\Adapter\Realtime;
use Utopia\Validator\Assoc;
use Appwrite\Usage\Stats;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\UID;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Appwrite\Utopia\Response;
use Utopia\Swoole\Request;
use Appwrite\Task\Validator\Cron;
use Appwrite\Utopia\Database\Validator\Queries\Deployments;
use Appwrite\Utopia\Database\Validator\Queries\Executions;
use Appwrite\Utopia\Database\Validator\Queries\Functions;
use Utopia\App;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\DateTime;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\WhiteList;
use Utopia\Config\Config;
use Executor\Executor;
use Utopia\CLI\Console;
use Utopia\Database\Validator\Roles;
use Utopia\Validator\Boolean;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use MaxMind\Db\Reader;

include_once __DIR__ . '/../shared/api.php';

$redeployVcs = function (Request $request, Document $function, Document $project, Document $installation, Database $dbForProject, Document $template) {
    $deploymentId = ID::unique();
    $entrypoint = $function->getAttribute('entrypoint', '');
    $deployment = $dbForProject->createDocument('deployments', new Document([
        '$id' => $deploymentId,
        '$permissions' => [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ],
        'resourceId' => $function->getId(),
        'resourceType' => 'functions',
        'entrypoint' => $entrypoint,
        'commands' => $function->getAttribute('commands', ''),
        'type' => 'vcs',
        'installationId' => $installation->getId(),
        'installationInternalId' => $installation->getInternalId(),
        'providerRepositoryId' => $function->getAttribute('providerRepositoryId', ''),
        'repositoryId' => $function->getAttribute('repositoryId', ''),
        'repositoryInternalId' => $function->getAttribute('repositoryInternalId', ''),
        'providerBranch' => $function->getAttribute('providerBranch', 'main'),
        'providerRootDirectory' => $function->getAttribute('providerRootDirectory', ''),
        'search' => implode(' ', [$deploymentId, $entrypoint]),
        'activate' => true,
    ]));

    $projectId = $project->getId();
    $functionId = $function->getId();

    $providerTargetUrl = $request->getProtocol() . '://' . $request->getHostname() . "/console/project-$projectId/functions/function-$functionId";

    $buildEvent = new Build();
    $buildEvent
        ->setType(BUILD_TYPE_DEPLOYMENT)
        ->setResource($function)
        ->setDeployment($deployment)
        ->setProviderTargetUrl($providerTargetUrl)
        ->setTemplate($template)
        ->setProject($project)
        ->trigger();
};

App::post('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('Create Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].create')
    ->label('audits.event', 'function.create')
    ->label('audits.resource', 'function/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/functions/create-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new CustomId(), 'Function ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('runtime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Execution runtime.')
    ->param('execute', [], new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of strings with execution roles. By default no user is granted with any execute permissions. [learn more about permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
    ->param('events', [], new ArrayList(new ValidatorEvent(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Function maximum execution time in seconds.', true)
    ->param('enabled', true, new Boolean(), 'Is function enabled?', true)
    ->param('logging', true, new Boolean(), 'Do executions get logged?', true)
    ->param('entrypoint', '', new Text('1028'), 'Entrypoint File.')
    ->param('commands', '', new Text('1028'), 'Build Commands.', true)
    ->param('installationId', '', new Text(128), 'Appwrite Installation ID for vcs deployment.', true)
    ->param('providerRepositoryId', '', new Text(128), 'Repository ID of the repo linked to the function', true)
    ->param('providerBranch', '', new Text(128), 'Production branch for the repo linked to the function', true)
    ->param('providerSilentMode', false, new Boolean(), 'Is VCS connection in silent mode for the repo linked to the function?', true)
    ->param('providerRootDirectory', '', new Text(128, 0), 'Path to function code in the linked repo', true)
    ->param('templateRepository', '', new Text(128), 'Repository name of the template', true)
    ->param('templateOwner', '', new Text(128), 'Owner name of the template', true)
    ->param('templateRootDirectory', '', new Text(128), 'Path to function code in the template repo', true)
    ->param('templateBranch', '', new Text(128), 'Branch of template repo with the code', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('events')
    ->inject('dbForConsole')
    ->action(function (string $functionId, string $name, string $runtime, array $execute, array $events, string $schedule, int $timeout, bool $enabled, bool $logging, string $entrypoint, string $commands, string $installationId, string $providerRepositoryId, string $providerBranch, bool $providerSilentMode, string $providerRootDirectory, string $templateRepository, string $templateOwner, string $templateRootDirectory, string $templateBranch, Request $request, Response $response, Database $dbForProject, Document $project, Document $user, Event $eventsInstance, Database $dbForConsole) use ($redeployVcs) {
        $functionId = ($functionId == 'unique()') ? ID::unique() : $functionId;

        // build from template
        $template = new Document([]);
        if (!empty($templateRepository) && !empty($templateOwner) && !empty($templateRootDirectory) && !empty($templateBranch)) {
            $template->setAttribute('repositoryName', $templateRepository)
                ->setAttribute('ownerName', $templateOwner)
                ->setAttribute('rootDirectory', $templateRootDirectory)
                ->setAttribute('branch', $templateBranch);
        }

        $installation = $dbForConsole->getDocument('installations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if (!empty($installationId) && $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!empty($providerRepositoryId) && (empty($installationId) || empty($providerBranch))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
        }

        $function = $dbForProject->createDocument('functions', new Document([
            '$id' => $functionId,
            'execute' => $execute,
            'enabled' => $enabled,
            'live' => true,
            'logging' => $logging,
            'name' => $name,
            'runtime' => $runtime,
            'deploymentInternalId' => '',
            'deployment' => '',
            'events' => $events,
            'schedule' => $schedule,
            'scheduleInternalId' => '',
            'timeout' => $timeout,
            'entrypoint' => $entrypoint,
            'commands' => $commands,
            'search' => implode(' ', [$functionId, $name, $runtime]),
            'version' => 'v3',
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getInternalId(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => '',
            'repositoryInternalId' => '',
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $providerRootDirectory,
            'providerSilentMode' => $providerSilentMode,
        ]));

        // Git connect logic
        if (!empty($providerRepositoryId)) {
            $repository = $dbForConsole->createDocument('repositories', new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ],
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getInternalId(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getInternalId(),
                'providerRepositoryId' => $providerRepositoryId,
                'resourceId' => $function->getId(),
                'resourceInternalId' => $function->getInternalId(),
                'resourceType' => 'function',
                'providerPullRequestIds' => []
            ]));

            $function = $dbForProject->updateDocument('functions', $function->getId(), $function
                ->setAttribute('repositoryId', $repository->getId())
                ->setAttribute('repositoryInternalId', $repository->getInternalId()));
        }

        $schedule = Authorization::skip(
            fn () => $dbForConsole->createDocument('schedules', new Document([
                'region' => App::getEnv('_APP_REGION', 'default'), // Todo replace with projects region
                'resourceType' => 'function',
                'resourceId' => $function->getId(),
                'resourceInternalId' => $function->getInternalId(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule'  => $function->getAttribute('schedule'),
                'active' => false,
            ]))
        );

        // Redeploy vcs logic
        if (!empty($providerRepositoryId)) {
            $redeployVcs($request, $function, $project, $installation, $dbForProject, $template);
        }

        $functionsDomain = App::getEnv('_APP_DOMAIN_FUNCTIONS', '');
        if (!empty($functionsDomain)) {
            $ruleId = ID::unique();
            $routeSubdomain = ID::unique();
            $domain = "{$routeSubdomain}.{$functionsDomain}";

            $rule = Authorization::skip(
                fn () => $dbForConsole->createDocument('rules', new Document([
                    '$id' => $ruleId,
                    'projectId' => $project->getId(),
                    'projectInternalId' => $project->getInternalId(),
                    'domain' => $domain,
                    'resourceType' => 'function',
                    'resourceId' => $function->getId(),
                    'resourceInternalId' => $function->getInternalId(),
                    'status' => 'verified',
                    'certificateId' => '',
                ]))
            );

            /** Trigger Webhook */
            $ruleModel = new Rule();
            $ruleCreate = new Event(Event::WEBHOOK_QUEUE_NAME, Event::WEBHOOK_CLASS_NAME);
            $ruleCreate
                ->setProject($project)
                ->setEvent('rules.[ruleId].create')
                ->setParam('ruleId', $rule->getId())
                ->setPayload($rule->getArrayCopy(array_keys($ruleModel->getRules())))
                ->trigger();

            /** Trigger Functions */
            $ruleCreate
                ->setClass(Event::FUNCTIONS_CLASS_NAME)
                ->setQueue(Event::FUNCTIONS_QUEUE_NAME)
                ->trigger();

            /** Trigger realtime event */
            $allEvents = Event::generateEvents('rules.[ruleId].create', [
                'ruleId' => $rule->getId(),
            ]);
            $target = Realtime::fromPayload(
                // Pass first, most verbose event pattern
                event: $allEvents[0],
                payload: $rule,
                project: $project
            );
            Realtime::send(
                projectId: 'console',
                payload: $rule->getArrayCopy(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles']
            );
            Realtime::send(
                projectId: $project->getId(),
                payload: $rule->getArrayCopy(),
                events: $allEvents,
                channels: $target['channels'],
                roles: $target['roles']
            );
        }

        $function->setAttribute('scheduleId', $schedule->getId());
        $function->setAttribute('scheduleInternalId', $schedule->getInternalId());
        $dbForProject->updateDocument('functions', $function->getId(), $function);

        $eventsInstance->setParam('functionId', $function->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('List Functions')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/functions/list-functions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION_LIST)
    ->param('queries', [], new Functions(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Functions::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (array $queries, string $search, Response $response, Database $dbForProject) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $functionId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('functions', $functionId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Function '{$functionId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'functions' => $dbForProject->find('functions', $queries),
            'total' => $dbForProject->count('functions', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_FUNCTION_LIST);
    });

App::get('/v1/functions/runtimes')
    ->groups(['api', 'functions'])
    ->desc('List runtimes')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listRuntimes')
    ->label('sdk.description', '/docs/references/functions/list-runtimes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_RUNTIME_LIST)
    ->inject('response')
    ->action(function (Response $response) {

        $runtimes = Config::getParam('runtimes');

        $runtimes = array_map(function ($key) use ($runtimes) {
            $runtimes[$key]['$id'] = $key;
            return $runtimes[$key];
        }, array_keys($runtimes));

        $response->dynamic(new Document([
            'total' => count($runtimes),
            'runtimes' => $runtimes
        ]), Response::MODEL_RUNTIME_LIST);
    });

App::get('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Get Function')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/functions/get-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, Response $response, Database $dbForProject) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::get('/v1/functions/:functionId/usage')
    ->desc('Get Function Usage')
    ->groups(['api', 'functions', 'usage'])
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getFunctionUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_FUNCTIONS)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d']), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, string $range, Response $response, Database $dbForProject) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            str_replace(['{resourceType}', '{resourceInternalId}'], ['functions', $function->getInternalId()], METRIC_FUNCTION_ID_DEPLOYMENTS),
            str_replace(['{resourceType}', '{resourceInternalId}'], ['functions', $function->getInternalId()], METRIC_FUNCTION_ID_DEPLOYMENTS_STORAGE),
            str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS),
            str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_STORAGE),
            str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_BUILDS_COMPUTE),
            str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS),
            str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE),
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
            'deploymentsTotal' => $usage[$metrics[0]],
            'deploymentsStorage' => $usage[$metrics[1]],
            'buildsTotal' => $usage[$metrics[2]],
            'buildsStorage' => $usage[$metrics[3]],
            'buildsTime' => $usage[$metrics[4]],
            'executionsTotal' => $usage[$metrics[5]],
            'executionsTime' => $usage[$metrics[6]],
        ]), Response::MODEL_USAGE_FUNCTION);
    });

App::get('/v1/functions/usage')
    ->desc('Get Functions Usage')
    ->groups(['api', 'functions', 'usage'])
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_FUNCTIONS)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d']), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $range, Response $response, Database $dbForProject) {

        $periods = Config::getParam('usage', []);
        $stats = $usage = [];
        $days = $periods[$range];
        $metrics = [
            METRIC_FUNCTIONS,
            METRIC_DEPLOYMENTS,
            METRIC_DEPLOYMENTS_STORAGE,
            METRIC_BUILDS,
            METRIC_BUILDS_STORAGE,
            METRIC_BUILDS_COMPUTE,
            METRIC_EXECUTIONS,
            METRIC_EXECUTIONS_COMPUTE,
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
            'functionsTotal' => $usage[$metrics[0]],
            'deploymentsTotal' => $usage[$metrics[1]],
            'deploymentsStorage' => $usage[$metrics[2]],
            'buildsTotal' => $usage[$metrics[3]],
            'buildsStorage' => $usage[$metrics[4]],
            'buildsTime' => $usage[$metrics[5]],
            'executionsTotal' => $usage[$metrics[6]],
            'executionsTime' => $usage[$metrics[7]],
        ]), Response::MODEL_USAGE_FUNCTIONS);
    });

App::put('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Update Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].update')
    ->label('audits.event', 'function.update')
    ->label('audits.resource', 'function/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'update')
    ->label('sdk.description', '/docs/references/functions/update-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('name', '', new Text(128), 'Function name. Max length: 128 chars.')
    ->param('execute', [], new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of strings with execution roles. By default no user is granted with any execute permissions. [learn more about permissions](https://appwrite.io/docs/permissions). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
    ->param('events', [], new ArrayList(new ValidatorEvent(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, (int) App::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Maximum execution time in seconds.', true)
    ->param('enabled', true, new Boolean(), 'Is function enabled?', true)
    ->param('logging', true, new Boolean(), 'Do executions get logged?', true)
    ->param('entrypoint', '', new Text('1028'), 'Entrypoint File.')
    ->param('commands', '', new Text('1028'), 'Build Commands.', true)
    ->param('installationId', '', new Text(128), 'Appwrite Installation ID for vcs deployment.', true)
    ->param('providerRepositoryId', '', new Text(128), 'Repository ID of the repo linked to the function', true)
    ->param('providerBranch', '', new Text(128), 'Production branch for the repo linked to the function', true)
    ->param('providerSilentMode', false, new Boolean(), 'Is VCS connection in silent mode for the repo linked to the function?', true)
    ->param('providerRootDirectory', '', new Text(128, 0), 'Path to function code in the linked repo', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('events')
    ->inject('dbForConsole')
    ->action(function (string $functionId, string $name, array $execute, array $events, string $schedule, int $timeout, bool $enabled, bool $logging, string $entrypoint, string $commands, string $installationId, string $providerRepositoryId, string $providerBranch, bool $providerSilentMode, string $providerRootDirectory, Request $request, Response $response, Database $dbForProject, Document $project, Event $eventsInstance, Database $dbForConsole) use ($redeployVcs) {
        // TODO: If only branch changes, re-deploy

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $installation = $dbForConsole->getDocument('installations', $installationId, [
            Query::equal('projectInternalId', [$project->getInternalId()])
        ]);

        if (!empty($installationId) && $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!empty($providerRepositoryId) && (empty($installationId) || empty($providerBranch))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
        }

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $enabled ??= $function->getAttribute('enabled', true);

        $repositoryId = $function->getAttribute('repositoryId', '');
        $repositoryInternalId = $function->getAttribute('repositoryInternalId', '');

        $isConnected = !empty($function->getAttribute('providerRepositoryId', ''));

        // Git disconnect logic
        if ($isConnected && empty($providerRepositoryId)) {
            $repositories = $dbForConsole->find('repositories', [
                Query::equal('projectInternalId', [$project->getInternalId()]),
                Query::equal('resourceInternalId', [$function->getInternalId()]),
                Query::equal('resourceType', ['function']),
                Query::limit(100),
            ]);

            foreach ($repositories as $repository) {
                $dbForConsole->deleteDocument('repositories', $repository->getId());
            }

            $providerRepositoryId = '';
            $installationId = '';
            $providerBranch = '';
            $providerRootDirectory = '';
            $providerSilentMode = true;
            $repositoryId = '';
            $repositoryInternalId = '';
        }

        // Git connect logic
        if (!$isConnected && !empty($providerRepositoryId)) {
            $teamId = $project->getAttribute('teamId', '');

            $repository = $dbForConsole->createDocument('repositories', new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::team(ID::custom($teamId))),
                    Permission::update(Role::team(ID::custom($teamId), 'owner')),
                    Permission::update(Role::team(ID::custom($teamId), 'developer')),
                    Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                    Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                ],
                'installationId' => $installation->getId(),
                'installationInternalId' => $installation->getInternalId(),
                'projectId' => $project->getId(),
                'projectInternalId' => $project->getInternalId(),
                'providerRepositoryId' => $providerRepositoryId,
                'resourceId' => $function->getId(),
                'resourceInternalId' => $function->getInternalId(),
                'resourceType' => 'function',
                'providerPullRequestIds' => []
            ]));

            $repositoryId = $repository->getId();
            $repositoryInternalId = $repository->getInternalId();
        }

        $live = true;

        if (
            $function->getAttribute('entrypoint') !== $entrypoint ||
            $function->getAttribute('commands') !== $commands ||
            $function->getAttribute('providerRootDirectory') !== $providerRootDirectory
        ) {
            $live = false;
        }

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'execute' => $execute,
            'name' => $name,
            'events' => $events,
            'schedule' => $schedule,
            'timeout' => $timeout,
            'enabled' => $enabled,
            'live' => $live,
            'logging' => $logging,
            'entrypoint' => $entrypoint,
            'commands' => $commands,
            'installationId' => $installation->getId(),
            'installationInternalId' => $installation->getInternalId(),
            'providerRepositoryId' => $providerRepositoryId,
            'repositoryId' => $repositoryId,
            'repositoryInternalId' => $repositoryInternalId,
            'providerBranch' => $providerBranch,
            'providerRootDirectory' => $providerRootDirectory,
            'providerSilentMode' => $providerSilentMode,
            'search' => implode(' ', [$functionId, $name, $function->getAttribute('runtime')]),
        ])));

        // Redeploy logic
        if (!$isConnected && !empty($providerRepositoryId)) {
            $redeployVcs($request, $function, $project, $installation, $dbForProject, new Document());
        }

        // Inform scheduler if function is still active
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $eventsInstance->setParam('functionId', $function->getId());

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::patch('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Update Function Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].deployments.[deploymentId].update')
    ->label('audits.event', 'deployment.update')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'updateDeployment')
    ->label('sdk.description', '/docs/references/functions/update-function-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('events')
    ->inject('dbForConsole')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject, Document $project, Event $events, Database $dbForConsole) {

        $function = $dbForProject->getDocument('functions', $functionId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($build->isEmpty()) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::BUILD_NOT_READY);
        }

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'deploymentInternalId' => $deployment->getInternalId(),
            'deployment' => $deployment->getId(),
        ])));

        // Inform scheduler if function is still active
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

App::delete('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Delete Function')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].delete')
    ->label('audits.event', 'function.delete')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/functions/delete-function.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('events')
    ->inject('project')
    ->inject('dbForConsole')
    ->action(function (string $functionId, Response $response, Database $dbForProject, Delete $deletes, Event $events, Document $project, Database $dbForConsole) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('functions', $function->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove function from DB');
        }

        // Inform scheduler to no longer run function
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('active', false);
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($function);

        $events->setParam('functionId', $function->getId());

        $response->noContent();
    });

App::post('/v1/functions/:functionId/deployments')
    ->groups(['api', 'functions'])
    ->desc('Create Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].deployments.[deploymentId].create')
    ->label('audits.event', 'deployment.create')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createDeployment')
    ->label('sdk.description', '/docs/references/functions/create-deployment.md')
    ->label('sdk.packaging', true)
    ->label('sdk.request.type', 'multipart/form-data')
    ->label('sdk.response.code', Response::STATUS_CODE_ACCEPTED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('code', [], new File(), 'Gzip file with your code package. When used with the Appwrite CLI, pass the path to your code directory, and the CLI will automatically package your code. Use a path that is within the current directory.', skipValidation: true)
    ->param('activate', false, new Boolean(true), 'Automatically activate the deployment when it is finished building.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('project')
    ->inject('deviceFunctions')
    ->inject('deviceLocal')
    ->action(function (string $functionId, mixed $code, mixed $activate, Request $request, Response $response, Database $dbForProject, Event $events, Document $project, Device $deviceFunctions, Device $deviceLocal) {
        $activate = filter_var($activate, FILTER_VALIDATE_BOOLEAN);

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $entrypoint = $function->getAttribute('entrypoint', '');
        $commands = $function->getAttribute('commands', '');

        if (empty($entrypoint)) {
            throw new Exception(Exception::FUNCTION_ENTRYPOINT_MISSING);
        }

        $file = $request->getFiles('code');

        // GraphQL multipart spec adds files with index keys
        if (empty($file)) {
            $file = $request->getFiles(0);
        }

        if (empty($file)) {
            throw new Exception(Exception::STORAGE_FILE_EMPTY, 'No file sent');
        }

        $fileExt = new FileExt([FileExt::TYPE_GZIP]);
        $fileSizeValidator = new FileSize(App::getEnv('_APP_FUNCTIONS_SIZE_LIMIT', 0));
        $upload = new Upload();

        // Make sure we handle a single file and multiple files the same way
        $fileName = (\is_array($file['name']) && isset($file['name'][0])) ? $file['name'][0] : $file['name'];
        $fileTmpName = (\is_array($file['tmp_name']) && isset($file['tmp_name'][0])) ? $file['tmp_name'][0] : $file['tmp_name'];
        $fileSize = (\is_array($file['size']) && isset($file['size'][0])) ? $file['size'][0] : $file['size'];

        if (!$fileExt->isValid($file['name'])) { // Check if file type is allowed
            throw new Exception(Exception::STORAGE_FILE_TYPE_UNSUPPORTED);
        }

        $contentRange = $request->getHeader('content-range');
        $deploymentId = ID::unique();
        $chunk = 1;
        $chunks = 1;

        if (!empty($contentRange)) {
            $start = $request->getContentRangeStart();
            $end = $request->getContentRangeEnd();
            $fileSize = $request->getContentRangeSize();
            $deploymentId = $request->getHeader('x-appwrite-id', $deploymentId);
            // TODO make `end >= $fileSize` in next breaking version
            if (is_null($start) || is_null($end) || is_null($fileSize) || $end > $fileSize) {
                throw new Exception(Exception::STORAGE_INVALID_CONTENT_RANGE);
            }

            // TODO remove the condition that checks `$end === $fileSize` in next breaking version
            if ($end === $fileSize - 1 || $end === $fileSize) {
                //if it's a last chunks the chunk size might differ, so we set the $chunks and $chunk to notify it's last chunk
                $chunks = $chunk = -1;
            } else {
                // Calculate total number of chunks based on the chunk size i.e ($rangeEnd - $rangeStart)
                $chunks = (int) ceil($fileSize / ($end + 1 - $start));
                $chunk = (int) ($start / ($end + 1 - $start)) + 1;
            }
        }

        if (!$fileSizeValidator->isValid($fileSize)) { // Check if file size is exceeding allowed limit
            throw new Exception(Exception::STORAGE_INVALID_FILE_SIZE);
        }

        if (!$upload->isValid($fileTmpName)) {
            throw new Exception(Exception::STORAGE_INVALID_FILE);
        }

        // Save to storage
        $fileSize ??= $deviceLocal->getFileSize($fileTmpName);
        $path = $deviceFunctions->getPath($deploymentId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        $metadata = ['content_type' => $deviceLocal->getFileMimeType($fileTmpName)];
        if (!$deployment->isEmpty()) {
            $chunks = $deployment->getAttribute('chunksTotal', 1);
            $metadata = $deployment->getAttribute('metadata', []);
            if ($chunk === -1) {
                $chunk = $chunks;
            }
        }

        $chunksUploaded = $deviceFunctions->upload($fileTmpName, $path, $chunk, $chunks, $metadata);

        if (empty($chunksUploaded)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed moving file');
        }

        $activate = (bool) filter_var($activate, FILTER_VALIDATE_BOOLEAN);

        if ($chunksUploaded === $chunks) {
            if ($activate) {
                // Remove deploy for all other deployments.
                $activeDeployments = $dbForProject->find('deployments', [
                    Query::equal('activate', [true]),
                    Query::equal('resourceId', [$functionId]),
                    Query::equal('resourceType', ['functions'])
                ]);

                foreach ($activeDeployments as $activeDeployment) {
                    $activeDeployment->setAttribute('activate', false);
                    $dbForProject->updateDocument('deployments', $activeDeployment->getId(), $activeDeployment);
                }
            }

            $fileSize = $deviceFunctions->getFileSize($path);

            if ($deployment->isEmpty()) {
                $deployment = $dbForProject->createDocument('deployments', new Document([
                    '$id' => $deploymentId,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                    'resourceInternalId' => $function->getInternalId(),
                    'resourceId' => $function->getId(),
                    'resourceType' => 'functions',
                    'buildInternalId' => '',
                    'entrypoint' => $entrypoint,
                    'commands' => $commands,
                    'path' => $path,
                    'size' => $fileSize,
                    'search' => implode(' ', [$deploymentId, $entrypoint]),
                    'activate' => $activate,
                    'metadata' => $metadata,
                    'type' => 'manual'
                ]));
            } else {
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment->setAttribute('size', $fileSize)->setAttribute('metadata', $metadata));
            }

            // Start the build
            $buildEvent = new Build();
            $buildEvent
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($function)
                ->setDeployment($deployment)
                ->setProject($project)
                ->trigger();
        } else {
            if ($deployment->isEmpty()) {
                $deployment = $dbForProject->createDocument('deployments', new Document([
                    '$id' => $deploymentId,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ],
                    'resourceInternalId' => $function->getInternalId(),
                    'resourceId' => $function->getId(),
                    'resourceType' => 'functions',
                    'buildInternalId' => '',
                    'entrypoint' => $entrypoint,
                    'commands' => $commands,
                    'path' => $path,
                    'size' => $fileSize,
                    'chunksTotal' => $chunks,
                    'chunksUploaded' => $chunksUploaded,
                    'search' => implode(' ', [$deploymentId, $entrypoint]),
                    'activate' => $activate,
                    'metadata' => $metadata,
                    'type' => 'manual'
                ]));
            } else {
                $deployment = $dbForProject->updateDocument('deployments', $deploymentId, $deployment->setAttribute('chunksUploaded', $chunksUploaded)->setAttribute('metadata', $metadata));
            }
        }

        $metadata = null;

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    });

App::get('/v1/functions/:functionId/deployments')
    ->groups(['api', 'functions'])
    ->desc('List Deployments')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listDeployments')
    ->label('sdk.description', '/docs/references/functions/list-deployments.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT_LIST)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('queries', [], new Deployments(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Deployments::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, array $queries, string $search, Response $response, Database $dbForProject) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Set resource queries
        $queries[] = Query::equal('resourceId', [$function->getId()]);
        $queries[] = Query::equal('resourceType', ['functions']);

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $deploymentId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('deployments', $deploymentId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Deployment '{$deploymentId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $results = $dbForProject->find('deployments', $queries);
        $total = $dbForProject->count('deployments', $filterQueries, APP_LIMIT_COUNT);

        foreach ($results as $result) {
            $build = $dbForProject->getDocument('builds', $result->getAttribute('buildId', ''));
            $result->setAttribute('status', $build->getAttribute('status', 'processing'));
            $result->setAttribute('buildLogs', $build->getAttribute('logs', ''));
            $result->setAttribute('buildTime', $build->getAttribute('duration', 0));
            $result->setAttribute('size', $result->getAttribute('size', 0) + $build->getAttribute('size', 0));
        }

        $response->dynamic(new Document([
            'deployments' => $results,
            'total' => $total,
        ]), Response::MODEL_DEPLOYMENT_LIST);
    });

App::get('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Get Deployment')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getDeployment')
    ->label('sdk.description', '/docs/references/functions/get-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DEPLOYMENT)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $build = $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', ''));
        $deployment->setAttribute('status', $build->getAttribute('status', 'waiting'));
        $deployment->setAttribute('buildLogs', $build->getAttribute('logs', ''));
        $deployment->setAttribute('buildTime', $build->getAttribute('duration', 0));
        $deployment->setAttribute('size', $deployment->getAttribute('size', 0) + $build->getAttribute('size', 0));

        $response->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    });

App::delete('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Delete Deployment')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].deployments.[deploymentId].delete')
    ->label('audits.event', 'deployment.delete')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'deleteDeployment')
    ->label('sdk.description', '/docs/references/functions/delete-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('deletes')
    ->inject('events')
    ->inject('deviceFunctions')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject, Delete $deletes, Event $events, Device $deviceFunctions) {

        $function = $dbForProject->getDocument('functions', $functionId);
        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);
        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        if (!$dbForProject->deleteDocument('deployments', $deployment->getId())) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from DB');
        }

        if (!empty($deployment->getAttribute('path', ''))) {
            if (!($deviceFunctions->delete($deployment->getAttribute('path', '')))) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from storage');
            }
        }

        if ($function->getAttribute('deployment') === $deployment->getId()) { // Reset function deployment
            $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
                'deployment' => '',
                'deploymentInternalId' => '',
            ])));
        }

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($deployment);

        $response->noContent();
    });

App::post('/v1/functions/:functionId/deployments/:deploymentId/builds/:buildId')
    ->groups(['api', 'functions'])
    ->desc('Create Build')
    ->label('scope', 'functions.write')
    ->label('event', 'functions.[functionId].deployments.[deploymentId].update')
    ->label('audits.event', 'deployment.update')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createBuild')
    ->label('sdk.description', '/docs/references/functions/create-build.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->param('buildId', '', new UID(), 'Build unique ID.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('project')
    ->action(function (string $functionId, string $deploymentId, string $buildId, Request $request, Response $response, Database $dbForProject, Database $dbForConsole, Document $project) use ($redeployVcs) {

        $function = $dbForProject->getDocument('functions', $functionId);
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $build = Authorization::skip(fn () => $dbForProject->getDocument('builds', $buildId));

        if ($build->isEmpty()) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        // TODO: Somehow set commit SHA & ownerName & repoName for git deployments, and file path for manual. Redeploy should use exact same source code

        $installation = $dbForConsole->getDocument('installations', $deployment->getAttribute('installationId', ''));

        $redeployVcs($request, $function, $project, $installation, $dbForProject, new Document([]));

        $response->noContent();
    });

App::post('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('Create Execution')
    ->label('scope', 'execution.write')
    ->label('event', 'functions.[functionId].executions.[executionId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createExecution')
    ->label('sdk.description', '/docs/references/functions/create-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('body', '', new Text(8192, 0), 'HTTP body of execution. Default value is empty string.', true)
    ->param('async', false, new Boolean(), 'Execute code in the background. Default value is false.', true)
    ->param('path', '/', new Text(2048), 'HTTP path of execution. Path can include query params. Default value is /', true)
    ->param('method', 'POST', new Whitelist(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true), 'HTTP method of execution. Default value is GET.', true)
    ->param('headers', [], new Assoc(), 'HTP headers of execution. Defaults to empty.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('events')
    ->inject('mode')
    ->inject('queueForFunctions')
    ->inject('geodb')
    ->inject('queueForUsage')
    ->action(function (string $functionId, string $body, bool $async, string $path, string $method, array $headers, Response $response, Document $project, Database $dbForProject, Document $user, Event $events, string $mode, Func $queueForFunctions, Reader $geodb, Usage $queueForUsage) {

        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty() || !$function->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception(Exception::FUNCTION_NOT_FOUND);
            }
        }

        $runtimes = Config::getParam('runtimes', []);

        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')])) ? $runtimes[$function->getAttribute('runtime', '')] : null;

        if (\is_null($runtime)) {
            throw new Exception(Exception::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $deployment = Authorization::skip(fn () => $dbForProject->getDocument('deployments', $function->getAttribute('deployment', '')));

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
        }

        /** Check if build has completed */
        $build = Authorization::skip(fn () => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));
        if ($build->isEmpty()) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::BUILD_NOT_READY);
        }

        $validator = new Authorization('execute');

        if (!$validator->isValid($function->getAttribute('execute'))) { // Check if user has write access to execute function
            throw new Exception(Exception::USER_UNAUTHORIZED, $validator->getDescription());
        }

        $jwt = ''; // initialize
        if (!$user->isEmpty()) { // If userId exists, generate a JWT for function
            $sessions = $user->getAttribute('sessions', []);
            $current = new Document();

            foreach ($sessions as $session) {
                /** @var Utopia\Database\Document $session */
                if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $current = $session;
                }
            }

            if (!$current->isEmpty()) {
                $jwtObj = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.
                $jwt = $jwtObj->encode([
                    'userId' => $user->getId(),
                    'sessionId' => $current->getId(),
                ]);
            }
        }

        $headers['x-appwrite-trigger'] = 'http';
        $headers['x-appwrite-user-id'] = $user->getId() ?? '';
        $headers['x-appwrite-user-jwt'] = $jwt ?? '';
        $headers['x-appwrite-country-code'] = '';
        $headers['x-appwrite-continent-code'] = '';
        $headers['x-appwrite-continent-eu'] = 'false';

        $ip = $headers['x-real-ip'] ?? '';
        if (!empty($ip)) {
            $record = $geodb->get($ip);

            if ($record) {
                $eu = Config::getParam('locale-eu');

                $headers['x-appwrite-country-code'] = $record['country']['iso_code'] ?? '';
                $headers['x-appwrite-continent-code'] = $record['continent']['code'] ?? '';
                $headers['x-appwrite-continent-eu'] = (\in_array($record['country']['iso_code'], $eu)) ? 'true' : 'false';
            }
        }

        $headersFiltered = [];
        foreach ($headers as $key => $value) {
            if (\in_array($key, FUNCTION_WHITELIST_HEADERS_REQUEST)) {
                $headersFiltered[] = [ 'key' => $key, 'value' => $value ];
            }
        }

        $executionId = ID::unique();

        $execution = new Document([
            '$id' => $executionId,
            '$permissions' => !$user->isEmpty() ? [Permission::read(Role::user($user->getId()))] : [],
            'functionInternalId' => $function->getInternalId(),
            'functionId' => $function->getId(),
            'deploymentInternalId' => $deployment->getInternalId(),
            'deploymentId' => $deployment->getId(),
            'trigger' => 'http', // http / schedule / event
            'status' => $async ? 'waiting' : 'processing', // waiting / processing / completed / failed
            'responseStatusCode' => 0,
            'responseHeaders' => [],
            'requestPath' => $path,
            'requestMethod' => $method,
            'requestHeaders' => $headersFiltered,
            'errors' => '',
            'logs' => '',
            'duration' => 0.0,
            'search' => implode(' ', [$functionId, $executionId]),
        ]);

        $events
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setContext('function', $function);

        if ($async) {
            if ($function->getAttribute('logging')) {
                /** @var Document $execution */
                $execution = Authorization::skip(fn () => $dbForProject->createDocument('executions', $execution));
            }

            $queueForFunctions
                ->setType('http')
                ->setExecution($execution)
                ->setFunction($function)
                ->setBody($body)
                ->setHeaders($headers)
                ->setPath($path)
                ->setMethod($method)
                ->setJWT($jwt)
                ->setProject($project)
                ->setUser($user)
                ->trigger();

            return $response
                ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                ->dynamic($execution, Response::MODEL_EXECUTION);
        }

        $durationStart = \microtime(true);

        $vars = [];

        // Shared vars
        $varsShared = $project->getAttribute('variables', []);
        $vars = \array_merge($vars, \array_reduce($varsShared, function (array $carry, Document $var) {
            $carry[$var->getAttribute('key')] = $var->getAttribute('value') ?? '';
            return $carry;
        }, []));

        // Function vars
        $vars = \array_merge($vars, array_reduce($function->getAttribute('vars', []), function (array $carry, Document $var) {
            $carry[$var->getAttribute('key')] = $var->getAttribute('value') ?? '';
            return $carry;
        }, []));

        // Appwrite vars
        $vars = \array_merge($vars, [
            'APPWRITE_FUNCTION_ID' => $functionId,
            'APPWRITE_FUNCTION_NAME' => $function->getAttribute('name'),
            'APPWRITE_FUNCTION_DEPLOYMENT' => $deployment->getId(),
            'APPWRITE_FUNCTION_PROJECT_ID' => $project->getId(),
            'APPWRITE_FUNCTION_RUNTIME_NAME' => $runtime['name'] ?? '',
            'APPWRITE_FUNCTION_RUNTIME_VERSION' => $runtime['version'] ?? '',
        ]);

        /** Execute function */
        $executor = new Executor(App::getEnv('_APP_EXECUTOR_HOST'));
        try {
            $command = $runtime['startCommand'];
            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deployment->getId(),
                version: $function->getAttribute('version'),
                body: \strlen($body) > 0 ? $body : null,
                variables: $vars,
                timeout: $function->getAttribute('timeout', 0),
                image: $runtime['image'],
                source: $build->getAttribute('path', ''),
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                path: $path,
                method: $method,
                headers: $headers,
                runtimeEntrypoint: 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"'
            );

            $headersFiltered = [];
            foreach ($executionResponse['headers'] as $key => $value) {
                if (\in_array($key, FUNCTION_WHITELIST_HEADERS_REQUEST)) {
                    $headersFiltered[] = [ 'key' => $key, 'value' => $value ];
                }
            }

            /** Update execution status */
            $status = $executionResponse['statusCode'] >= 400 ? 'failed' : 'completed';
            $execution->setAttribute('status', $status);
            $execution->setAttribute('responseStatusCode', $executionResponse['statusCode']);
            $execution->setAttribute('responseHeaders', $headersFiltered);
            $execution->setAttribute('logs', $executionResponse['logs']);
            $execution->setAttribute('errors', $executionResponse['errors']);
            $execution->setAttribute('duration', $executionResponse['duration']);
            /**
             * Sync execution compute usage from
             */
            $queueForUsage
                ->addMetric(METRIC_EXECUTIONS_COMPUTE, (int)($executionResponse['duration'] * 1000))// per project
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE), (int)($executionResponse['duration'] * 1000))// per function
            ;
        } catch (\Throwable $th) {
            $durationEnd = \microtime(true);

            $execution
                ->setAttribute('duration', $durationEnd - $durationStart)
                ->setAttribute('status', 'failed')
                ->setAttribute('responseStatusCode', 500)
                ->setAttribute('errors', $th->getMessage() . '\nError Code: ' . $th->getCode());
            Console::error($th->getMessage());
        }

        if ($function->getAttribute('logging')) {
            /** @var Document $execution */
            $execution = Authorization::skip(fn () => $dbForProject->createDocument('executions', $execution));
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        if (!$isPrivilegedUser && !$isAppUser) {
            $execution->setAttribute('logs', '');
            $execution->setAttribute('errors', '');
        }

        $execution->setAttribute('responseBody', $executionResponse['body']);
        $execution->setAttribute('responseHeaders', $executionResponse['headers']);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($execution, Response::MODEL_EXECUTION);
    });

App::get('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('List Executions')
    ->label('scope', 'execution.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listExecutions')
    ->label('sdk.description', '/docs/references/functions/list-executions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION_LIST)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('queries', [], new Executions(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Executions::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $functionId, array $queries, string $search, Response $response, Database $dbForProject, string $mode) {

        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty() || !$function->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception(Exception::FUNCTION_NOT_FOUND);
            }
        }

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Set internal queries
        $queries[] = Query::equal('functionId', [$function->getId()]);

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, [Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE]);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $executionId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('executions', $executionId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Execution '{$executionId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $results = $dbForProject->find('executions', $queries);
        $total = $dbForProject->count('executions', $filterQueries, APP_LIMIT_COUNT);

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        if (!$isPrivilegedUser && !$isAppUser) {
            $results = array_map(function ($execution) {
                $execution->setAttribute('logs', '');
                $execution->setAttribute('errors', '');
                return $execution;
            }, $results);
        }

        $response->dynamic(new Document([
            'executions' => $results,
            'total' => $total,
        ]), Response::MODEL_EXECUTION_LIST);
    });

App::get('/v1/functions/:functionId/executions/:executionId')
    ->groups(['api', 'functions'])
    ->desc('Get Execution')
    ->label('scope', 'execution.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_KEY, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getExecution')
    ->label('sdk.description', '/docs/references/functions/get-execution.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EXECUTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('executionId', '', new UID(), 'Execution ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('mode')
    ->action(function (string $functionId, string $executionId, Response $response, Database $dbForProject, string $mode) {

        $function = Authorization::skip(fn () => $dbForProject->getDocument('functions', $functionId));

        if ($function->isEmpty() || !$function->getAttribute('enabled')) {
            if (!($mode === APP_MODE_ADMIN && Auth::isPrivilegedUser(Authorization::getRoles()))) {
                throw new Exception(Exception::FUNCTION_NOT_FOUND);
            }
        }

        $execution = $dbForProject->getDocument('executions', $executionId);

        if ($execution->getAttribute('functionId') !== $function->getId()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        if ($execution->isEmpty()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        if (!$isPrivilegedUser && !$isAppUser) {
            $execution->setAttribute('logs', '');
            $execution->setAttribute('errors', '');
        }

        $response->dynamic($execution, Response::MODEL_EXECUTION);
    });

// Variables

App::post('/v1/functions/:functionId/variables')
    ->desc('Create Variable')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.write')
    ->label('audits.event', 'variable.create')
    ->label('audits.resource', 'function/{request.functionId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'createVariable')
    ->label('sdk.description', '/docs/references/functions/create-variable.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE)
    ->param('functionId', '', new UID(), 'Function unique ID.', false)
    ->param('key', null, new Text(Database::LENGTH_KEY), 'Variable key. Max length: ' . Database::LENGTH_KEY  . ' chars.', false)
    ->param('value', null, new Text(8192), 'Variable value. Max length: 8192 chars.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (string $functionId, string $key, string $value, Document $project, Response $response, Database $dbForProject, Database $dbForConsole) {
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
            'search' => implode(' ', [$variableId, $function->getId(), $key, 'function']),
        ]);

        try {
            $variable = $dbForProject->createDocument('variables', $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }
        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $dbForProject->deleteCachedDocument('functions', $function->getId());

        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::get('/v1/functions/:functionId/variables')
    ->desc('List Variables')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'listVariables')
    ->label('sdk.description', '/docs/references/functions/list-variables.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_VARIABLE_LIST)
    ->param('functionId', '', new UID(), 'Function unique ID.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $functionId, Response $response, Database $dbForProject) {
        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $response->dynamic(new Document([
            'variables' => $function->getAttribute('vars'),
            'total' => \count($function->getAttribute('vars')),
        ]), Response::MODEL_VARIABLE_LIST);
    });

App::get('/v1/functions/:functionId/variables/:variableId')
    ->desc('Get Variable')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
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
        if ($variable === false || $variable->isEmpty() || $variable->getAttribute('resourceInternalId') !== $function->getInternalId() || $variable->getAttribute('resourceType') !== 'function') {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        if ($variable === false || $variable->isEmpty()) {
            throw new Exception(Exception::VARIABLE_NOT_FOUND);
        }

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::put('/v1/functions/:functionId/variables/:variableId')
    ->desc('Update Variable')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.write')
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
    ->param('value', null, new Text(8192), 'Variable value. Max length: 8192 chars.', true)
    ->inject('project')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (string $functionId, string $variableId, string $key, ?string $value, Document $project, Response $response, Database $dbForProject, Database $dbForConsole) {

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

        $variable
            ->setAttribute('key', $key)
            ->setAttribute('value', $value ?? $variable->getAttribute('value'))
            ->setAttribute('search', implode(' ', [$variableId, $function->getId(), $key, 'function']));

        try {
            $dbForProject->updateDocument('variables', $variable->getId(), $variable);
        } catch (DuplicateException $th) {
            throw new Exception(Exception::VARIABLE_ALREADY_EXISTS);
        }
        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $dbForProject->deleteCachedDocument('functions', $function->getId());

        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    });

App::delete('/v1/functions/:functionId/variables/:variableId')
    ->desc('Delete Variable')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.write')
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
    ->inject('project')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->action(function (string $functionId, string $variableId, Document $project, Response $response, Database $dbForProject, Database $dbForConsole) {
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
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $dbForProject->deleteCachedDocument('functions', $function->getId());

        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        Authorization::skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response->noContent();
    });
