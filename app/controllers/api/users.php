<?php

use Utopia\App;
use Utopia\Exception;
use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Email;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Audit\Audit;
use Utopia\Audit\Adapters\MySQL as AuditAdapter;
use Utopia\Config\Config;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Database\Database;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\UID;
use Appwrite\Utopia\Response;
use DeviceDetector\DeviceDetector;

App::post('/v1/users')
    ->desc('Create User')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/users/create-user.md')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be between 6 to 32 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->action(function ($email, $password, $name, $response, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

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
                'status' => Auth::USER_STATUS_UNACTIVATED,
                'password' => Auth::passwordHash($password),
                'password-update' => \time(),
                'registration' => \time(),
                'reset' => false,
                'name' => $name,
            ], ['email' => $email]);
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409);
        }

        $oauth2Keys = [];

        foreach (Config::getParam('providers') as $key => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            $oauth2Keys[] = 'oauth2'.\ucfirst($key);
            $oauth2Keys[] = 'oauth2'.\ucfirst($key).'AccessToken';
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->json(\array_merge($user->getArrayCopy(\array_merge([
                '$id',
                'status',
                'email',
                'registration',
                'emailVerification',
                'name',
            ], $oauth2Keys)), ['roles' => []]));
    }, ['response', 'projectDB']);

App::get('/v1/users')
    ->desc('List Users')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/users/list-users.md')
    ->param('search', '', new Text(256), 'Search term to filter your list results. Max length: 256 chars.', true)
    ->param('limit', 25, new Range(0, 100), 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, 2000), 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', new WhiteList(['ASC', 'DESC'], true), 'Order result by ASC or DESC order.', true)
    ->action(function ($search, $limit, $offset, $orderType, $response, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $results = $projectDB->getCollection([
            'limit' => $limit,
            'offset' => $offset,
            'orderField' => 'registration',
            'orderType' => $orderType,
            'orderCast' => 'int',
            'search' => $search,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
            ],
        ]);

        $oauth2Keys = [];

        foreach (Config::getParam('providers') as $key => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            $oauth2Keys[] = 'oauth2'.\ucfirst($key);
            $oauth2Keys[] = 'oauth2'.\ucfirst($key).'AccessToken';
        }

        $results = \array_map(function ($value) use ($oauth2Keys) { /* @var $value \Database\Document */
            return $value->getArrayCopy(\array_merge(
                [
                    '$id',
                    'status',
                    'email',
                    'registration',
                    'emailVerification',
                    'name',
                ],
                $oauth2Keys
            ));
        }, $results);

        $response->json(['sum' => $projectDB->getSum(), 'users' => $results]);
    }, ['response', 'projectDB']);

App::get('/v1/users/:userId')
    ->desc('Get User')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/users/get-user.md')
    ->param('userId', '', new UID(), 'User unique ID.')
    ->action(function ($userId, $response, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $oauth2Keys = [];

        foreach (Config::getParam('providers') as $key => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            $oauth2Keys[] = 'oauth2'.\ucfirst($key);
            $oauth2Keys[] = 'oauth2'.\ucfirst($key).'AccessToken';
        }

        $response->json(\array_merge($user->getArrayCopy(\array_merge(
            [
                '$id',
                'status',
                'email',
                'registration',
                'emailVerification',
                'name',
            ],
            $oauth2Keys
        )), ['roles' => []]));
    }, ['response', 'projectDB']);

App::get('/v1/users/:userId/prefs')
    ->desc('Get User Preferences')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/users/get-user-prefs.md')
    ->param('userId', '', new UID(), 'User unique ID.')
    ->action(function ($userId, $response, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $prefs = $user->getAttribute('prefs', '');

        try {
            $prefs = \json_decode($prefs, true);
            $prefs = ($prefs) ? $prefs : [];
        } catch (\Exception $error) {
            throw new Exception('Failed to parse prefs', 500);
        }

        $response->json($prefs);
    }, ['response', 'projectDB']);

App::get('/v1/users/:userId/sessions')
    ->desc('Get User Sessions')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getSessions')
    ->label('sdk.description', '/docs/references/users/get-user-sessions.md')
    ->param('userId', '', new UID(), 'User unique ID.')
    ->action(function ($userId, $response, $projectDB, $locale, $geodb) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $tokens = $user->getAttribute('tokens', []);
        $sessions = [];
        $index = 0;
        $countries = $locale->getText('countries');

        foreach ($tokens as $token) { /* @var $token Document */
            if (Auth::TOKEN_TYPE_LOGIN != $token->getAttribute('type')) {
                continue;
            }

            $userAgent = (!empty($token->getAttribute('userAgent'))) ? $token->getAttribute('userAgent') : 'UNKNOWN';

            $dd = new DeviceDetector($userAgent);

            // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)
            // $dd->skipBotDetection();

            $dd->parse();

            $sessions[$index] = [
                '$id' => $token->getId(),
                'OS' => $dd->getOs(),
                'client' => $dd->getClient(),
                'device' => $dd->getDevice(),
                'brand' => $dd->getBrand(),
                'model' => $dd->getModel(),
                'ip' => $token->getAttribute('ip', ''),
                'geo' => [],
            ];

            try {
                $record = $geodb->get($token->getAttribute('ip', ''));

                if ($record) {
                    $sessions[$index]['geo']['isoCode'] = \strtolower($record['country']['iso_code']);
                    $sessions[$index]['geo']['country'] = (isset($countries[$record['country']['iso_code']])) ? $countries[$record['country']['iso_code']] : $locale->getText('locale.country.unknown');
                } else {
                    $sessions[$index]['geo']['isoCode'] = '--';
                    $sessions[$index]['geo']['country'] = $locale->getText('locale.country.unknown');
                }

            } catch (\Exception $e) {
                $sessions[$index]['geo']['isoCode'] = '--';
                $sessions[$index]['geo']['country'] = $locale->getText('locale.country.unknown');
            }

            ++$index;
        }

        $response->json($sessions);
    }, ['response', 'projectDB', 'locale', 'geodb']);

App::get('/v1/users/:userId/logs')
    ->desc('Get User Logs')
    ->groups(['api', 'users'])
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getLogs')
    ->label('sdk.description', '/docs/references/users/get-user-logs.md')
    ->param('userId', '', new UID(), 'User unique ID.')
    ->action(function ($userId, $response, $register, $project, $projectDB, $locale, $geodb) {
        /** @var Utopia\Response $response */
        /** @var Utopia\Registry\Registry $register */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        
        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $adapter = new AuditAdapter($register->get('db'));
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

            $output[$i] = [
                'event' => $log['event'],
                'ip' => $log['ip'],
                'time' => \strtotime($log['time']),
                'OS' => $dd->getOs(),
                'client' => $dd->getClient(),
                'device' => $dd->getDevice(),
                'brand' => $dd->getBrand(),
                'model' => $dd->getModel(),
                'geo' => [],
            ];

            try {
                $record = $geodb->get($log['ip']);

                if(isset($record)){
                    $output[$i]['geo']['isoCode'] = \strtolower($record['country']['iso_code']);
                    $output[$i]['geo']['country'] = (isset($countries[$record['country']['iso_code']])) ? $countries[$record['country']['iso_code']] : $locale->getText('locale.country.unknown');
                    }
                else{
                    $output[$i]['geo']['isoCode'] = '--';
                    $output[$i]['geo']['country'] = $locale->getText('locale.country.unknown');
                }

            } catch (\Exception $e) {
                $output[$i]['geo']['isoCode'] = '--';
                $output[$i]['geo']['country'] = $locale->getText('locale.country.unknown');
            }
        }

        $response->json($output);
    }, ['response', 'register', 'project', 'projectDB', 'locale', 'geodb']);

App::patch('/v1/users/:userId/status')
    ->desc('Update User Status')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/users/update-user-status.md')
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('status', '', new WhiteList([Auth::USER_STATUS_ACTIVATED, Auth::USER_STATUS_BLOCKED, Auth::USER_STATUS_UNACTIVATED], true), 'User Status code. To activate the user pass '.Auth::USER_STATUS_ACTIVATED.', to block the user pass '.Auth::USER_STATUS_BLOCKED.' and for disabling the user pass '.Auth::USER_STATUS_UNACTIVATED)
    ->action(function ($userId, $status, $response, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
            'status' => (int)$status,
        ]));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }
        
        $oauth2Keys = [];

        foreach (Config::getParam('providers') as $key => $provider) {
            if (!$provider['enabled']) {
                continue;
            }

            $oauth2Keys[] = 'oauth2'.\ucfirst($key);
            $oauth2Keys[] = 'oauth2'.\ucfirst($key).'AccessToken';
        }

        $response
            ->json(\array_merge($user->getArrayCopy(\array_merge([
                '$id',
                'status',
                'email',
                'registration',
                'emailVerification',
                'name',
            ], $oauth2Keys)), ['roles' => []]));
    }, ['response', 'projectDB']);

App::patch('/v1/users/:userId/prefs')
    ->desc('Update User Preferences')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/users/update-user-prefs.md')
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('prefs', '', new Assoc(), 'Prefs key-value JSON object.')
    ->action(function ($userId, $prefs, $response, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $old = \json_decode($user->getAttribute('prefs', '{}'), true);
        $old = ($old) ? $old : [];

        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
            'prefs' => \json_encode(\array_merge($old, $prefs)),
        ]));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $prefs = $user->getAttribute('prefs', '');

        try {
            $prefs = \json_decode($prefs, true);
            $prefs = ($prefs) ? $prefs : [];
        } catch (\Exception $error) {
            throw new Exception('Failed to parse prefs', 500);
        }

        $response->json($prefs);
    }, ['response', 'projectDB']);

App::delete('/v1/users/:userId/sessions/:sessionId')
    ->desc('Delete User Session')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/users/delete-user-session.md')
    ->label('abuse-limit', 100)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('sessionId', null, new UID(), 'User unique session ID.')
    ->action(function ($userId, $sessionId, $response, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $tokens = $user->getAttribute('tokens', []);

        foreach ($tokens as $token) { /* @var $token Document */
            if ($sessionId == $token->getId()) {
                if (!$projectDB->deleteDocument($token->getId())) {
                    throw new Exception('Failed to remove token from DB', 500);
                }
            }
        }

        $response->json(array('result' => 'success'));
    }, ['response', 'projectDB']);

App::delete('/v1/users/:userId/sessions')
    ->desc('Delete User Sessions')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/users/delete-user-sessions.md')
    ->label('abuse-limit', 100)
    ->param('userId', '', new UID(), 'User unique ID.')
    ->action(function ($userId, $response, $projectDB) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */

        $user = $projectDB->getDocument($userId);

        if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
            throw new Exception('User not found', 404);
        }

        $tokens = $user->getAttribute('tokens', []);

        foreach ($tokens as $token) { /* @var $token Document */
            if (!$projectDB->deleteDocument($token->getId())) {
                throw new Exception('Failed to remove token from DB', 500);
            }
        }

        $response->json(array('result' => 'success'));
    }, ['response', 'projectDB']);

App::delete('/v1/users/:userId')
    ->desc('Delete User')
    ->groups(['api', 'users'])
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteUser')
    ->label('sdk.description', '/docs/references/users/delete-user.md')
    ->label('abuse-limit', 100)
    ->param('userId', '', function () {return new UID();}, 'User unique ID.')
    ->action(function ($userId, $response, $projectDB, $deletes) {
        /** @var Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
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

        $deletes->setParam('document', $user);

        $response->noContent();
    }, ['response', 'projectDB', 'deletes']);
