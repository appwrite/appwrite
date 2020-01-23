<?php

global $utopia, $register, $request, $response, $user, $audit,
    $webhook, $project, $domain, $projectDB, $providers, $clients;

use Utopia\Exception;
use Utopia\Response;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;
use Utopia\Validator\Email;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Host;
use Utopia\Validator\URL;
use Utopia\Audit\Audit;
use Utopia\Audit\Adapters\MySQL as AuditAdapter;
use Utopia\Locale\Locale;
use Auth\Auth;
use Auth\Validator\Password;
use Database\Database;
use Database\Document;
use Database\Validator\UID;
use Database\Validator\Authorization;
use DeviceDetector\DeviceDetector;
use GeoIp2\Database\Reader;
use Template\Template;
use OpenSSL\OpenSSL;

include_once __DIR__ . '/../shared/api.php';

$oauthKeys = [];

$utopia->init(function() use ($providers, &$oauthKeys) {
    foreach ($providers as $key => $provider) {
        if (!$provider['enabled']) {
            continue;
        }

        $oauthKeys[] = 'oauth'.ucfirst($key);
        $oauthKeys[] = 'oauth'.ucfirst($key).'AccessToken';
    }

});

$utopia->get('/v1/account')
    ->desc('Get Account')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getAccount')
    ->label('sdk.description', '/docs/references/account/get.md')
    ->action(
        function () use ($response, &$user, $oauthKeys) {            
            $response->json(array_merge($user->getArrayCopy(array_merge(
                [
                    '$uid',
                    'email',
                    'registration',
                    'name',
                ],
                $oauthKeys
            )), ['roles' => Authorization::getRoles()]));
        }
    );

$utopia->get('/v1/account/prefs')
    ->desc('Get Account Preferences')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getAccountPrefs')
    ->label('sdk.description', '/docs/references/account/get-prefs.md')
    ->action(
        function () use ($response, $user) {
            $prefs = $user->getAttribute('prefs', '{}');

            try {
                $prefs = json_decode($prefs, true);
                $prefs = ($prefs) ? $prefs : [];
            } catch (\Exception $error) {
                throw new Exception('Failed to parse prefs', 500);
            }

            $response->json($prefs);
        }
    );

$utopia->get('/v1/account/sessions')
    ->desc('Get Account Sessions')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getAccountSessions')
    ->label('sdk.description', '/docs/references/account/get-sessions.md')
    ->action(
        function () use ($response, $user) {
            $tokens = $user->getAttribute('tokens', []);
            $reader = new Reader(__DIR__.'/../db/DBIP/dbip-country-lite-2020-01.mmdb');
            $sessions = [];
            $current = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_LOGIN, Auth::$secret);
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
                    'current' => ($current == $token->getUid()) ? true : false,
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

$utopia->get('/v1/account/logs')
    ->desc('Get Account Logs')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getAccountLogs')
    ->label('sdk.description', '/docs/references/account/get-logs.md')
    ->action(
        function () use ($response, $register, $project, $user) {
            $adapter = new AuditAdapter($register->get('db'));
            $adapter->setNamespace('app_'.$project->getUid());

            $audit = new Audit($adapter);
            $countries = Locale::getText('countries');

            $logs = $audit->getLogsByUserAndActions($user->getUid(), [
                'auth.register', // TODO Deprectate this
                'auth.login', // TODO Deprectate this
                'auth.logout', // TODO Deprectate this
                'auth.recovery', // TODO Deprectate this
                'auth.recovery.reset', // TODO Deprectate this
                'auth.oauth.login', // TODO Deprectate this
                'auth.invite', // TODO Deprectate this
                'auth.join', // TODO Deprectate this
                'auth.leave', // TODO Deprectate this
                'account.create',
                'account.delete',
                'account.update.name',
                'account.update.email',
                'account.update.password',
                'account.update.prefs',
                'account.sessions.create',
                'account.sessions.delete',
            ]);

            $reader = new Reader(__DIR__.'/../db/DBIP/dbip-country-lite-2020-01.mmdb');
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

$utopia->post('/v1/account')
    ->desc('Create Account')
    ->label('webhook', 'account.create')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createAccount')
    ->label('sdk.description', '/docs/references/account/create.md')
    ->label('abuse-limit', 10)
    ->param('email', '', function () { return new Email(); }, 'Account email')
    ->param('password', '', function () { return new Password(); }, 'User password')
    ->param('name', '', function () { return new Text(100); }, 'User name', true)
    ->action(
        function ($email, $password, $name) use ($register, $request, $response, $audit, $projectDB, $project, $webhook, $oauthKeys) {
            if ('console' === $project->getUid()) {
                $whitlistEmails = $project->getAttribute('authWhitelistEmails');
                $whitlistIPs = $project->getAttribute('authWhitelistIPs');
                $whitlistDomains = $project->getAttribute('authWhitelistDomains');

                if (!empty($whitlistEmails) && !in_array($email, $whitlistEmails)) {
                    throw new Exception('Console registration is restricted to specific emails. Contact your administrator for more information.', 401);
                }

                if (!empty($whitlistIPs) && !in_array($request->getIP(), $whitlistIPs)) {
                    throw new Exception('Console registration is restricted to specific IPs. Contact your administrator for more information.', 401);
                }

                if (!empty($whitlistDomains) && !in_array(substr(strrchr($email, '@'), 1), $whitlistDomains)) {
                    throw new Exception('Console registration is restricted to specific domains. Contact your administrator for more information.', 401);
                }
            }

            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    'email='.$email,
                ],
            ]);

            if (!empty($profile)) {
                throw new Exception('Account already exists', 409);
            }

            Authorization::disable();

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

            Authorization::enable();

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $webhook
                ->setParam('payload', [
                    'name' => $name,
                    'email' => $email,
                ])
            ;

            $audit
                ->setParam('userId', $user->getUid())
                ->setParam('event', 'account.create')
                ->setParam('resource', 'users/'.$user->getUid())
            ;

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json(array_merge($user->getArrayCopy(array_merge(
                    [
                        '$uid',
                        'email',
                        'registration',
                        'name',
                    ],
                    $oauthKeys
                )), ['roles' => Authorization::getRoles()]));
        }
    );

$utopia->post('/v1/account/sessions')
    ->desc('Create Account Session')
    ->label('webhook', 'account.sessions.create')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createAccountSession')
    ->label('sdk.description', '/docs/references/account/create-session.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', function () { return new Email(); }, 'User account email address')
    ->param('password', '', function () { return new Password(); }, 'User account password')
    ->action(
        function ($email, $password) use ($response, $request, $projectDB, $audit, $webhook) {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    'email='.$email,
                ],
            ]);

            if (false == $profile || !Auth::passwordVerify($password, $profile->getAttribute('password'))) {
                $audit
                    //->setParam('userId', $profile->getUid())
                    ->setParam('event', 'account.sesssions.failed')
                    ->setParam('resource', 'users/'.($profile ? $profile->getUid() : ''))
                ;

                throw new Exception('Invalid credentials', 401); // Wrong password or username
            }

            $expiry = time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
            $secret = Auth::tokenGenerator();
            $session = new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:'.$profile->getUid()], 'write' => ['user:'.$profile->getUid()]],
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]);

            Authorization::setRole('user:'.$profile->getUid());

            $session = $projectDB->createDocument($session->getArrayCopy());

            if (false === $session) {
                throw new Exception('Failed saving session to DB', 500);
            }

            $profile->setAttribute('tokens', $session, Document::SET_TYPE_APPEND);

            $profile = $projectDB->updateDocument($profile->getArrayCopy());

            if (false === $profile) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $webhook
                ->setParam('payload', [
                    'name' => $profile->getAttribute('name', ''),
                    'email' => $profile->getAttribute('email', ''),
                ])
            ;

            $audit
                ->setParam('userId', $profile->getUid())
                ->setParam('event', 'account.sessions.create')
                ->setParam('resource', 'users/'.$profile->getUid())
            ;
            
            $response
                ->addCookie(Auth::$cookieName.'_legacy', Auth::encodeSession($profile->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null)
                ->addCookie(Auth::$cookieName, Auth::encodeSession($profile->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, COOKIE_SAMESITE)
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($session->getArrayCopy(['$uid', 'type', 'expire']))
            ;
        }
    );

$utopia->get('/v1/account/sessions/oauth/:provider')
    ->desc('Create Account Session with OAuth')
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createAccountSessionOAuth')
    ->label('sdk.description', '/docs/references/account/create-session-oauth.md')
    ->label('sdk.response.code', 301)
    ->label('sdk.response.type', 'text/html')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', function () use ($providers) { return new WhiteList(array_keys($providers)); }, 'OAuth Provider. Currently, supported providers are: ' . implode(', ', array_keys($providers)))
    ->param('success', '', function () use ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a successful login attempt.')
    ->param('failure', '', function () use ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a failed login attempt.')
    ->action(
        function ($provider, $success, $failure) use ($response, $request, $project) {
            $callback = $request->getServer('REQUEST_SCHEME', 'https').'://'.$request->getServer('HTTP_HOST').'/v1/account/sessions/oauth/callback/'.$provider.'/'.$project->getUid();
            $appId = $project->getAttribute('usersOauth'.ucfirst($provider).'Appid', '');
            $appSecret = $project->getAttribute('usersOauth'.ucfirst($provider).'Secret', '{}');

            $appSecret = json_decode($appSecret, true);

            if (!empty($appSecret) && isset($appSecret['version'])) {
                $key = $request->getServer('_APP_OPENSSL_KEY_V'.$appSecret['version']);
                $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, hex2bin($appSecret['iv']), hex2bin($appSecret['tag']));
            }

            if (empty($appId) || empty($appSecret)) {
                throw new Exception('Provider is undefined, configure provider app ID and app secret key to continue', 412);
            }

            $classname = 'Auth\\OAuth\\'.ucfirst($provider);

            if (!class_exists($classname)) {
                throw new Exception('Provider is not supported', 501);
            }

            $oauth = new $classname($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);

            $response->redirect($oauth->getLoginURL());
        }
    );

$utopia->get('/v1/account/sessions/oauth/callback/:provider/:projectId')
    ->desc('OAuth Callback')
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('projectId', '', function () { return new Text(1024); }, 'Project unique ID')
    ->param('provider', '', function () use ($providers) { return new WhiteList(array_keys($providers)); }, 'OAuth provider')
    ->param('code', '', function () { return new Text(1024); }, 'OAuth code')
    ->param('state', '', function () { return new Text(2048); }, 'Login state params', true)
    ->action(
        function ($projectId, $provider, $code, $state) use ($response, $request, $domain) {
            $response->redirect($request->getServer('REQUEST_SCHEME', 'https').'://'.$domain.'/v1/account/sessions/oauth/'.$provider.'/redirect?'
                .http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
        }
    );

$utopia->get('/v1/account/sessions/oauth/:provider/redirect')
    ->desc('OAuth Redirect')
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('webhook', 'account.sessions.create')
    ->label('scope', 'public')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->label('docs', false)
    ->param('provider', '', function () use ($providers) { return new WhiteList(array_keys($providers)); }, 'OAuth provider')
    ->param('code', '', function () { return new Text(1024); }, 'OAuth code')
    ->param('state', '', function () { return new Text(2048); }, 'OAuth state params', true)
    ->action(
        function ($provider, $code, $state) use ($response, $request, $user, $projectDB, $project, $audit) {
            $callback = $request->getServer('REQUEST_SCHEME', 'https').'://'.$request->getServer('HTTP_HOST').'/v1/account/sessions/oauth/callback/'.$provider.'/'.$project->getUid();
            $defaultState = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
            $validateURL = new URL();

            $appId = $project->getAttribute('usersOauth'.ucfirst($provider).'Appid', '');
            $appSecret = $project->getAttribute('usersOauth'.ucfirst($provider).'Secret', '{}');

            $appSecret = json_decode($appSecret, true);

            if (!empty($appSecret) && isset($appSecret['version'])) {
                $key = $request->getServer('_APP_OPENSSL_KEY_V'.$appSecret['version']);
                $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, hex2bin($appSecret['iv']), hex2bin($appSecret['tag']));
            }

            $classname = 'Auth\\OAuth\\'.ucfirst($provider);

            if (!class_exists($classname)) {
                throw new Exception('Provider is not supported', 501);
            }

            $oauth = new $classname($appId, $appSecret, $callback);

            if (!empty($state)) {
                try {
                    $state = array_merge($defaultState, $oauth->parseState($state));
                } catch (\Exception $exception) {
                    throw new Exception('Failed to parse login state params as passed from OAuth provider');
                }
            } else {
                $state = $defaultState;
            }

            if (!$validateURL->isValid($state['success'])) {
                throw new Exception('Invalid redirect URL for success login', 400);
            }

            if (!empty($state['failure']) && !$validateURL->isValid($state['failure'])) {
                throw new Exception('Invalid redirect URL for failure login', 400);
            }
            $state['failure'] = null;
            $accessToken = $oauth->getAccessToken($code);

            if (empty($accessToken)) {
                if (!empty($state['failure'])) {
                    $response->redirect($state['failure'], 301, 0);
                }

                throw new Exception('Failed to obtain access token');
            }

            $oauthID = $oauth->getUserID($accessToken);
            
            if (empty($oauthID)) {
                if (!empty($state['failure'])) {
                    $response->redirect($state['failure'], 301, 0);
                }

                throw new Exception('Missing ID from OAuth provider', 400);
            }

            $current = Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_LOGIN, Auth::$secret);

            if ($current) {
                $projectDB->deleteDocument($current); //throw new Exception('User already logged in', 401);
            }

            $user = (empty($user->getUid())) ? $projectDB->getCollection([ // Get user by provider id
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    'oauth'.ucfirst($provider).'='.$oauthID,
                ],
            ]) : $user;

            if (empty($user)) { // No user logged in or with oauth provider ID, create new one or connect with account with same email
                $name = $oauth->getUserName($accessToken);
                $email = $oauth->getUserEmail($accessToken);

                $user = $projectDB->getCollection([ // Get user by provider email address
                    'limit' => 1,
                    'first' => true,
                    'filters' => [
                        '$collection='.Database::SYSTEM_COLLECTION_USERS,
                        'email='.$email,
                    ],
                ]);

                if (!$user || empty($user->getUid())) { // Last option -> create user alone, generate random password
                    Authorization::disable();

                    $user = $projectDB->createDocument([
                        '$collection' => Database::SYSTEM_COLLECTION_USERS,
                        '$permissions' => ['read' => ['*'], 'write' => ['user:{self}']],
                        'email' => $email,
                        'status' => Auth::USER_STATUS_ACTIVATED, // Email should already be authenticated by OAuth provider
                        'password' => Auth::passwordHash(Auth::passwordGenerator()),
                        'password-update' => time(),
                        'registration' => time(),
                        'confirm' => true,
                        'reset' => false,
                        'name' => $name,
                    ]);

                    Authorization::enable();

                    if (false === $user) {
                        throw new Exception('Failed saving user to DB', 500);
                    }
                }
            }

            // Create session token, verify user account and update OAuth ID and Access Token

            $secret = Auth::tokenGenerator();
            $expiry = time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
            $session = new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:'.$user['$uid']], 'write' => ['user:'.$user['$uid']]],
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]);

            $user
                ->setAttribute('oauth'.ucfirst($provider), $oauthID)
                ->setAttribute('oauth'.ucfirst($provider).'AccessToken', $accessToken)
                ->setAttribute('status', Auth::USER_STATUS_ACTIVATED)
                ->setAttribute('tokens', $session, Document::SET_TYPE_APPEND)
            ;

            Authorization::setRole('user:'.$user->getUid());

            $user = $projectDB->updateDocument($user->getArrayCopy());

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit
                ->setParam('userId', $user->getUid())
                ->setParam('event', 'account.sessions.create')
                ->setParam('resource', 'users/'.$user->getUid())
                ->setParam('data', ['provider' => $provider])
            ;

            $response
                ->addCookie(Auth::$cookieName.'_legacy', Auth::encodeSession($user->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null)
                ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, COOKIE_SAMESITE)
                ->redirect($state['success'])
            ;
        }
    );

$utopia->patch('/v1/account/name')
    ->desc('Update Account Name')
    ->label('webhook', 'account.update.name')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateAccountName')
    ->label('sdk.description', '/docs/references/account/update-name.md')
    ->param('name', '', function () { return new Text(100); }, 'User name')
    ->action(
        function ($name) use ($response, $user, $projectDB, $audit, $oauthKeys) {
            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'name' => $name,
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit
                ->setParam('event', 'account.update.name')
                ->setParam('resource', 'users/'.$user->getUid())
            ;

            $response->json(array_merge($user->getArrayCopy(array_merge(
                [
                    '$uid',
                    'email',
                    'registration',
                    'name',
                ],
                $oauthKeys
            )), ['roles' => Authorization::getRoles()]));
        }
    );

$utopia->patch('/v1/account/password')
    ->desc('Update Account Password')
    ->label('webhook', 'account.update.password')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateAccountPassword')
    ->label('sdk.description', '/docs/references/account/update-password.md')
    ->param('password', '', function () { return new Password(); }, 'New password')
    ->param('old-password', '', function () { return new Password(); }, 'Old password')
    ->action(
        function ($password, $oldPassword) use ($response, $user, $projectDB, $audit, $oauthKeys) {
            if (!Auth::passwordVerify($oldPassword, $user->getAttribute('password'))) { // Double check user password
                throw new Exception('Invalid credentials', 401);
            }

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'password' => Auth::passwordHash($password),
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit
                ->setParam('event', 'account.update.password')
                ->setParam('resource', 'users/'.$user->getUid())
            ;

            $response->json(array_merge($user->getArrayCopy(array_merge(
                [
                    '$uid',
                    'email',
                    'registration',
                    'name',
                ],
                $oauthKeys
            )), ['roles' => Authorization::getRoles()]));
        }
    );

$utopia->patch('/v1/account/email')
    ->desc('Update Account Email')
    ->label('webhook', 'account.update.email')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/account/update-email.md')
    ->param('email', '', function () { return new Email(); }, 'Email address')
    ->param('password', '', function () { return new Password(); }, 'User password')
    ->action(
        function ($email, $password) use ($response, $user, $projectDB, $audit, $oauthKeys) {
            if (!Auth::passwordVerify($password, $user->getAttribute('password'))) { // Double check user password
                throw new Exception('Invalid credentials', 401);
            }

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

            // TODO after this user needs to confirm mail again

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'email' => $email,
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit
                ->setParam('event', 'account.update.email')
                ->setParam('resource', 'users/'.$user->getUid())
            ;

            $response->json(array_merge($user->getArrayCopy(array_merge(
                [
                    '$uid',
                    'email',
                    'registration',
                    'name',
                ],
                $oauthKeys
            )), ['roles' => Authorization::getRoles()]));
        }
    );

$utopia->patch('/v1/account/prefs')
    ->desc('Update Account Preferences')
    ->label('webhook', 'account.update.prefs')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePrefs')
    ->param('prefs', '', function () { return new Assoc();}, 'Prefs key-value JSON object.')
    ->label('sdk.description', '/docs/references/account/update-prefs.md')
    ->action(
        function ($prefs) use ($response, $user, $projectDB, $audit) {
            $old = json_decode($user->getAttribute('prefs', '{}'), true);
            $old = ($old) ? $old : [];

            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'prefs' => json_encode(array_merge($old, $prefs)),
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit
                ->setParam('event', 'account.update.prefs')
                ->setParam('resource', 'users/'.$user->getUid())
            ;

            $prefs = $user->getAttribute('prefs', '{}');

            try {
                $prefs = json_decode($prefs, true);
                $prefs = ($prefs) ? $prefs : [];
            } catch (\Exception $error) {
                throw new Exception('Failed to parse prefs', 500);
            }

            $response->json($prefs);
        }
    );

$utopia->delete('/v1/account')
    ->desc('Delete Account')
    ->label('webhook', 'account.delete')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/account/delete.md')
    ->action(
        function () use ($response, $request, $user, $projectDB, $audit, $webhook) {
            $user = $projectDB->updateDocument(array_merge($user->getArrayCopy(), [
                'status' => Auth::USER_STATUS_BLOCKED,
            ]));

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            //TODO delete all tokens or only current session?
            //TODO delete all user data according to GDPR. Make sure everything is backed up and backups are deleted later
            /*
             * Data to delete
             * * Tokens
             * * Memberships
             */

            $audit
                ->setParam('event', 'account.delete')
                ->setParam('resource', 'users/'.$user->getUid())
                ->setParam('data', $user->getArrayCopy())
            ;

            $webhook
                ->setParam('payload', [
                    'name' => $user->getAttribute('name', ''),
                    'email' => $user->getAttribute('email', ''),
                ])
            ;

            $response
                ->addCookie(Auth::$cookieName.'_legacy', '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null)
                ->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, COOKIE_SAMESITE)
                ->noContent()
            ;
        }
    );

$utopia->delete('/v1/account/sessions')
    ->desc('Delete All Account Sessions')
    ->label('scope', 'account')
    ->label('webhook', 'account.sessions.delete')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteAccountSessions')
    ->label('sdk.description', '/docs/references/account/delete-sessions.md')
    ->label('abuse-limit', 100)
    ->action(
        function () use ($response, $request, $user, $projectDB, $audit, $webhook) {
            $tokens = $user->getAttribute('tokens', []);

            foreach ($tokens as $token) { /* @var $token Document */
                if (!$projectDB->deleteDocument($token->getUid())) {
                    throw new Exception('Failed to remove token from DB', 500);
                }

                $audit
                    ->setParam('event', 'account.sessions.delete')
                    ->setParam('resource', '/user/'.$user->getUid())
                ;

                $webhook
                    ->setParam('payload', [
                        'name' => $user->getAttribute('name', ''),
                        'email' => $user->getAttribute('email', ''),
                    ])
                ;

                if ($token->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $response
                        ->addCookie(Auth::$cookieName.'_legacy', '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null)
                        ->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, COOKIE_SAMESITE)
                    ;
                }
            }

            $response->noContent();
        }
    );

$utopia->delete('/v1/account/sessions/:id')
    ->desc('Delete Account Session')
    ->label('scope', 'account')
    ->label('webhook', 'account.sessions.delete')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteAccountSession')
    ->label('sdk.description', '/docs/references/account/delete-session.md')
    ->label('abuse-limit', 100)
    ->param('id', null, function () { return new UID(); }, 'Session unique ID.')
    ->action(
        function ($id) use ($response, $request, $user, $projectDB, $webhook, $audit) {
            $tokens = $user->getAttribute('tokens', []);

            foreach ($tokens as $token) { /* @var $token Document */
                if (($id == $token->getUid()) && Auth::TOKEN_TYPE_LOGIN == $token->getAttribute('type')) {
                    if (!$projectDB->deleteDocument($token->getUid())) {
                        throw new Exception('Failed to remove token from DB', 500);
                    }

                    $audit
                        ->setParam('event', 'account.sessions.delete')
                        ->setParam('resource', '/user/'.$user->getUid())
                    ;

                    $webhook
                        ->setParam('payload', [
                            'name' => $user->getAttribute('name', ''),
                            'email' => $user->getAttribute('email', ''),
                        ])
                    ;

                    if ($token->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                        $response
                            ->addCookie(Auth::$cookieName.'_legacy', '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null)
                            ->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, COOKIE_SAMESITE)
                        ;
                    }
                }
            }

            $response->noContent();
        }
    );

$utopia->delete('/v1/account/sessions/current')
    ->desc('Delete Current Account Session')
    ->label('webhook', 'account.sessions.delete')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteAccountCurrentSession')
    ->label('sdk.description', '/docs/references/account/delete-session-current.md')
    ->label('abuse-limit', 100)
    ->action(
        function () use ($response, $request, $user, $projectDB, $audit, $webhook) {
            $token = Auth::tokenVerify($user->getAttribute('tokens'), Auth::TOKEN_TYPE_LOGIN, Auth::$secret);

            if (!$projectDB->deleteDocument($token)) {
                throw new Exception('Failed to remove token from DB', 500);
            }

            $webhook
                ->setParam('payload', [
                    'name' => $user->getAttribute('name', ''),
                    'email' => $user->getAttribute('email', ''),
                ])
            ;

            $audit->setParam('event', 'account.sessions.delete');

            $response
                ->addCookie(Auth::$cookieName.'_legacy', '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null)
                ->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, COOKIE_SAMESITE)
                ->noContent()
            ;
        }
    );

$utopia->post('/v1/account/recovery')
    ->desc('Password Recovery')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createAccountRecovery')
    ->label('sdk.description', '/docs/references/account/create-recovery.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', function () { return new Email(); }, 'User account email address.')
    ->param('url', '', function () use ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the recovery email.')
    ->action(
        function ($email, $url) use ($request, $response, $projectDB, $register, $audit, $project) {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    'email='.$email,
                ],
            ]);

            if (empty($profile)) {
                throw new Exception('User not found', 404); // TODO maybe hide this
            }

            $secret = Auth::tokenGenerator();
            $recovery = new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:'.$profile->getUid()], 'write' => ['user:'.$profile->getUid()]],
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                'expire' => time() + Auth::TOKEN_EXPIRATION_RECOVERY,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]);
                
            Authorization::setRole('user:'.$profile->getUid());

            $recovery = $projectDB->createDocument($recovery->getArrayCopy());

            if (false === $recovery) {
                throw new Exception('Failed saving recovery to DB', 500);
            }

            $profile->setAttribute('tokens', $recovery, Document::SET_TYPE_APPEND);

            $profile = $projectDB->updateDocument($profile->getArrayCopy());

            if (false === $profile) {
                throw new Exception('Failed to save user to DB', 500);
            }

            $url = Template::parseURL($url);
            $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $profile->getUid(), 'secret' => $secret]);
            $url = Template::unParseURL($url);

            $body = new Template(__DIR__.'/../../config/locales/templates/'.Locale::getText('auth.emails.recovery.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{name}}', $profile->getAttribute('name'))
                ->setParam('{{redirect}}', $url)
            ;

            $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */

            $mail->addAddress($profile->getAttribute('email', ''), $profile->getAttribute('name', ''));

            $mail->Subject = Locale::getText('auth.emails.recovery.title');
            $mail->Body = $body->render();
            $mail->AltBody = strip_tags($body->render());

            try {
                $mail->send();
            } catch (\Exception $error) {
                throw new Exception('Error sending mail: ' . $error->getMessage(), 500);
            }

            $audit
                ->setParam('userId', $profile->getUid())
                ->setParam('event', 'account.recovery.create')
                ->setParam('resource', 'users/'.$profile->getUid())
            ;

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($recovery->getArrayCopy(['$uid', 'type', 'expire']))
            ;
        }
    );

$utopia->put('/v1/account/recovery')
    ->desc('Password Reset')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateAccountRecovery')
    ->label('sdk.description', '/docs/references/account/update-recovery.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', function () { return new UID(); }, 'User account UID address.')
    ->param('secret', '', function () { return new Text(256); }, 'Valid reset token.')
    ->param('password-a', '', function () { return new Password(); }, 'New password.')
    ->param('password-b', '', function () {return new Password(); }, 'New password again.')
    ->action(
        function ($userId, $secret, $passwordA, $passwordB) use ($response, $projectDB, $audit) {
            if ($passwordA !== $passwordB) {
                throw new Exception('Passwords must match', 400);
            }

            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    '$uid='.$userId,
                ],
            ]);

            if (empty($profile)) {
                throw new Exception('User not found', 404); // TODO maybe hide this
            }

            $recovery = Auth::tokenVerify($profile->getAttribute('tokens', []), Auth::TOKEN_TYPE_RECOVERY, $secret);

            if (!$recovery) {
                throw new Exception('Invalid recovery token', 401);
            }

            Authorization::setRole('user:'.$profile->getUid());

            $profile = $projectDB->updateDocument(array_merge($profile->getArrayCopy(), [
                'password' => Auth::passwordHash($passwordA),
                'password-update' => time(),
                'confirm' => true,
            ]));

            if (false === $profile) {
                throw new Exception('Failed saving user to DB', 500);
            }

            /**
             * We act like we're updating and validating
             *  the recovery token but actually we don't need it anymore.
             */
            if (!$projectDB->deleteDocument($recovery)) {
                throw new Exception('Failed to remove recovery from DB', 500);
            }

            $audit
                ->setParam('userId', $profile->getUid())
                ->setParam('event', 'account.recovery.update')
                ->setParam('resource', 'users/'.$profile->getUid())
            ;

            $recovery = $profile->search('$uid', $recovery, $profile->getAttribute('tokens', []));

            $response->json($recovery->getArrayCopy(['$uid', 'type', 'expire']));
        }
    );

    $utopia->post('/v1/account/verification')
    ->desc('Create Verification')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createAccountVerification')
    ->label('sdk.description', '/docs/references/account/create-verification.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('url', '', function () use ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the verification email.') // TODO add our own built-in confirm page
    ->action(
        function ($url) use ($request, $response, $register, $user, $project, $projectDB, $audit) {
            $verificationSecret = Auth::tokenGenerator();
            
            $verification = new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:'.$user->getUid()], 'write' => ['user:'.$user->getUid()]],
                'type' => Auth::TOKEN_TYPE_VERIFICATION,
                'secret' => Auth::hash($verificationSecret), // On way hash encryption to protect DB leak
                'expire' => time() + Auth::TOKEN_EXPIRATION_CONFIRM,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]);
                
            Authorization::setRole('user:'.$user->getUid());

            $verification = $projectDB->createDocument($verification->getArrayCopy());

            if (false === $verification) {
                throw new Exception('Failed saving verification to DB', 500);
            }

            $user->setAttribute('tokens', $verification, Document::SET_TYPE_APPEND);

            $user = $projectDB->updateDocument($user->getArrayCopy());

            if (false === $user) {
                throw new Exception('Failed to save user to DB', 500);
            }
            
            $url = Template::parseURL($url);
            $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getUid(), 'secret' => $verificationSecret]);
            $url = Template::unParseURL($url);

            $body = new Template(__DIR__.'/../../config/locales/templates/'.Locale::getText('auth.emails.verification.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{name}}', $user->getAttribute('name'))
                ->setParam('{{redirect}}', $url)
            ;

            $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */

            $mail->addAddress($user->getAttribute('email'), $user->getAttribute('name'));

            $mail->Subject = Locale::getText('auth.emails.verification.title');
            $mail->Body = $body->render();
            $mail->AltBody = strip_tags($body->render());

            try {
                $mail->send();
            } catch (\Exception $error) {
                throw new Exception('Problem sending mail: ' . $error->getMessage(), 500);
            }

            $audit
                ->setParam('userId', $user->getUid())
                ->setParam('event', 'account.verification.create')
                ->setParam('resource', 'users/'.$user->getUid())
            ;

            $response
                ->setStatusCode(Response::STATUS_CODE_CREATED)
                ->json($verification->getArrayCopy(['$uid', 'type', 'expire']))
            ;
        }
    );

$utopia->put('/v1/account/verification')
    ->desc('Updated Verification')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateAccountVerification')
    ->label('sdk.description', '/docs/references/account/update-verification.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', function () { return new UID(); }, 'User account UID address.')
    ->param('secret', '', function () { return new Text(256); }, 'Valid reset token.')    ->param('password-b', '', function () {return new Password(); }, 'New password again.')
    ->action(
        function ($userId, $secret) use ($response, $user, $projectDB, $audit) {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    '$uid='.$userId,
                ],
            ]);

            if (empty($profile)) {
                throw new Exception('User not found', 404); // TODO maybe hide this
            }

            $verification = Auth::tokenVerify($profile->getAttribute('tokens', []), Auth::TOKEN_TYPE_VERIFICATION, $secret);

            if (!$verification) {
                throw new Exception('Invalid verification token', 401);
            }

            Authorization::setRole('user:'.$profile->getUid());

            $profile = $projectDB->updateDocument(array_merge($profile->getArrayCopy(), [
                'confirm' => true,
            ]));

            if (false === $profile) {
                throw new Exception('Failed saving user to DB', 500);
            }

            /**
             * We act like we're updating and validating
             *  the verification token but actually we don't need it anymore.
             */
            if (!$projectDB->deleteDocument($verification)) {
                throw new Exception('Failed to remove verification from DB', 500);
            }

            $audit
                ->setParam('userId', $profile->getUid())
                ->setParam('event', 'account.verification.update')
                ->setParam('resource', 'users/'.$user->getUid())
            ;

            $verification = $profile->search('$uid', $verification, $profile->getAttribute('tokens', []));

            $response->json($verification->getArrayCopy(['$uid', 'type', 'expire']));
        }
    );