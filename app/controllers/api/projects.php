<?php

use Appwrite\Auth\Auth;
use Appwrite\Event\Delete;
use Appwrite\Event\Mail;
use Appwrite\Event\Validator\Event;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Origin;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\Validator\ProjectId;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Response;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\Abuse\Adapters\TimeLimit;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\Locale\Locale;
use Utopia\Pools\Group;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Integer;
use Utopia\Validator\Multiple;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
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
    ->desc('Create project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'create')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new ProjectId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, and hyphen. Can\'t start with a special char. Max length is 36 chars.')
    ->param('name', null, new Text(128), 'Project name. Max length: 128 chars.')
    ->param('teamId', '', new UID(), 'Team unique ID.')
    ->param('region', System::getEnv('_APP_REGION', 'default'), new Whitelist(array_keys(array_filter(Config::getParam('regions'), fn ($config) => !$config['disabled']))), 'Project Region.', true)
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
    ->inject('pools')
    ->inject('hooks')
    ->action(function (string $projectId, string $name, string $teamId, string $region, string $description, string $logo, string $url, string $legalName, string $legalCountry, string $legalState, string $legalCity, string $legalAddress, string $legalTaxId, Response $response, Database $dbForConsole, Cache $cache, Group $pools, Hooks $hooks) {

        $team = $dbForConsole->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $allowList = \array_filter(\explode(',', System::getEnv('_APP_PROJECT_REGIONS', '')));

        if (!empty($allowList) && !\in_array($region, $allowList)) {
            throw new Exception(Exception::PROJECT_REGION_UNSUPPORTED, 'Region "' . $region . '" is not supported');
        }

        $auth = Config::getParam('auth', []);
        $auths = ['limit' => 0, 'maxSessions' => APP_LIMIT_USER_SESSIONS_DEFAULT, 'passwordHistory' => 0, 'passwordDictionary' => false, 'duration' => Auth::TOKEN_EXPIRATION_LOGIN_LONG, 'personalDataCheck' => false];
        foreach ($auth as $index => $method) {
            $auths[$method['key'] ?? ''] = true;
        }

        $projectId = ($projectId == 'unique()') ? ID::unique() : $projectId;

        $backups['database_db_fra1_v14x_02'] = ['from' => '03:00', 'to' => '05:00'];
        $backups['database_db_fra1_v14x_03'] = ['from' => '00:00', 'to' => '02:00'];
        $backups['database_db_fra1_v14x_04'] = ['from' => '00:00', 'to' => '02:00'];
        $backups['database_db_fra1_v14x_05'] = ['from' => '00:00', 'to' => '02:00'];
        $backups['database_db_fra1_v14x_06'] = ['from' => '00:00', 'to' => '02:00'];
        $backups['database_db_fra1_v14x_07'] = ['from' => '00:00', 'to' => '02:00'];

        $databases = Config::getParam('pools-database', []);

        /**
         * Remove databases from the list that are currently undergoing an backup
         */
        if (count($databases) > 1) {
            $now = new \DateTime();

            foreach ($databases as $index => $database) {
                if (empty($backups[$database])) {
                    continue;
                }
                $backup = $backups[$database];
                $from = \DateTime::createFromFormat('H:i', $backup['from']);
                $to = \DateTime::createFromFormat('H:i', $backup['to']);
                if ($now >= $from && $now <= $to) {
                    unset($databases[$index]);
                    break;
                }
            }
        }

        $databaseOverride = System::getEnv('_APP_DATABASE_OVERRIDE', null);
        $index = array_search($databaseOverride, $databases);
        if ($index !== false) {
            $database = $databases[$index];
        } else {
            $database = $databases[array_rand($databases)];
        }

        if ($projectId === 'console') {
            throw new Exception(Exception::PROJECT_RESERVED_PROJECT, "'console' is a reserved project.");
        }

        try {
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
                'oAuthProviders' => [],
                'webhooks' => null,
                'keys' => null,
                'auths' => $auths,
                'search' => implode(' ', [$projectId, $name]),
                'database' => $database
            ]));
        } catch (Duplicate $th) {
            throw new Exception(Exception::PROJECT_ALREADY_EXISTS);
        }

        $dbForProject = new Database($pools->get($database)->pop()->getResource(), $cache);
        $dbForProject->setNamespace("_{$project->getInternalId()}");
        $dbForProject->create();

        $audit = new Audit($dbForProject);
        $audit->setup();

        $adapter = new TimeLimit('', 0, 1, $dbForProject);
        $adapter->setup();

        /** @var array $collections */
        $collections = Config::getParam('collections', [])['projects'] ?? [];

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

        $hooks->trigger('afterProjectCreation', [ $project, $pools, $cache ]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($project, Response::MODEL_PROJECT);
    });

App::get('/v1/projects')
    ->desc('List projects')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'list')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT_LIST)
    ->param('queries', [], new Projects(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Projects::ALLOWED_ATTRIBUTES), true)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (array $queries, string $search, Response $response, Database $dbForConsole) {

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
    ->desc('Get project')
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

App::patch('/v1/projects/:projectId')
    ->desc('Update project')
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

App::patch('/v1/projects/:projectId/team')
    ->desc('Update Project Team')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateTeam')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('teamId', '', new UID(), 'Team ID of the team to transfer project to.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $teamId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);
        $team = $dbForConsole->getDocument('teams', $teamId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $permissions = [
            Permission::read(Role::team(ID::custom($teamId))),
            Permission::update(Role::team(ID::custom($teamId), 'owner')),
            Permission::update(Role::team(ID::custom($teamId), 'developer')),
            Permission::delete(Role::team(ID::custom($teamId), 'owner')),
            Permission::delete(Role::team(ID::custom($teamId), 'developer')),
        ];

        $project
            ->setAttribute('teamId', $teamId)
            ->setAttribute('teamInternalId', $team->getInternalId())
            ->setAttribute('$permissions', $permissions);
        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project);

        $installations = $dbForConsole->find('installations', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);
        foreach ($installations as $installation) {
            $installation->getAttribute('$permissions', $permissions);
            $dbForConsole->updateDocument('installations', $installation->getId(), $installation);
        }

        $repositories = $dbForConsole->find('repositories', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);
        foreach ($repositories as $repository) {
            $repository->getAttribute('$permissions', $permissions);
            $dbForConsole->updateDocument('repositories', $repository->getId(), $repository);
        }

        $vcsComments = $dbForConsole->find('vcsComments', [
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);
        foreach ($vcsComments as $vcsComment) {
            $vcsComment->getAttribute('$permissions', $permissions);
            $dbForConsole->updateDocument('vcsComments', $vcsComment->getId(), $vcsComment);
        }

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
    ->param('service', '', new WhiteList(array_keys(array_filter(Config::getParam('services'), fn ($element) => $element['optional'])), true), 'Service name.')
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

App::patch('/v1/projects/:projectId/service/all')
    ->desc('Update all service status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateServiceStatusAll')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('status', null, new Boolean(), 'Service status.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, bool $status, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $allServices = array_keys(array_filter(Config::getParam('services'), fn ($element) => $element['optional']));

        $services = [];
        foreach ($allServices as $service) {
            $services[$service] = $status;
        }

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('services', $services));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/api')
    ->desc('Update API status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateApiStatus')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('api', '', new WhiteList(array_keys(Config::getParam('apis')), true), 'API name.')
    ->param('status', null, new Boolean(), 'API status.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $api, bool $status, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $apis = $project->getAttribute('apis', []);
        $apis[$api] = $status;

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('apis', $apis));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/api/all')
    ->desc('Update all API status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateApiStatusAll')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('status', null, new Boolean(), 'API status.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, bool $status, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $allApis = array_keys(Config::getParam('apis'));

        $apis = [];
        foreach ($allApis as $api) {
            $apis[$api] = $status;
        }

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('apis', $apis));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/oauth2')
    ->desc('Update project OAuth2')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateOAuth2')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'Provider Name')
    ->param('appId', null, new Text(256), 'Provider app ID. Max length: 256 chars.', true)
    ->param('secret', null, new text(512), 'Provider secret key. Max length: 512 chars.', true)
    ->param('enabled', null, new Boolean(), 'Provider status. Set to \'false\' to disable new session creation.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $provider, ?string $appId, ?string $secret, ?bool $enabled, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $providers = $project->getAttribute('oAuthProviders', []);

        if ($appId !== null) {
            $providers[$provider . 'Appid'] = $appId;
        }

        if ($secret !== null) {
            $providers[$provider . 'Secret'] = $secret;
        }

        if ($enabled !== null) {
            $providers[$provider . 'Enabled'] = $enabled;
        }

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('oAuthProviders', $providers));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/limit')
    ->desc('Update project users limit')
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

App::patch('/v1/projects/:projectId/auth/duration')
    ->desc('Update project authentication duration')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateAuthDuration')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('duration', 31536000, new Range(0, 31536000), 'Project session length in seconds. Max length: 31536000 seconds.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, int $duration, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['duration'] = $duration;

        $dbForConsole->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/:method')
    ->desc('Update project auth method status. Use this endpoint to enable or disable a given auth method for this project.')
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

App::patch('/v1/projects/:projectId/auth/password-history')
    ->desc('Update authentication password history. Use this endpoint to set the number of password history to save and 0 to disable password history.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateAuthPasswordHistory')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('limit', 0, new Range(0, APP_LIMIT_USER_PASSWORD_HISTORY), 'Set the max number of passwords to store in user history. User can\'t choose a new password that is already stored in the password history list.  Max number of passwords allowed in history is' . APP_LIMIT_USER_PASSWORD_HISTORY . '. Default value is 0')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordHistory'] = $limit;

        $dbForConsole->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/password-dictionary')
    ->desc('Update authentication password dictionary status. Use this endpoint to enable or disable the dicitonary check for user password')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateAuthPasswordDictionary')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('enabled', false, new Boolean(false), 'Set whether or not to enable checking user\'s password against most commonly used passwords. Default is false.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordDictionary'] = $enabled;

        $dbForConsole->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/personal-data')
    ->desc('Enable or disable checking user passwords for similarity with their personal data.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updatePersonalDataCheck')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('enabled', false, new Boolean(false), 'Set whether or not to check a password for similarity with personal data. Default is false.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['personalDataCheck'] = $enabled;

        $dbForConsole->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/max-sessions')
    ->desc('Update project user sessions limit')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateAuthSessionsLimit')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('limit', false, new Range(1, APP_LIMIT_USER_SESSIONS_MAX), 'Set the max number of users allowed in this project. Value allowed is between 1-' . APP_LIMIT_USER_SESSIONS_MAX . '. Default is ' . APP_LIMIT_USER_SESSIONS_DEFAULT)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['maxSessions'] = $limit;

        $dbForConsole->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::delete('/v1/projects/:projectId')
    ->desc('Delete project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'delete')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForConsole')
    ->inject('queueForDeletes')
    ->action(function (string $projectId, Response $response, Document $user, Database $dbForConsole, Delete $queueForDeletes) {
        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($project);

        if (!$dbForConsole->deleteDocument('projects', $projectId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove project from DB');
        }

        $response->noContent();
    });

// Webhooks

App::post('/v1/projects/:projectId/webhooks')
    ->desc('Create webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createWebhook')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('name', null, new Text(128), 'Webhook name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(true), 'Enable or disable a webhook.', true)
    ->param('events', null, new ArrayList(new Event(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
    ->param('url', '', fn ($request) => new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING), 'Webhook URL.', false, ['request'])
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpUser', '', new Text(256), 'Webhook HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Webhook HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $name, bool $enabled, array $events, string $url, bool $security, string $httpUser, string $httpPass, Response $response, Database $dbForConsole) {

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
            'enabled' => $enabled,
        ]);

        $webhook = $dbForConsole->createDocument('webhooks', $webhook);

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::get('/v1/projects/:projectId/webhooks')
    ->desc('List webhooks')
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
    ->desc('Get webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getWebhook')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('webhookId', '', new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForConsole->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($webhook === false || $webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::put('/v1/projects/:projectId/webhooks/:webhookId')
    ->desc('Update webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateWebhook')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('webhookId', '', new UID(), 'Webhook unique ID.')
    ->param('name', null, new Text(128), 'Webhook name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(true), 'Enable or disable a webhook.', true)
    ->param('events', null, new ArrayList(new Event(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
    ->param('url', '', fn ($request) => new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING), 'Webhook URL.', false, ['request'])
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpUser', '', new Text(256), 'Webhook HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Webhook HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $webhookId, string $name, bool $enabled, array $events, string $url, bool $security, string $httpUser, string $httpPass, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $security = ($security === '1' || $security === 'true' || $security === 1 || $security === true);

        $webhook = $dbForConsole->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
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
            ->setAttribute('enabled', $enabled);

        if ($enabled) {
            $webhook->setAttribute('attempts', 0);
        }

        $dbForConsole->updateDocument('webhooks', $webhook->getId(), $webhook);
        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::patch('/v1/projects/:projectId/webhooks/:webhookId/signature')
    ->desc('Update webhook signature key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateWebhookSignature')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_WEBHOOK)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('webhookId', '', new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForConsole->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($webhook === false || $webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $webhook->setAttribute('signatureKey', \bin2hex(\random_bytes(64)));

        $dbForConsole->updateDocument('webhooks', $webhook->getId(), $webhook);
        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::delete('/v1/projects/:projectId/webhooks/:webhookId')
    ->desc('Delete webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteWebhook')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('webhookId', '', new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForConsole->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($webhook === false || $webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $dbForConsole->deleteDocument('webhooks', $webhook->getId());

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    });

// Keys

App::post('/v1/projects/:projectId/keys')
    ->desc('Create key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createKey')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
    ->param('scopes', null, new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.')
    ->param('expire', null, new DatetimeValidator(), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Use null for unlimited expiration.', true)
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

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($key, Response::MODEL_KEY);
    });

App::get('/v1/projects/:projectId/keys')
    ->desc('List keys')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listKeys')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
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
    ->desc('Get key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getKey')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('keyId', '', new UID(), 'Key unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $keyId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForConsole->findOne('keys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $response->dynamic($key, Response::MODEL_KEY);
    });

App::put('/v1/projects/:projectId/keys/:keyId')
    ->desc('Update key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateKey')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_KEY)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('keyId', '', new UID(), 'Key unique ID.')
    ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
    ->param('scopes', null, new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
    ->param('expire', null, new DatetimeValidator(), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Use null for unlimited expiration.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $keyId, string $name, array $scopes, ?string $expire, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForConsole->findOne('keys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $key
            ->setAttribute('name', $name)
            ->setAttribute('scopes', $scopes)
            ->setAttribute('expire', $expire);

        $dbForConsole->updateDocument('keys', $key->getId(), $key);

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($key, Response::MODEL_KEY);
    });

App::delete('/v1/projects/:projectId/keys/:keyId')
    ->desc('Delete key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteKey')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('keyId', '', new UID(), 'Key unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $keyId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForConsole->findOne('keys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($key === false || $key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $dbForConsole->deleteDocument('keys', $key->getId());

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    });

// Platforms

App::post('/v1/projects/:projectId/platforms')
    ->desc('Create platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createPlatform')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PLATFORM)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', null, new WhiteList([Origin::CLIENT_TYPE_WEB, Origin::CLIENT_TYPE_FLUTTER_WEB, Origin::CLIENT_TYPE_FLUTTER_IOS, Origin::CLIENT_TYPE_FLUTTER_ANDROID, Origin::CLIENT_TYPE_FLUTTER_LINUX, Origin::CLIENT_TYPE_FLUTTER_MACOS, Origin::CLIENT_TYPE_FLUTTER_WINDOWS, Origin::CLIENT_TYPE_APPLE_IOS, Origin::CLIENT_TYPE_APPLE_MACOS,  Origin::CLIENT_TYPE_APPLE_WATCHOS, Origin::CLIENT_TYPE_APPLE_TVOS, Origin::CLIENT_TYPE_ANDROID, Origin::CLIENT_TYPE_UNITY], true), 'Platform type.')
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

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::get('/v1/projects/:projectId/platforms')
    ->desc('List platforms')
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
            Query::equal('projectInternalId', [$project->getInternalId()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'platforms' => $platforms,
            'total' => count($platforms),
        ]), Response::MODEL_PLATFORM_LIST);
    });

App::get('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Get platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getPlatform')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PLATFORM)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('platformId', '', new UID(), 'Platform unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $platformId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = $dbForConsole->findOne('platforms', [
            Query::equal('$id', [$platformId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($platform === false || $platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $response->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::put('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Update platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updatePlatform')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PLATFORM)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('platformId', '', new UID(), 'Platform unique ID.')
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
            Query::equal('$id', [$platformId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($platform === false || $platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $platform
            ->setAttribute('name', $name)
            ->setAttribute('key', $key)
            ->setAttribute('store', $store)
            ->setAttribute('hostname', $hostname);

        $dbForConsole->updateDocument('platforms', $platform->getId(), $platform);

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::delete('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Delete platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deletePlatform')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('platformId', '', new UID(), 'Platform unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $platformId, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = $dbForConsole->findOne('platforms', [
            Query::equal('$id', [$platformId]),
            Query::equal('projectInternalId', [$project->getInternalId()]),
        ]);

        if ($platform === false || $platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $dbForConsole->deleteDocument('platforms', $platformId);

        $dbForConsole->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    });


// CUSTOM SMTP and Templates
App::patch('/v1/projects/:projectId/smtp')
    ->desc('Update SMTP')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateSmtp')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('enabled', false, new Boolean(), 'Enable custom SMTP service')
    ->param('senderName', '', new Text(255, 0), 'Name of the email sender', true)
    ->param('senderEmail', '', new Email(), 'Email of the sender', true)
    ->param('replyTo', '', new Email(), 'Reply to email', true)
    ->param('host', '', new HostName(), 'SMTP server host name', true)
    ->param('port', 587, new Integer(), 'SMTP server port', true)
    ->param('username', '', new Text(0, 0), 'SMTP server username', true)
    ->param('password', '', new Text(0, 0), 'SMTP server password', true)
    ->param('secure', '', new WhiteList(['tls', 'ssl'], true), 'Does SMTP server use secure connection', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, bool $enabled, string $senderName, string $senderEmail, string $replyTo, string $host, int $port, string $username, string $password, string $secure, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        // Ensure required params for when enabling SMTP
        if ($enabled) {
            if (empty($senderName)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Sender name is required when enabling SMTP.');
            } elseif (empty($senderEmail)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Sender email is required when enabling SMTP.');
            } elseif (empty($host)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Host is required when enabling SMTP.');
            } elseif (empty($port)) {
                throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Port is required when enabling SMTP.');
            }
        }

        // validate SMTP settings
        if ($enabled) {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Username = $username;
            $mail->Password = $password;
            $mail->Host = $host;
            $mail->Port = $port;
            $mail->SMTPSecure = $secure;
            $mail->SMTPAutoTLS = false;
            $mail->Timeout = 5;

            try {
                $valid = $mail->SmtpConnect();

                if (!$valid) {
                    throw new Exception('Connection is not valid.');
                }
            } catch (Throwable $error) {
                throw new Exception(Exception::PROJECT_SMTP_CONFIG_INVALID, 'Could not connect to SMTP server: ' . $error->getMessage());
            }
        }

        // Save SMTP settings
        if ($enabled) {
            $smtp = [
                'enabled' => $enabled,
                'senderName' => $senderName,
                'senderEmail' => $senderEmail,
                'replyTo' => $replyTo,
                'host' => $host,
                'port' => $port,
                'username' => $username,
                'password' => $password,
                'secure' => $secure,
            ];
        } else {
            $smtp = [
                'enabled' => false
            ];
        }

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('smtp', $smtp));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::post('/v1/projects/:projectId/smtp/tests')
    ->desc('Create SMTP test')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createSmtpTest')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('emails', [], new ArrayList(new Email(), 10), 'Array of emails to send test email to. Maximum of 10 emails are allowed.')
    ->param('senderName', System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'), new Text(255, 0), 'Name of the email sender')
    ->param('senderEmail', System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), new Email(), 'Email of the sender')
    ->param('replyTo', '', new Email(), 'Reply to email', true)
    ->param('host', '', new HostName(), 'SMTP server host name')
    ->param('port', 587, new Integer(), 'SMTP server port', true)
    ->param('username', '', new Text(0, 0), 'SMTP server username', true)
    ->param('password', '', new Text(0, 0), 'SMTP server password', true)
    ->param('secure', '', new WhiteList(['tls'], true), 'Does SMTP server use secure connection', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('queueForMails')
    ->action(function (string $projectId, array $emails, string $senderName, string $senderEmail, string $replyTo, string $host, int $port, string $username, string $password, string $secure, Response $response, Database $dbForConsole, Mail $queueForMails) {
        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $replyToEmail = !empty($replyTo) ? $replyTo : $senderEmail;

        $subject = 'Custom SMTP email sample';
        $template = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-smtp-test.tpl');
        $template
            ->setParam('{{from}}', "{$senderName} ({$senderEmail})")
            ->setParam('{{replyTo}}', "{$senderName} ({$replyToEmail})");

        foreach ($emails as $email) {
            $queueForMails
                ->setSmtpHost($host)
                ->setSmtpPort($port)
                ->setSmtpUsername($username)
                ->setSmtpPassword($password)
                ->setSmtpSecure($secure)
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName)
                ->setRecipient($email)
                ->setName('')
                ->setbodyTemplate(__DIR__ . '/../../config/locale/templates/email-base-styled.tpl')
                ->setBody($template->render())
                ->setVariables([])
                ->setSubject($subject)
                ->trigger();
        }

        return $response->noContent();
    });

App::get('/v1/projects/:projectId/templates/sms/:type/:locale')
    ->desc('Get custom SMS template')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getSmsTemplate')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SMS_TEMPLATE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['sms'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForConsole) {

        throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['sms.' . $type . '-' . $locale] ?? null;

        if (is_null($template)) {
            $template = [
                'message' => Template::fromFile(__DIR__ . '/../../config/locale/templates/sms-base.tpl')->render(),
            ];
        }

        $template['type'] = $type;
        $template['locale'] = $locale;

        $response->dynamic(new Document($template), Response::MODEL_SMS_TEMPLATE);
    });


App::get('/v1/projects/:projectId/templates/email/:type/:locale')
    ->desc('Get custom email template')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getEmailTemplate')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EMAIL_TEMPLATE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['email.' . $type . '-' . $locale] ?? null;

        $localeObj = new Locale($locale);
        if (is_null($template)) {
            $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
            $message
                ->setParam('{{hello}}', $localeObj->getText("emails.{$type}.hello"))
                ->setParam('{{footer}}', $localeObj->getText("emails.{$type}.footer"))
                ->setParam('{{body}}', $localeObj->getText('emails.' . $type . '.body'), escapeHtml: false)
                ->setParam('{{thanks}}', $localeObj->getText("emails.{$type}.thanks"))
                ->setParam('{{signature}}', $localeObj->getText("emails.{$type}.signature"))
                ->setParam('{{direction}}', $localeObj->getText('settings.direction'));
            $message = $message->render();

            $template = [
                'message' => $message,
                'subject' => $localeObj->getText('emails.' . $type . '.subject'),
                'senderEmail' => '',
                'senderName' => ''
            ];
        }

        $template['type'] = $type;
        $template['locale'] = $locale;

        $response->dynamic(new Document($template), Response::MODEL_EMAIL_TEMPLATE);
    });

App::patch('/v1/projects/:projectId/templates/sms/:type/:locale')
    ->desc('Update custom SMS template')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateSmsTemplate')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SMS_TEMPLATE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['sms'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->param('message', '', new Text(0), 'Template message')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $type, string $locale, string $message, Response $response, Database $dbForConsole) {

        throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $templates['sms.' . $type . '-' . $locale] = [
            'message' => $message
        ];

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

        $response->dynamic(new Document([
            'message' => $message,
            'type' => $type,
            'locale' => $locale,
        ]), Response::MODEL_SMS_TEMPLATE);
    });

App::patch('/v1/projects/:projectId/templates/email/:type/:locale')
    ->desc('Update custom email templates')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateEmailTemplate')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PROJECT)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->param('subject', '', new Text(255), 'Email Subject')
    ->param('message', '', new Text(0), 'Template message')
    ->param('senderName', '', new Text(255, 0), 'Name of the email sender', true)
    ->param('senderEmail', '', new Email(), 'Email of the sender', true)
    ->param('replyTo', '', new Email(), 'Reply to email', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $type, string $locale, string $subject, string $message, string $senderName, string $senderEmail, string $replyTo, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $templates['email.' . $type . '-' . $locale] = [
            'senderName' => $senderName,
            'senderEmail' => $senderEmail,
            'subject' => $subject,
            'replyTo' => $replyTo,
            'message' => $message
        ];

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

        $response->dynamic(new Document([
            'type' => $type,
            'locale' => $locale,
            'senderName' => $senderName,
            'senderEmail' => $senderEmail,
            'subject' => $subject,
            'replyTo' => $replyTo,
            'message' => $message
        ]), Response::MODEL_EMAIL_TEMPLATE);
    });

App::delete('/v1/projects/:projectId/templates/sms/:type/:locale')
    ->desc('Reset custom SMS template')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteSmsTemplate')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SMS_TEMPLATE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['sms'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForConsole) {

        throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['sms.' . $type . '-' . $locale] ?? null;

        if (is_null($template)) {
            throw new Exception(Exception::PROJECT_TEMPLATE_DEFAULT_DELETION);
        }

        unset($template['sms.' . $type . '-' . $locale]);

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

        $response->dynamic(new Document([
            'type' => $type,
            'locale' => $locale,
            'message' => $template['message']
        ]), Response::MODEL_SMS_TEMPLATE);
    });

App::delete('/v1/projects/:projectId/templates/email/:type/:locale')
    ->desc('Reset custom email template')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteEmailTemplate')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_EMAIL_TEMPLATE)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForConsole) {

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['email.' . $type . '-' . $locale] ?? null;

        if (is_null($template)) {
            throw new Exception(Exception::PROJECT_TEMPLATE_DEFAULT_DELETION);
        }

        unset($templates['email.' . $type . '-' . $locale]);

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

        $response->dynamic(new Document([
            'type' => $type,
            'locale' => $locale,
            'senderName' => $template['senderName'],
            'senderEmail' => $template['senderEmail'],
            'subject' => $template['subject'],
            'replyTo' => $template['replyTo'],
            'message' => $template['message']
        ]), Response::MODEL_EMAIL_TEMPLATE);
    });
