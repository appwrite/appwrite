<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Validator\MockNumber;
use Appwrite\Event\Delete;
use Appwrite\Event\Mail;
use Appwrite\Event\Validator\Event;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Network\Platform;
use Appwrite\Network\Validator\Email;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\ContentType;
use Appwrite\SDK\Deprecated;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Template\Template;
use Appwrite\Utopia\Database\Validator\ProjectId;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use PHPMailer\PHPMailer\PHPMailer;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Cache\Cache;
use Utopia\Config\Config;
use Utopia\Database\Adapter\Pool as DatabasePool;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Datetime as DatetimeValidator;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Validator\PublicDomain;
use Utopia\DSN\DSN;
use Utopia\Locale\Locale;
use Utopia\Pools\Group;
use Utopia\System\System;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Hostname;
use Utopia\Validator\Integer;
use Utopia\Validator\Multiple;
use Utopia\Validator\Nullable;
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
    ->label('audits.event', 'projects.create')
    ->label('audits.resource', 'project/{response.$id}')
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'create',
        description: '/docs/references/projects/create.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
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
    ->inject('request')
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('cache')
    ->inject('pools')
    ->inject('hooks')
    ->action(function (string $projectId, string $name, string $teamId, string $region, string $description, string $logo, string $url, string $legalName, string $legalCountry, string $legalState, string $legalCity, string $legalAddress, string $legalTaxId, Request $request, Response $response, Database $dbForPlatform, Cache $cache, Group $pools, Hooks $hooks) {

        $team = $dbForPlatform->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception(Exception::TEAM_NOT_FOUND);
        }

        $allowList = \array_filter(\explode(',', System::getEnv('_APP_PROJECT_REGIONS', '')));

        if (!empty($allowList) && !\in_array($region, $allowList)) {
            throw new Exception(Exception::PROJECT_REGION_UNSUPPORTED, 'Region "' . $region . '" is not supported');
        }

        $auth = Config::getParam('auth', []);
        $auths = [
            'limit' => 0,
            'maxSessions' => APP_LIMIT_USER_SESSIONS_DEFAULT,
            'passwordHistory' => 0,
            'passwordDictionary' => false,
            'duration' => TOKEN_EXPIRATION_LOGIN_LONG,
            'personalDataCheck' => false,
            'mockNumbers' => [],
            'sessionAlerts' => false,
            'membershipsUserName' => false,
            'membershipsUserEmail' => false,
            'membershipsMfa' => false,
            'invalidateSessions' => true
        ];

        foreach ($auth as $method) {
            $auths[$method['key'] ?? ''] = true;
        }

        $projectId = ($projectId == 'unique()') ? ID::unique() : $projectId;

        if ($projectId === 'console') {
            throw new Exception(Exception::PROJECT_RESERVED_PROJECT, "'console' is a reserved project.");
        }

        $databases = Config::getParam('pools-database', []);

        if ($region !== 'default') {
            $databaseKeys = System::getEnv('_APP_DATABASE_KEYS', '');
            $keys = explode(',', $databaseKeys);
            $databases = array_filter($keys, function ($value) use ($region) {
                return str_contains($value, $region);
            });
        }

        $databaseOverride = System::getEnv('_APP_DATABASE_OVERRIDE');
        $index = \array_search($databaseOverride, $databases);
        if ($index !== false) {
            $dsn = $databases[$index];
        } else {
            $dsn = $databases[array_rand($databases)];
        }

        // TODO: Temporary until all projects are using shared tables.
        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));

        if (\in_array($dsn, $sharedTables)) {
            $schema = 'appwrite';
            $database = 'appwrite';
            $namespace = System::getEnv('_APP_DATABASE_SHARED_NAMESPACE', '');
            $dsn = $schema . '://' . $dsn . '?database=' . $database;

            if (!empty($namespace)) {
                $dsn .= '&namespace=' . $namespace;
            }
        }

        try {
            $project = $dbForPlatform->createDocument('projects', new Document([
                '$id' => $projectId,
                '$permissions' => [
                    Permission::read(Role::team(ID::custom($teamId))),
                    Permission::update(Role::team(ID::custom($teamId), 'owner')),
                    Permission::update(Role::team(ID::custom($teamId), 'developer')),
                    Permission::delete(Role::team(ID::custom($teamId), 'owner')),
                    Permission::delete(Role::team(ID::custom($teamId), 'developer')),
                ],
                'name' => $name,
                'teamInternalId' => $team->getSequence(),
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
                'accessedAt' => DateTime::now(),
                'search' => implode(' ', [$projectId, $name]),
                'database' => $dsn,
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::PROJECT_ALREADY_EXISTS);
        }

        try {
            $dsn = new DSN($dsn);
        } catch (\InvalidArgumentException) {
            // TODO: Temporary until all projects are using shared tables
            $dsn = new DSN('mysql://' . $dsn);
        }

        $sharedTables = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES', ''));
        $sharedTablesV1 = \explode(',', System::getEnv('_APP_DATABASE_SHARED_TABLES_V1', ''));
        $projectTables = !\in_array($dsn->getHost(), $sharedTables);
        $sharedTablesV1 = \in_array($dsn->getHost(), $sharedTablesV1);
        $sharedTablesV2 = !$projectTables && !$sharedTablesV1;
        $sharedTables = $sharedTablesV1 || $sharedTablesV2;

        if (!$sharedTablesV2) {
            $adapter = new DatabasePool($pools->get($dsn->getHost()));
            $dbForProject = new Database($adapter, $cache);

            if ($sharedTables) {
                $dbForProject
                    ->setSharedTables(true)
                    ->setTenant($sharedTablesV1 ? (int)$project->getSequence() : null)
                    ->setNamespace($dsn->getParam('namespace'));
            } else {
                $dbForProject
                    ->setSharedTables(false)
                    ->setTenant(null)
                    ->setNamespace('_' . $project->getSequence());
            }

            $create = true;

            try {
                $dbForProject->create();
            } catch (Duplicate) {
                $create = false;
            }

            if ($create || $projectTables) {
                $audit = new Audit($dbForProject);
                $audit->setup();
            }

            if (!$create && $sharedTablesV1) {
                $attributes = \array_map(fn ($attribute) => new Document($attribute), Audit::ATTRIBUTES);
                $indexes = \array_map(fn (array $index) => new Document($index), Audit::INDEXES);
                $dbForProject->createDocument(Database::METADATA, new Document([
                    '$id' => ID::custom('audit'),
                    '$permissions' => [Permission::create(Role::any())],
                    'name' => 'audit',
                    'attributes' => $attributes,
                    'indexes' => $indexes,
                    'documentSecurity' => true
                ]));
            }

            if ($create || $sharedTablesV1) {
                /** @var array $collections */
                $collections = Config::getParam('collections', [])['projects'] ?? [];

                foreach ($collections as $key => $collection) {
                    if (($collection['$collection'] ?? '') !== Database::METADATA) {
                        continue;
                    }

                    $attributes = \array_map(fn ($attribute) => new Document($attribute), $collection['attributes']);
                    $indexes = \array_map(fn (array $index) => new Document($index), $collection['indexes']);

                    try {
                        $dbForProject->createCollection($key, $attributes, $indexes);
                    } catch (Duplicate) {
                        $dbForProject->createDocument(Database::METADATA, new Document([
                            '$id' => ID::custom($key),
                            '$permissions' => [Permission::create(Role::any())],
                            'name' => $key,
                            'attributes' => $attributes,
                            'indexes' => $indexes,
                            'documentSecurity' => true
                        ]));
                    }
                }
            }
        }

        // Hook allowing instant project mirroring during migration
        // Outside of migration, hook is not registered and has no effect
        $hooks->trigger('afterProjectCreation', [ $project, $pools, $cache ]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($project, Response::MODEL_PROJECT);
    });

App::get('/v1/projects/:projectId')
    ->desc('Get project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'get',
        description: '/docs/references/projects/get.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId')
    ->desc('Update project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('audits.event', 'projects.update')
    ->label('audits.resource', 'project/{request.projectId}')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'update',
        description: '/docs/references/projects/update.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
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
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $name, string $description, string $logo, string $url, string $legalName, string $legalCountry, string $legalState, string $legalCity, string $legalAddress, string $legalTaxId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project
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
    ->desc('Update project team')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'updateTeam',
        description: '/docs/references/projects/update-team.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('teamId', '', new UID(), 'Team ID of the team to transfer project to.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $teamId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);
        $team = $dbForPlatform->getDocument('teams', $teamId);

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
            ->setAttribute('teamInternalId', $team->getSequence())
            ->setAttribute('$permissions', $permissions);
        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project);

        $installations = $dbForPlatform->find('installations', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($installations as $installation) {
            $installation->getAttribute('$permissions', $permissions);
            $dbForPlatform->updateDocument('installations', $installation->getId(), $installation);
        }

        $repositories = $dbForPlatform->find('repositories', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($repositories as $repository) {
            $repository->getAttribute('$permissions', $permissions);
            $dbForPlatform->updateDocument('repositories', $repository->getId(), $repository);
        }

        $vcsComments = $dbForPlatform->find('vcsComments', [
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);
        foreach ($vcsComments as $vcsComment) {
            $vcsComment->getAttribute('$permissions', $permissions);
            $dbForPlatform->updateDocument('vcsComments', $vcsComment->getId(), $vcsComment);
        }

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/service')
    ->desc('Update service status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'updateServiceStatus',
        description: '/docs/references/projects/update-service-status.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('service', '', new WhiteList(array_keys(array_filter(Config::getParam('services'), fn ($element) => $element['optional'])), true), 'Service name.')
    ->param('status', null, new Boolean(), 'Service status.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $service, bool $status, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $services = $project->getAttribute('services', []);
        $services[$service] = $status;

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('services', $services));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/service/all')
    ->desc('Update all service status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'updateServiceStatusAll',
        description: '/docs/references/projects/update-service-status-all.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('status', null, new Boolean(), 'Service status.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $status, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $allServices = array_keys(array_filter(Config::getParam('services'), fn ($element) => $element['optional']));

        $services = [];
        foreach ($allServices as $service) {
            $services[$service] = $status;
        }

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('services', $services));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/api')
    ->desc('Update API status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', [
        new Method(
            namespace: 'projects',
            group: 'projects',
            name: 'updateApiStatus',
            description: '/docs/references/projects/update-api-status.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_PROJECT,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'projects.updateAPIStatus',
            ),
            public: false,
        ),
        new Method(
            namespace: 'projects',
            group: 'projects',
            name: 'updateAPIStatus',
            description: '/docs/references/projects/update-api-status.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_PROJECT,
                )
            ]
        )
    ])
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('api', '', new WhiteList(array_keys(Config::getParam('apis')), true), 'API name.')
    ->param('status', null, new Boolean(), 'API status.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $api, bool $status, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $apis = $project->getAttribute('apis', []);
        $apis[$api] = $status;

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('apis', $apis));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/api/all')
    ->desc('Update all API status')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', [
        new Method(
            namespace: 'projects',
            group: 'projects',
            name: 'updateApiStatusAll',
            description: '/docs/references/projects/update-api-status-all.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_PROJECT,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'projects.updateAPIStatusAll',
            ),
            public: false,
        ),
        new Method(
            namespace: 'projects',
            group: 'projects',
            name: 'updateAPIStatusAll',
            description: '/docs/references/projects/update-api-status-all.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_PROJECT,
                )
            ]
        )
    ])
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('status', null, new Boolean(), 'API status.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $status, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $allApis = array_keys(Config::getParam('apis'));

        $apis = [];
        foreach ($allApis as $api) {
            $apis[$api] = $status;
        }

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('apis', $apis));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/oauth2')
    ->desc('Update project OAuth2')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateOAuth2',
        description: '/docs/references/projects/update-oauth2.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'Provider Name')
    ->param('appId', null, new Nullable(new Text(256)), 'Provider app ID. Max length: 256 chars.', true)
    ->param('secret', null, new Nullable(new text(512)), 'Provider secret key. Max length: 512 chars.', true)
    ->param('enabled', null, new Nullable(new Boolean()), 'Provider status. Set to \'false\' to disable new session creation.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $provider, ?string $appId, ?string $secret, ?bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

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

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('oAuthProviders', $providers));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/session-alerts')
    ->desc('Update project sessions emails')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateSessionAlerts',
        description: '/docs/references/projects/update-session-alerts.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('alerts', false, new Boolean(true), 'Set to true to enable session emails.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $alerts, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['sessionAlerts'] = $alerts;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/memberships-privacy')
    ->desc('Update project memberships privacy attributes')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateMembershipsPrivacy',
        description: '/docs/references/projects/update-memberships-privacy.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('userName', true, new Boolean(true), 'Set to true to show userName to members of a team.')
    ->param('userEmail', true, new Boolean(true), 'Set to true to show email to members of a team.')
    ->param('mfa', true, new Boolean(true), 'Set to true to show mfa to members of a team.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $userName, bool $userEmail, bool $mfa, Response $response, Database $dbForPlatform) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);

        $auths['membershipsUserName'] = $userName;
        $auths['membershipsUserEmail'] = $userEmail;
        $auths['membershipsMfa'] = $mfa;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/limit')
    ->desc('Update project users limit')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthLimit',
        description: '/docs/references/projects/update-auth-limit.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('limit', false, new Range(0, APP_LIMIT_USERS), 'Set the max number of users allowed in this project. Use 0 for unlimited.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['limit'] = $limit;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/duration')
    ->desc('Update project authentication duration')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthDuration',
        description: '/docs/references/projects/update-auth-duration.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('duration', 31536000, new Range(0, 31536000), 'Project session length in seconds. Max length: 31536000 seconds.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $duration, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['duration'] = $duration;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/:method')
    ->desc('Update project auth method status. Use this endpoint to enable or disable a given auth method for this project.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthStatus',
        description: '/docs/references/projects/update-auth-status.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('method', '', new WhiteList(\array_keys(Config::getParam('auth')), true), 'Auth Method. Possible values: ' . implode(',', \array_keys(Config::getParam('auth'))), false)
    ->param('status', false, new Boolean(true), 'Set the status of this auth method.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $method, bool $status, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);
        $auth = Config::getParam('auth')[$method] ?? [];
        $authKey = $auth['key'] ?? '';
        $status = ($status === '1' || $status === 'true' || $status === 1 || $status === true);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths[$authKey] = $status;

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/password-history')
    ->desc('Update authentication password history. Use this endpoint to set the number of password history to save and 0 to disable password history.')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordHistory',
        description: '/docs/references/projects/update-auth-password-history.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('limit', 0, new Range(0, APP_LIMIT_USER_PASSWORD_HISTORY), 'Set the max number of passwords to store in user history. User can\'t choose a new password that is already stored in the password history list.  Max number of passwords allowed in history is' . APP_LIMIT_USER_PASSWORD_HISTORY . '. Default value is 0')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordHistory'] = $limit;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/password-dictionary')
    ->desc('Update authentication password dictionary status. Use this endpoint to enable or disable the dicitonary check for user password')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthPasswordDictionary',
        description: '/docs/references/projects/update-auth-password-dictionary.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('enabled', false, new Boolean(false), 'Set whether or not to enable checking user\'s password against most commonly used passwords. Default is false.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['passwordDictionary'] = $enabled;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/personal-data')
    ->desc('Update personal data check')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updatePersonalDataCheck',
        description: '/docs/references/projects/update-personal-data-check.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('enabled', false, new Boolean(false), 'Set whether or not to check a password for similarity with personal data. Default is false.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['personalDataCheck'] = $enabled;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/max-sessions')
    ->desc('Update project user sessions limit')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateAuthSessionsLimit',
        description: '/docs/references/projects/update-auth-sessions-limit.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('limit', false, new Range(1, APP_LIMIT_USER_SESSIONS_MAX), 'Set the max number of users allowed in this project. Value allowed is between 1-' . APP_LIMIT_USER_SESSIONS_MAX . '. Default is ' . APP_LIMIT_USER_SESSIONS_DEFAULT)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, int $limit, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['maxSessions'] = $limit;

        $dbForPlatform->updateDocument('projects', $project->getId(), $project
            ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::patch('/v1/projects/:projectId/auth/mock-numbers')
    ->desc('Update the mock numbers for the project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateMockNumbers',
        description: '/docs/references/projects/update-mock-numbers.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('numbers', '', new ArrayList(new MockNumber(), 10), 'An array of mock numbers and their corresponding verification codes (OTPs). Each number should be a valid E.164 formatted phone number. Maximum of 10 numbers are allowed.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, array $numbers, Response $response, Database $dbForPlatform) {

        $uniqueNumbers = [];
        foreach ($numbers as $number) {
            if (isset($uniqueNumbers[$number['phone']])) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST, 'Duplicate phone numbers are not allowed.');
            }
            $uniqueNumbers[$number['phone']] = $number['otp'];
        }

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);

        $auths['mockNumbers'] = $numbers;

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::delete('/v1/projects/:projectId')
    ->desc('Delete project')
    ->groups(['api', 'projects'])
    ->label('audits.event', 'projects.delete')
    ->label('audits.resource', 'project/{request.projectId}')
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'projects',
        name: 'delete',
        description: '/docs/references/projects/delete.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForPlatform')
    ->inject('queueForDeletes')
    ->action(function (string $projectId, Response $response, Document $user, Database $dbForPlatform, Delete $queueForDeletes) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $queueForDeletes
            ->setProject($project)
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($project);

        if (!$dbForPlatform->deleteDocument('projects', $projectId)) {
            throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to remove project from DB');
        }

        $response->noContent();
    });

// Webhooks

App::post('/v1/projects/:projectId/webhooks')
    ->desc('Create webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'webhooks',
        name: 'createWebhook',
        description: '/docs/references/projects/create-webhook.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_WEBHOOK,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('name', null, new Text(128), 'Webhook name. Max length: 128 chars.')
    ->param('enabled', true, new Boolean(true), 'Enable or disable a webhook.', true)
    ->param('events', null, new ArrayList(new Event(), APP_LIMIT_ARRAY_PARAMS_SIZE), 'Events list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
    ->param('url', '', fn ($request) => new Multiple([new URL(['http', 'https']), new PublicDomain()], Multiple::TYPE_STRING), 'Webhook URL.', false, ['request'])
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpUser', '', new Text(256), 'Webhook HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Webhook HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $name, bool $enabled, array $events, string $url, bool $security, string $httpUser, string $httpPass, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

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
            'projectInternalId' => $project->getSequence(),
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

        $webhook = $dbForPlatform->createDocument('webhooks', $webhook);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::get('/v1/projects/:projectId/webhooks')
    ->desc('List webhooks')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'webhooks',
        name: 'listWebhooks',
        description: '/docs/references/projects/list-webhooks.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_WEBHOOK_LIST,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $includeTotal, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhooks = $dbForPlatform->find('webhooks', [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'webhooks' => $webhooks,
            'total' => $includeTotal ? count($webhooks) : 0,
        ]), Response::MODEL_WEBHOOK_LIST);
    });

App::get('/v1/projects/:projectId/webhooks/:webhookId')
    ->desc('Get webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'webhooks',
        name: 'getWebhook',
        description: '/docs/references/projects/get-webhook.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_WEBHOOK,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('webhookId', '', new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForPlatform->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::put('/v1/projects/:projectId/webhooks/:webhookId')
    ->desc('Update webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'webhooks',
        name: 'updateWebhook',
        description: '/docs/references/projects/update-webhook.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_WEBHOOK,
            )
        ]
    ))
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
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $webhookId, string $name, bool $enabled, array $events, string $url, bool $security, string $httpUser, string $httpPass, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $security = ($security === '1' || $security === 'true' || $security === 1 || $security === true);

        $webhook = $dbForPlatform->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($webhook->isEmpty()) {
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

        $dbForPlatform->updateDocument('webhooks', $webhook->getId(), $webhook);
        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::patch('/v1/projects/:projectId/webhooks/:webhookId/signature')
    ->desc('Update webhook signature key')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'webhooks',
        name: 'updateWebhookSignature',
        description: '/docs/references/projects/update-webhook-signature.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_WEBHOOK,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('webhookId', '', new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForPlatform->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $webhook->setAttribute('signatureKey', \bin2hex(\random_bytes(64)));

        $dbForPlatform->updateDocument('webhooks', $webhook->getId(), $webhook);
        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($webhook, Response::MODEL_WEBHOOK);
    });

App::delete('/v1/projects/:projectId/webhooks/:webhookId')
    ->desc('Delete webhook')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'webhooks',
        name: 'deleteWebhook',
        description: '/docs/references/projects/delete-webhook.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('webhookId', '', new UID(), 'Webhook unique ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $webhookId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $webhook = $dbForPlatform->findOne('webhooks', [
            Query::equal('$id', [$webhookId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($webhook->isEmpty()) {
            throw new Exception(Exception::WEBHOOK_NOT_FOUND);
        }

        $dbForPlatform->deleteDocument('webhooks', $webhook->getId());

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    });

// Keys

App::post('/v1/projects/:projectId/keys')
    ->desc('Create key')
    ->groups(['api', 'projects'])
    ->label('scope', 'keys.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'keys',
        name: 'createKey',
        description: '/docs/references/projects/create-key.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_KEY,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
    ->param('scopes', null, new Nullable(new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.')
    ->param('expire', null, new Nullable(new DatetimeValidator()), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Use null for unlimited expiration.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $name, array $scopes, ?string $expire, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

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
            'projectInternalId' => $project->getSequence(),
            'projectId' => $project->getId(),
            'name' => $name,
            'scopes' => $scopes,
            'expire' => $expire,
            'sdks' => [],
            'accessedAt' => null,
            'secret' => API_KEY_STANDARD . '_' . \bin2hex(\random_bytes(128)),
        ]);

        $key = $dbForPlatform->createDocument('keys', $key);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($key, Response::MODEL_KEY);
    });

App::get('/v1/projects/:projectId/keys')
    ->desc('List keys')
    ->groups(['api', 'projects'])
    ->label('scope', 'keys.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'keys',
        name: 'listKeys',
        description: '/docs/references/projects/list-keys.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_KEY_LIST,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $includeTotal, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $keys = $dbForPlatform->find('keys', [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'keys' => $keys,
            'total' => $includeTotal ? count($keys) : 0,
        ]), Response::MODEL_KEY_LIST);
    });

App::get('/v1/projects/:projectId/keys/:keyId')
    ->desc('Get key')
    ->groups(['api', 'projects'])
    ->label('scope', 'keys.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'keys',
        name: 'getKey',
        description: '/docs/references/projects/get-key.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_KEY,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('keyId', '', new UID(), 'Key unique ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $keyId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForPlatform->findOne('keys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $response->dynamic($key, Response::MODEL_KEY);
    });

App::put('/v1/projects/:projectId/keys/:keyId')
    ->desc('Update key')
    ->groups(['api', 'projects'])
    ->label('scope', 'keys.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'keys',
        name: 'updateKey',
        description: '/docs/references/projects/update-key.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_KEY,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('keyId', '', new UID(), 'Key unique ID.')
    ->param('name', null, new Text(128), 'Key name. Max length: 128 chars.')
    ->param('scopes', null, new Nullable(new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE)), 'Key scopes list. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' events are allowed.')
    ->param('expire', null, new Nullable(new DatetimeValidator()), 'Expiration time in [ISO 8601](https://www.iso.org/iso-8601-date-and-time-format.html) format. Use null for unlimited expiration.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $keyId, string $name, array $scopes, ?string $expire, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForPlatform->findOne('keys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $key
            ->setAttribute('name', $name)
            ->setAttribute('scopes', $scopes)
            ->setAttribute('expire', $expire);

        $dbForPlatform->updateDocument('keys', $key->getId(), $key);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($key, Response::MODEL_KEY);
    });

App::delete('/v1/projects/:projectId/keys/:keyId')
    ->desc('Delete key')
    ->groups(['api', 'projects'])
    ->label('scope', 'keys.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'keys',
        name: 'deleteKey',
        description: '/docs/references/projects/delete-key.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('keyId', '', new UID(), 'Key unique ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $keyId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $key = $dbForPlatform->findOne('keys', [
            Query::equal('$id', [$keyId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($key->isEmpty()) {
            throw new Exception(Exception::KEY_NOT_FOUND);
        }

        $dbForPlatform->deleteDocument('keys', $key->getId());

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    });

// JWT Keys

App::post('/v1/projects/:projectId/jwts')
    ->groups(['api', 'projects'])
    ->desc('Create JWT')
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'createJWT',
        description: '/docs/references/projects/create-jwt.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_JWT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('scopes', [], new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true), APP_LIMIT_ARRAY_PARAMS_SIZE), 'List of scopes allowed for JWT key. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed.')
    ->param('duration', 900, new Range(0, 3600), 'Time in seconds before JWT expires. Default duration is 900 seconds, and maximum is 3600 seconds.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, array $scopes, int $duration, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', $duration, 0);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(['jwt' => API_KEY_DYNAMIC . '_' . $jwt->encode([
                'projectId' => $project->getId(),
                'scopes' => $scopes
            ])]), Response::MODEL_JWT);
    });

// Platforms

App::post('/v1/projects/:projectId/platforms')
    ->desc('Create platform')
    ->groups(['api', 'projects'])
    ->label('audits.event', 'platforms.create')
    ->label('audits.resource', 'project/{request.projectId}')
    ->label('scope', 'platforms.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'platforms',
        name: 'createPlatform',
        description: '/docs/references/projects/create-platform.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_CREATED,
                model: Response::MODEL_PLATFORM,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param(
        'type',
        null,
        new WhiteList([
            Platform::TYPE_WEB,
            Platform::TYPE_FLUTTER_WEB,
            Platform::TYPE_FLUTTER_IOS,
            Platform::TYPE_FLUTTER_ANDROID,
            Platform::TYPE_FLUTTER_LINUX,
            Platform::TYPE_FLUTTER_MACOS,
            Platform::TYPE_FLUTTER_WINDOWS,
            Platform::TYPE_APPLE_IOS,
            Platform::TYPE_APPLE_MACOS,
            Platform::TYPE_APPLE_WATCHOS,
            Platform::TYPE_APPLE_TVOS,
            Platform::TYPE_ANDROID,
            Platform::TYPE_UNITY,
            Platform::TYPE_REACT_NATIVE_IOS,
            Platform::TYPE_REACT_NATIVE_ANDROID,
        ], true),
        'Platform type. Possible values are: web, flutter-web, flutter-ios, flutter-android, flutter-linux, flutter-macos, flutter-windows, apple-ios, apple-macos, apple-watchos, apple-tvos, android, unity, react-native-ios, react-native-android.'
    )
    ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
    ->param('key', '', new Text(256), 'Package name for Android or bundle ID for iOS or macOS. Max length: 256 chars.', true)
    ->param('store', '', new Text(256), 'App store or Google Play store ID. Max length: 256 chars.', true)
    ->param('hostname', '', new Hostname(), 'Platform client hostname. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $type, string $name, string $key, string $store, string $hostname, Response $response, Database $dbForPlatform) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

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
            'projectInternalId' => $project->getSequence(),
            'projectId' => $project->getId(),
            'type' => $type,
            'name' => $name,
            'key' => $key,
            'store' => $store,
            'hostname' => $hostname
        ]);

        $platform = $dbForPlatform->createDocument('platforms', $platform);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::get('/v1/projects/:projectId/platforms')
    ->desc('List platforms')
    ->groups(['api', 'projects'])
    ->label('scope', 'platforms.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'platforms',
        name: 'listPlatforms',
        description: '/docs/references/projects/list-platforms.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PLATFORM_LIST,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('total', true, new Boolean(true), 'When set to false, the total count returned will be 0 and will not be calculated.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $includeTotal, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platforms = $dbForPlatform->find('platforms', [
            Query::equal('projectInternalId', [$project->getSequence()]),
            Query::limit(5000),
        ]);

        $response->dynamic(new Document([
            'platforms' => $platforms,
            'total' => $includeTotal ? count($platforms) : 0,
        ]), Response::MODEL_PLATFORM_LIST);
    });

App::get('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Get platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'platforms.read')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'platforms',
        name: 'getPlatform',
        description: '/docs/references/projects/get-platform.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PLATFORM,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('platformId', '', new UID(), 'Platform unique ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $platformId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = $dbForPlatform->findOne('platforms', [
            Query::equal('$id', [$platformId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $response->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::put('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Update platform')
    ->groups(['api', 'projects'])
    ->label('scope', 'platforms.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'platforms',
        name: 'updatePlatform',
        description: '/docs/references/projects/update-platform.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PLATFORM,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('platformId', '', new UID(), 'Platform unique ID.')
    ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
    ->param('key', '', new Text(256), 'Package name for android or bundle ID for iOS. Max length: 256 chars.', true)
    ->param('store', '', new Text(256), 'App store or Google Play store ID. Max length: 256 chars.', true)
    ->param('hostname', '', new Hostname(), 'Platform client URL. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $platformId, string $name, string $key, string $store, string $hostname, Response $response, Database $dbForPlatform) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = $dbForPlatform->findOne('platforms', [
            Query::equal('$id', [$platformId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $platform
            ->setAttribute('name', $name)
            ->setAttribute('key', $key)
            ->setAttribute('store', $store)
            ->setAttribute('hostname', $hostname);

        $dbForPlatform->updateDocument('platforms', $platform->getId(), $platform);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->dynamic($platform, Response::MODEL_PLATFORM);
    });

App::delete('/v1/projects/:projectId/platforms/:platformId')
    ->desc('Delete platform')
    ->groups(['api', 'projects'])
    ->label('audits.event', 'platforms.delete')
    ->label('audits.resource', 'project/{request.projectId}/platform/${request.platformId}')
    ->label('scope', 'platforms.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'platforms',
        name: 'deletePlatform',
        description: '/docs/references/projects/delete-platform.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_NOCONTENT,
                model: Response::MODEL_NONE,
            )
        ],
        contentType: ContentType::NONE
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('platformId', '', new UID(), 'Platform unique ID.')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $platformId, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $platform = $dbForPlatform->findOne('platforms', [
            Query::equal('$id', [$platformId]),
            Query::equal('projectInternalId', [$project->getSequence()]),
        ]);

        if ($platform->isEmpty()) {
            throw new Exception(Exception::PLATFORM_NOT_FOUND);
        }

        $dbForPlatform->deleteDocument('platforms', $platformId);

        $dbForPlatform->purgeCachedDocument('projects', $project->getId());

        $response->noContent();
    });


// CUSTOM SMTP and Templates
App::patch('/v1/projects/:projectId/smtp')
    ->desc('Update SMTP')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', [
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'updateSmtp',
            description: '/docs/references/projects/update-smtp.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_PROJECT,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'projects.updateSMTP',
            ),
            public: false,
        ),
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'updateSMTP',
            description: '/docs/references/projects/update-smtp.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_PROJECT,
                )
            ]
        )
    ])
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
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, string $senderName, string $senderEmail, string $replyTo, string $host, int $port, string $username, string $password, string $secure, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

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

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('smtp', $smtp));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });

App::post('/v1/projects/:projectId/smtp/tests')
    ->desc('Create SMTP test')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', [
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'createSmtpTest',
            description: '/docs/references/projects/create-smtp-test.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_NOCONTENT,
                    model: Response::MODEL_NONE,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'projects.createSMTPTest',
            ),
            public: false,
        ),
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'createSMTPTest',
            description: '/docs/references/projects/create-smtp-test.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_NOCONTENT,
                    model: Response::MODEL_NONE,
                )
            ]
        )
    ])
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('emails', [], new ArrayList(new Email(), 10), 'Array of emails to send test email to. Maximum of 10 emails are allowed.')
    ->param('senderName', System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server'), new Text(255, 0), 'Name of the email sender')
    ->param('senderEmail', System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM), new Email(), 'Email of the sender')
    ->param('replyTo', '', new Email(), 'Reply to email', true)
    ->param('host', '', new HostName(), 'SMTP server host name')
    ->param('port', 587, new Integer(), 'SMTP server port', true)
    ->param('username', '', new Text(0, 0), 'SMTP server username', true)
    ->param('password', '', new Text(0, 0), 'SMTP server password', true)
    ->param('secure', '', new WhiteList(['tls', 'ssl'], true), 'Does SMTP server use secure connection', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->inject('queueForMails')
    ->inject('plan')
    ->action(function (string $projectId, array $emails, string $senderName, string $senderEmail, string $replyTo, string $host, int $port, string $username, string $password, string $secure, Response $response, Database $dbForPlatform, Mail $queueForMails, array $plan) {
        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $replyToEmail = !empty($replyTo) ? $replyTo : $senderEmail;

        $subject = 'Custom SMTP email sample';
        $template = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-smtp-test.tpl');
        $template
            ->setParam('{{from}}', "{$senderName} ({$senderEmail})")
            ->setParam('{{replyTo}}', "{$senderName} ({$replyToEmail})")
            ->setParam('{{logoUrl}}', $plan['logoUrl'] ?? APP_EMAIL_LOGO_URL)
            ->setParam('{{accentColor}}', $plan['accentColor'] ?? APP_EMAIL_ACCENT_COLOR)
            ->setParam('{{twitterUrl}}', $plan['twitterUrl'] ?? APP_SOCIAL_TWITTER)
            ->setParam('{{discordUrl}}', $plan['discordUrl'] ?? APP_SOCIAL_DISCORD)
            ->setParam('{{githubUrl}}', $plan['githubUrl'] ?? APP_SOCIAL_GITHUB_APPWRITE)
            ->setParam('{{termsUrl}}', $plan['termsUrl'] ?? APP_EMAIL_TERMS_URL)
            ->setParam('{{privacyUrl}}', $plan['privacyUrl'] ?? APP_EMAIL_PRIVACY_URL);

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
                ->setBodyTemplate(__DIR__ . '/../../config/locale/templates/email-base-styled.tpl')
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
    ->label('sdk', [
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'getSmsTemplate',
            description: '/docs/references/projects/get-sms-template.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_SMS_TEMPLATE,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'projects.getSMSTemplate',
            ),
            public: false,
        ),
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'getSMSTemplate',
            description: '/docs/references/projects/get-sms-template.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_SMS_TEMPLATE,
                )
            ]
        )
    ])
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['sms'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForPlatform) {

        throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);

        $project = $dbForPlatform->getDocument('projects', $projectId);

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
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'templates',
        name: 'getEmailTemplate',
        description: '/docs/references/projects/get-email-template.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_EMAIL_TEMPLATE,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['email.' . $type . '-' . $locale] ?? null;

        $localeObj = new Locale($locale);
        $localeObj->setFallback(System::getEnv('_APP_LOCALE', 'en'));

        if (is_null($template)) {
            /**
             * different templates, different placeholders.
             */
            $templateConfigs = [
                'magicSession' => [
                    'file' => 'email-magic-url.tpl',
                    'placeholders' => ['optionButton', 'buttonText', 'optionUrl', 'clientInfo', 'securityPhrase']
                ],
                'mfaChallenge' => [
                    'file' => 'email-mfa-challenge.tpl',
                    'placeholders' => ['description', 'clientInfo']
                ],
                'otpSession' => [
                    'file' => 'email-otp.tpl',
                    'placeholders' => ['description', 'clientInfo', 'securityPhrase']
                ],
                'sessionAlert' => [
                    'file' => 'email-session-alert.tpl',
                    'placeholders' => ['body', 'listDevice', 'listIpAddress', 'listCountry', 'footer']
                ],
            ];

            // fallback to the base template.
            $config = $templateConfigs[$type] ?? [
                'file' => 'email-inner-base.tpl',
                'placeholders' => ['buttonText', 'body', 'footer']
            ];

            $templateString = file_get_contents(__DIR__ . '/../../config/locale/templates/' . $config['file']);

            // We use `fromString` due to the replace above
            $message = Template::fromString($templateString);

            // Set type-specific parameters
            foreach ($config['placeholders'] as $param) {
                $escapeHtml = !in_array($param, ['clientInfo', 'body', 'footer', 'description']);
                $message->setParam("{{{$param}}}", $localeObj->getText("emails.{$type}.{$param}"), escapeHtml: $escapeHtml);
            }

            $message
                // common placeholders on all the templates
                ->setParam('{{hello}}', $localeObj->getText("emails.{$type}.hello"))
                ->setParam('{{thanks}}', $localeObj->getText("emails.{$type}.thanks"))
                ->setParam('{{signature}}', $localeObj->getText("emails.{$type}.signature"));

            // `useContent: false` will strip new lines!
            $message = $message->render(useContent: true);

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
    ->label('sdk', [
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'updateSmsTemplate',
            description: '/docs/references/projects/update-sms-template.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_SMS_TEMPLATE,
                )
            ],
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'projects.updateSMSTemplate',
            ),
            public: false,
        ),
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'updateSMSTemplate',
            description: '/docs/references/projects/update-sms-template.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_SMS_TEMPLATE,
                )
            ]
        )
    ])
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['sms'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->param('message', '', new Text(0), 'Template message')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $type, string $locale, string $message, Response $response, Database $dbForPlatform) {

        throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $templates['sms.' . $type . '-' . $locale] = [
            'message' => $message
        ];

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

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
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'templates',
        name: 'updateEmailTemplate',
        description: '/docs/references/projects/update-email-template.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_EMAIL_TEMPLATE,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->param('subject', '', new Text(255), 'Email Subject')
    ->param('message', '', new Text(0), 'Template message')
    ->param('senderName', '', new Text(255, 0), 'Name of the email sender', true)
    ->param('senderEmail', '', new Email(), 'Email of the sender', true)
    ->param('replyTo', '', new Email(), 'Reply to email', true)
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $type, string $locale, string $subject, string $message, string $senderName, string $senderEmail, string $replyTo, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

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

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

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
    ->label('sdk', [
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'deleteSmsTemplate',
            description: '/docs/references/projects/delete-sms-template.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_SMS_TEMPLATE,
                )
            ],
            contentType: ContentType::JSON,
            deprecated: new Deprecated(
                since: '1.8.0',
                replaceWith: 'projects.deleteSMSTemplate',
            ),
            public: false,
        ),
        new Method(
            namespace: 'projects',
            group: 'templates',
            name: 'deleteSMSTemplate',
            description: '/docs/references/projects/delete-sms-template.md',
            auth: [AuthType::ADMIN],
            responses: [
                new SDKResponse(
                    code: Response::STATUS_CODE_OK,
                    model: Response::MODEL_SMS_TEMPLATE,
                )
            ],
            contentType: ContentType::JSON
        )
    ])
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['sms'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForPlatform) {

        throw new Exception(Exception::GENERAL_NOT_IMPLEMENTED);

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['sms.' . $type . '-' . $locale] ?? null;

        if (is_null($template)) {
            throw new Exception(Exception::PROJECT_TEMPLATE_DEFAULT_DELETION);
        }

        unset($template['sms.' . $type . '-' . $locale]);

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

        $response->dynamic(new Document([
            'type' => $type,
            'locale' => $locale,
            'message' => $template['message']
        ]), Response::MODEL_SMS_TEMPLATE);
    });

App::delete('/v1/projects/:projectId/templates/email/:type/:locale')
    ->desc('Delete custom email template')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'templates',
        name: 'deleteEmailTemplate',
        description: '/docs/references/projects/delete-email-template.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_EMAIL_TEMPLATE,
            )
        ],
        contentType: ContentType::JSON
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('type', '', new WhiteList(Config::getParam('locale-templates')['email'] ?? []), 'Template type')
    ->param('locale', '', fn ($localeCodes) => new WhiteList($localeCodes), 'Template locale', false, ['localeCodes'])
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, string $type, string $locale, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $templates = $project->getAttribute('templates', []);
        $template  = $templates['email.' . $type . '-' . $locale] ?? null;

        if (is_null($template)) {
            throw new Exception(Exception::PROJECT_TEMPLATE_DEFAULT_DELETION);
        }

        unset($templates['email.' . $type . '-' . $locale]);

        $project = $dbForPlatform->updateDocument('projects', $project->getId(), $project->setAttribute('templates', $templates));

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

App::patch('/v1/projects/:projectId/auth/session-invalidation')
    ->desc('Update invalidate session option of the project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk', new Method(
        namespace: 'projects',
        group: 'auth',
        name: 'updateSessionInvalidation',
        description: '/docs/references/projects/update-session-invalidation.md',
        auth: [AuthType::ADMIN],
        responses: [
            new SDKResponse(
                code: Response::STATUS_CODE_OK,
                model: Response::MODEL_PROJECT,
            )
        ]
    ))
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('enabled', false, new Boolean(), 'Update authentication session invalidation status. Use this endpoint to enable or disable session invalidation on password change')
    ->inject('response')
    ->inject('dbForPlatform')
    ->action(function (string $projectId, bool $enabled, Response $response, Database $dbForPlatform) {

        $project = $dbForPlatform->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception(Exception::PROJECT_NOT_FOUND);
        }

        $auths = $project->getAttribute('auths', []);
        $auths['invalidateSessions'] = $enabled;
        $dbForPlatform->updateDocument('projects', $project->getId(), $project
        ->setAttribute('auths', $auths));

        $response->dynamic($project, Response::MODEL_PROJECT);
    });
