<?php

use Appwrite\Auth\Auth;
use Appwrite\Network\Validator\CNAME;
use Appwrite\Network\Validator\Domain as DomainValidator;
use Appwrite\Network\Validator\URL;
use Appwrite\Task\Validator\Cron;
use Appwrite\Utopia\Response;
use Cron\CronExpression;
use Utopia\App;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\UID;
use Utopia\Domains\Domain;
use Utopia\Exception;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Boolean;
use Utopia\Validator\Integer;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

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
    ->param('name', null, new Text(128), 'Project name. Max length: 128 chars.')
    ->param('teamId', '', new UID(), 'Team unique ID.')
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
    ->inject('dbForInternal')
    ->inject('dbForExternal')
    ->action(function ($name, $teamId, $description, $logo, $url, $legalName, $legalCountry, $legalState, $legalCity, $legalAddress, $legalTaxId, $response, $dbForConsole, $dbForInternal, $dbForExternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Database\Database $dbForExternal */

        $team = $dbForInternal->getDocument('teams', $teamId);

        if ($team->isEmpty()) {
            throw new Exception('Team not found', 404);
        }

        $project = $dbForConsole->createDocument('projects', new Document([
            '$collection' => 'projects',
            '$read' => ['team:' . $teamId],
            '$write' => ['team:' . $teamId . '/owner', 'team:' . $teamId . '/developer'],
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
            'teamId' => $team->getId(),
            'platforms' => [],
            'webhooks' => [],
            'keys' => [],
            'tasks' => [],
            'domains' => [],
            'usersAuthEmailPassword' => true,
            'usersAuthAnonymous' => true,
            'usersAuthInvites' => true,
            'usersAuthJWT' => true,
            'usersAuthPhone' => true,
        ]));

        $collections = Config::getParam('collections2', []); /** @var array $collections */

        $dbForInternal->setNamespace('project_' . $project->getId() . '_internal');
        $dbForInternal->create();
        $dbForExternal->setNamespace('project_' . $project->getId() . '_external');
        $dbForExternal->create();

        foreach ($collections as $key => $collection) {
            $dbForInternal->createCollection($key);

            foreach ($collection['attributes'] as $i => $attribute) {
                $dbForInternal->createAttribute(
                    $key,
                    $attribute['$id'],
                    $attribute['type'],
                    $attribute['size'],
                    $attribute['required'],
                    $attribute['signed'],
                    $attribute['array'],
                    $attribute['filters'],
                );
            }

            foreach ($collection['indexes'] as $i => $index) {
                $dbForInternal->createIndex(
                    $key,
                    $index['$id'],
                    $index['type'],
                    $index['attributes'],
                    $index['lengths'],
                    $index['orders'],
                );
            }
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($project, Response::MODEL_PROJECT);
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
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($search, $limit, $offset, $orderType, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $queries = ($search) ? [new Query('name', Query::TYPE_SEARCH, [$search])] : [];

        $results = $dbForConsole->find('projects', $queries, $limit, $offset);
        $sum = $dbForConsole->count('projects', $queries, APP_LIMIT_COUNT);

        $response->dynamic2(new Document([
            'projects' => $results,
            'sum' => $sum,
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
    ->action(function ($projectId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $response->dynamic2($project, Response::MODEL_PROJECT);
    });

App::get('/v1/projects/:projectId/usage')
    ->desc('Get Project')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getUsage')
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->inject('projectDB')
    ->inject('register')
    ->action(function ($projectId, $range, $response, $dbForConsole, $projectDB, $register) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Registry\Registry $register */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {

            $period = [
                '24h' => [
                    'start' => DateTime::createFromFormat('U', \strtotime('-24 hours')),
                    'end' => DateTime::createFromFormat('U', \strtotime('+1 hour')),
                    'group' => '30m',
                ],
                '7d' => [
                    'start' => DateTime::createFromFormat('U', \strtotime('-7 days')),
                    'end' => DateTime::createFromFormat('U', \strtotime('now')),
                    'group' => '1d',
                ],
                '30d' => [
                    'start' => DateTime::createFromFormat('U', \strtotime('-30 days')),
                    'end' => DateTime::createFromFormat('U', \strtotime('now')),
                    'group' => '1d',
                ],
                '90d' => [
                    'start' => DateTime::createFromFormat('U', \strtotime('-90 days')),
                    'end' => DateTime::createFromFormat('U', \strtotime('now')),
                    'group' => '1d',
                ],
            ];

            $client = $register->get('influxdb');

            $requests = [];
            $network = [];
            $functions = [];

            if ($client) {
                $start = $period[$range]['start']->format(DateTime::RFC3339);
                $end = $period[$range]['end']->format(DateTime::RFC3339);
                $database = $client->selectDB('telegraf');

                // Requests
                $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_requests_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' AND "project"=\'' . $project->getId() . '\' GROUP BY time(' . $period[$range]['group'] . ') FILL(null)');
                $points = $result->getPoints();

                foreach ($points as $point) {
                    $requests[] = [
                        'value' => (!empty($point['value'])) ? $point['value'] : 0,
                        'date' => \strtotime($point['time']),
                    ];
                }

                // Network
                $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_network_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' AND "project"=\'' . $project->getId() . '\' GROUP BY time(' . $period[$range]['group'] . ') FILL(null)');
                $points = $result->getPoints();

                foreach ($points as $point) {
                    $network[] = [
                        'value' => (!empty($point['value'])) ? $point['value'] : 0,
                        'date' => \strtotime($point['time']),
                    ];
                }

                // Functions
                $result = $database->query('SELECT sum(value) AS "value" FROM "appwrite_usage_executions_all" WHERE time > \'' . $start . '\' AND time < \'' . $end . '\' AND "metric_type"=\'counter\' AND "project"=\'' . $project->getId() . '\' GROUP BY time(' . $period[$range]['group'] . ') FILL(null)');
                $points = $result->getPoints();

                foreach ($points as $point) {
                    $functions[] = [
                        'value' => (!empty($point['value'])) ? $point['value'] : 0,
                        'date' => \strtotime($point['time']),
                    ];
                }
            }
        } else {
            $requests = [];
            $network = [];
            $functions = [];
        }

        // Users

        $projectDB->getCollection([
            'limit' => 0,
            'offset' => 0,
            'filters' => [
                '$collection=users',
            ],
        ]);

        $usersTotal = $projectDB->getSum();

        // Documents

        $collections = $projectDB->getCollection([
            'limit' => 100,
            'offset' => 0,
            'filters' => [
                '$collection=collections',
            ],
        ]);

        $collectionsTotal = $projectDB->getSum();

        $documents = [];

        foreach ($collections as $collection) {
            $result = $projectDB->getCollection([
                'limit' => 0,
                'offset' => 0,
                'filters' => [
                    '$collection=' . $collection['$id'],
                ],
            ]);

            $documents[] = ['name' => $collection['name'], 'total' => $projectDB->getSum()];
        }

        // Tasks
        $tasksTotal = \count($project->getAttribute('tasks', []));

        $response->json([
            'range' => $range,
            'requests' => [
                'data' => $requests,
                'total' => \array_sum(\array_map(function ($item) {
                    return $item['value'];
                }, $requests)),
            ],
            'network' => [
                'data' => \array_map(function ($value) {return ['value' => \round($value['value'] / 1000000, 2), 'date' => $value['date']];}, $network), // convert bytes to mb
                'total' => \array_sum(\array_map(function ($item) {
                    return $item['value'];
                }, $network)),
            ],
            'functions' => [
                'data' => $functions,
                'total' => \array_sum(\array_map(function ($item) {
                    return $item['value'];
                }, $functions)),
            ],
            'collections' => [
                'data' => $collections,
                'total' => $collectionsTotal,
            ],
            'documents' => [
                'data' => $documents,
                'total' => \array_sum(\array_map(function ($item) {
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
                        'attribute' => 'sizeOriginal',
                        'filters' => [
                            '$collection=files',
                        ],
                    ]
                ) +
                $projectDB->getCount(
                    [
                        'attribute' => 'size',
                        'filters' => [
                            '$collection=tags',
                        ],
                    ]
                ),
            ],
        ]);
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
    ->action(function ($projectId, $name, $description, $logo, $url, $legalName, $legalCountry, $legalState, $legalCity, $legalAddress, $legalTaxId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
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
        );

        $response->dynamic2($project, Response::MODEL_PROJECT);
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
    ->action(function ($projectId, $provider, $appId, $secret, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute('usersOauth2' . \ucfirst($provider) . 'Appid', $appId)
                ->setAttribute('usersOauth2' . \ucfirst($provider) . 'Secret', $secret)
        );

        $response->dynamic2($project, Response::MODEL_PROJECT);
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
    ->param('limit', false, new Integer(true), 'Set the max number of users allowed in this project. Use 0 for unlimited.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $limit, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute('usersAuthLimit', $limit)
        );

        $response->dynamic2($project, Response::MODEL_PROJECT);
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
    ->action(function ($projectId, $method, $status, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);
        $auth = Config::getParam('auth')[$method] ?? [];
        $authKey = $auth['key'] ?? '';
        $status = ($status === '1' || $status === 'true' || $status === 1 || $status === true);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute($authKey, $status)
        );

        $response->dynamic2($project, Response::MODEL_PROJECT);
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
    ->param('password', '', new UID(), 'Your user password for confirmation. Must be between 6 to 32 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForConsole')
    ->inject('deletes')
    ->action(function ($projectId, $password, $response, $user, $dbForConsole, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Utopia\Database\Database $dbForConsole */
        /** @var Appwrite\Event\Event $deletes */

        if (!Auth::passwordVerify($password, $user->getAttribute('password'))) { // Double check user password
            throw new Exception('Invalid credentials', 401);
        }

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $project)
        ;

        if (!$dbForConsole->deleteDocument('teams', $project->getAttribute('teamId', null))) {
            throw new Exception('Failed to remove project team from DB', 500);
        }

        if (!$dbForConsole->deleteDocument('projects', $projectId)) {
            throw new Exception('Failed to remove project from DB', 500);
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
    ->param('events', null, new ArrayList(new WhiteList(array_keys(Config::getParam('events'), true), true)), 'Events list.')
    ->param('url', null, new URL(), 'Webhook URL.')
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpUser', '', new Text(256), 'Webhook HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Webhook HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $name, $events, $url, $security, $httpUser, $httpPass, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $security = ($security === '1' || $security === 'true' || $security === 1 || $security === true);

        $webhook = new Document([
            '$id' => $dbForConsole->getId(),
            'name' => $name,
            'events' => $events,
            'url' => $url,
            'security' => $security,
            'httpUser' => $httpUser,
            'httpPass' => $httpPass,
        ]);

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute('webhooks', $webhook, Document::SET_TYPE_APPEND)
        );

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($webhook, Response::MODEL_WEBHOOK);
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
    ->action(function ($projectId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $webhooks = $project->getAttribute('webhooks', []);

        $response->dynamic2(new Document([
            'webhooks' => $webhooks,
            'sum' => count($webhooks),
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
    ->action(function ($projectId, $webhookId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $webhook = $project->find('$id', $webhookId, 'webhooks');

        if (empty($webhook) || !$webhook instanceof Document) {
            throw new Exception('Webhook not found', 404);
        }

        $response->dynamic2($webhook, Response::MODEL_WEBHOOK);
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
    ->param('events', null, new ArrayList(new WhiteList(array_keys(Config::getParam('events'), true), true)), 'Events list.')
    ->param('url', null, new URL(), 'Webhook URL.')
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpUser', '', new Text(256), 'Webhook HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Webhook HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $webhookId, $name, $events, $url, $security, $httpUser, $httpPass, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $security = ($security === '1' || $security === 'true' || $security === 1 || $security === true);

        $webhook = $project->find('$id', $webhookId, 'webhooks');

        if (empty($webhook) || !$webhook instanceof Document) {
            throw new Exception('Webhook not found', 404);
        }

        $project->findAndReplace('$id', $webhook->getId(), $webhook
                ->setAttribute('name', $name)
                ->setAttribute('events', $events)
                ->setAttribute('url', $url)
                ->setAttribute('security', $security)
                ->setAttribute('httpUser', $httpUser)
                ->setAttribute('httpPass', $httpPass)
            , 'webhooks');

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

        $response->dynamic2($webhook, Response::MODEL_WEBHOOK);
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
    ->action(function ($projectId, $webhookId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        if (!$project->findAndRemove('$id', $webhookId, 'webhooks')) {
            throw new Exception('Webhook not found', 404);
        }

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

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
    ->param('scopes', null, new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true)), 'Key scopes list.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $name, $scopes, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $key = new Document([
            '$id' => $dbForConsole->getId(),
            'name' => $name,
            'scopes' => $scopes,
            'secret' => \bin2hex(\random_bytes(128)),
        ]);

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute('keys', $key, Document::SET_TYPE_APPEND)
        );

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($key, Response::MODEL_KEY);
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
    ->action(function ($projectId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $keys = $project->getAttribute('keys', []);

        $response->dynamic2(new Document([
            'keys' => $keys,
            'sum' => count($keys),
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
    ->action(function ($projectId, $keyId, $response, $dbForConsole) {
        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $key = $project->find('$id', $keyId, 'keys');

        if (empty($key) || !$key instanceof Document) {
            throw new Exception('Key not found', 404);
        }

        $response->dynamic2($key, Response::MODEL_KEY);
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
    ->param('scopes', null, new ArrayList(new WhiteList(array_keys(Config::getParam('scopes')), true)), 'Key scopes list')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $keyId, $name, $scopes, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $key = $project->find('$id', $keyId, 'keys');

        if (empty($key) || !$key instanceof Document) {
            throw new Exception('Key not found', 404);
        }

        $project->findAndReplace('$id', $key->getId(), $key
                ->setAttribute('name', $name)
                ->setAttribute('scopes', $scopes)
            , 'keys');

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

        $response->dynamic2($key, Response::MODEL_KEY);
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
    ->action(function ($projectId, $keyId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        if (!$project->findAndRemove('$id', $keyId, 'keys')) {
            throw new Exception('Key not found', 404);
        }

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

        $response->noContent();
    });

// Tasks

App::post('/v1/projects/:projectId/tasks')
    ->desc('Create Task')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'createTask')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TASK)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('name', null, new Text(128), 'Task name. Max length: 128 chars.')
    ->param('status', null, new WhiteList(['play', 'pause'], true), 'Task status.')
    ->param('schedule', null, new Cron(), 'Task schedule CRON syntax.')
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpMethod', '', new WhiteList(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'], true), 'Task HTTP method.')
    ->param('httpUrl', '', new URL(), 'Task HTTP URL')
    ->param('httpHeaders', null, new ArrayList(new Text(256)), 'Task HTTP headers list.', true)
    ->param('httpUser', '', new Text(256), 'Task HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Task HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $name, $status, $schedule, $security, $httpMethod, $httpUrl, $httpHeaders, $httpUser, $httpPass, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $cron = new CronExpression($schedule);
        $next = ($status == 'play') ? $cron->getNextRunDate()->format('U') : null;
        $security = ($security === '1' || $security === 'true' || $security === 1 || $security === true);

        $task = new Document([
            '$id' => $dbForConsole->getId(),
            'name' => $name,
            'status' => $status,
            'schedule' => $schedule,
            'updated' => \time(),
            'previous' => null,
            'next' => $next,
            'security' => $security,
            'httpMethod' => $httpMethod,
            'httpUrl' => $httpUrl,
            'httpHeaders' => $httpHeaders,
            'httpUser' => $httpUser,
            'httpPass' => $httpPass,
            'log' => '{}',
            'failures' => 0,
        ]);

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute('tasks', $task, Document::SET_TYPE_APPEND)
        );

        if ($next) {
            ResqueScheduler::enqueueAt($next, 'v1-tasks', 'TasksV1', $task->getArrayCopy());
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($task, Response::MODEL_TASK);
    });

App::get('/v1/projects/:projectId/tasks')
    ->desc('List Tasks')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'listTasks')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TASK_LIST)
    ->param('projectId', '', new UID(), 'Project unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $tasks = $project->getAttribute('tasks', []);

        $response->dynamic2(new Document([
            'tasks' => $tasks,
            'sum' => count($tasks),
        ]), Response::MODEL_TASK_LIST);

    });

App::get('/v1/projects/:projectId/tasks/:taskId')
    ->desc('Get Task')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'getTask')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TASK)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('taskId', null, new UID(), 'Task unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $taskId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $task = $project->find('$id', $taskId, 'tasks');

        if (empty($task) || !$task instanceof Document) {
            throw new Exception('Task not found', 404);
        }

        $response->dynamic2($task, Response::MODEL_TASK);
    });

App::put('/v1/projects/:projectId/tasks/:taskId')
    ->desc('Update Task')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'updateTask')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TASK)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('taskId', null, new UID(), 'Task unique ID.')
    ->param('name', null, new Text(128), 'Task name. Max length: 128 chars.')
    ->param('status', null, new WhiteList(['play', 'pause'], true), 'Task status.')
    ->param('schedule', null, new Cron(), 'Task schedule CRON syntax.')
    ->param('security', false, new Boolean(true), 'Certificate verification, false for disabled or true for enabled.')
    ->param('httpMethod', '', new WhiteList(['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'TRACE', 'CONNECT'], true), 'Task HTTP method.')
    ->param('httpUrl', '', new URL(), 'Task HTTP URL.')
    ->param('httpHeaders', null, new ArrayList(new Text(256)), 'Task HTTP headers list.', true)
    ->param('httpUser', '', new Text(256), 'Task HTTP user. Max length: 256 chars.', true)
    ->param('httpPass', '', new Text(256), 'Task HTTP password. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $taskId, $name, $status, $schedule, $security, $httpMethod, $httpUrl, $httpHeaders, $httpUser, $httpPass, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $task = $project->find('$id', $taskId, 'tasks');

        if (empty($task) || !$task instanceof Document) {
            throw new Exception('Task not found', 404);
        }

        $cron = new CronExpression($schedule);
        $next = ($status == 'play') ? $cron->getNextRunDate()->format('U') : null;
        $security = ($security === '1' || $security === 'true' || $security === 1 || $security === true);

        $project->findAndReplace('$id', $task->getId(), $task
                ->setAttribute('name', $name)
                ->setAttribute('status', $status)
                ->setAttribute('schedule', $schedule)
                ->setAttribute('updated', \time())
                ->setAttribute('next', $next)
                ->setAttribute('security', $security)
                ->setAttribute('httpMethod', $httpMethod)
                ->setAttribute('httpUrl', $httpUrl)
                ->setAttribute('httpHeaders', $httpHeaders)
                ->setAttribute('httpUser', $httpUser)
                ->setAttribute('httpPass', $httpPass)
            , 'tasks');

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

        if ($next) {
            ResqueScheduler::enqueueAt($next, 'v1-tasks', 'TasksV1', $task->getArrayCopy());
        }

        $response->dynamic2($task, Response::MODEL_TASK);
    });

App::delete('/v1/projects/:projectId/tasks/:taskId')
    ->desc('Delete Task')
    ->groups(['api', 'projects'])
    ->label('scope', 'projects.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'projects')
    ->label('sdk.method', 'deleteTask')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('projectId', null, new UID(), 'Project unique ID.')
    ->param('taskId', null, new UID(), 'Task unique ID.')
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $taskId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        if (!$project->findAndRemove('$id', $taskId, 'tasks')) {
            throw new Exception('Task not found', 404);
        }

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

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
    ->param('type', null, new WhiteList(['web', 'flutter-ios', 'flutter-android', 'flutter-linux', 'flutter-macos', 'flutter-windows', 'ios', 'android', 'unity'], true), 'Platform type.')
    ->param('name', null, new Text(128), 'Platform name. Max length: 128 chars.')
    ->param('key', '', new Text(256), 'Package name for android or bundle ID for iOS. Max length: 256 chars.', true)
    ->param('store', '', new Text(256), 'App store or Google Play store ID. Max length: 256 chars.', true)
    ->param('hostname', '', new Text(256), 'Platform client hostname. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $type, $name, $key, $store, $hostname, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $platform = new Document([
            '$id' => $dbForConsole->getId(),
            'type' => $type,
            'name' => $name,
            'key' => $key,
            'store' => $store,
            'hostname' => $hostname,
            'dateCreated' => \time(),
            'dateUpdated' => \time(),
        ]);

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute('platforms', $platform, Document::SET_TYPE_APPEND)
        );

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($platform, Response::MODEL_PLATFORM);
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
    ->action(function ($projectId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $platforms = $project->getAttribute('platforms', []);

        $response->dynamic2(new Document([
            'platforms' => $platforms,
            'sum' => count($platforms),
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
    ->action(function ($projectId, $platformId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $platform = $project->find('$id', $platformId, 'platforms');

        if (empty($platform) || !$platform instanceof Document) {
            throw new Exception('Platform not found', 404);
        }

        $response->dynamic2($platform, Response::MODEL_PLATFORM);
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
    ->param('hostname', '', new Text(256), 'Platform client URL. Max length: 256 chars.', true)
    ->inject('response')
    ->inject('dbForConsole')
    ->action(function ($projectId, $platformId, $name, $key, $store, $hostname, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $platform = $project->find('$id', $platformId, 'platforms');

        if (empty($platform) || !$platform instanceof Document) {
            throw new Exception('Platform not found', 404);
        }

        $platform
            ->setAttribute('name', $name)
            ->setAttribute('dateUpdated', \time())
            ->setAttribute('key', $key)
            ->setAttribute('store', $store)
            ->setAttribute('hostname', $hostname)
        ;

        $project->findAndReplace('$id', $platform->getId(), $platform
                ->setAttribute('name', $name)
                ->setAttribute('dateUpdated', \time())
                ->setAttribute('key', $key)
                ->setAttribute('store', $store)
                ->setAttribute('hostname', $hostname)
            , 'platforms');

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

        $response->dynamic2($platform, Response::MODEL_PLATFORM);
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
    ->action(function ($projectId, $platformId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        if (!$project->findAndRemove('$id', $platformId, 'platforms')) {
            throw new Exception('Platform not found', 404);
        }

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

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
    ->action(function ($projectId, $domain, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $document = $project->find('domain', $domain, 'domains');

        if ($document) {
            throw new Exception('Domain already exists', 409);
        }

        $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

        if (!$target->isKnown() || $target->isTest()) {
            throw new Exception('Unreachable CNAME target (' . $target->get() . '), please use a domain with a public suffix.', 500);
        }

        $domain = new Domain($domain);

        $domain = new Document([
            '$id' => $dbForConsole->getId(),
            'updated' => \time(),
            'domain' => $domain->get(),
            'tld' => $domain->getSuffix(),
            'registerable' => $domain->getRegisterable(),
            'verification' => false,
            'certificateId' => null,
        ]);

        $project = $dbForConsole->updateDocument('projects', $project->getId(), $project
                ->setAttribute('domains', $domain, Document::SET_TYPE_APPEND)
        );

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic2($domain, Response::MODEL_DOMAIN);
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
    ->action(function ($projectId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $domains = $project->getAttribute('domains', []);

        $response->dynamic2(new Document([
            'domains' => $domains,
            'sum' => count($domains),
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
    ->action(function ($projectId, $domainId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $domain = $project->find('$id', $domainId, 'domains');

        if (empty($domain) || !$domain instanceof Document) {
            throw new Exception('Domain not found', 404);
        }

        $response->dynamic2($domain, Response::MODEL_DOMAIN);
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
    ->action(function ($projectId, $domainId, $response, $dbForConsole) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $domain = $project->find('$id', $domainId, 'domains');

        if (empty($domain) || !$domain instanceof Document) {
            throw new Exception('Domain not found', 404);
        }

        $target = new Domain(App::getEnv('_APP_DOMAIN_TARGET', ''));

        if (!$target->isKnown() || $target->isTest()) {
            throw new Exception('Unreachable CNAME target (' . $target->get() . '), please use a domain with a public suffix.', 500);
        }

        if ($domain->getAttribute('verification') === true) {
            return $response->dynamic2($domain, Response::MODEL_DOMAIN);
        }

        $validator = new CNAME($target->get()); // Verify Domain with DNS records

        if (!$validator->isValid($domain->getAttribute('domain', ''))) {
            throw new Exception('Failed to verify domain', 401);
        }

        $project->findAndReplace('$id', $domain->getId(), $domain
                ->setAttribute('verification', true)
            , 'domains');

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

        // Issue a TLS certificate when domain is verified
        Resque::enqueue('v1-certificates', 'CertificatesV1', [
            'document' => $domain->getArrayCopy(),
            'domain' => $domain->getAttribute('domain'),
        ]);

        $response->dynamic2($domain, Response::MODEL_DOMAIN);
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
    ->action(function ($projectId, $domainId, $response, $dbForConsole, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForConsole */

        $project = $dbForConsole->getDocument('projects', $projectId);

        if ($project->isEmpty()) {
            throw new Exception('Project not found', 404);
        }

        $domain = $project->find('$id', $domainId, 'domains');

        if (!$project->findAndRemove('$id', $domainId, 'domains')) {
            throw new Exception('Domain not found', 404);
        }

        $dbForConsole->updateDocument('projects', $project->getId(), $project);

        $deletes
            ->setParam('type', DELETE_TYPE_CERTIFICATES)
            ->setParam('document', $domain)
        ;

        $response->noContent();
    });
