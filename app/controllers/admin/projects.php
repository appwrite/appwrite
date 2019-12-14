<?php

global $utopia, $request, $response, $register, $user, $consoleDB, $projectDB, $providers;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Validator\URL;
use Task\Validator\Cron;
use Database\Database;
use Database\Document;
use Database\Validator\UID;
use OpenSSL\OpenSSL;

include_once '../shared/api.php';

$scopes = [ // TODO sync with console UI list
    'users.read',
    'users.write',
    'teams.read',
    'teams.write',
    'collections.read',
    'collections.write',
    'documents.read',
    'documents.write',
    'files.read',
    'files.write',
];

$utopia->get('/v1/projects')
    ->desc('List Projects')
    ->label('scope', 'projects.read')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listProjects')
    ->action(
        function () use ($request, $response, $providers, $consoleDB) {
            $results = $consoleDB->getCollection([
                'limit' => 20,
                'offset' => 0,
                'orderField' => 'name',
                'orderType' => 'ASC',
                'orderCast' => 'string',
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_PROJECTS,
                ],
            ]);

            foreach ($results as $project) {
                foreach ($providers as $provider => $node) {
                    $secret = json_decode($project->getAttribute('usersOauth'.ucfirst($provider).'Secret', '{}'), true);

                    if (!empty($secret) && isset($secret['version'])) {
                        $key = $request->getServer('_APP_OPENSSL_KEY_V'.$secret['version']);
                        $project->setAttribute('usersOauth'.ucfirst($provider).'Secret', OpenSSL::decrypt($secret['data'], $secret['method'], $key, 0, hex2bin($secret['iv']), hex2bin($secret['tag'])));
                    }
                }
            }

            $response->json($results);
        }
    );

$utopia->get('/v1/projects/:projectId')
    ->desc('Get Project')
    ->label('scope', 'projects.read')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getProject')
    ->param('projectId', '', function () { return new UID(); }, 'Project unique ID.')
    ->action(
        function ($projectId) use ($request, $response, $providers, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            foreach ($providers as $provider => $node) {
                $secret = json_decode($project->getAttribute('usersOauth'.ucfirst($provider).'Secret', '{}'), true);

                if (!empty($secret) && isset($secret['version'])) {
                    $key = $request->getServer('_APP_OPENSSL_KEY_V'.$secret['version']);
                    $project->setAttribute('usersOauth'.ucfirst($provider).'Secret', OpenSSL::decrypt($secret['data'], $secret['method'], $key, 0, hex2bin($secret['iv']), hex2bin($secret['tag'])));
                }
            }

            $response->json($project->getArrayCopy());
        }
    );

$utopia->get('/v1/projects/:projectId/usage')
    ->desc('Get Project')
    ->label('scope', 'projects.read')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getProjectUsage')
    ->param('projectId', '', function () { return new UID(); }, 'Project unique ID.')
    ->action(
        function ($projectId) use ($response, $consoleDB, $projectDB, $register) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            $client = $register->get('influxdb');

            $requests = [];
            $network = [];

            if ($client) {
                $start = DateTime::createFromFormat('U', strtotime('last day of last month'));
                $start = $start->format(DateTime::RFC3339);
                $end = DateTime::createFromFormat('U', strtotime('last day of this month'));
                $end = $end->format(DateTime::RFC3339);
                $database = $client->selectDB('telegraf');

                // Requests
                $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_requests_all" WHERE time > \''.$start.'\' AND time < \''.$end.'\' AND "metric_type"=\'counter\' AND "project"=\''.$project->getUid().'\' GROUP BY time(1d) FILL(null)');
                $points = $result->getPoints();

                foreach ($points as $point) {
                    $requests[] = [
                        'value' => (!empty($point['value'])) ? $point['value'] : 0,
                        'date' => strtotime($point['time']),
                    ];
                }

                // Network
                $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_network_all" WHERE time > \''.$start.'\' AND time < \''.$end.'\' AND "metric_type"=\'counter\' AND "project"=\''.$project->getUid().'\' GROUP BY time(1d) FILL(null)');
                $points = $result->getPoints();

                foreach ($points as $point) {
                    $network[] = [
                        'value' => (!empty($point['value'])) ? $point['value'] : 0,
                        'date' => strtotime($point['time']),
                    ];
                }
            }

            // Users

            $projectDB->getCollection([
                'limit' => 0,
                'offset' => 0,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                ],
            ]);

            $usersTotal = $projectDB->getSum();

            // Documents

            $collections = $projectDB->getCollection([
                'limit' => 100,
                'offset' => 0,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_COLLECTIONS,
                ],
            ]);

            $collectionsTotal = $projectDB->getSum();

            $documents = [];

            foreach ($collections as $collection) {
                $result = $projectDB->getCollection([
                    'limit' => 0,
                    'offset' => 0,
                    'filters' => [
                        '$collection='.$collection['$uid'],
                    ],
                ]);

                $documents[] = ['name' => $collection['name'], 'total' => $projectDB->getSum()];
            }

            // Tasks
            $tasksTotal = count($project->getAttribute('tasks', []));

            $response->json([
                'requests' => [
                    'data' => $requests,
                    'total' => array_sum(array_map(function ($item) {
                        return $item['value'];
                    }, $requests)),
                ],
                'network' => [
                    'data' => $network,
                    'total' => array_sum(array_map(function ($item) {
                        return $item['value'];
                    }, $network)),
                ],
                'collections' => [
                    'data' => $collections,
                    'total' => $collectionsTotal,
                ],
                'documents' => [
                    'data' => $documents,
                    'total' => array_sum(array_map(function ($item) {
                        return $item['total'];
                    }, $documents)),
                ],
                'users' => [
                    'data' => [],
                    'total' => $usersTotal,
                ],
                'tasks' => [
                    'data' => [],
                    'total' => $tasksTotal,
                ],
                'storage' => [
                    'total' => $projectDB->getCount(
                        [
                            'filters' => [
                                '$collection='.Database::SYSTEM_COLLECTION_FILES,
                            ],
                        ]
                    ),
                ],
            ]);
        }
    );

$utopia->post('/v1/projects')
    ->desc('Create Project')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createProject')
    ->param('name', null, function () { return new Text(100); }, 'Project name')
    ->param('teamId', '', function () { return new UID(); }, 'Team unique ID.')
    ->param('description', '', function () { return new Text(255); }, 'Project description', true)
    ->param('logo', '', function () { return new Text(1024); }, 'Project logo', true)
    ->param('url', '', function () { return new URL(); }, 'Project URL', true)
    ->param('legalName', '', function () { return new Text(256); }, 'Project Legal Name', true)
    ->param('legalCountry', '', function () { return new Text(256); }, 'Project Legal Country', true)
    ->param('legalState', '', function () { return new Text(256); }, 'Project Legal State', true)
    ->param('legalCity', '', function () { return new Text(256); }, 'Project Legal City', true)
    ->param('legalAddress', '', function () { return new Text(256); }, 'Project Legal Address', true)
    ->param('legalTaxId', '', function () { return new Text(256); }, 'Project Legal Tax ID', true)
    ->action(
        function ($name, $teamId, $description, $logo, $url, $legalName, $legalCountry, $legalState, $legalCity, $legalAddress, $legalTaxId) use ($response, $user, $consoleDB, $projectDB) {
            $team = $projectDB->getDocument($teamId);

            if (empty($team->getUid()) || Database::SYSTEM_COLLECTION_TEAMS != $team->getCollection()) {
                throw new Exception('Team not found', 404);
            }

            $project = $consoleDB->createDocument(
                [
                    '$collection' => Database::SYSTEM_COLLECTION_PROJECTS,
                    '$permissions' => [
                        'read' => ['team:'.$teamId],
                        'write' => ['team:'.$teamId.'/owner', 'team:'.$teamId.'/developer'],
                    ],
                    'name' => $name,
                    'description' => $description,
                    'logo' => $logo,
                    'url' => $url,
                    'legalName' => $legalName,
                    'legalCountry' => $legalCountry,
                    'legalState' => $legalState,
                    'legalCity' => $legalCity,
                    'legalAddress' => $legalAddress,
                    'legalTaxId' => $legalTaxId,
                    'teamId' => $team->getUid(),
                    'webhooks' => [],
                    'keys' => [],
                ]
            );

            if (false === $project) {
                throw new Exception('Failed saving project to DB', 500);
            }

            $consoleDB->createNamespace($project->getUid());

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($project->getArrayCopy())
            ;
        }
    );

$utopia->patch('/v1/projects/:projectId')
    ->desc('Update Project')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateProject')
    ->param('projectId', '', function () { return new UID(); }, 'Project unique ID.')
    ->param('name', null, function () { return new Text(100); }, 'Project name')
    ->param('description', '', function () { return new Text(255); }, 'Project description', true)
    ->param('logo', '', function () { return new Text(1024); }, 'Project logo', true)
    ->param('url', '', function () { return new URL(); }, 'Project URL', true)
    ->param('legalName', '', function () { return new Text(256); }, 'Project Legal Name', true)
    ->param('legalCountry', '', function () { return new Text(256); }, 'Project Legal Country', true)
    ->param('legalState', '', function () { return new Text(256); }, 'Project Legal State', true)
    ->param('legalCity', '', function () { return new Text(256); }, 'Project Legal City', true)
    ->param('legalAddress', '', function () { return new Text(256); }, 'Project Legal Address', true)
    ->param('legalTaxId', '', function () { return new Text(256); }, 'Project Legal Tax ID', true)
    ->action(
        function ($projectId, $name, $description, $logo, $url, $legalName, $legalCountry, $legalState, $legalCity, $legalAddress, $legalTaxId) use ($response, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            $project = $consoleDB->updateDocument(array_merge($project->getArrayCopy(), [
                'name' => $name,
                'description' => $description,
                'logo' => $logo,
                'url' => $url,
                'legalName' => $legalName,
                'legalCountry' => $legalCountry,
                'legalState' => $legalState,
                'legalCity' => $legalCity,
                'legalAddress' => $legalAddress,
                'legalTaxId' => $legalTaxId,
            ]));

            if (false === $project) {
                throw new Exception('Failed saving project to DB', 500);
            }

            $response->json($project->getArrayCopy());
        }
    );

$utopia->patch('/v1/projects/:projectId/oauth')
    ->desc('Update Project OAuth')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateProjectOAuth')
    ->param('projectId', '', function () { return new UID(); }, 'Project unique ID.')
    ->param('provider', '', function () use ($providers) { return new WhiteList(array_keys($providers)); }, 'Provider Name', false)
    ->param('appId', '', function () { return new Text(256); }, 'Provider App ID', true)
    ->param('secret', '', function () { return new text(256); }, 'Provider Secret Key', true)
    ->action(
        function ($projectId, $provider, $appId, $secret) use ($request, $response, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            $key = $request->getServer('_APP_OPENSSL_KEY_V1');
            $iv = OpenSSL::randomPseudoBytes(OpenSSL::cipherIVLength(OpenSSL::CIPHER_AES_128_GCM));
            $tag = null;
            $secret = json_encode([
                'data' => OpenSSL::encrypt($secret, OpenSSL::CIPHER_AES_128_GCM, $key, 0, $iv, $tag),
                'method' => OpenSSL::CIPHER_AES_128_GCM,
                'iv' => bin2hex($iv),
                'tag' => bin2hex($tag),
                'version' => '1',
            ]);

            $project = $consoleDB->updateDocument(array_merge($project->getArrayCopy(), [
                'usersOauth'.ucfirst($provider).'Appid' => $appId,
                'usersOauth'.ucfirst($provider).'Secret' => $secret,
            ]));

            if (false === $project) {
                throw new Exception('Failed saving project to DB', 500);
            }

            $response->json($project->getArrayCopy());
        }
    );

$utopia->delete('/v1/projects/:projectId')
    ->desc('Delete Project')
    ->label('scope', 'projects.write')
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteProject')
    ->param('projectId', '', function () { return new UID(); }, 'Project unique ID.')
    ->action(
        function ($projectId) use ($response, $consoleDB) {
            $project = $consoleDB->getDocument($projectId);

            if (empty($project->getUid()) || Database::SYSTEM_COLLECTION_PROJECTS != $project->getCollection()) {
                throw new Exception('Project not found', 404);
            }

            // Delete all children (keys, webhooks, tasks [stop tasks?], platforms)

            if (!$consoleDB->deleteDocument($projectId)) {
                throw new Exception('Failed to remove project from DB', 500);
            }

            // Delete all DBs
            // $consoleDB->deleteNamespace($project->getUid());

            // Optimize DB?

            // Delete all storage files
            // Delete all storage cache

            $response->noContent();
        }
    );