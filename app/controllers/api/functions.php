<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Event\Build;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Func;
use Appwrite\Event\Usage;
use Appwrite\Event\Validator\FunctionEvent;
use Appwrite\Extend\Exception;
use Appwrite\Messaging\Adapter\Realtime;
use Appwrite\Task\Validator\Cron;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Deployments;
use Appwrite\Utopia\Database\Validator\Queries\Executions;
use Appwrite\Utopia\Database\Validator\Queries\Functions;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Model\Rule;
use Executor\Executor;
use MaxMind\Db\Reader;
use Utopia\CLI\Console;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\Roles;
use Utopia\Database\Validator\UID;
use Utopia\Http\Http;
use Utopia\Http\Validator\ArrayList;
use Utopia\Http\Validator\Assoc;
use Utopia\Http\Validator\Boolean;
use Utopia\Http\Validator\Range;
use Utopia\Http\Validator\Text;
use Utopia\Http\Validator\WhiteList;
use Utopia\Storage\Device;
use Utopia\Storage\Validator\File;
use Utopia\Storage\Validator\FileExt;
use Utopia\Storage\Validator\FileSize;
use Utopia\Storage\Validator\Upload;
use Utopia\System\System;
use Utopia\VCS\Adapter\Git\GitHub;
use Utopia\VCS\Exception\RepositoryNotFound;

include_once __DIR__ . '/../shared/api.php';

$redeployVcs = function (Request $request, Document $function, Document $project, Document $installation, Database $dbForProject, Build $queueForBuilds, Document $template, GitHub $github) {
    $deploymentId = ID::unique();
    $entrypoint = $function->getAttribute('entrypoint', '');
    $providerInstallationId = $installation->getAttribute('providerInstallationId', '');
    $privateKey = System::getEnv('_APP_VCS_GITHUB_PRIVATE_KEY');
    $githubAppId = System::getEnv('_APP_VCS_GITHUB_APP_ID');
    $github->initializeVariables($providerInstallationId, $privateKey, $githubAppId);
    $owner = $github->getOwnerName($providerInstallationId);
    $providerRepositoryId = $function->getAttribute('providerRepositoryId', '');
    try {
        $repositoryName = $github->getRepositoryName($providerRepositoryId) ?? '';
        if (empty($repositoryName)) {
            throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
        }
    } catch (RepositoryNotFound $e) {
        throw new Exception(Exception::PROVIDER_REPOSITORY_NOT_FOUND);
    }
    $providerBranch = $function->getAttribute('providerBranch', 'main');
    $authorUrl = "https://github.com/$owner";
    $repositoryUrl = "https://github.com/$owner/$repositoryName";
    $branchUrl = "https://github.com/$owner/$repositoryName/tree/$providerBranch";

    $commitDetails = [];
    if ($template->isEmpty()) {
        try {
            $commitDetails = $github->getLatestCommit($owner, $repositoryName, $providerBranch);
        } catch (\Throwable $error) {
            Console::warning('Failed to get latest commit details');
            Console::warning($error->getMessage());
            Console::warning($error->getTraceAsString());
        }
    }

    $deployment = $dbForProject->createDocument('deployments', new Document([
        '$id' => $deploymentId,
        '$permissions' => [
            Permission::read(Role::any()),
            Permission::update(Role::any()),
            Permission::delete(Role::any()),
        ],
        'resourceId' => $function->getId(),
        'resourceInternalId' => $function->getInternalId(),
        'resourceType' => 'functions',
        'entrypoint' => $entrypoint,
        'commands' => $function->getAttribute('commands', ''),
        'type' => 'vcs',
        'installationId' => $installation->getId(),
        'installationInternalId' => $installation->getInternalId(),
        'providerRepositoryId' => $providerRepositoryId,
        'repositoryId' => $function->getAttribute('repositoryId', ''),
        'repositoryInternalId' => $function->getAttribute('repositoryInternalId', ''),
        'providerBranchUrl' => $branchUrl,
        'providerRepositoryName' => $repositoryName,
        'providerRepositoryOwner' => $owner,
        'providerRepositoryUrl' => $repositoryUrl,
        'providerCommitHash' => $commitDetails['commitHash'] ?? '',
        'providerCommitAuthorUrl' => $authorUrl,
        'providerCommitAuthor' => $commitDetails['commitAuthor'] ?? '',
        'providerCommitMessage' => $commitDetails['commitMessage'] ?? '',
        'providerCommitUrl' => $commitDetails['commitUrl'] ?? '',
        'providerBranch' => $providerBranch,
        'providerRootDirectory' => $function->getAttribute('providerRootDirectory', ''),
        'search' => implode(' ', [$deploymentId, $entrypoint]),
        'activate' => true,
    ]));

    $queueForBuilds
        ->setType(BUILD_TYPE_DEPLOYMENT)
        ->setResource($function)
        ->setDeployment($deployment)
        ->setTemplate($template);
};

Http::post('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('Create function')
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
    ->param('execute', [], new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of role strings with execution permissions. By default no user is granted with any execute permissions. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
    ->param('events', [], new ArrayList(new FunctionEvent(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, (int) System::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Function maximum execution time in seconds.', true)
    ->param('enabled', true, new Boolean(), 'Is function enabled? When set to \'disabled\', users cannot access the function but Server SDKs with and API key can still access the function. No data is lost when this is toggled.', true)
    ->param('logging', true, new Boolean(), 'Whether executions will be logged. When set to false, executions will not be logged, but will reduce resource used by your Appwrite project.', true)
    ->param('entrypoint', '', new Text(1028, 0), 'Entrypoint File. This path is relative to the "providerRootDirectory".', true)
    ->param('commands', '', new Text(8192, 0), 'Build Commands.', true)
    ->param('installationId', '', new Text(128, 0), 'Appwrite Installation ID for VCS (Version Control System) deployment.', true)
    ->param('providerRepositoryId', '', new Text(128, 0), 'Repository ID of the repo linked to the function.', true)
    ->param('providerBranch', '', new Text(128, 0), 'Production branch for the repo linked to the function.', true)
    ->param('providerSilentMode', false, new Boolean(), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the function? In silent mode, comments will not be made on commits and pull requests.', true)
    ->param('providerRootDirectory', '', new Text(128, 0), 'Path to function code in the linked repo.', true)
    ->param('templateRepository', '', new Text(128, 0), 'Repository name of the template.', true)
    ->param('templateOwner', '', new Text(128, 0), 'The name of the owner of the template.', true)
    ->param('templateRootDirectory', '', new Text(128, 0), 'Path to function code in the template repo.', true)
    ->param('templateBranch', '', new Text(128, 0), 'Production branch for the repo linked to the function template.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('queueForBuilds')
    ->inject('dbForConsole')
    ->inject('gitHub')
    ->inject('auth')
    ->action(function (string $functionId, string $name, string $runtime, array $execute, array $events, string $schedule, int $timeout, bool $enabled, bool $logging, string $entrypoint, string $commands, string $installationId, string $providerRepositoryId, string $providerBranch, bool $providerSilentMode, string $providerRootDirectory, string $templateRepository, string $templateOwner, string $templateRootDirectory, string $templateBranch, Request $request, Response $response, Database $dbForProject, Document $project, Document $user, Event $queueForEvents, Build $queueForBuilds, Database $dbForConsole, GitHub $github, Authorization $auth) use ($redeployVcs) {
        $functionId = ($functionId == 'unique()') ? ID::unique() : $functionId;

        $allowList = \array_filter(\explode(',', System::getEnv('_APP_FUNCTIONS_RUNTIMES', '')));

        if (!empty($allowList) && !\in_array($runtime, $allowList)) {
            throw new Exception(Exception::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $runtime . '" is not supported');
        }

        // build from template
        $template = new Document([]);
        if (
            !empty($templateRepository)
            && !empty($templateOwner)
            && !empty($templateRootDirectory)
            && !empty($templateBranch)
        ) {
            $template->setAttribute('repositoryName', $templateRepository)
                ->setAttribute('ownerName', $templateOwner)
                ->setAttribute('rootDirectory', $templateRootDirectory)
                ->setAttribute('branch', $templateBranch);
        }

        $installation = $dbForConsole->getDocument('installations', $installationId);

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
            'scheduleId' => '',
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

        $schedule = $auth->skip(
            fn () => $dbForConsole->createDocument('schedules', new Document([
                'region' => System::getEnv('_APP_REGION', 'default'), // Todo replace with projects region
                'resourceType' => 'function',
                'resourceId' => $function->getId(),
                'resourceInternalId' => $function->getInternalId(),
                'resourceUpdatedAt' => DateTime::now(),
                'projectId' => $project->getId(),
                'schedule'  => $function->getAttribute('schedule'),
                'active' => false,
            ]))
        );

        $function->setAttribute('scheduleId', $schedule->getId());
        $function->setAttribute('scheduleInternalId', $schedule->getInternalId());

        // Git connect logic
        if (!empty($providerRepositoryId)) {
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

            $function->setAttribute('repositoryId', $repository->getId());
            $function->setAttribute('repositoryInternalId', $repository->getInternalId());
        }

        $function = $dbForProject->updateDocument('functions', $function->getId(), $function);

        // Redeploy vcs logic
        if (!empty($providerRepositoryId)) {
            $redeployVcs($request, $function, $project, $installation, $dbForProject, $queueForBuilds, $template, $github);
        }

        $functionsDomain = System::getEnv('_APP_DOMAIN_FUNCTIONS', '');
        if (!empty($functionsDomain)) {
            $ruleId = ID::unique();
            $routeSubdomain = ID::unique();
            $domain = "{$routeSubdomain}.{$functionsDomain}";

            $rule = $auth->skip(
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
            $ruleCreate =
                $queueForEvents
                ->setClass(Event::WEBHOOK_CLASS_NAME)
                ->setQueue(Event::WEBHOOK_QUEUE_NAME);

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

        $queueForEvents->setParam('functionId', $function->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($function, Response::MODEL_FUNCTION);
    });

Http::get('/v1/functions')
    ->groups(['api', 'functions'])
    ->desc('List functions')
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

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
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

Http::get('/v1/functions/runtimes')
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

        $allowList = \array_filter(\explode(',', System::getEnv('_APP_FUNCTIONS_RUNTIMES', '')));

        $allowed = [];
        foreach ($runtimes as $key => $runtime) {
            if (!empty($allowList) && !\in_array($key, $allowList)) {
                continue;
            }

            $runtimes[$key]['$id'] = $key;
            $allowed[] = $runtimes[$key];
        }

        $response->dynamic(new Document([
            'total' => count($allowed),
            'runtimes' => $allowed
        ]), Response::MODEL_RUNTIME_LIST);
    });

Http::get('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Get function')
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

Http::get('/v1/functions/:functionId/usage')
    ->desc('Get function usage')
    ->groups(['api', 'functions', 'usage'])
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getFunctionUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_FUNCTION)
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d']), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('auth')
    ->action(function (string $functionId, string $range, Response $response, Database $dbForProject, Authorization $auth) {

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

        $auth->skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result =  $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
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
            $usage[$metric]['total'] =  $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }

        $response->dynamic(new Document([
            'range' => $range,
            'deploymentsTotal' => $usage[$metrics[0]]['total'],
            'deploymentsStorageTotal' => $usage[$metrics[1]]['total'],
            'buildsTotal' => $usage[$metrics[2]]['total'],
            'buildsStorageTotal' => $usage[$metrics[3]]['total'],
            'buildsTimeTotal' => $usage[$metrics[4]]['total'],
            'executionsTotal' => $usage[$metrics[5]]['total'],
            'executionsTimeTotal' => $usage[$metrics[6]]['total'],
            'deployments' => $usage[$metrics[0]]['data'],
            'deploymentsStorage' => $usage[$metrics[1]]['data'],
            'builds' => $usage[$metrics[2]]['data'],
            'buildsStorage' => $usage[$metrics[3]]['data'],
            'buildsTime' => $usage[$metrics[4]]['data'],
            'executions' => $usage[$metrics[5]]['data'],
            'executionsTime' => $usage[$metrics[6]]['data'],
        ]), Response::MODEL_USAGE_FUNCTION);
    });

Http::get('/v1/functions/usage')
    ->desc('Get functions usage')
    ->groups(['api', 'functions'])
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_FUNCTIONS)
    ->param('range', '30d', new WhiteList(['24h', '30d', '90d']), 'Date range.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('auth')
    ->action(function (string $range, Response $response, Database $dbForProject, Authorization $auth) {

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

        $auth->skip(function () use ($dbForProject, $days, $metrics, &$stats) {
            foreach ($metrics as $metric) {
                $result =  $dbForProject->findOne('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', ['inf'])
                ]);

                $stats[$metric]['total'] = $result['value'] ?? 0;
                $limit = $days['limit'];
                $period = $days['period'];
                $results = $dbForProject->find('stats', [
                    Query::equal('metric', [$metric]),
                    Query::equal('period', [$period]),
                    Query::limit($limit),
                    Query::orderDesc('time'),
                ]);
                $stats[$metric]['data'] = [];
                foreach ($results as $result) {
                    $stats[$metric]['data'][$result->getAttribute('time')] = [
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
            $usage[$metric]['total'] =  $stats[$metric]['total'];
            $usage[$metric]['data'] = [];
            $leap = time() - ($days['limit'] * $days['factor']);
            while ($leap < time()) {
                $leap += $days['factor'];
                $formatDate = date($format, $leap);
                $usage[$metric]['data'][] = [
                    'value' => $stats[$metric]['data'][$formatDate]['value'] ?? 0,
                    'date' => $formatDate,
                ];
            }
        }
        $response->dynamic(new Document([
            'range' => $range,
            'functionsTotal' => $usage[$metrics[0]]['total'],
            'deploymentsTotal' => $usage[$metrics[1]]['total'],
            'deploymentsStorageTotal' => $usage[$metrics[2]]['total'],
            'buildsTotal' => $usage[$metrics[3]]['total'],
            'buildsStorageTotal' => $usage[$metrics[4]]['total'],
            'buildsTimeTotal' => $usage[$metrics[5]]['total'],
            'executionsTotal' => $usage[$metrics[6]]['total'],
            'executionsTimeTotal' => $usage[$metrics[7]]['total'],
            'functions' => $usage[$metrics[0]]['data'],
            'deployments' => $usage[$metrics[1]]['data'],
            'deploymentsStorage' => $usage[$metrics[2]]['data'],
            'builds' => $usage[$metrics[3]]['data'],
            'buildsStorage' => $usage[$metrics[4]]['data'],
            'buildsTime' => $usage[$metrics[5]]['data'],
            'executions' => $usage[$metrics[6]]['data'],
            'executionsTime' => $usage[$metrics[7]]['data'],
        ]), Response::MODEL_USAGE_FUNCTIONS);
    });

Http::put('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Update function')
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
    ->param('runtime', '', new WhiteList(array_keys(Config::getParam('runtimes')), true), 'Execution runtime.', true)
    ->param('execute', [], new Roles(APP_LIMIT_ARRAY_PARAMS_SIZE), 'An array of role strings with execution permissions. By default no user is granted with any execute permissions. [learn more about roles](https://appwrite.io/docs/permissions#permission-roles). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' roles are allowed, each 64 characters long.', true)
    ->param('events', [], new ArrayList(new FunctionEvent(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.', true)
    ->param('schedule', '', new Cron(), 'Schedule CRON syntax.', true)
    ->param('timeout', 15, new Range(1, (int) System::getEnv('_APP_FUNCTIONS_TIMEOUT', 900)), 'Maximum execution time in seconds.', true)
    ->param('enabled', true, new Boolean(), 'Is function enabled? When set to \'disabled\', users cannot access the function but Server SDKs with and API key can still access the function. No data is lost when this is toggled.', true)
    ->param('logging', true, new Boolean(), 'Whether executions will be logged. When set to false, executions will not be logged, but will reduce resource used by your Appwrite project.', true)
    ->param('entrypoint', '', new Text(1028, 0), 'Entrypoint File. This path is relative to the "providerRootDirectory".', true)
    ->param('commands', '', new Text(8192, 0), 'Build Commands.', true)
    ->param('installationId', '', new Text(128, 0), 'Appwrite Installation ID for VCS (Version Controle System) deployment.', true)
    ->param('providerRepositoryId', '', new Text(128, 0), 'Repository ID of the repo linked to the function', true)
    ->param('providerBranch', '', new Text(128, 0), 'Production branch for the repo linked to the function', true)
    ->param('providerSilentMode', false, new Boolean(), 'Is the VCS (Version Control System) connection in silent mode for the repo linked to the function? In silent mode, comments will not be made on commits and pull requests.', true)
    ->param('providerRootDirectory', '', new Text(128, 0), 'Path to function code in the linked repo.', true)
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForEvents')
    ->inject('queueForBuilds')
    ->inject('dbForConsole')
    ->inject('gitHub')
    ->inject('auth')
    ->action(function (string $functionId, string $name, string $runtime, array $execute, array $events, string $schedule, int $timeout, bool $enabled, bool $logging, string $entrypoint, string $commands, string $installationId, string $providerRepositoryId, string $providerBranch, bool $providerSilentMode, string $providerRootDirectory, Request $request, Response $response, Database $dbForProject, Document $project, Event $queueForEvents, Build $queueForBuilds, Database $dbForConsole, GitHub $github, Authorization $auth) use ($redeployVcs) {
        // TODO: If only branch changes, re-deploy

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $installation = $dbForConsole->getDocument('installations', $installationId);

        if (!empty($installationId) && $installation->isEmpty()) {
            throw new Exception(Exception::INSTALLATION_NOT_FOUND);
        }

        if (!empty($providerRepositoryId) && (empty($installationId) || empty($providerBranch))) {
            throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'When connecting to VCS (Version Control System), you need to provide "installationId" and "providerBranch".');
        }

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if (empty($runtime)) {
            $runtime = $function->getAttribute('runtime');
        }

        $enabled ??= $function->getAttribute('enabled', true);

        $repositoryId = $function->getAttribute('repositoryId', '');
        $repositoryInternalId = $function->getAttribute('repositoryInternalId', '');

        if (empty($entrypoint)) {
            $entrypoint = $function->getAttribute('entrypoint', '');
        }

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
            $function->getAttribute('name') !== $name ||
            $function->getAttribute('entrypoint') !== $entrypoint ||
            $function->getAttribute('commands') !== $commands ||
            $function->getAttribute('providerRootDirectory') !== $providerRootDirectory ||
            $function->getAttribute('runtime') !== $runtime
        ) {
            $live = false;
        }

        $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
            'execute' => $execute,
            'name' => $name,
            'runtime' => $runtime,
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
            'search' => implode(' ', [$functionId, $name, $runtime]),
        ])));

        // Redeploy logic
        if (!$isConnected && !empty($providerRepositoryId)) {
            $redeployVcs($request, $function, $project, $installation, $dbForProject, $queueForBuilds, new Document(), $github);
        }

        // Inform scheduler if function is still active
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        $auth->skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $queueForEvents->setParam('functionId', $function->getId());

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

Http::get('/v1/functions/:functionId/deployments/:deploymentId/download')
    ->groups(['api', 'functions'])
    ->desc('Download Deployment')
    ->label('scope', 'functions.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'functions')
    ->label('sdk.method', 'downloadDeployment')
    ->label('sdk.description', '/docs/references/functions/download-deployment.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', '*/*')
    ->label('sdk.methodType', 'location')
    ->param('functionId', '', new UID(), 'Function ID.')
    ->param('deploymentId', '', new UID(), 'Deployment ID.')
    ->inject('response')
    ->inject('request')
    ->inject('dbForProject')
    ->inject('deviceForFunctions')
    ->action(function (string $functionId, string $deploymentId, Response $response, Request $request, Database $dbForProject, Device $deviceForFunctions) {

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

        $path = $deployment->getAttribute('path', '');
        if (!$deviceForFunctions->exists($path)) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $response
            ->setContentType('application/gzip')
            ->addHeader('Expires', \date('D, d M Y H:i:s', \time() + (60 * 60 * 24 * 45)) . ' GMT') // 45 days cache
            ->addHeader('X-Peak', \memory_get_peak_usage())
            ->addHeader('Content-Disposition', 'attachment; filename="' . $deploymentId . '.tar.gz"');

        $size = $deviceForFunctions->getFileSize($path);
        $rangeHeader = $request->getHeader('range');

        if (!empty($rangeHeader)) {
            $start = $request->getRangeStart();
            $end = $request->getRangeEnd();
            $unit = $request->getRangeUnit();

            if ($end === null) {
                $end = min(($start + MAX_OUTPUT_CHUNK_SIZE - 1), ($size - 1));
            }

            if ($unit !== 'bytes' || $start >= $end || $end >= $size) {
                throw new Exception(Exception::STORAGE_INVALID_RANGE);
            }

            $response
                ->addHeader('Accept-Ranges', 'bytes')
                ->addHeader('Content-Range', 'bytes ' . $start . '-' . $end . '/' . $size)
                ->addHeader('Content-Length', $end - $start + 1)
                ->setStatusCode(Response::STATUS_CODE_PARTIALCONTENT);

            $response->send($deviceForFunctions->read($path, $start, ($end - $start + 1)));
        }

        if ($size > APP_STORAGE_READ_BUFFER) {
            for ($i = 0; $i < ceil($size / MAX_OUTPUT_CHUNK_SIZE); $i++) {
                $response->chunk(
                    $deviceForFunctions->read(
                        $path,
                        ($i * MAX_OUTPUT_CHUNK_SIZE),
                        min(MAX_OUTPUT_CHUNK_SIZE, $size - ($i * MAX_OUTPUT_CHUNK_SIZE))
                    ),
                    (($i + 1) * MAX_OUTPUT_CHUNK_SIZE) >= $size
                );
            }
        } else {
            $response->send($deviceForFunctions->read($path));
        }
    });

Http::patch('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Update function deployment')
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
    ->inject('queueForEvents')
    ->inject('dbForConsole')
    ->inject('auth')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject, Event $queueForEvents, Database $dbForConsole, Authorization $auth) {

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
        $auth->skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->dynamic($function, Response::MODEL_FUNCTION);
    });

Http::delete('/v1/functions/:functionId')
    ->groups(['api', 'functions'])
    ->desc('Delete function')
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
    ->inject('queueForDeletes')
    ->inject('queueForEvents')
    ->inject('dbForConsole')
    ->inject('auth')
    ->action(function (string $functionId, Response $response, Database $dbForProject, Delete $queueForDeletes, Event $queueForEvents, Database $dbForConsole, Authorization $auth) {

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
        $auth->skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($function);

        $queueForEvents->setParam('functionId', $function->getId());

        $response->noContent();
    });

Http::post('/v1/functions/:functionId/deployments')
    ->groups(['api', 'functions'])
    ->desc('Create deployment')
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
    ->param('entrypoint', null, new Text(1028), 'Entrypoint File.', true)
    ->param('commands', null, new Text(8192, 0), 'Build Commands.', true)
    ->param('code', [], new File(), 'Gzip file with your code package. When used with the Appwrite CLI, pass the path to your code directory, and the CLI will automatically package your code. Use a path that is within the current directory.', skipValidation: true)
    ->param('activate', false, new Boolean(true), 'Automatically activate the deployment when it is finished building.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('project')
    ->inject('deviceForFunctions')
    ->inject('deviceForLocal')
    ->inject('queueForBuilds')
    ->action(function (string $functionId, ?string $entrypoint, ?string $commands, mixed $code, bool $activate, Request $request, Response $response, Database $dbForProject, Event $queueForEvents, Document $project, Device $deviceForFunctions, Device $deviceForLocal, Build $queueForBuilds) {

        $activate = filter_var($activate, FILTER_VALIDATE_BOOLEAN);

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        if ($entrypoint === null) {
            $entrypoint = $function->getAttribute('entrypoint', '');
        }

        if ($commands === null) {
            $commands = $function->getAttribute('commands', '');
        }

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
        $fileSizeValidator = new FileSize(System::getEnv('_APP_FUNCTIONS_SIZE_LIMIT', '30000000'));
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
        $fileSize ??= $deviceForLocal->getFileSize($fileTmpName);
        $path = $deviceForFunctions->getPath($deploymentId . '.' . \pathinfo($fileName, PATHINFO_EXTENSION));
        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        $metadata = ['content_type' => $deviceForLocal->getFileMimeType($fileTmpName)];
        if (!$deployment->isEmpty()) {
            $chunks = $deployment->getAttribute('chunksTotal', 1);
            $metadata = $deployment->getAttribute('metadata', []);
            if ($chunk === -1) {
                $chunk = $chunks;
            }
        }

        $chunksUploaded = $deviceForFunctions->upload($fileTmpName, $path, $chunk, $chunks, $metadata);

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

            $fileSize = $deviceForFunctions->getFileSize($path);

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
            $queueForBuilds
                ->setType(BUILD_TYPE_DEPLOYMENT)
                ->setResource($function)
                ->setDeployment($deployment);
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

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
            ->dynamic($deployment, Response::MODEL_DEPLOYMENT);
    });

Http::get('/v1/functions/:functionId/deployments')
    ->groups(['api', 'functions'])
    ->desc('List deployments')
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

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Set resource queries
        $queries[] = Query::equal('resourceId', [$function->getId()]);
        $queries[] = Query::equal('resourceType', ['functions']);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
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

Http::get('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Get deployment')
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

Http::delete('/v1/functions/:functionId/deployments/:deploymentId')
    ->groups(['api', 'functions'])
    ->desc('Delete deployment')
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
    ->inject('queueForDeletes')
    ->inject('queueForEvents')
    ->inject('deviceForFunctions')
    ->action(function (string $functionId, string $deploymentId, Response $response, Database $dbForProject, Delete $queueForDeletes, Event $queueForEvents, Device $deviceForFunctions) {

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
            if (!($deviceForFunctions->delete($deployment->getAttribute('path', '')))) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove deployment from storage');
            }
        }

        if ($function->getAttribute('deployment') === $deployment->getId()) { // Reset function deployment
            $function = $dbForProject->updateDocument('functions', $function->getId(), new Document(array_merge($function->getArrayCopy(), [
                'deployment' => '',
                'deploymentInternalId' => '',
            ])));
        }

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($deployment);

        $response->noContent();
    });

Http::post('/v1/functions/:functionId/deployments/:deploymentId/builds/:buildId')
    ->groups(['api', 'functions'])
    ->desc('Create build')
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
    ->inject('project')
    ->inject('queueForEvents')
    ->inject('queueForBuilds')
    ->inject('auth')
    ->action(function (string $functionId, string $deploymentId, string $buildId, Request $request, Response $response, Database $dbForProject, Document $project, Event $queueForEvents, Build $queueForBuilds, Authorization $auth) {

        $function = $dbForProject->getDocument('functions', $functionId);

        if ($function->isEmpty()) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $deployment = $dbForProject->getDocument('deployments', $deploymentId);

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND);
        }

        $build = $auth->skip(fn () => $dbForProject->getDocument('builds', $buildId));

        if ($build->isEmpty()) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        $deploymentId = ID::unique();

        $deployment->removeAttribute('$internalId');
        $deployment = $dbForProject->createDocument('deployments', $deployment->setAttributes([
            '$id' => $deploymentId,
            'buildId' => '',
            'buildInternalId' => '',
            'entrypoint' => $function->getAttribute('entrypoint'),
            'commands' => $function->getAttribute('commands', ''),
            'search' => implode(' ', [$deploymentId, $function->getAttribute('entrypoint')]),
        ]));

        $queueForBuilds
            ->setType(BUILD_TYPE_DEPLOYMENT)
            ->setResource($function)
            ->setDeployment($deployment);

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('deploymentId', $deployment->getId());

        $response->noContent();
    });

Http::post('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('Create execution')
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
    ->param('body', '', new Text(0, 0), 'HTTP body of execution. Default value is empty string.', true)
    ->param('async', false, new Boolean(), 'Execute code in the background. Default value is false.', true)
    ->param('path', '/', new Text(2048), 'HTTP path of execution. Path can include query params. Default value is /', true)
    ->param('method', 'POST', new Whitelist(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true), 'HTTP method of execution. Default value is GET.', true)
    ->param('headers', [], new Assoc(), 'HTTP headers of execution. Defaults to empty.', true)
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('queueForEvents')
    ->inject('queueForUsage')
    ->inject('mode')
    ->inject('queueForFunctions')
    ->inject('geodb')
    ->inject('auth')
    ->action(function (string $functionId, string $body, bool $async, string $path, string $method, array $headers, Response $response, Document $project, Database $dbForProject, Document $user, Event $queueForEvents, Usage $queueForUsage, string $mode, Func $queueForFunctions, Reader $geodb, Authorization $auth) {

        $function = $auth->skip(fn () => $dbForProject->getDocument('functions', $functionId));

        $isAPIKey = Auth::isAppUser($auth->getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser($auth->getRoles());

        if ($function->isEmpty() || (!$function->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $version = $function->getAttribute('version', 'v2');
        $runtimes = Config::getParam($version === 'v2' ? 'runtimes-v2' : 'runtimes', []);

        $runtime = (isset($runtimes[$function->getAttribute('runtime', '')])) ? $runtimes[$function->getAttribute('runtime', '')] : null;

        if (\is_null($runtime)) {
            throw new Exception(Exception::FUNCTION_RUNTIME_UNSUPPORTED, 'Runtime "' . $function->getAttribute('runtime', '') . '" is not supported');
        }

        $deployment = $auth->skip(fn () => $dbForProject->getDocument('deployments', $function->getAttribute('deployment', '')));

        if ($deployment->getAttribute('resourceId') !== $function->getId()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
        }

        if ($deployment->isEmpty()) {
            throw new Exception(Exception::DEPLOYMENT_NOT_FOUND, 'Deployment not found. Create a deployment before trying to execute a function');
        }

        /** Check if build has completed */
        $build = $auth->skip(fn () => $dbForProject->getDocument('builds', $deployment->getAttribute('buildId', '')));
        if ($build->isEmpty()) {
            throw new Exception(Exception::BUILD_NOT_FOUND);
        }

        if ($build->getAttribute('status') !== 'ready') {
            throw new Exception(Exception::BUILD_NOT_READY);
        }

        if (!$auth->isValid(new Input('execute', $function->getAttribute('execute')))) { // Check if user has write access to execute function
            throw new Exception(Exception::USER_UNAUTHORIZED, $auth->getDescription());
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
                $jwtObj = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.
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
            if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_REQUEST)) {
                $headersFiltered[] = ['name' => $key, 'value' => $value];
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

        $queueForEvents
            ->setParam('functionId', $function->getId())
            ->setParam('executionId', $execution->getId())
            ->setContext('function', $function);

        if ($async) {
            if ($function->getAttribute('logging')) {
                /** @var Document $execution */
                $execution = $auth->skip(fn () => $dbForProject->createDocument('executions', $execution));
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
                ->setParam('functionId', $function->getId())
                ->setParam('executionId', $execution->getId())
                ->trigger();

            return $response
                ->setStatusCode(Response::STATUS_CODE_ACCEPTED)
                ->dynamic($execution, Response::MODEL_EXECUTION);
        }

        $durationStart = \microtime(true);

        $vars = [];

        // V2 vars
        if ($version === 'v2') {
            $vars = \array_merge($vars, [
                'APPWRITE_FUNCTION_TRIGGER' => $headers['x-appwrite-trigger'] ?? '',
                'APPWRITE_FUNCTION_DATA' => $body ?? '',
                'APPWRITE_FUNCTION_USER_ID' => $headers['x-appwrite-user-id'] ?? '',
                'APPWRITE_FUNCTION_JWT' => $headers['x-appwrite-user-jwt'] ?? ''
            ]);
        }

        // Shared vars
        foreach ($function->getAttribute('varsProject', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

        // Function vars
        foreach ($function->getAttribute('vars', []) as $var) {
            $vars[$var->getAttribute('key')] = $var->getAttribute('value', '');
        }

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
        $executor = new Executor(System::getEnv('_APP_EXECUTOR_HOST'));
        try {
            $version = $function->getAttribute('version', 'v2');
            $command = $runtime['startCommand'];
            $command = $version === 'v2' ? '' : 'cp /tmp/code.tar.gz /mnt/code/code.tar.gz && nohup helpers/start.sh "' . $command . '"';
            $executionResponse = $executor->createExecution(
                projectId: $project->getId(),
                deploymentId: $deployment->getId(),
                body: \strlen($body) > 0 ? $body : null,
                variables: $vars,
                timeout: $function->getAttribute('timeout', 0),
                image: $runtime['image'],
                source: $build->getAttribute('path', ''),
                entrypoint: $deployment->getAttribute('entrypoint', ''),
                version: $version,
                path: $path,
                method: $method,
                headers: $headers,
                runtimeEntrypoint: $command,
                requestTimeout: 30
            );

            $headersFiltered = [];
            foreach ($executionResponse['headers'] as $key => $value) {
                if (\in_array(\strtolower($key), FUNCTION_ALLOWLIST_HEADERS_RESPONSE)) {
                    $headersFiltered[] = ['name' => $key, 'value' => $value];
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
        } catch (\Throwable $th) {
            $durationEnd = \microtime(true);

            $execution
                ->setAttribute('duration', $durationEnd - $durationStart)
                ->setAttribute('status', 'failed')
                ->setAttribute('responseStatusCode', 500)
                ->setAttribute('errors', $th->getMessage() . '\nError Code: ' . $th->getCode());
            Console::error($th->getMessage());
        } finally {
            $queueForUsage
                ->addMetric(METRIC_EXECUTIONS, 1)
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS), 1)
                ->addMetric(METRIC_EXECUTIONS_COMPUTE, (int)($execution->getAttribute('duration') * 1000)) // per project
                ->addMetric(str_replace('{functionInternalId}', $function->getInternalId(), METRIC_FUNCTION_ID_EXECUTIONS_COMPUTE), (int)($execution->getAttribute('duration') * 1000)) // per function
            ;
        }

        if ($function->getAttribute('logging')) {
            /** @var Document $execution */
            $execution = $auth->skip(fn () => $dbForProject->createDocument('executions', $execution));
        }

        $roles = $auth->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        if (!$isPrivilegedUser && !$isAppUser) {
            $execution->setAttribute('logs', '');
            $execution->setAttribute('errors', '');
        }

        $headers = [];
        foreach (($executionResponse['headers'] ?? []) as $key => $value) {
            $headers[] = ['name' => $key, 'value' => $value];
        }

        $execution->setAttribute('responseBody', $executionResponse['body'] ?? '');
        $execution->setAttribute('responseHeaders', $headers);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($execution, Response::MODEL_EXECUTION);
    });

Http::get('/v1/functions/:functionId/executions')
    ->groups(['api', 'functions'])
    ->desc('List executions')
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
    ->inject('auth')
    ->action(function (string $functionId, array $queries, string $search, Response $response, Database $dbForProject, string $mode, Authorization $auth) {
        $function = $auth->skip(fn () => $dbForProject->getDocument('functions', $functionId));

        $isAPIKey = Auth::isAppUser($auth->getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser($auth->getRoles());

        if ($function->isEmpty() || (!$function->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Set internal queries
        $queries[] = Query::equal('functionId', [$function->getId()]);

        /**
         * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
         */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
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

        $roles = $auth->getRoles();
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

Http::get('/v1/functions/:functionId/executions/:executionId')
    ->groups(['api', 'functions'])
    ->desc('Get execution')
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
    ->inject('auth')
    ->action(function (string $functionId, string $executionId, Response $response, Database $dbForProject, string $mode, Authorization $auth) {
        $function = $auth->skip(fn () => $dbForProject->getDocument('functions', $functionId));

        $isAPIKey = Auth::isAppUser($auth->getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser($auth->getRoles());

        if ($function->isEmpty() || (!$function->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::FUNCTION_NOT_FOUND);
        }

        $execution = $dbForProject->getDocument('executions', $executionId);

        if ($execution->getAttribute('functionId') !== $function->getId()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        if ($execution->isEmpty()) {
            throw new Exception(Exception::EXECUTION_NOT_FOUND);
        }

        $roles = $auth->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        if (!$isPrivilegedUser && !$isAppUser) {
            $execution->setAttribute('logs', '');
            $execution->setAttribute('errors', '');
        }

        $response->dynamic($execution, Response::MODEL_EXECUTION);
    });

// Variables

Http::post('/v1/functions/:functionId/variables')
    ->desc('Create variable')
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
    ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', false)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('auth')
    ->action(function (string $functionId, string $key, string $value, Response $response, Database $dbForProject, Database $dbForConsole, Authorization $auth) {
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

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        $auth->skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($variable, Response::MODEL_VARIABLE);
    });

Http::get('/v1/functions/:functionId/variables')
    ->desc('List variables')
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
            'variables' => $function->getAttribute('vars', []),
            'total' => \count($function->getAttribute('vars', [])),
        ]), Response::MODEL_VARIABLE_LIST);
    });

Http::get('/v1/functions/:functionId/variables/:variableId')
    ->desc('Get variable')
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

Http::put('/v1/functions/:functionId/variables/:variableId')
    ->desc('Update variable')
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
    ->param('value', null, new Text(8192, 0), 'Variable value. Max length: 8192 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('auth')
    ->action(function (string $functionId, string $variableId, string $key, ?string $value, Response $response, Database $dbForProject, Database $dbForConsole, Authorization $auth) {

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

        $dbForProject->updateDocument('functions', $function->getId(), $function->setAttribute('live', false));

        // Inform scheduler to pull the latest changes
        $schedule = $dbForConsole->getDocument('schedules', $function->getAttribute('scheduleId'));
        $schedule
            ->setAttribute('resourceUpdatedAt', DateTime::now())
            ->setAttribute('schedule', $function->getAttribute('schedule'))
            ->setAttribute('active', !empty($function->getAttribute('schedule')) && !empty($function->getAttribute('deployment')));
        $auth->skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response->dynamic($variable, Response::MODEL_VARIABLE);
    });

Http::delete('/v1/functions/:functionId/variables/:variableId')
    ->desc('Delete variable')
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
    ->inject('response')
    ->inject('dbForProject')
    ->inject('dbForConsole')
    ->inject('auth')
    ->action(function (string $functionId, string $variableId, Response $response, Database $dbForProject, Database $dbForConsole, Authorization $auth) {
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
        $auth->skip(fn () => $dbForConsole->updateDocument('schedules', $schedule->getId(), $schedule));

        $response->noContent();
    });
