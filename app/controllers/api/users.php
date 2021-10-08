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
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;

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
    ->param('userId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string `unique()` to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be between 6 to 32 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($userId, $email, $password, $name, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

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
                'search' => implode(' ', [$userId, $email, $name]),
                'deleted' => false
            ]));
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409);
        }

        $usage
            ->setParam('users.create', 1)
        ;

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
    ->param('cursor', '', new UID(), 'ID of the user used as the starting point for the query, excluding the user itself. Should be used for efficient pagination when working with large sets of data.', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('usage')
    ->action(function ($search, $limit, $offset, $cursor, $cursorDirection, $orderType, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        if (!empty($cursor)) {
            $cursorUser = $dbForInternal->getDocument('users', $cursor);

            if ($cursorUser->isEmpty()) {
                throw new Exception("User '{$cursor}' for the 'cursor' value not found.", 400);
            }
        }

        $queries = [
            new Query('deleted', Query::TYPE_EQUAL, [false])
        ];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $usage
            ->setParam('users.read', 1)
        ;

        $response->dynamic(new Document([
            'users' => $dbForInternal->find('users', $queries, $limit, $offset, [], [$orderType], $cursorUser ?? null, $cursorDirection),
            'sum' => $dbForInternal->count('users', $queries, APP_LIMIT_COUNT),
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
    ->inject('usage')
    ->action(function ($userId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */ 

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        $usage
            ->setParam('users.read', 1)
        ;
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
    ->inject('usage')
    ->action(function ($userId, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        $prefs = $user->getAttribute('prefs', new \stdClass());

        $usage
            ->setParam('users.read', 1)
        ;
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
    ->inject('usage')
    ->action(function ($userId, $response, $dbForInternal, $locale, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
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

        $usage
            ->setParam('users.read', 1)
        ;
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
    ->inject('usage')
    ->action(function ($userId, $response, $dbForInternal, $locale, $geodb, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Document $project */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
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

        $usage
            ->setParam('users.read', 1)
        ;
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
    ->inject('usage')
    ->action(function ($userId, $status, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        $user = $dbForInternal->updateDocument('users', $user->getId(), $user->setAttribute('status', (bool) $status));

        $usage
            ->setParam('users.update', 1)
        ;
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
    ->inject('usage')
    ->action(function ($userId, $emailVerification, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        $user = $dbForInternal->updateDocument('users', $user->getId(), $user->setAttribute('emailVerification', $emailVerification));

        $usage
            ->setParam('users.update', 1)
        ;
        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/name')
    ->desc('Update Name')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.name')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/users/update-user-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->action(function ($userId, $name, $response, $dbForInternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        $user = $dbForInternal->updateDocument('users', $user->getId(), $user->setAttribute('name', $name));

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'users.update.name')
            ->setParam('resource', 'user/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/password')
    ->desc('Update Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.password')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/users/update-user-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('password', '', new Password(), 'New user password. Must be between 6 to 32 chars.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->action(function ($userId, $password, $response, $dbForInternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        $user
            ->setAttribute('password', Auth::passwordHash($password))
            ->setAttribute('passwordUpdate', \time());

        $user = $dbForInternal->updateDocument('users', $user->getId(), $user);

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'users.update.password')
            ->setParam('resource', 'user/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/email')
    ->desc('Update Email')
    ->groups(['api', 'users'])
    ->label('event', 'users.update.email')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/users/update-user-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('email', '', new Email(), 'User email.')
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('audits')
    ->action(function ($userId, $email, $response, $dbForInternal, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $audits */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        $isAnonymousUser = is_null($user->getAttribute('email')) && is_null($user->getAttribute('password')); // Check if request is from an anonymous account for converting
        if (!$isAnonymousUser) {
            //TODO: Remove previous unique ID.
        }

        $email = \strtolower($email);

        try {
            $user = $dbForInternal->updateDocument('users', $user->getId(), $user->setAttribute('email', $email));
        } catch(Duplicate $th) {
            throw new Exception('Email already exists', 409);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'users.update.email')
            ->setParam('resource', 'user/'.$user->getId())
        ;

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
    ->inject('usage')
    ->action(function ($userId, $prefs, $response, $dbForInternal, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        $user = $dbForInternal->updateDocument('users', $user->getId(), $user->setAttribute('prefs', $prefs));

        $usage
            ->setParam('users.update', 1)
        ;
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
    ->inject('usage')
    ->action(function ($userId, $sessionId, $response, $dbForInternal, $events, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
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

        $usage
            ->setParam('users.update', 1)
            ->setParam('users.sessions.delete', 1)
        ;

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
    ->inject('usage')
    ->action(function ($userId, $response, $dbForInternal, $events, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Stats\Stats $usage */

        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
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

        $usage
            ->setParam('users.update', 1)
            ->setParam('users.sessions.delete', 1)
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
    ->inject('usage')
    ->action(function ($userId, $response, $dbForInternal, $events, $deletes, $usage) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $deletes */
        /** @var Appwrite\Stats\Stats $usage */
        
        $user = $dbForInternal->getDocument('users', $userId);

        if ($user->isEmpty() || $user->getAttribute('deleted')) {
            throw new Exception('User not found', 404);
        }

        // clone user object to send to workers
        $clone = clone $user;

        $user
            ->setAttribute("name", null)
            ->setAttribute("email", null)
            ->setAttribute("password", null)
            ->setAttribute("deleted", true)
        ;

        $dbForInternal->updateDocument('users', $userId, $user);

        $deletes
            ->setParam('type', DELETE_TYPE_DOCUMENT)
            ->setParam('document', $clone)
        ;

        $events
            ->setParam('eventData', $response->output($clone, Response::MODEL_USER))
        ;

        $usage
            ->setParam('users.delete', 1)
        ;

        $response->noContent();
    });

App::get('/v1/users/usage')
    ->desc('Get usage stats for the users API')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getUsage')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USAGE_USERS)
    ->param('range', '30d', new WhiteList(['24h', '7d', '30d', '90d'], true), 'Date range.', true)
    ->param('provider', '', new WhiteList(\array_merge(['email', 'anonymous'], \array_map(function($value) { return "oauth-".$value; }, \array_keys(Config::getParam('providers', [])))), true), 'Provider Name.', true)
    ->inject('response')
    ->inject('dbForInternal')
    ->inject('register')
    ->action(function ($range, $provider, $response, $dbForInternal) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Database\Database $dbForInternal */

        $usage = [];
        if (App::getEnv('_APP_USAGE_STATS', 'enabled') == 'enabled') {
            $period = [
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

            $metrics = [
                "users.count",
                "users.create",
                "users.read",
                "users.update",
                "users.delete",
                "users.sessions.create",
                "users.sessions.$provider.create",
                "users.sessions.delete"
            ];

            $stats = [];

            Authorization::skip(function() use ($dbForInternal, $period, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $requestDocs = $dbForInternal->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period[$range]['period']]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $period[$range]['limit'], 0, ['time'], [Database::ORDER_DESC]);
    
                    $stats[$metric] = [];
                    foreach ($requestDocs as $requestDoc) {
                        $stats[$metric][] = [
                            'value' => $requestDoc->getAttribute('value'),
                            'date' => $requestDoc->getAttribute('time'),
                        ];
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }    
            });

            $usage = new Document([
                'range' => $range,
                'users.count' => $stats["users.count"],
                'users.create' => $stats["users.create"],
                'users.read' => $stats["users.read"],
                'users.update' => $stats["users.update"],
                'users.delete' => $stats["users.delete"],
                'sessions.create' => $stats["users.sessions.create"],
                'sessions.provider.create' => $stats["users.sessions.$provider.create"],
                'sessions.delete' => $stats["users.sessions.delete"]
            ]);

        }

        $response->dynamic($usage, Response::MODEL_USAGE_USERS);
    });