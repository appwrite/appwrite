<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Exception;
use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;
use Appwrite\Network\Validator\Email;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\Boolean;
use Utopia\Audit\Audit;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\UID;
use DeviceDetector\DeviceDetector;
use Appwrite\Database\Validator\CustomId;

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
    ->param('userId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, and underscore. Can\'t start with a leading underscore. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be between 6 to 32 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($userId, $email, $password, $name, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $email = \strtolower($email);

        try {
            $userId = $userId == 'unique()' ? $dbForInternal->getId() : $userId;
            $user = $dbForInternal->createDocument('users', new Document([
                '$id' => $userId,
                '$read' => ['role:all'],
                '$write' => ['user:'.$userId],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => Auth::passwordHash($password),
                'passwordUpdate' => \time(),
                'registration' => \time(),
                'reset' => false,
                'name' => $name,
                'prefs' => [],
                'sessions' => [],
                'tokens' => [],
                'memberships' => [],
            ]));
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409);
        }

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($user, Response::MODEL_USER);
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
    ->param('after', '', new UID(), 'ID of the user used as the starting point for the query, excluding the user itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($search, $limit, $offset, $after, $orderType, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        if (!empty($after)) {
            $afterUser = $dbForInternal->getDocument('users', $after);

            if ($afterUser->isEmpty()) {
                throw new Exception('User for after not found', 400);
            }
        }

        $results = $dbForInternal->find('users', [], $limit, $offset, [], [$orderType], $afterUser ?? null);
        $sum = $dbForInternal->count('users', [], APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'users' => $results,
            'sum' => $sum,
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
    ->inject('dbForInternal')
    ->action(function ($userId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
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
    ->inject('dbForInternal')
    ->action(function ($userId, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
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
    ->inject('dbForInternal')
    ->inject('locale')
    ->action(function ($userId, $response, $dbForInternal, $locale) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Locale\Locale $locale */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) { 
            /** @var Document $session */

            $countryName = $locale->getText('countries.'.strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));
            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', false);

            $sessions[$key] = $session;
        }

        $response->dynamic(new Document([
            'sessions' => $sessions,
            'sum' => count($sessions),
        ]), Response::MODEL_SESSION_LIST);
    }, ['response', 'dbForInternal', 'locale']);

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
    ->inject('dbForInternal')
    ->inject('locale')
    ->inject('geodb')
    ->action(function ($userId, $response, $dbForInternal, $locale, $geodb) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $audit = new Audit($dbForInternal);

        $logs = $audit->getLogsByUserAndEvents($user->getId(), [
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
                'time' => $log['time'],

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
                $output[$i]['countryCode'] = $locale->getText('countries.'.strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.'.strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
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
    ->param('status', null, new Boolean(true), 'User Status. To activate the user pass `true` and to block the user pass `false`')
    ->inject('response')
    ->inject('dbForInternal')
    ->action(function ($userId, $status, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $user = $dbForInternal->updateDocument('users', $user->getId(), $user->setAttribute('status', (bool) $status));

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
    ->inject('dbForInternal')
    ->action(function ($userId, $emailVerification, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $user = $dbForInternal->updateDocument('users', $user->getId(), $user->setAttribute('emailVerification', $emailVerification));

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
    ->inject('dbForInternal')
    ->action(function ($userId, $prefs, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $user = $dbForInternal->updateDocument('users', $user->getId(), $user->setAttribute('prefs', $prefs));

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
    ->inject('dbForInternal')
    ->inject('events')
    ->action(function ($userId, $sessionId, $response, $dbForInternal, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $events */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) { /** @var Document $session */

            if ($sessionId == $session->getId()) {
                unset($sessions[$key]);

                $dbForInternal->deleteDocument('sessions', $session->getId());

                $user->setAttribute('sessions', $sessions);
                
                $events
                    ->setParam('eventData', $response->output($user, Response::MODEL_USER))
                ;

                $dbForInternal->updateDocument('users', $user->getId(), $user);
            }
        }

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
    ->inject('dbForInternal')
    ->inject('events')
    ->action(function ($userId, $response, $dbForInternal, $events) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $events */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) { /** @var Document $session */
            $dbForInternal->deleteDocument('sessions', $session->getId());
        }

        $dbForInternal->updateDocument('users', $user->getId(), $user->getAttribute('sessions', []));

        $events
            ->setParam('eventData', $response->output($user, Response::MODEL_USER))
        ;

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
    ->inject('dbForInternal')
    ->inject('events')
    ->inject('deletes')
    ->action(function ($userId, $response, $dbForInternal, $events, $deletes) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $deletes */
        
        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404);
        }

        if (!$dbForInternal->deleteDocument('users', $userId)) {
            throw new Exception('Failed to remove user from DB', 500);
        }
        
        // $dbForInternal->createDocument('users', new Document([
        //     '$id' => $userId,
        //     '$read' => ['role:all'],
        // ]));

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $user)
        ;

        $events
            ->setParam('eventData', $response->output($user, Response::MODEL_USER))
        ;

        $response->noContent();
    });
