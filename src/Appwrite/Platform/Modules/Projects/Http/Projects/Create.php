<?php

namespace Appwrite\Platform\Modules\Projects\Http\Projects;

use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\SDK\AuthType;
use Appwrite\SDK\Method;
use Appwrite\SDK\Response as SDKResponse;
use Appwrite\Utopia\Database\Validator\ProjectId;
use Appwrite\Utopia\Database\Validator\Queries\Projects;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Utopia\Audit\Adapter\Database as AdapterDatabase;
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
use Utopia\Database\Validator\UID;
use Utopia\DSN\DSN;
use Utopia\Platform\Scope\HTTP;
use Utopia\Pools\Group;
use Utopia\System\System;
use Utopia\Validator;
use Utopia\Validator\Text;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

class Create extends Action
{
    use HTTP;

    public static function getName()
    {
        return 'createProject';
    }

    protected function getQueriesValidator(): Validator
    {
        return new Projects();
    }

    public function __construct()
    {
        $this
            ->setHttpMethod(Action::HTTP_REQUEST_METHOD_POST)
            ->setHttpPath('/v1/projects')
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
            ->param('region', System::getEnv('_APP_REGION', 'default'), new WhiteList(array_keys(array_filter(Config::getParam('regions'), fn ($config) => !$config['disabled']))), 'Project Region.', true)
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
            ->callback($this->action(...));
    }

    public function action(string $projectId, string $name, string $teamId, string $region, string $description, string $logo, string $url, string $legalName, string $legalCountry, string $legalState, string $legalCity, string $legalAddress, string $legalTaxId, Request $request, Response $response, Database $dbForPlatform, Cache $cache, Group $pools, Hooks $hooks)
    {
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
                '$permissions' => $this->getPermissions($teamId, $projectId),
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
                'services' => new \stdClass(),
                'platforms' => null,
                'oAuthProviders' => [],
                'webhooks' => null,
                'keys' => null,
                'auths' => $auths,
                'accessedAt' => DateTime::now(),
                'search' => implode(' ', [$projectId, $name]),
                'database' => $dsn,
                'labels' => [],
                'status' => PROJECT_STATUS_ACTIVE,
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
            $dbForProject->setDatabase(APP_DATABASE);

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
                $adapter = new AdapterDatabase($dbForProject);
                $audit = new Audit($adapter);
                $audit->setup();
            }

            if (!$create && $sharedTablesV1) {
                $adapter = new AdapterDatabase($dbForProject);
                $attributes = $adapter->getAttributeDocuments();
                $indexes = $adapter->getIndexDocuments();
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
        $hooks->trigger('afterProjectCreation', [$project, $pools, $cache]);

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($project, Response::MODEL_PROJECT);
    }
}
