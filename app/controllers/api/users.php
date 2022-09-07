<?php

use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Audit as EventAudit;
use Appwrite\Network\Validator\Email;
use Appwrite\Stats\Stats;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Response;
use Utopia\App;
use Utopia\Audit\Audit;
use Utopia\Config\Config;
use Utopia\Locale\Locale;
use Appwrite\Extend\Exception;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Validator\UID;
use Utopia\Database\Database;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Validator\Boolean;
use MaxMind\Db\Reader;

App::post('/v1/users')
    ->desc('Create User')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/users/create-user.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new CustomId(), 'User ID. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $name, Response $response, Database $dbForProject, Stats $usage, Event $events) {

        $email = \strtolower($email);

        try {
            $userId = $userId == 'unique()' ? $dbForProject->getId() : $userId;
            $user = $dbForProject->createDocument('users', new Document([
                '$id' => $userId,
                '$read' => ['role:all'],
                '$write' => ['user:' . $userId],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => Auth::passwordHash($password),
                'passwordUpdate' => \time(),
                'registration' => \time(),
                'reset' => false,
                'name' => $name,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email, $name])
            ]));
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409, Exception::USER_ALREADY_EXISTS);
        }

        $usage
            ->setParam('users.create', 1)
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER);
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
    ->param('limit', 25, new Range(0, 100), 'Maximum number of users to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this param to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursor', '', new UID(), 'ID of the user used as the starting point for the query, excluding the user itself. Should be used for efficient pagination when working with large sets of data. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->param('cursorDirection', Database::CURSOR_AFTER, new WhiteList([Database::CURSOR_AFTER, Database::CURSOR_BEFORE]), 'Direction of the cursor, can be either \'before\' or \'after\'.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $search, int $limit, int $offset, string $cursor, string $cursorDirection, string $orderType, Response $response, Database $dbForProject, Stats $usage) {

        if (!empty($cursor)) {
            $cursorUser = $dbForProject->getDocument('users', $cursor);

            if ($cursorUser->isEmpty()) {
                throw new Exception("User '{$cursor}' for the 'cursor' value not found.", 400, Exception::GENERAL_CURSOR_NOT_FOUND);
            }
        }

        $queries = [];

        if (!empty($search)) {
            $queries[] = new Query('search', Query::TYPE_SEARCH, [$search]);
        }

        $usage
            ->setParam('users.read', 1)
        ;

        $response->dynamic(new Document([
            'users' => $dbForProject->find('users', $queries, $limit, $offset, [], [$orderType], $cursorUser ?? null, $cursorDirection),
            'total' => $dbForProject->count('users', $queries, APP_LIMIT_COUNT),
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
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $userId, Response $response, Database $dbForProject, Stats $usage) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
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
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (string $userId, Response $response, Database $dbForProject, Stats $usage) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
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
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('usage')
    ->action(function (string $userId, Response $response, Database $dbForProject, Locale $locale, Stats $usage) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {
            /** @var Document $session */

            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));
            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', false);

            $sessions[$key] = $session;
        }

        $usage
            ->setParam('users.read', 1)
        ;
        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => count($sessions),
        ]), Response::MODEL_SESSION_LIST);
    });

App::get('/v1/users/:userId/memberships')
    ->desc('Get User Memberships')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getMemberships')
    ->label('sdk.description', '/docs/references/users/get-user-memberships.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MEMBERSHIP_LIST)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->action(function (string $userId, Response $response, Database $dbForProject) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $memberships = array_map(function ($membership) use ($dbForProject, $user) {
            $team = $dbForProject->getDocument('teams', $membership->getAttribute('teamId'));

            $membership
                ->setAttribute('teamName', $team->getAttribute('name'))
                ->setAttribute('userName', $user->getAttribute('name'))
                ->setAttribute('userEmail', $user->getAttribute('email'));

            return $membership;
        }, $user->getAttribute('memberships', []));

        $response->dynamic(new Document([
            'memberships' => $memberships,
            'total' => count($memberships),
        ]), Response::MODEL_MEMBERSHIP_LIST);
    });

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
    ->param('userId', '', new UID(), 'User ID.')
    ->param('limit', 25, new Range(0, 100), 'Maximum number of logs to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('usage')
    ->action(function (string $userId, int $limit, int $offset, Response $response, Database $dbForProject, Locale $locale, Reader $geodb, Stats $usage) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $audit = new Audit($dbForProject);

        $logs = $audit->getLogsByUser($user->getId(), $limit, $offset);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);
            $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

            $os = $detector->getOS();
            $client = $detector->getClient();
            $device = $detector->getDevice();

            $output[$i] = new Document([
                'event' => $log['event'],
                'ip' => $log['ip'],
                'time' => $log['time'],
                'osCode' => $os['osCode'],
                'osName' => $os['osName'],
                'osVersion' => $os['osVersion'],
                'clientType' => $client['clientType'],
                'clientCode' => $client['clientCode'],
                'clientName' => $client['clientName'],
                'clientVersion' => $client['clientVersion'],
                'clientEngine' => $client['clientEngine'],
                'clientEngineVersion' => $client['clientEngineVersion'],
                'deviceName' => $device['deviceName'],
                'deviceBrand' => $device['deviceBrand'],
                'deviceModel' => $device['deviceModel']
            ]);

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $usage
            ->setParam('users.read', 1)
        ;

        $response->dynamic(new Document([
            'total' => $audit->countLogsByUser($user->getId()),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::patch('/v1/users/:userId/status')
    ->desc('Update User Status')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.status')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/users/update-user-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('status', null, new Boolean(true), 'User Status. To activate the user pass `true` and to block the user pass `false`.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, bool $status, Response $response, Database $dbForProject, Stats $usage, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('status', (bool) $status));

        $usage
            ->setParam('users.update', 1)
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification')
    ->desc('Update Email Verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.verification')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateEmailVerification')
    ->label('sdk.description', '/docs/references/users/update-user-email-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('emailVerification', false, new Boolean(), 'User email verification status.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, bool $emailVerification, Response $response, Database $dbForProject, Stats $usage, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('emailVerification', $emailVerification));

        $usage
            ->setParam('users.update', 1)
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/verification/phone')
    ->desc('Update Phone Verification')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.verification')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePhoneVerification')
    ->label('sdk.description', '/docs/references/users/update-user-phone-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('phoneVerification', false, new Boolean(), 'User phone verification status.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, bool $phoneVerification, Response $response, Database $dbForProject, Stats $usage, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('phoneVerification', $phoneVerification));

        $usage
            ->setParam('users.update', 1)
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/name')
    ->desc('Update Name')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.name')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/users/update-user-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $userId, string $name, Response $response, Database $dbForProject, EventAudit $audits, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user
            ->setAttribute('name', $name)
            ->setAttribute('search', \implode(' ', [$user->getId(), $user->getAttribute('email'), $name]));
        ;

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $audits
            ->setResource('user/' . $user->getId())
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/password')
    ->desc('Update Password')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.password')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/users/update-user-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('password', '', new Password(), 'New user password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $userId, string $password, Response $response, Database $dbForProject, EventAudit $audits, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user
            ->setAttribute('password', Auth::passwordHash($password))
            ->setAttribute('passwordUpdate', \time());

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        $audits
            ->setResource('user/' . $user->getId())
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/email')
    ->desc('Update Email')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.email')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/users/update-user-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('email', '', new Email(), 'User email.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $userId, string $email, Response $response, Database $dbForProject, EventAudit $audits, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $email = \strtolower($email);

        $user
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false)
            ->setAttribute('search', \implode(' ', [$user->getId(), $email, $user->getAttribute('name')]))
        ;

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
        } catch (Duplicate $th) {
            throw new Exception('Email already exists', 409, Exception::USER_EMAIL_ALREADY_EXISTS);
        }


        $audits
            ->setResource('user/' . $user->getId())
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/phone')
    ->desc('Update Phone')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.phone')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePhone')
    ->label('sdk.description', '/docs/references/users/update-user-phone.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('number', '', new Phone(), 'User phone number.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $userId, string $number, Response $response, Database $dbForProject, EventAudit $audits, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user
            ->setAttribute('phone', $number)
            ->setAttribute('phoneVerification', false)
        ;

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
        } catch (Duplicate $th) {
            throw new Exception('Email already exists', 409, Exception::USER_EMAIL_ALREADY_EXISTS);
        }


        $audits
            ->setResource('user/' . $user->getId())
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/users/:userId/prefs')
    ->desc('Update User Preferences')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].update.prefs')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/users/update-user-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, array $prefs, Response $response, Database $dbForProject, Stats $usage, Event $events) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('prefs', $prefs));

        $usage
            ->setParam('users.update', 1)
        ;

        $events
            ->setParam('userId', $user->getId())
        ;

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::delete('/v1/users/:userId/sessions/:sessionId')
    ->desc('Delete User Session')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/users/delete-user-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User ID.')
    ->param('sessionId', null, new UID(), 'Session ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('usage')
    ->action(function (string $userId, string $sessionId, Response $response, Database $dbForProject, Event $events, Stats $usage) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $session = $dbForProject->getDocument('sessions', $sessionId);

        if ($session->isEmpty()) {
            throw new Exception('Session not found', 404, Exception::USER_SESSION_NOT_FOUND);
        }

        $dbForProject->deleteDocument('sessions', $session->getId());
        $dbForProject->deleteCachedDocument('users', $user->getId());


        $usage
            ->setParam('users.update', 1)
            ->setParam('users.sessions.delete', 1)
        ;

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $sessionId)
        ;

        $response->noContent();
    });

App::delete('/v1/users/:userId/sessions')
    ->desc('Delete User Sessions')
    ->groups(['api', 'users'])
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/users/delete-user-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('usage')
    ->action(function (string $userId, Response $response, Database $dbForProject, Event $events, Stats $usage) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) { /** @var Document $session */
            $dbForProject->deleteDocument('sessions', $session->getId());
            //TODO: fix this
        }

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $events
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_USER))
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
    ->label('event', 'users.[userId].delete')
    ->label('scope', 'users.write')
    ->label('sdk.auth', [APP_AUTH_TYPE_KEY])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/users/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('userId', '', new UID(), 'User ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('events')
    ->inject('deletes')
    ->inject('usage')
    ->action(function (string $userId, Response $response, Database $dbForProject, Event $events, Delete $deletes, Stats $usage) {

        $user = $dbForProject->getDocument('users', $userId);

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        // clone user object to send to workers
        $clone = clone $user;

        $dbForProject->deleteDocument('users', $userId);

        $deletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($clone)
        ;

        $events
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($clone, Response::MODEL_USER))
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
    ->param('provider', '', new WhiteList(\array_merge(['email', 'anonymous'], \array_map(fn($value) => "oauth-" . $value, \array_keys(Config::getParam('providers', [])))), true), 'Provider Name.', true)
    ->inject('response')
    ->inject('dbForProject')
    ->inject('register')
    ->action(function (string $range, string $provider, Response $response, Database $dbForProject) {

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

            Authorization::skip(function () use ($dbForProject, $periods, $range, $metrics, &$stats) {
                foreach ($metrics as $metric) {
                    $limit = $periods[$range]['limit'];
                    $period = $periods[$range]['period'];

                    $requestDocs = $dbForProject->find('stats', [
                        new Query('period', Query::TYPE_EQUAL, [$period]),
                        new Query('metric', Query::TYPE_EQUAL, [$metric]),
                    ], $limit, 0, ['time'], [Database::ORDER_DESC]);

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
                            'date' => ($stats[$metric][$last]['date'] ?? \time()) - $diff, // time of last metric minus period
                        ];
                        $backfill--;
                    }
                    $stats[$metric] = array_reverse($stats[$metric]);
                }
            });

            $usage = new Document([
                'range' => $range,
                'usersCount' => $stats["users.count"],
                'usersCreate' => $stats["users.create"],
                'usersRead' => $stats["users.read"],
                'usersUpdate' => $stats["users.update"],
                'usersDelete' => $stats["users.delete"],
                'sessionsCreate' => $stats["users.sessions.create"],
                'sessionsProviderCreate' => $stats["users.sessions.$provider.create"],
                'sessionsDelete' => $stats["users.sessions.delete"]
            ]);
        }

        $response->dynamic($usage, Response::MODEL_USAGE_USERS);
    });
