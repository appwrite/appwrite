<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Validator;
use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;
use Appwrite\Network\Validator\Email;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\Boolean;
use Utopia\Audit\Audit;
use Utopia\Audit\Adapters\MySQL as AuditAdapter;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\UID;
use Appwrite\Utopia\Response;
use DeviceDetector\DeviceDetector;

App::post('/v1/users')
    ->desc('Create User')
    ->groups(['api', 'users'])
    ->label('event', 'users.create')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/users/create-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be between 6 to 32 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($email, $password, $name, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $email = \strtolower($email);
        $profile = $projectDB->getCollectionFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                'email='.$email,
            ],
        ]);

        if (!empty($profile)) {
            throw new Exception('User already registered', 409);
        }

        try {
            $user = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_USERS,
                '$permissions' => [
                    'read' => ['*'],
                    'write' => ['user:{self}'],
                ],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => Auth::passwordHash($password),
                'passwordUpdate' => \time(),
                'registration' => \time(),
                'reset' => false,
                'name' => $name,
            ], ['email' => $email]);
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER)
        ;
    });

App::get('/v1/users')
    ->desc('List Users')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/users/list-users.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER_LIST)
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderType' => $orderType,
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
            ],
        ]);

        $response->dynamic(new Document([
            'sum' => $projectDB->getSum(),
            'users' => $results
        ]), Response::MODEL_USER_LIST);
    });

App::get('/v1/users/:userId')
    ->desc('Get User')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/users/get-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($userId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/users/:userId/prefs')
    ->desc('Get User Preferences')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/users/get-user-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($userId, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $prefs = $user->getAttribute('prefs', new \stdClass());

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::get('/v1/users/:userId/sessions')
    ->desc('Get User Sessions')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getSessions')
    ->label('sdk.description', '/docs/references/users/get-user-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION_LIST)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('locale')
    ->action(function ($userId, $response, $projectDB, $locale) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Locale\Locale $locale */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $sessions = $user->getAttribute('sessions', []);
        $countries = $locale->getText('countries');

        foreach ($sessions as $key => $session) { 
            /** @var Document $session */

            $session->setAttribute('countryName', (isset($countries[strtoupper($session->getAttribute('countryCode'))]))
                ? $countries[strtoupper($session->getAttribute('countryCode'))]
                : $locale->getText('locale.country.unknown'));
            $session->setAttribute('current', false);

            $sessions[$key] = $session;
        }

        $response->dynamic(new Document([
            'sum' => count($sessions),
            'sessions' => $sessions
        ]), Response::MODEL_SESSION_LIST);
    }, ['response', 'projectDB', 'locale']);

App::get('/v1/users/:userId/logs')
    ->desc('Get User Logs')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getLogs')
    ->label('sdk.description', '/docs/references/users/get-user-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->inject('response')
    ->inject('project')
    ->inject('projectDB')
    ->inject('locale')
    ->inject('geodb')
    ->inject('utopia')
    ->action(function ($userId, $response, $project, $projectDB, $locale, $geodb, $utopia) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Utopia\App $utopia */
        
        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $adapter = new AuditAdapter($utopia->getResource('db'));
        $adapter->setNamespace('app_'.$project->getId());

        $audit = new Audit($adapter);
        
        $countries = $locale->getText('countries');

        $logs = $audit->getLogsByUserAndActions($user->getId(), [
            'account.create',
            'account.delete',
            'account.update.name',
            'account.update.email',
            'account.update.password',
            'account.update.prefs',
            'account.sessions.create',
            'account.sessions.delete',
            'account.recovery.create',
            'account.recovery.update',
            'account.verification.create',
            'account.verification.update',
            'teams.membership.create',
            'teams.membership.update',
            'teams.membership.delete',
        ]);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $dd = new DeviceDetector($log['userAgent']);

            $dd->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $dd->parse();

            $os = $dd->getOs();
            $osCode = (isset($os['short_name'])) ? $os['short_name'] : '';
            $osName = (isset($os['name'])) ? $os['name'] : '';
            $osVersion = (isset($os['version'])) ? $os['version'] : '';

            $client = $dd->getClient();
            $clientType = (isset($client['type'])) ? $client['type'] : '';
            $clientCode = (isset($client['short_name'])) ? $client['short_name'] : '';
            $clientName = (isset($client['name'])) ? $client['name'] : '';
            $clientVersion = (isset($client['version'])) ? $client['version'] : '';
            $clientEngine = (isset($client['engine'])) ? $client['engine'] : '';
            $clientEngineVersion = (isset($client['engine_version'])) ? $client['engine_version'] : '';

            $output[$i] = new Document([
                'event' => $log['event'],
                'ip' => $log['ip'],
                'time' => \strtotime($log['time']),

                'osCode' => $osCode,
                'osName' => $osName,
                'osVersion' => $osVersion,
                'clientType' => $clientType,
                'clientCode' => $clientCode,
                'clientName' => $clientName,
                'clientVersion' => $clientVersion,
                'clientEngine' => $clientEngine,
                'clientEngineVersion' => $clientEngineVersion,
                'deviceName' => $dd->getDeviceName(),
                'deviceBrand' => $dd->getBrandName(),
                'deviceModel' => $dd->getModel(),
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = (isset($countries[$record['country']['iso_code']])) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = (isset($countries[$record['country']['iso_code']])) ? $countries[$record['country']['iso_code']] : $locale->getText('locale.country.unknown');
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->dynamic(new Document(['logs' => $output]), Response::MODEL_LOG_LIST);
    });

App::patch('/v1/users/:userId/status')
    ->desc('Update User Status')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.status')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/users/update-user-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('status', null, new Boolean(true), 'User Status. To activate the user pass true and to block the user pass false')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($userId, $status, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
            'status' => (bool) $status,
        ]));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification')
    ->desc('Update Email Verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.verification')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateVerification')
    ->label('sdk.description', '/docs/references/users/update-user-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('emailVerification', false, new Boolean(), 'User Email Verification Status.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($userId, $emailVerification, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
            'emailVerification' => $emailVerification,
        ]));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/prefs')
    ->desc('Update User Preferences')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.prefs')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/users/update-user-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('projectDB')
    ->action(function ($userId, $prefs, $response, $projectDB) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
            'prefs' => $prefs,
        ]));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::delete('/v1/users/:userId/sessions/:sessionId')
    ->desc('Delete User Session')
    ->groups(['api', 'users'])
    ->label('event', 'users.sessions.delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/users/delete-user-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('sessionId', null, new UID(), 'User unique session ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('events')
    ->action(function ($userId, $sessionId, $response, $projectDB, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $events */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $session) { 
            /** @var Document $session */

            if ($sessionId == $session->getId()) {
                if (!$projectDB->deleteDocument($session->getId())) {
                    throw new Exception('Failed to remove token from DB', 500);
                }

                $events
                    ->setParam('eventData', $response->output($user, Response::MODEL_USER))
                ;
            }
        }

        // TODO : Response filter implementation
        $response->noContent();
    });

App::delete('/v1/users/:userId/sessions')
    ->desc('Delete User Sessions')
    ->groups(['api', 'users'])
    ->label('event', 'users.sessions.delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/users/delete-user-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('events')
    ->action(function ($userId, $response, $projectDB, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $events */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $session) { 
            /** @var Document $session */

            if (!$projectDB->deleteDocument($session->getId())) {
                throw new Exception('Failed to remove token from DB', 500);
            }
        }

        $events
            ->setParam('eventData', $response->output($user, Response::MODEL_USER))
        ;

        // TODO : Response filter implementation
        $response->noContent();
    });

App::delete('/v1/users/:userId')
    ->desc('Delete User')
    ->groups(['api', 'users'])
    ->label('event', 'users.delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/users/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', function () {return new UID();}, 'User unique ID.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('events')
    ->inject('deletes')
    ->action(function ($userId, $response, $projectDB, $events, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $deletes */
        
        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }
        if (!$projectDB->deleteDocument($userId)) {
            throw new Exception('Failed to remove user from DB', 500);
        }

        if (!$projectDB->deleteUniqueKey(md5('users:email='.$user->getAttribute('email', null)))) {
            throw new Exception('Failed to remove unique key from DB', 500);
        }
        
        $reservedId = $projectDB->createDocument([
            '$collection' => Database::SYSTEM_COLLECTION_RESERVED,
            '$id' => $userId,
            '$permissions' => [
                'read' => ['*'],
            ],
        ]);

        if (false === $reservedId) {
            throw new Exception('Failed saving reserved id to DB', 500);
        }

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $user)
        ;

        $events
            ->setParam('eventData', $response->output($user, Response::MODEL_USER))
        ;

        // TODO : Response filter implementation
        $response->noContent();
    });
