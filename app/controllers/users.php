<?php

global $utopia, $response, $projectDB, $providers;

use Auth\Auth;
use Auth\Validator\Password;
use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Email;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Locale\Locale;
use Database\Database;
use Database\Validator\Authorization;
use Database\Validator\UID;
use DeviceDetector\DeviceDetector;
use GeoIp2\Database\Reader;

$utopia->get('/v1/users')
    ->desc('List Users')
    ->label('scope', 'users.read')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'listUsers')
    ->label('sdk.description', 'Get a list of all the project users. You can use the query params to filter your results.')
    ->param('search', '', function () {
        return new Text(256);
    }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () {
        return new Range(0, 100);
    }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () {
        return new Range(0, 2000);
    }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () {
        return new WhiteList(['ASC', 'DESC']);
    }, 'Order result by ASC or DESC order.', true)
    ->action(
        function ($search, $limit, $offset, $orderType) use ($response, $projectDB, $providers) {
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

            $oauthKeys = [];

            foreach ($providers as $key => $provider) {
                if (!$provider['enabled']) {
                    continue;
                }

                $oauthKeys[] = 'oauth'.ucfirst($key);
                $oauthKeys[] = 'oauth'.ucfirst($key).'AccessToken';
            }

            $results = array_map(function ($value) use ($oauthKeys) { /* @var $value \Database\Document */
                return $value->getArrayCopy(array_merge(
                    [
                        '$uid',
                        'email',
                        'registration',
                        'confirm',
                        'name',
                    ],
                    $oauthKeys
                ));
            }, $results);

            $response->json(['sum' => $projectDB->getSum(), 'users' => $results]);
        }
    );

$utopia->get('/v1/users/:userId')
    ->desc('Get User')
    ->label('scope', 'users.read')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getUser')
    ->label('sdk.description', 'Get user by its unique ID.')
    ->param('userId', '', function () {
        return new UID();
    }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $projectDB, $providers) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getUid()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $oauthKeys = [];

            foreach ($providers as $key => $provider) {
                if (!$provider['enabled']) {
                    continue;
                }

                $oauthKeys[] = 'oauth'.ucfirst($key);
                $oauthKeys[] = 'oauth'.ucfirst($key).'AccessToken';
            }

            $response->json(array_merge($user->getArrayCopy(array_merge(
                [
                    '$uid',
                    'email',
                    'registration',
                    'confirm',
                    'name',
                ],
                $oauthKeys
            )), ['roles' => Authorization::getRoles()]));
        }
    );

$utopia->get('/v1/users/:userId/prefs')
    ->desc('Get User Prefs')
    ->label('scope', 'users.read')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getUserPrefs')
    ->label('sdk.description', 'Get user preferences by its unique ID.')
    ->param('userId', '', function () {
        return new UID();
    }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getUid()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $prefs = $user->getAttribute('prefs', '');

            if (empty($prefs)) {
                $prefs = '[]';
            }

            try {
                $prefs = json_decode($prefs, true);
            } catch (\Exception $error) {
                throw new Exception('Failed to parse prefs', 500);
            }

            $response->json($prefs);
        }
    );

$utopia->get('/v1/users/:userId/sessions')
    ->desc('Get User Sessions')
    ->label('scope', 'users.read')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getUserSessions')
    ->label('sdk.description', 'Get user sessions list by its unique ID.')
    ->param('userId', '', function () {
        return new UID();
    }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getUid()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $tokens = $user->getAttribute('tokens', []);
            $reader = new Reader(__DIR__.'/../db/GeoLite2/GeoLite2-Country.mmdb');
            $sessions = [];
            $index = 0;
            $countries = Locale::getText('countries');

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
                    '$uid' => $token->getUid(),
                    'OS' => $dd->getOs(),
                    'client' => $dd->getClient(),
                    'device' => $dd->getDevice(),
                    'brand' => $dd->getBrand(),
                    'model' => $dd->getModel(),
                    'ip' => $token->getAttribute('ip', ''),
                    'geo' => [],
                ];

                try {
                    $record = $reader->country($token->getAttribute('ip', ''));
                    $sessions[$index]['geo']['isoCode'] = strtolower($record->country->isoCode);
                    $sessions[$index]['geo']['country'] = (isset($countries[$record->country->isoCode])) ? $countries[$record->country->isoCode] : Locale::getText('locale.country.unknown');
                } catch (\Exception $e) {
                    $sessions[$index]['geo']['isoCode'] = '--';
                    $sessions[$index]['geo']['country'] = Locale::getText('locale.country.unknown');
                }

                ++$index;
            }

            $response->json($sessions);
        }
    );

$utopia->get('/v1/users/:userId/logs')
    ->desc('Get User Logs')
    ->label('scope', 'users.read')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getUserLogs')
    ->label('sdk.description', 'Get user activity logs list by its unique ID.')
    ->param('userId', '', function () {
        return new UID();
    }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $register, $projectDB, $project) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getUid()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $ad = new \Audit\Adapter\MySQL($register->get('db'));
            $ad->setNamespace('app_'.$project->getUid());
            $au = new \Audit\Audit($ad, $user->getUid(), $user->getAttribute('type'), '', '', '');
            $countries = Locale::getText('countries');

            $logs = $au->getLogsByUser($user->getUid(), $user->getAttribute('type', 0));

            $reader = new Reader(__DIR__.'/../db/GeoLite2/GeoLite2-Country.mmdb');
            $output = [];

            foreach ($logs as $i => &$log) {
                $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

                $dd = new DeviceDetector($log['userAgent']);

                $dd->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

                $dd->parse();

                $output[$i] = [
                    'event' => $log['event'],
                    'ip' => $log['ip'],
                    'time' => strtotime($log['time']),
                    'OS' => $dd->getOs(),
                    'client' => $dd->getClient(),
                    'device' => $dd->getDevice(),
                    'brand' => $dd->getBrand(),
                    'model' => $dd->getModel(),
                    'geo' => [],
                ];

                try {
                    $record = $reader->country($log['ip']);
                    $output[$i]['geo']['isoCode'] = strtolower($record->country->isoCode);
                    $output[$i]['geo']['country'] = $record->country->name;
                    $output[$i]['geo']['country'] = (isset($countries[$record->country->isoCode])) ? $countries[$record->country->isoCode] : Locale::getText('locale.country.unknown');
                } catch (\Exception $e) {
                    $output[$i]['geo']['isoCode'] = '--';
                    $output[$i]['geo']['country'] = Locale::getText('locale.country.unknown');
                }
            }

            $response->json($output);
        }
    );

$utopia->post('/v1/users')
    ->desc('Create User')
    ->label('scope', 'users.write')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'createUser')
    ->label('sdk.description', 'Create a new user.')
    ->param('email', '', function () {
        return new Email();
    }, 'User account email.')
    ->param('password', '', function () {
        return new Password();
    }, 'User account password.')
    ->param('name', '', function () {
        return new Text(100);
    }, 'User account name.', true)
    ->action(
        function ($email, $password, $name) use ($response, $register, $projectDB, $providers) {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    'email='.$email,
                ],
            ]);

            if (!empty($profile)) {
                throw new Exception('User already registered', 400);
            }

            $user = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_USERS,
                '$permissions' => [
                    'read' => ['*'],
                    'write' => ['user:{self}'],
                ],
                'email' => $email,
                'status' => Auth::USER_STATUS_UNACTIVATED,
                'password' => Auth::passwordHash($password),
                'password-update' => time(),
                'registration' => time(),
                'confirm' => false,
                'reset' => false,
                'name' => $name,
            ]);

            $oauthKeys = [];

            foreach ($providers as $key => $provider) {
                if (!$provider['enabled']) {
                    continue;
                }

                $oauthKeys[] = 'oauth'.ucfirst($key);
                $oauthKeys[] = 'oauth'.ucfirst($key).'AccessToken';
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json(array_merge($user->getArrayCopy(array_merge([
                    '$uid',
                    'status',
                    'email',
                    'registration',
                    'confirm',
                    'name',
                ], $oauthKeys)), ['roles' => Authorization::getRoles()]));
        }
    );

$utopia->patch('/v1/users/:userId/status')
    ->desc('Update user status')
    ->label('scope', 'users.write')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateUserStatus')
    ->label('sdk.description', 'Update user status by its unique ID.')
    ->param('userId', '', function () {
        return new UID();
    }, 'User unique ID.')
    ->param('status', '', function () {
        return new WhiteList([Auth::USER_STATUS_ACTIVATED, Auth::USER_STATUS_BLOCKED, Auth::USER_STATUS_UNACTIVATED]);
    }, 'User Status code. To activate the user pass '.Auth::USER_STATUS_ACTIVATED.', to blocking the user pass '.Auth::USER_STATUS_BLOCKED.' and for disabling the user pass '.Auth::USER_STATUS_UNACTIVATED)
    ->action(
        function ($userId, $status) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getUid()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'status' => $status,
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $response
                ->json(array('result' => 'success'));
        }
    );

$utopia->patch('/v1/users/:userId/prefs')
    ->desc('Update Account Prefs')
    ->label('scope', 'users.write')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateUserPrefs')
    ->param('userId', '', function () {
        return new UID();
    }, 'User unique ID.')
    ->param('prefs', '', function () {
        return new \Utopia\Validator\Mock();
    }, 'Prefs key-value JSON object string.')
    ->label('sdk.description', 'Update user preferences by its unique ID. You can pass only the specific settings you wish to update.')
    ->action(
        function ($userId, $prefs) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getUid()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'prefs' => json_encode(array_merge(json_decode($user->getAttribute('prefs', '{}'), true), $prefs)),
            ]));
            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $response->json(array('result' => 'success'));
        }
    );


$utopia->delete('/v1/users/:userId/sessions/:session')
    ->desc('Delete User Session')
    ->label('scope', 'users.write')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteUsersSession')
    ->label('sdk.description', 'Delete user sessions by its unique ID.')
    ->label('abuse-limit', 100)
    ->param('userId', '', function () {
        return new UID();
    }, 'User unique ID.')
    ->param('sessionId', null, function () {
        return new UID();
    }, 'User unique session ID.')
    ->action(
        function ($userId, $sessionId) use ($response, $request, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getUid()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $tokens = $user->getAttribute('tokens', []);

            foreach ($tokens as $token) { /* @var $token Document */
                if ($sessionId == $token->getUid()) {
                    if (!$projectDB->deleteDocument($token->getUid())) {
                        throw new Exception('Failed to remove token from DB', 500);
                    }
                }
            }

            $response->json(array('result' => 'success'));
        }
    );

$utopia->delete('/v1/users/:userId/sessions')
    ->desc('Delete User Sessions')
    ->label('scope', 'users.write')
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteUserSessions')
    ->label('sdk.description', 'Delete all user sessions by its unique ID.')
    ->label('abuse-limit', 100)
    ->param('userId', '', function () {
        return new UID();
    }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $request, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getUid()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $tokens = $user->getAttribute('tokens', []);

            foreach ($tokens as $token) { /* @var $token Document */
                if (!$projectDB->deleteDocument($token->getUid())) {
                    throw new Exception('Failed to remove token from DB', 500);
                }
            }

            $response->json(array('result' => 'success'));
        }
    );
