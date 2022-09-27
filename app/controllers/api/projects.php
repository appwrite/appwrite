<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Database\DatabasePool;
use Appwrite\Event\Certificate;
use Appwrite\Event\Delete;
use Appwrite\Event\Validator\Event;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Network\Validator\Domain as DomainValidator;
use Appwrite\Network\Validator\Origin;
use Appwrite\Network\Validator\URL;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\ID;
use Utopia\Database\DateTime;
use Utopia\Database\Permission;
use Utopia\Database\Query;
use Utopia\Database\Role;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Registry\Registry;
use Appwrite\Extend\Exception;
use Utopia\Cache\Adapter\Redis;
use Utopia\Cache\Cache;
use Utopia\Database\Adapter\MariaDB;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

App::init()
    ->groups(['projects'])
    ->inject('project')
    ->action(function (Document $project) {
        if ($project->getId() !== 'console') {
            throw new Exception(Exception::GENERAL_ACCESS_FORBIDDEN);
        }
    });

App::post('/v1/projects')
    ->desc('Create Project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'create')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', null, new Text(128), 'Project name. Max length: 128 chars.')
    ->param('teamId', '', new UID(), 'Team unique ID.')
    ->param('region', '', new Whitelist(array_keys(array_filter(Config::getParam('regions'), fn($config) => !$config['disabled']))), 'Project Region.')
    ->param('description', '', new Text(256), 'Project description. Max length: 256 chars.', true)
    ->param('logo', '', new Text(1024), 'Project logo.', true)
    ->param('url', '', new URL(), 'Project URL.', true)
    ->param('legalName', '', new Text(256), 'Project legal Name. Max length: 256 chars.', true)
    ->param('legalCountry', '', new Text(256), 'Project legal Country. Max length: 256 chars.', true)
    ->param('legalState', '', new Text(256), 'Project legal State. Max length: 256 chars.', true)
    ->param('legalCity', '', new Text(256), 'Project legal City. Max length: 256 chars.', true)
    ->param('legalAddress', '', new Text(256), 'Project legal Address. Max length: 256 chars.', true)
    ->param('legalTaxId', '', new Text(256), 'Project legal Tax ID. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('cache')
    ->inject('dbPool')
    ->action(function (string $projectId, string $name, string $teamId, string $region, string $description, string $logo, string $url, string $legalName, string $legalCountry, string $legalState, string $legalCity, string $legalAddress, string $legalTaxId, Response $response, Database $dbForConsole, \Redis $cache, DatabasePool $dbPool) {

        $team = $dbForConsole->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $auth = Config::getParam('auth', []);
        $auths = ['limit' => 0];
        foreach ($auth as $index => $method) {
            $auths[$method['key'] ?? ''] = true;
        }

        $projectId = ($projectId == 'unique()') ? ID::unique() : $projectId;

        if ($projectId === 'console') {
            throw new Exception(Exception::PROJECT_RESERVED_PROJECT, "'console' is a reserved project.");
        }

        $pdo = $dbPool->getAnyFromPool();

        $project = $dbForConsole->createDocument('projects', new Document([
            '$id' => $projectId,
            '$permissions' => [
                Permission::read(Role::team(ID::custom($teamId))),
                Permission::update(Role::team(ID::custom($teamId), 'owner')),
                Permission::update(Role::team(ID::custom($teamId), 'developer')),
                Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                Permission::delete(Role::team(ID::custom($teamId), 'developer')),
            ],
            'name' => $name,
            'teamInternalId' => $team->getInternalId(),
            'teamId' => $team->getId(),
            'region' => $region,
            'description' => $description,
            'logo' => $logo,
            'url' => $url,
            'version' => APP_VERSION_STABLE,
            'legalName' => $legalName,
            'legalCountry' => $legalCountry,
            'legalState' => $legalState,
            'legalCity' => $legalCity,
            'legalAddress' => $legalAddress,
            'legalTaxId' => ID::custom($legalTaxId),
            'services' => new stdClass(),
            'platforms' => null,
            'authProviders' => [],
            'webhooks' => null,
            'keys' => null,
            'domains' => null,
            'auths' => $auths,
            'search' => implode(' ', [$projectId, $name]),
            'database' => $pdo->getName()
        ]));

        $dbForProject = DatabasePool::getDatabase($pdo->getConnection(), $cache, "_{$project->getInternalId()}");
        $dbForProject->create(App::getEnv('_APP_DB_SCHEMA', 'appwrite'));

        $audit = new Audit($dbForProject);
        $audit->setup();

        $adapter = new TimeLimit('', 0, 1, $dbForProject);
        $adapter->setup();

        /** @var array $collections */
        $collections = Config::getParam('collections', []);

        foreach ($collections as $key => $collection) {
            if (($collection['$collection'] ?? '') !== Database::METADATA) {
                continue;
            }

            $attributes = [];
            $indexes = [];

            foreach ($collection['attributes'] as $attribute) {
                $attributes[] = new Document([
                    '$id' => $attribute['$id'],
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'filters' => $attribute['filters'],
                    'default' => $attribute['default'] ?? null,
                    'format' => $attribute['format'] ?? ''
                ]);
            }

            foreach ($collection['indexes'] as $index) {
                $indexes[] = new Document([
                    '$id' => $index['$id'],
                    'type' => $index['type'],
                    'attributes' => $index['attributes'],
                    'lengths' => $index['lengths'],
                    'orders' => $index['orders'],
                ]);
            }
            $dbForProject->createCollection($key, $attributes, $indexes);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($project, Response::MODEL_PROJECT);
    });

App::get('/v1/projects')
    ->desc('List Projects')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'list')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT_LIST)
    ->param('queries', [], new Projects(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/databases#querying-documents). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Projects::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (array $queries, string $search, Response $response, Database $dbForConsole) {

        $queries = Query::parseQueries($queries);

        if (!empty($search)) {
            $queries[] = Query::search('search', $search);
        }

        // Get cursor document if there was a cursor query
        $cursor = Query::getByType($queries, Query::TYPE_CURSORAFTER, Query::TYPE_CURSORBEFORE);
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $projectId = $cursor->getValue();
            $cursorDocument = $dbForConsole->getDocument('projects', $projectId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Project '{$projectId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $response->dynamic(new Document([
            'projects' => $dbForConsole->find('projects', $queries),
            'total' => $dbForConsole->count('projects', $filterQueries, APP_LIMIT_COUNT),
        ]), Response::MODEL_PROJECT_LIST);
    });

App::get('/v1/projects/:projectId')
    ->desc('Get Project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'get')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::get('/v1/projects/:projectId/usage')
    ->desc('Get usage stats for a project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('dbForProject')
    ->inject('register')
    ->action(function (string $projectId, string $range, Response $response, Database $dbForConsole, Database $dbForProject, Registry $register) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $periods = [
                '24h' => [
                    'period' => '30m',
                    'limit' => 48,
                ],
                '7d' => [
                    'period' => '1d',
                    'limit' => 7,
                ],
                '30d' => [
                    'period' => '1d',
                    'limit' => 30,
                ],
                '90d' => [
                    'period' => '1d',
                    'limit' => 90,
                ],
            ];

            $dbForProject->setNamespace("_{$project->getInternalId()}");

            $metrics = [
                'project.$all.network.requests',
                'project.$all.network.bandwidth',
                'project.$all.storage.size',
                'users.$all.count.total',
                'collections.$all.count.total',
                'documents.$all.count.total',
                'executions.$all.compute.total',
            ];

            $stats = [];

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        Query::equal('period', [$period]),
                        Query::equal('metric', [$metric]),
                        Query::limit($limit),
                        Query::orderDesc('time'),
                    ]);

                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }

                    // backfill metrics with empty values for graphs
                    $backfill = $limit - \count($requestDocs);
                    while ($backfill > 0) {
                        $last = $limit - $backfill - 1; // array index of last added metric
                        $diff = match ($period) { // convert period to seconds for unix timestamp math
                            '30m' => 1800,
                            '1d' => 86400,
                        };
                        $stats[$metric][] = [
                            'value' => 0,
                            'date' => DateTime::addSeconds(new \DateTime($stats[$metric][$last]['date'] ?? null), -1 * $diff),
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'requests' => $stats[$metrics[0]] ?? [],
                'network' => $stats[$metrics[1]] ?? [],
                'storage' => $stats[$metrics[2]] ?? [],
                'users' => $stats[$metrics[3]] ?? [],
                'collections' => $stats[$metrics[4]] ?? [],
                'documents' => $stats[$metrics[5]] ?? [],
                'executions' => $stats[$metrics[6]] ?? [],
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_PROJECT);
    });

App::patch('/v1/projects/:projectId')
    ->desc('Update Project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'update')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('name', null, new Text(128), 'Project name. Max length: 128 chars.')
    ->param('description', '', new Text(256), 'Project description. Max length: 256 chars.', true)
    ->param('logo', '', new Text(1024), 'Project logo.', true)
    ->param('url', '', new URL(), 'Project URL.', true)
    ->param('legalName', '', new Text(256), 'Project legal name. Max length: 256 chars.', true)
    ->param('legalCountry', '', new Text(256), 'Project legal country. Max length: 256 chars.', true)
    ->param('legalState', '', new Text(256), 'Project legal state. Max length: 256 chars.', true)
    ->param('legalCity', '', new Text(256), 'Project legal city. Max length: 256 chars.', true)
    ->param('legalAddress', '', new Text(256), 'Project legal address. Max length: 256 chars.', true)
    ->param('legalTaxId', '', new Text(256), 'Project legal tax ID. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $name, string $description, string $logo, string $url, string $legalName, string $legalCountry, string $legalState, string $legalCity, string $legalAddress, string $legalTaxId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute('name', $name)
                ->setAttribute('description', $description)
                ->setAttribute('logo', $logo)
                ->setAttribute('url', $url)
                ->setAttribute('legalName', $legalName)
                ->setAttribute('legalCountry', $legalCountry)
                ->setAttribute('legalState', $legalState)
                ->setAttribute('legalCity', $legalCity)
                ->setAttribute('legalAddress', $legalAddress)
                ->setAttribute('legalTaxId', $legalTaxId)
                ->setAttribute('search', implode(' ', [$projectId, $name])));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/service')
    ->desc('Update service status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateServiceStatus')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('service', '', new WhiteList(array_keys(array_filter(Config::getParam('services'), fn($element) => $element['optional'])), true), 'Service name.')
    ->param('status', null, new Boolean(), 'Service status.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $service, bool $status, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $services = $project->getAttribute('services', []);
        $services[$service] = $status;

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('services', $services));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/oauth2')
    ->desc('Update Project OAuth2')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateOAuth2')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'Provider Name', false)
    ->param('appId', '', new Text(256), 'Provider app ID. Max length: 256 chars.', true)
    ->param('secret', '', new text(512), 'Provider secret key. Max length: 512 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $provider, string $appId, string $secret, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $providers = $project->getAttribute('authProviders', []);
        $providers[$provider . 'Appid'] = $appId;
        $providers[$provider . 'Secret'] = $secret;

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('authProviders', $providers));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/limit')
    ->desc('Update Project users limit')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateAuthLimit')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('limit', false, new Range(0, APP_LIMIT_USERS), 'Set the max number of users allowed in this project. Use 0 for unlimited.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['limit'] = $limit;

        $dbForConsole->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/:method')
    ->desc('Update Project auth method status. Use this endpoint to enable or disable a given auth method for this project.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateAuthStatus')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('method', '', new WhiteList(\array_keys(Config::getParam('auth')), true), 'Auth Method. Possible values: ' . implode(',', \array_keys(Config::getParam('auth'))), false)
    ->param('status', false, new Boolean(true), 'Set the status of this auth method.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $method, bool $status, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);
        $auth = Config::getParam('auth')[$method] ?? [];
        $authKey = $auth['key'] ?? '';
        $status = ($status === '1' || $status === 'true' || $status === 1 || $status === true);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths[$authKey] = $status;

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::delete('/v1/projects/:projectId')
    ->desc('Delete Project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'delete')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('password', '', new Password(), 'Your user password for confirmation. Must be at least 8 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForConsole')
    ->inject('deletes')
    ->action(function (string $projectId, string $password, Response $response, Document $user, Database $dbForConsole, Delete $deletes) {

        if (!Auth::passwordVerify($password, $user->getAttribute('password'), $user->getAttribute('hash'), $user->getAttribute('hashOptions'))) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($project)
        ;

        if (!$dbForConsole->deleteDocument('teams', $project->getAttribute('teamId', null))) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove project team from DB');
        }

        if (!$dbForConsole->deleteDocument('projects', $projectId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove project from DB');
        }

        $response->noContent();
    });

// Webhooks

App::post('/v1/projects/:projectId/webhooks')
    ->desc('Create Webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createWebhook')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('name', null, new Text(128), 'Webhook name. Max length: 128 chars.')
    ->param('events', null, new ArrayList(new Event(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
    ->param('url', null, new URL(['http', 'https']), 'Webhook URL.')
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpUser', '', new Text(256), 'Webhook HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Webhook HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $name, array $events, string $url, bool $security, string $httpUser, string $httpPass, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $security = (bool) filter_var($security, FILTER_VALIDATE_BOOLEAN);

        $webhook = new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'projectInternalId' => $project->getInternalId(),
            'projectId' => $project->getId(),
            'name' => $name,
            'events' => $events,
            'url' => $url,
            'security' => $security,
            'httpUser' => $httpUser,
            'httpPass' => $httpPass,
            'signatureKey' => \bin2hex(\random_bytes(64)),
        ]);

        $webhook = $dbForConsole->createDocument('webhooks', $webhook);

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::get('/v1/projects/:projectId/webhooks')
    ->desc('List Webhooks')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listWebhooks')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhooks = $dbForConsole->find('webhooks', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'webhooks' => $webhooks,
            'total' => count($webhooks),
        ]), Response::MODEL_WEBHOOK_LIST);
    });

App::get('/v1/projects/:projectId/webhooks/:webhookId')
    ->desc('Get Webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getWebhook')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('webhookId', null, new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForConsole->findOne('webhooks', [
            Query::equal('_uid', [$webhookId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($webhook === false || $webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::put('/v1/projects/:projectId/webhooks/:webhookId')
    ->desc('Update Webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateWebhook')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('webhookId', null, new UID(), 'Webhook unique ID.')
    ->param('name', null, new Text(128), 'Webhook name. Max length: 128 chars.')
    ->param('events', null, new ArrayList(new Event(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
    ->param('url', null, new URL(['http', 'https']), 'Webhook URL.')
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpUser', '', new Text(256), 'Webhook HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Webhook HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $webhookId, string $name, array $events, string $url, bool $security, string $httpUser, string $httpPass, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $security = ($security === '1' || $security === 'true' || $security === 1 || $security === true);

        $webhook = $dbForConsole->findOne('webhooks', [
            Query::equal('_uid', [$webhookId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($webhook === false || $webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $webhook
            ->setAttribute('name', $name)
            ->setAttribute('events', $events)
            ->setAttribute('url', $url)
            ->setAttribute('security', $security)
            ->setAttribute('httpUser', $httpUser)
            ->setAttribute('httpPass', $httpPass)
        ;

        $dbForConsole->updateDocument('webhooks', $webhook->getId(), $webhook);
        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::patch('/v1/projects/:projectId/webhooks/:webhookId/signature')
    ->desc('Update Webhook Signature Key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateWebhookSignature')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('webhookId', null, new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForConsole->findOne('webhooks', [
            Query::equal('_uid', [$webhookId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($webhook === false || $webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $webhook->setAttribute('signatureKey', \bin2hex(\random_bytes(64)));

        $dbForConsole->updateDocument('webhooks', $webhook->getId(), $webhook);
        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::delete('/v1/projects/:projectId/webhooks/:webhookId')
    ->desc('Delete Webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteWebhook')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('webhookId', null, new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForConsole->findOne('webhooks', [
            Query::equal('_uid', [$webhookId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($webhook === false || $webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $dbForConsole->deleteDocument('webhooks', $webhook->getId());

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response->noContent();
    });

// Keys

App::post('/v1/projects/:projectId/keys')
    ->desc('Create Key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createKey')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
    ->param('scopes', null, new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.')
    ->param('expire', null, new DatetimeValidator(), 'Expiration time in ISO 8601 format. Use null for unlimited expiration.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $name, array $scopes, ?string $expire, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'projectInternalId' => $project->getInternalId(),
            'projectId' => $project->getId(),
            'name' => $name,
            'scopes' => $scopes,
            'expire' => $expire,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => \bin2hex(\random_bytes(128)),
        ]);

        $key = $dbForConsole->createDocument('keys', $key);

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($key, Response::MODEL_KEY);
    });

App::get('/v1/projects/:projectId/keys')
    ->desc('List Keys')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listKeys')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY_LIST)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $keys = $dbForConsole->find('keys', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'keys' => $keys,
            'total' => count($keys),
        ]), Response::MODEL_KEY_LIST);
    });

App::get('/v1/projects/:projectId/keys/:keyId')
    ->desc('Get Key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getKey')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('keyId', null, new UID(), 'Key unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $keyId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForConsole->findOne('keys', [
            Query::equal('_uid', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $response->dynamic($key, Response::MODEL_KEY);
    });

App::put('/v1/projects/:projectId/keys/:keyId')
    ->desc('Update Key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateKey')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('keyId', null, new UID(), 'Key unique ID.')
    ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
    ->param('scopes', null, new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
    ->param('expire', null, new DatetimeValidator(), 'Expiration time in ISO 8601 format. Use null for unlimited expiration.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $keyId, string $name, array $scopes, ?string $expire, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForConsole->findOne('keys', [
            Query::equal('_uid', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $key
            ->setAttribute('name', $name)
            ->setAttribute('scopes', $scopes)
            ->setAttribute('expire', $expire)
        ;

        $dbForConsole->updateDocument('keys', $key->getId(), $key);

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response->dynamic($key, Response::MODEL_KEY);
    });

App::delete('/v1/projects/:projectId/keys/:keyId')
    ->desc('Delete Key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteKey')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('keyId', null, new UID(), 'Key unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $keyId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForConsole->findOne('keys', [
            Query::equal('_uid', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $dbForConsole->deleteDocument('keys', $key->getId());

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response->noContent();
    });

// Platforms

App::post('/v1/projects/:projectId/platforms')
    ->desc('Create Platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createPlatform')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PLATFORM)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('type', null, new WhiteList([Origin::CLIENT_TYPE_WEB, Origin::CLIENT_TYPE_FLUTTER_IOS, Origin::CLIENT_TYPE_FLUTTER_ANDROID, Origin::CLIENT_TYPE_FLUTTER_LINUX, Origin::CLIENT_TYPE_FLUTTER_MACOS, Origin::CLIENT_TYPE_FLUTTER_WINDOWS, Origin::CLIENT_TYPE_APPLE_IOS, Origin::CLIENT_TYPE_APPLE_MACOS,  Origin::CLIENT_TYPE_APPLE_WATCHOS, Origin::CLIENT_TYPE_APPLE_TVOS, Origin::CLIENT_TYPE_ANDROID, Origin::CLIENT_TYPE_UNITY], true), 'Platform type.')
    ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
    ->param('key', '', new Text(256), 'Package name for Android or bundle ID for iOS or macOS. Max length: 256 chars.', true)
    ->param('store', '', new Text(256), 'App store or Google Play store ID. Max length: 256 chars.', true)
    ->param('hostname', '', new Hostname(), 'Platform client hostname. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $type, string $name, string $key, string $store, string $hostname, Response $response, Database $dbForConsole) {
        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'projectInternalId' => $project->getInternalId(),
            'projectId' => $project->getId(),
            'type' => $type,
            'name' => $name,
            'key' => $key,
            'store' => $store,
            'hostname' => $hostname
        ]);

        $platform = $dbForConsole->createDocument('platforms', $platform);

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::get('/v1/projects/:projectId/platforms')
    ->desc('List Platforms')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listPlatforms')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PLATFORM_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platforms = $dbForConsole->find('platforms', [
            Query::equal('projectId', [$project->getId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'platforms' => $platforms,
            'total' => count($platforms),
        ]), Response::MODEL_PLATFORM_LIST);
    });

App::get('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Get Platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getPlatform')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PLATFORM)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('platformId', null, new UID(), 'Platform unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $platformId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = $dbForConsole->findOne('platforms', [
            Query::equal('_uid', [$platformId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($platform === false || $platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $response->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::put('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Update Platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updatePlatform')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PLATFORM)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('platformId', null, new UID(), 'Platform unique ID.')
    ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
    ->param('key', '', new Text(256), 'Package name for android or bundle ID for iOS. Max length: 256 chars.', true)
    ->param('store', '', new Text(256), 'App store or Google Play store ID. Max length: 256 chars.', true)
    ->param('hostname', '', new Hostname(), 'Platform client URL. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $platformId, string $name, string $key, string $store, string $hostname, Response $response, Database $dbForConsole) {
        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = $dbForConsole->findOne('platforms', [
            Query::equal('_uid', [$platformId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($platform === false || $platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $platform
            ->setAttribute('name', $name)
            ->setAttribute('key', $key)
            ->setAttribute('store', $store)
            ->setAttribute('hostname', $hostname)
        ;

        $dbForConsole->updateDocument('platforms', $platform->getId(), $platform);

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::delete('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Delete Platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deletePlatform')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('platformId', null, new UID(), 'Platform unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $platformId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = $dbForConsole->findOne('platforms', [
            Query::equal('_uid', [$platformId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($platform === false || $platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $dbForConsole->deleteDocument('platforms', $platformId);

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response->noContent();
    });

// Domains

App::post('/v1/projects/:projectId/domains')
    ->desc('Create Domain')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createDomain')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOMAIN)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('domain', null, new DomainValidator(), 'Domain name.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $domain, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $document = $dbForConsole->findOne('domains', [
            Query::equal('domain', [$domain]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($document && !$document->isEmpty()) {
            throw new Exception(Exception::DOMAIN_ALREADY_EXISTS);
        }

        $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

        if (!$target->isKnown() || $target->isTest()) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unreachable CNAME target (' . $target->get() . '), please use a domain with a public suffix.');
        }

        $domain = new Domain($domain);

        $domain = new Document([
            '$id' => ID::unique(),
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::any()),
                Permission::delete(Role::any()),
            ],
            'projectInternalId' => $project->getInternalId(),
            'projectId' => $project->getId(),
            'updated' => DateTime::now(),
            'domain' => $domain->get(),
            'tld' => $domain->getSuffix(),
            'registerable' => $domain->getRegisterable(),
            'verification' => false,
            'certificateId' => null,
        ]);

        $domain = $dbForConsole->createDocument('domains', $domain);

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($domain, Response::MODEL_DOMAIN);
    });

App::get('/v1/projects/:projectId/domains')
    ->desc('List Domains')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listDomains')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOMAIN_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $domains = $dbForConsole->find('domains', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'domains' => $domains,
            'total' => count($domains),
        ]), Response::MODEL_DOMAIN_LIST);
    });

App::get('/v1/projects/:projectId/domains/:domainId')
    ->desc('Get Domain')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getDomain')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOMAIN)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('domainId', null, new UID(), 'Domain unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $domainId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $domain = $dbForConsole->findOne('domains', [
            Query::equal('_uid', [$domainId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($domain === false || $domain->isEmpty()) {
            throw new Exception(Exception::DOMAIN_NOT_FOUND);
        }

        $response->dynamic($domain, Response::MODEL_DOMAIN);
    });

App::patch('/v1/projects/:projectId/domains/:domainId/verification')
    ->desc('Update Domain Verification Status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateDomainVerification')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_DOMAIN)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('domainId', null, new UID(), 'Domain unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $domainId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $domain = $dbForConsole->findOne('domains', [
            Query::equal('_uid', [$domainId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($domain === false || $domain->isEmpty()) {
            throw new Exception(Exception::DOMAIN_NOT_FOUND);
        }

        $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

        if (!$target->isKnown() || $target->isTest()) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Unreachable CNAME target (' . $target->get() . '), please use a domain with a public suffix.');
        }

        if ($domain->getAttribute('verification') === true) {
            return $response->dynamic($domain, Response::MODEL_DOMAIN);
        }

        $validator = new CNAME($target->get()); // Verify Domain with DNS records

        if (!$validator->isValid($domain->getAttribute('domain', ''))) {
            throw new Exception(Exception::DOMAIN_VERIFICATION_FAILED);
        }


        $dbForConsole->updateDocument('domains', $domain->getId(), $domain->setAttribute('verification', true));
        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        // Issue a TLS certificate when domain is verified
        $event = new Certificate();
        $event
            ->setDomain($domain)
            ->trigger();

        $response->dynamic($domain, Response::MODEL_DOMAIN);
    });

App::delete('/v1/projects/:projectId/domains/:domainId')
    ->desc('Delete Domain')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteDomain')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('domainId', null, new UID(), 'Domain unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('deletes')
    ->action(function (string $projectId, string $domainId, Response $response, Database $dbForConsole, Delete $deletes) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $domain = $dbForConsole->findOne('domains', [
            Query::equal('_uid', [$domainId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($domain === false || $domain->isEmpty()) {
            throw new Exception(Exception::DOMAIN_NOT_FOUND);
        }

        $dbForConsole->deleteDocument('domains', $domain->getId());

        $dbForConsole->deleteCachedDocument('projects', $project->getId());

        $deletes
            ->setType(DELETE_TYPE_CERTIFICATES)
            ->setDocument($domain);

        $response->noContent();
    });
