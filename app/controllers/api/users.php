<?php

global $utopia, $response, $projectDB;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\Assoc;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Email;
use Utopia\Validator\Text;
use Utopia\Validator\Range;
use Utopia\Audit\Audit;
use Utopia\Audit\Adapters\MySQL as AuditAdapter;
use Utopia\Config\Config;
use Utopia\Locale\Locale;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Database\Database;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\UID;
use DeviceDetector\DeviceDetector;
use GeoIp2\Database\Reader;

include_once __DIR__ . '/../shared/api.php';

$utopia->post('/v1/users')
    ->desc('Create User')
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/users/create-user.md')
    ->param('email', '', function () { return new Email(); }, 'User email.')
    ->param('password', '', function () { return new Password(); }, 'User password.')
    ->param('name', '', function () { return new Text(100); }, 'User name.', true)
    ->action(
        function ($email, $password, $name) use ($response, $projectDB) {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
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
                    'password-update' => time(),
                    'registration' => time(),
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

                $oauth2Keys[] = 'oauth2'.ucfirst($key);
                $oauth2Keys[] = 'oauth2'.ucfirst($key).'AccessToken';
            }

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json(array_merge($user->getArrayCopy(array_merge([
                    '$id',
                    'status',
                    'email',
                    'registration',
                    'emailVerification',
                    'name',
                ], $oauth2Keys)), ['roles' => []]));
        }
    );
    
$utopia->get('/v1/users')
    ->desc('List Users')
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'list')
    ->label('sdk.description', '/docs/references/users/list-users.md')
    ->param('search', '', function () { return new Text(256); }, 'Search term to filter your list results.', true)
    ->param('limit', 25, function () { return new Range(0, 100); }, 'Results limit value. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, function () { return new Range(0, 2000); }, 'Results offset. The default value is 0. Use this param to manage pagination.', true)
    ->param('orderType', 'ASC', function () { return new WhiteList(['ASC', 'DESC']); }, 'Order result by ASC or DESC order.', true)
    ->action(
        function ($search, $limit, $offset, $orderType) use ($response, $projectDB) {
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

                $oauth2Keys[] = 'oauth2'.ucfirst($key);
                $oauth2Keys[] = 'oauth2'.ucfirst($key).'AccessToken';
            }

            $results = array_map(function ($value) use ($oauth2Keys) { /* @var $value \Database\Document */
                return $value->getArrayCopy(array_merge(
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
        }
    );

$utopia->get('/v1/users/:userId')
    ->desc('Get User')
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/users/get-user.md')
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $oauth2Keys = [];

            foreach (Config::getParam('providers') as $key => $provider) {
                if (!$provider['enabled']) {
                    continue;
                }

                $oauth2Keys[] = 'oauth2'.ucfirst($key);
                $oauth2Keys[] = 'oauth2'.ucfirst($key).'AccessToken';
            }

            $response->json(array_merge($user->getArrayCopy(array_merge(
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
        }
    );

$utopia->get('/v1/users/:userId/prefs')
    ->desc('Get User Preferences')
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/users/get-user-prefs.md')
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $prefs = $user->getAttribute('prefs', '');

            try {
                $prefs = json_decode($prefs, true);
                $prefs = ($prefs) ? $prefs : [];
            } catch (\Exception $error) {
                throw new Exception('Failed to parse prefs', 500);
            }

            $response->json($prefs);
        }
    );

$utopia->get('/v1/users/:userId/sessions')
    ->desc('Get User Sessions')
    ->label('scope', 'users.read')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getSessions')
    ->label('sdk.description', '/docs/references/users/get-user-sessions.md')
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $tokens = $user->getAttribute('tokens', []);
            $reader = new Reader(__DIR__.'/../../db/DBIP/dbip-country-lite-2020-01.mmdb');
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
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'getLogs')
    ->label('sdk.description', '/docs/references/users/get-user-logs.md')
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $register, $projectDB, $project) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $adapter = new AuditAdapter($register->get('db'));
            $adapter->setNamespace('app_'.$project->getId());

            $audit = new Audit($adapter);
            
            $countries = Locale::getText('countries');

            $logs = $audit->getLogsByUser($user->getId());

            $reader = new Reader(__DIR__.'/../../db/DBIP/dbip-country-lite-2020-01.mmdb');
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

$utopia->patch('/v1/users/:userId/status')
    ->desc('Update User Status')
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/users/update-user-status.md')
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->param('status', '', function () { return new WhiteList([Auth::USER_STATUS_ACTIVATED, Auth::USER_STATUS_BLOCKED, Auth::USER_STATUS_UNACTIVATED]); }, 'User Status code. To activate the user pass '.Auth::USER_STATUS_ACTIVATED.', to block the user pass '.Auth::USER_STATUS_BLOCKED.' and for disabling the user pass '.Auth::USER_STATUS_UNACTIVATED)
    ->action(
        function ($userId, $status) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
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

                $oauth2Keys[] = 'oauth2'.ucfirst($key);
                $oauth2Keys[] = 'oauth2'.ucfirst($key).'AccessToken';
            }

            $response
                ->json(array_merge($user->getArrayCopy(array_merge([
                    '$id',
                    'status',
                    'email',
                    'registration',
                    'emailVerification',
                    'name',
                ], $oauth2Keys)), ['roles' => []]));
        }
    );

$utopia->patch('/v1/users/:userId/prefs')
    ->desc('Update User Preferences')
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/users/update-user-prefs.md')
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->param('prefs', '', function () { return new Assoc();}, 'Prefs key-value JSON object.')
    ->action(
        function ($userId, $prefs) use ($response, $projectDB) {
            $user = $projectDB->getDocument($userId);

            if (empty($user->getId()) || Database::SYSTEM_COLLECTION_USERS != $user->getCollection()) {
                throw new Exception('User not found', 404);
            }

            $old = json_decode($user->getAttribute('prefs', '{}'), true);
            $old = ($old) ? $old : [];

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'prefs' => json_encode(array_merge($old, $prefs)),
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $prefs = $user->getAttribute('prefs', '');

            try {
                $prefs = json_decode($prefs, true);
                $prefs = ($prefs) ? $prefs : [];
            } catch (\Exception $error) {
                throw new Exception('Failed to parse prefs', 500);
            }

            $response->json($prefs);
        }
    );


$utopia->delete('/v1/users/:userId/sessions/:sessionId')
    ->desc('Delete User Session')
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/users/delete-user-session.md')
    ->label('abuse-limit', 100)
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->param('sessionId', null, function () { return new UID(); }, 'User unique session ID.')
    ->action(
        function ($userId, $sessionId) use ($response, $request, $projectDB) {
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
        }
    );

$utopia->delete('/v1/users/:userId/sessions')
    ->desc('Delete User Sessions')
    ->label('scope', 'users.write')
    ->label('sdk.platform', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'users')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/users/delete-user-sessions.md')
    ->label('abuse-limit', 100)
    ->param('userId', '', function () { return new UID(); }, 'User unique ID.')
    ->action(
        function ($userId) use ($response, $request, $projectDB) {
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
        }
    );
