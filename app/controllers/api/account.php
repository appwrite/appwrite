<?php

use Ahc\Jwt\JWT;
use Utopia\App;
use Utopia\Exception;
use Utopia\Config\Config;
use Utopia\Validator\Assoc;
use Utopia\Validator\Text;
use Utopia\Validator\Email;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Host;
use Utopia\Validator\URL;
use Utopia\Audit\Audit;
use Utopia\Audit\Adapters\MySQL as AuditAdapter;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Validator\Password;
use Appwrite\Database\Database;
use Appwrite\Database\Document;
use Appwrite\Database\Exception\Duplicate;
use Appwrite\Database\Validator\UID;
use Appwrite\Database\Validator\Authorization;
use Appwrite\Detector\Detector;
use Appwrite\Template\Template;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\URL\URL as URLParser;
use Appwrite\Utopia\Response;
use Utopia\Validator\ArrayList;

$oauthDefaultSuccess = App::getEnv('_APP_HOME').'/auth/oauth2/success';
$oauthDefaultFailure = App::getEnv('_APP_HOME').'/auth/oauth2/failure';

App::post('/v1/account')
    ->desc('Create Account')
    ->groups(['api', 'account'])
    ->label('event', 'account.create')
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/account/create.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('abuse-limit', 10)
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be between 6 to 32 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($email, $password, $name, $request, $response, $project, $projectDB, $audits) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        if ('console' === $project->getId()) {
            $whitlistEmails = $project->getAttribute('authWhitelistEmails');
            $whitlistIPs = $project->getAttribute('authWhitelistIPs');
            $whitlistDomains = $project->getAttribute('authWhitelistDomains');

            if (!empty($whitlistEmails) && !\in_array($email, $whitlistEmails)) {
                throw new Exception('Console registration is restricted to specific emails. Contact your administrator for more information.', 401);
            }

            if (!empty($whitlistIPs) && !\in_array($request->getIP(), $whitlistIPs)) {
                throw new Exception('Console registration is restricted to specific IPs. Contact your administrator for more information.', 401);
            }

            if (!empty($whitlistDomains) && !\in_array(\substr(\strrchr($email, '@'), 1), $whitlistDomains)) {
                throw new Exception('Console registration is restricted to specific domains. Contact your administrator for more information.', 401);
            }
        }

        $profile = $projectDB->getCollectionFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                'email='.$email,
            ],
        ]);

        if (!empty($profile)) {
            throw new Exception('Account already exists', 409);
        }

        Authorization::disable();

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
                'passwordUpdate' => \time(),
                'registration' => \time(),
                'reset' => false,
                'name' => $name,
            ], ['email' => $email]);
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409);
        }

        Authorization::reset();

        Authorization::unsetRole('role:'.Auth::USER_ROLE_GUEST);
        Authorization::setRole('user:'.$user->getId());
        Authorization::setRole('role:'.Auth::USER_ROLE_MEMBER);

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.create')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_USER)
        ;
    });

App::post('/v1/account/sessions')
    ->desc('Create Account Session')
    ->groups(['api', 'account'])
    ->label('event', 'account.sessions.create')
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createSession')
    ->label('sdk.description', '/docs/references/account/create-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be between 6 to 32 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('projectDB')
    ->inject('locale')
    ->inject('geodb')
    ->inject('audits')
    ->action(function ($email, $password, $request, $response, $projectDB, $locale, $geodb, $audits) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */

        $protocol = $request->getProtocol();
        $profile = $projectDB->getCollectionFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                'email='.$email,
            ],
        ]);

        if (false == $profile || !Auth::passwordVerify($password, $profile->getAttribute('password'))) {
            $audits
                //->setParam('userId', $profile->getId())
                ->setParam('event', 'account.sesssions.failed')
                ->setParam('resource', 'users/'.($profile ? $profile->getId() : ''))
            ;

            throw new Exception('Invalid credentials', 401); // Wrong password or username
        }

        if (Auth::USER_STATUS_BLOCKED == $profile->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401); // User is in status blocked
        }

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $secret = Auth::tokenGenerator();
        $session = new Document(array_merge(
            [
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:'.$profile->getId()], 'write' => ['user:'.$profile->getId()]],
                'userId' => $profile->getId(),
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ], $detector->getOS(), $detector->getClient(), $detector->getDevice()
        ));

        Authorization::setRole('user:'.$profile->getId());

        $session = $projectDB->createDocument($session->getArrayCopy());

        if (false === $session) {
            throw new Exception('Failed saving session to DB', 500);
        }

        $profile->setAttribute('tokens', $session, Document::SET_TYPE_APPEND);

        $profile = $projectDB->updateDocument($profile->getArrayCopy());

        if (false === $profile) {
            throw new Exception('Failed saving user to DB', 500);
        }
        
        $audits
            ->setParam('userId', $profile->getId())
            ->setParam('event', 'account.sessions.create')
            ->setParam('resource', 'users/'.$profile->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($profile->getId(), $secret)]))
            ;
        }
        
        $response
            ->addCookie(Auth::$cookieName.'_legacy', Auth::encodeSession($profile->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($profile->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countries = $locale->getText('countries');

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', (isset($countries[strtoupper($session->getAttribute('countryCode'))])) ? $countries[strtoupper($session->getAttribute('countryCode'))] : $locale->getText('locale.country.unknown'))
        ;
        
        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::get('/v1/account/sessions/oauth2/:provider')
    ->desc('Create Account Session with OAuth2')
    ->groups(['api', 'account'])
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createOAuth2Session')
    ->label('sdk.description', '/docs/references/account/create-session-oauth2.md')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 Provider. Currently, supported providers are: ' . \implode(', ', \array_keys(\array_filter(Config::getParam('providers'), function($node) {return (!$node['mock']);}))).'.')
    ->param('success', $oauthDefaultSuccess, function ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('failure', $oauthDefaultFailure, function ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('scopes', [], new ArrayList(new Text(128)), 'A list of custom OAuth2 scopes. Check each provider internal docs for a list of supported scopes.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->action(function ($provider, $success, $failure, $scopes, $request, $response, $project) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */

        $protocol = $request->getProtocol();
        $callback = $protocol.'://'.$request->getHostname().'/v1/account/sessions/oauth2/callback/'.$provider.'/'.$project->getId();
        $appId = $project->getAttribute('usersOauth2'.\ucfirst($provider).'Appid', '');
        $appSecret = $project->getAttribute('usersOauth2'.\ucfirst($provider).'Secret', '{}');

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = App::getEnv('_APP_OPENSSL_KEY_V'.$appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        if (empty($appId) || empty($appSecret)) {
            throw new Exception('This provider is disabled. Please configure the provider app ID and app secret key from your '.APP_NAME.' console to continue.', 412);
        }

        $classname = 'Appwrite\\Auth\\OAuth2\\'.\ucfirst($provider);

        if (!\class_exists($classname)) {
            throw new Exception('Provider is not supported', 501);
        }

        $oauth2 = new $classname($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure], $scopes);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    });

App::get('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 Callback')
    ->groups(['api', 'account'])
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project unique ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(1024), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->inject('request')
    ->inject('response')
    ->action(function ($projectId, $provider, $code, $state, $request, $response) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();
        
        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol.'://'.$domain.'/v1/account/sessions/oauth2/'.$provider.'/redirect?'
                .\http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
    });

App::post('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 Callback')
    ->groups(['api', 'account'])
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('origin', '*')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project unique ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(1024), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->inject('request')
    ->inject('response')
    ->action(function ($projectId, $provider, $code, $state, $request, $response) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();
        
        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol.'://'.$domain.'/v1/account/sessions/oauth2/'.$provider.'/redirect?'
                .\http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
    });

App::get('/v1/account/sessions/oauth2/:provider/redirect')
    ->desc('OAuth2 Redirect')
    ->groups(['api', 'account'])
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('event', 'account.sessions.create')
    ->label('scope', 'public')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->label('docs', false)
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(1024), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'OAuth2 state params.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('projectDB')
    ->inject('geodb')
    ->inject('audits')
    ->action(function ($provider, $code, $state, $request, $response, $project, $user, $projectDB, $geodb, $audits) use ($oauthDefaultSuccess) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */
        
        $protocol = $request->getProtocol();
        $callback = $protocol.'://'.$request->getHostname().'/v1/account/sessions/oauth2/callback/'.$provider.'/'.$project->getId();
        $defaultState = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
        $validateURL = new URL();

        $appId = $project->getAttribute('usersOauth2'.\ucfirst($provider).'Appid', '');
        $appSecret = $project->getAttribute('usersOauth2'.\ucfirst($provider).'Secret', '{}');

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = App::getEnv('_APP_OPENSSL_KEY_V'.$appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        $classname = 'Appwrite\\Auth\\OAuth2\\'.\ucfirst($provider);

        if (!\class_exists($classname)) {
            throw new Exception('Provider is not supported', 501);
        }

        $oauth2 = new $classname($appId, $appSecret, $callback);

        if (!empty($state)) {
            try {
                $state = \array_merge($defaultState, $oauth2->parseState($state));
            } catch (\Exception $exception) {
                throw new Exception('Failed to parse login state params as passed from OAuth2 provider');
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
        $accessToken = $oauth2->getAccessToken($code);

        if (empty($accessToken)) {
            if (!empty($state['failure'])) {
                $response->redirect($state['failure'], 301, 0);
            }

            throw new Exception('Failed to obtain access token');
        }

        $oauth2ID = $oauth2->getUserID($accessToken);
        
        if (empty($oauth2ID)) {
            if (!empty($state['failure'])) {
                $response->redirect($state['failure'], 301, 0);
            }

            throw new Exception('Missing ID from OAuth2 provider', 400);
        }

        $current = Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_LOGIN, Auth::$secret);

        if ($current) {
            $projectDB->deleteDocument($current); //throw new Exception('User already logged in', 401);
        }

        $user = (empty($user->getId())) ? $projectDB->getCollectionFirst([ // Get user by provider id
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                'oauth2'.\ucfirst($provider).'='.$oauth2ID,
            ],
        ]) : $user;

        if (empty($user)) { // No user logged in or with OAuth2 provider ID, create new one or connect with account with same email
            $name = $oauth2->getUserName($accessToken);
            $email = $oauth2->getUserEmail($accessToken);

            $user = $projectDB->getCollectionFirst([ // Get user by provider email address
                'limit' => 1,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    'email='.$email,
                ],
            ]);

            if (!$user || empty($user->getId())) { // Last option -> create user alone, generate random password
                Authorization::disable();

                try {
                    $user = $projectDB->createDocument([
                        '$collection' => Database::SYSTEM_COLLECTION_USERS,
                        '$permissions' => ['read' => ['*'], 'write' => ['user:{self}']],
                        'email' => $email,
                        'emailVerification' => true,
                        'status' => Auth::USER_STATUS_ACTIVATED, // Email should already be authenticated by OAuth2 provider
                        'password' => Auth::passwordHash(Auth::passwordGenerator()),
                        'passwordUpdate' => \time(),
                        'registration' => \time(),
                        'reset' => false,
                        'name' => $name,
                    ], ['email' => $email]);
                } catch (Duplicate $th) {
                    throw new Exception('Account already exists', 409);
                }

                Authorization::reset();

                if (false === $user) {
                    throw new Exception('Failed saving user to DB', 500);
                }
            }
        }

        if (Auth::USER_STATUS_BLOCKED == $user->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401); // User is in status blocked
        }

        // Create session token, verify user account and update OAuth2 ID and Access Token

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge([
            '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
            '$permissions' => ['read' => ['user:'.$user['$id']], 'write' => ['user:'.$user['$id']]],
            'userId' => $user->getId(),
            'type' => Auth::TOKEN_TYPE_LOGIN,
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expiry,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
            'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
        ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

        $isAnonymousUser = is_null($user->getAttribute('email')) && is_null($user->getAttribute('password'));

        if ($isAnonymousUser) {
            $user
                ->setAttribute('name', $oauth2->getUserName($accessToken))
                ->setAttribute('email', $oauth2->getUserEmail($accessToken))
            ;
        }

        $user
            ->setAttribute('oauth2'.\ucfirst($provider), $oauth2ID)
            ->setAttribute('oauth2'.\ucfirst($provider).'AccessToken', $accessToken)
            ->setAttribute('status', Auth::USER_STATUS_ACTIVATED)
            ->setAttribute('tokens', $session, Document::SET_TYPE_APPEND)
        ;

        Authorization::setRole('user:'.$user->getId());

        $user = $projectDB->updateDocument($user->getArrayCopy());

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.sessions.create')
            ->setParam('resource', 'users/'.$user->getId())
            ->setParam('data', ['provider' => $provider])
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }
        
        // Add keys for non-web platforms - TODO - add verification phase to aviod session sniffing
        if (parse_url($state['success'], PHP_URL_PATH) === parse_url($oauthDefaultSuccess, PHP_URL_PATH)) {
            $state['success'] = URLParser::parse($state['success']);
            $query = URLParser::parseQuery($state['success']['query']);
            $query['project'] = $project->getId();
            $query['domain'] = Config::getParam('cookieDomain');
            $query['key'] = Auth::$cookieName;
            $query['secret'] = Auth::encodeSession($user->getId(), $secret);
            $state['success']['query'] = URLParser::unparseQuery($query);
            $state['success'] = URLParser::unparse($state['success']);
        }

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->addCookie(Auth::$cookieName.'_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->redirect($state['success'])
        ;
    });

App::post('/v1/account/sessions/anonymous')
    ->desc('Create Anonymous Session')
    ->groups(['api', 'account'])
    ->label('event', 'account.sessions.create')
    ->label('scope', 'public')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createAnonymousSession')
    ->label('sdk.description', '/docs/references/account/create-session-anonymous.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->inject('request')
    ->inject('response')
    ->inject('locale')
    ->inject('user')
    ->inject('project')
    ->inject('projectDB')
    ->inject('geodb')
    ->inject('audits')
    ->action(function ($request, $response, $locale, $user, $project, $projectDB, $geodb, $audits) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var MaxMind\Db\Reader $geodb */
        /** @var Appwrite\Event\Event $audits */

        $protocol = $request->getProtocol();

        if(App::getEnv('_APP_LOGIN_ANONYMOUS', 'enabled') !== 'enabled') {
            throw new Exception('Anonymous login is disabled.', 412);
        }

        if ($user->getId() || 'console' === $project->getId()) {
            throw new Exception('Failed to create anonymous user.', 401);
        }

        Authorization::disable();
        try {
            $user = $projectDB->createDocument([
                '$collection' => Database::SYSTEM_COLLECTION_USERS,
                '$permissions' => [
                    'read' => ['*'], 
                    'write' => ['user:{self}']
                ],
                'email' => null,
                'emailVerification' => false,
                'status' => Auth::USER_STATUS_UNACTIVATED,
                'password' => null,
                'passwordUpdate' => \time(),
                'registration' => \time(),
                'reset' => false,
                'name' => null
            ]);
        } catch (Exception $th) {
            throw new Exception('Failed saving user to DB', 500);
        }

        Authorization::reset();

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        // Create session token

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge(
            [
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:' . $user['$id']], 'write' => ['user:' . $user['$id']]],
                'userId' => $user->getId(),
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $user->setAttribute('tokens', $session, Document::SET_TYPE_APPEND);

        Authorization::setRole('user:'.$user->getId());

        $user = $projectDB->updateDocument($user->getArrayCopy());

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.sessions.create')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName.'_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', (isset($countries[$session->getAttribute('countryCode')])) ? $countries[$session->getAttribute('countryCode')] : $locale->getText('locale.country.unknown'))
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/jwt')
    ->desc('Create Account JWT')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('docs', false) // Hidden for now - private beta
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createJWT')
    ->label('sdk.description', '/docs/references/account/create-jwt.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->inject('response')
    ->inject('user')
    ->action(function ($response, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
            
        $tokens = $user->getAttribute('tokens', []);
        $session = new Document();

        foreach ($tokens as $token) { /** @var Appwrite\Database\Document $token */
            if ($token->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                $session = $token;
            }
        }

        if($session->isEmpty()) {
            throw new Exception('No valid session found', 401);
        }
        
        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(['jwt' => $jwt->encode([
                // 'uid'    => 1,
                // 'aud'    => 'http://site.com',
                // 'scopes' => ['user'],
                // 'iss'    => 'http://api.mysite.com',
                'userId' => $user->getId(),
                'sessionId' => $session->getId(),
            ])]), Response::MODEL_JWT);
    });

App::get('/v1/account')
    ->desc('Get Account')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/account/get.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->inject('response')
    ->inject('user')
    ->action(function ($response, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */

        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/account/prefs')
    ->desc('Get Account Preferences')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/account/get-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ANY)
    ->inject('response')
    ->inject('user')
    ->action(function ($response, $user) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */

        $prefs = $user->getAttribute('prefs', new \stdClass());

        $response->dynamic(new Document($prefs), Response::MODEL_ANY);
    });

App::get('/v1/account/sessions')
    ->desc('Get Account Sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSessions')
    ->label('sdk.description', '/docs/references/account/get-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION_LIST)
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->action(function ($response, $user, $locale) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Utopia\Locale\Locale $locale */

        $tokens = $user->getAttribute('tokens', []);
        $sessions = [];
        $countries = $locale->getText('countries');
        $current = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_LOGIN, Auth::$secret);

        foreach ($tokens as $token) { /* @var $token Document */
            if (Auth::TOKEN_TYPE_LOGIN != $token->getAttribute('type')) {
                continue;
            }

            $token->setAttribute('countryName', (isset($countries[strtoupper($token->getAttribute('countryCode'))]))
                ? $countries[strtoupper($token->getAttribute('countryCode'))]
                : $locale->getText('locale.country.unknown'));
            $token->setAttribute('current', ($current == $token->getId()) ? true : false);

            $sessions[] = $token;
        }

        $response->dynamic(new Document([
            'sum' => count($sessions),
            'sessions' => $sessions
        ]), Response::MODEL_SESSION_LIST);
    });

App::get('/v1/account/logs')
    ->desc('Get Account Logs')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getLogs')
    ->label('sdk.description', '/docs/references/account/get-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->inject('response')
    ->inject('register')
    ->inject('project')
    ->inject('user')
    ->inject('locale')
    ->inject('geodb')
    ->action(function ($response, $register, $project, $user, $locale, $geodb) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Document $user */
        /** @var Utopia\Locale\Locale $locale */
        /** @var MaxMind\Db\Reader $geodb */

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

            $detector = new Detector($log['userAgent']);

            $output[$i] = new Document(array_merge([
                'event' => $log['event'],
                'ip' => $log['ip'],
                'time' => \strtotime($log['time']),
            ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

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

App::patch('/v1/account/name')
    ->desc('Update Account Name')
    ->groups(['api', 'account'])
    ->label('event', 'account.update.name')
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/account/update-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($name, $response, $user, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
            'name' => $name,
        ]));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.update.name')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/password')
    ->desc('Update Account Password')
    ->groups(['api', 'account'])
    ->label('event', 'account.update.password')
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/account/update-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('password', '', new Password(), 'New user password. Must be between 6 to 32 chars.')
    ->param('oldPassword', '', new Password(), 'Old user password. Must be between 6 to 32 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($password, $oldPassword, $response, $user, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        if (!Auth::passwordVerify($oldPassword, $user->getAttribute('password'))) { // Double check user password
            throw new Exception('Invalid credentials', 401);
        }

        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
            'password' => Auth::passwordHash($password),
        ]));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.update.password')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/email')
    ->desc('Update Account Email')
    ->groups(['api', 'account'])
    ->label('event', 'account.update.email')
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/account/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be between 6 to 32 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($email, $password, $response, $user, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $isAnonymousUser = is_null($user->getAttribute('email')) && is_null($user->getAttribute('password')); // Check if request is from an anonymous account for converting

        if (
            !$isAnonymousUser &&
            !Auth::passwordVerify($password, $user->getAttribute('password'))
        ) { // Double check user password
            throw new Exception('Invalid credentials', 401);
        }

        $profile = $projectDB->getCollectionFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                'email='.$email,
            ],
        ]);

        if (!empty($profile)) {
            throw new Exception('User already registered', 400);
        }

        // TODO after this user needs to confirm mail again

        $user = $projectDB->updateDocument(\array_merge(
            $user->getArrayCopy(),
            ($isAnonymousUser ? [ 'password' => Auth::passwordHash($password) ] : []),
            [
                'email' => $email,
                'emailVerification' => false,
            ]
        ));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }
        
        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.update.email')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/prefs')
    ->desc('Update Account Preferences')
    ->groups(['api', 'account'])
    ->label('event', 'account.update.prefs')
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/account/update-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_ANY)
    ->param('prefs', [], new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($prefs, $response, $user, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
        
        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
            'prefs' => $prefs,
        ]));

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500);
        }

        $audits
            ->setParam('event', 'account.update.prefs')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        $response->dynamic($user, Response::MODEL_USER);
    });

App::delete('/v1/account')
    ->desc('Delete Account')
    ->groups(['api', 'account'])
    ->label('event', 'account.delete')
    ->label('scope', 'account')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/account/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->inject('events')
    ->action(function ($request, $response, $user, $projectDB, $audits, $events) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */

        $protocol = $request->getProtocol();
        $user = $projectDB->updateDocument(\array_merge($user->getArrayCopy(), [
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

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.delete')
            ->setParam('resource', 'users/'.$user->getId())
            ->setParam('data', $user->getArrayCopy())
        ;

        $events
            ->setParam('payload', $response->output($user, Response::MODEL_USER))
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName.'_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->noContent()
        ;
    });

App::delete('/v1/account/sessions/:sessionId')
    ->desc('Delete Account Session')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'account.sessions.delete')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/account/delete-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->param('sessionId', null, new UID(), 'Session unique ID. Use the string \'current\' to delete the current device session.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->inject('events')
    ->action(function ($sessionId, $request, $response, $user, $projectDB, $audits, $events) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */

        $protocol = $request->getProtocol();
        $sessionId = ($sessionId === 'current')
            ? Auth::tokenVerify($user->getAttribute('tokens'), Auth::TOKEN_TYPE_LOGIN, Auth::$secret)
            : $sessionId;
                
        $tokens = $user->getAttribute('tokens', []);

        foreach ($tokens as $token) { /* @var $token Document */
            if (($sessionId == $token->getId()) && Auth::TOKEN_TYPE_LOGIN == $token->getAttribute('type')) {
                if (!$projectDB->deleteDocument($token->getId())) {
                    throw new Exception('Failed to remove token from DB', 500);
                }

                $audits
                    ->setParam('userId', $user->getId())
                    ->setParam('event', 'account.sessions.delete')
                    ->setParam('resource', '/user/'.$user->getId())
                ;

                if (!Config::getParam('domainVerification')) {
                    $response
                        ->addHeader('X-Fallback-Cookies', \json_encode([]))
                    ;
                }
                
                $token->setAttribute('current', false);

                if ($token->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $token->setAttribute('current', true);

                    $response
                        ->addCookie(Auth::$cookieName.'_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                        ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
                    ;
                }

                $events
                    ->setParam('payload', $response->output($token, Response::MODEL_SESSION))
                ;

                return $response->noContent();
            }
        }

        throw new Exception('Session not found', 404);
    });

App::delete('/v1/account/sessions')
    ->desc('Delete All Account Sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'account.sessions.delete')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/account/delete-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->inject('events')
    ->action(function ($request, $response, $user, $projectDB, $audits, $events) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */

        $protocol = $request->getProtocol();
        $tokens = $user->getAttribute('tokens', []);

        foreach ($tokens as $token) { /* @var $token Document */
            if (!$projectDB->deleteDocument($token->getId())) {
                throw new Exception('Failed to remove token from DB', 500);
            }

            $audits
                ->setParam('userId', $user->getId())
                ->setParam('event', 'account.sessions.delete')
                ->setParam('resource', '/user/'.$user->getId())
            ;

            if (!Config::getParam('domainVerification')) {
                $response
                    ->addHeader('X-Fallback-Cookies', \json_encode([]))
                ;
            }

            $token->setAttribute('current', false);

            if ($token->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                $token->setAttribute('current', true);
                $response
                    ->addCookie(Auth::$cookieName.'_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                    ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
                ;
            }
        }
                    
        $events
            ->setParam('payload', $response->output(new Document([
                'sum' => count($tokens),
                'sessions' => $tokens
            ]), Response::MODEL_SESSION_LIST))
        ;

        $response->noContent();
    });

App::post('/v1/account/recovery')
    ->desc('Create Password Recovery')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'account.recovery.create')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createRecovery')
    ->label('sdk.description', '/docs/references/account/create-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', function ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the recovery email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('projectDB')
    ->inject('project')
    ->inject('locale')
    ->inject('mails')
    ->inject('audits')
    ->inject('events')
    ->action(function ($email, $url, $request, $response, $projectDB, $project, $locale, $mails, $audits, $events) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Database\Document $project */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Event\Event $mails */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */

        $isPreviliggedUser = Auth::isPreviliggedUser(Authorization::$roles);
        $isAppUser = Auth::isAppUser(Authorization::$roles);

        $profile = $projectDB->getCollectionFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                'email='.$email,
            ],
        ]);

        if (empty($profile)) {
            throw new Exception('User not found', 404); // TODO maybe hide this
        }

        if (Auth::USER_STATUS_BLOCKED == $profile->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401); // User is in status blocked
        }

        $secret = Auth::tokenGenerator();
        $recovery = new Document([
            '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
            '$permissions' => ['read' => ['user:'.$profile->getId()], 'write' => ['user:'.$profile->getId()]],
            'userId' => $profile->getId(),
            'type' => Auth::TOKEN_TYPE_RECOVERY,
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => \time() + Auth::TOKEN_EXPIRATION_RECOVERY,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);
            
        Authorization::setRole('user:'.$profile->getId());

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
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $profile->getId(), 'secret' => $secret]);
        $url = Template::unParseURL($url);

        $body = new Template(__DIR__.'/../../config/locale/templates/email-base.tpl');
        $content = new Template(__DIR__.'/../../config/locale/translations/templates/'.$locale->getText('account.emails.recovery.body'));
        $cta = new Template(__DIR__.'/../../config/locale/templates/email-cta.tpl');

        $body
            ->setParam('{{content}}', $content->render())
            ->setParam('{{cta}}', $cta->render())
            ->setParam('{{title}}', $locale->getText('account.emails.recovery.title'))
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
            ->setParam('{{name}}', $profile->getAttribute('name'))
            ->setParam('{{redirect}}', $url)
            ->setParam('{{bg-body}}', '#f6f6f6')
            ->setParam('{{bg-content}}', '#ffffff')
            ->setParam('{{bg-cta}}', '#3498db')
            ->setParam('{{bg-cta-hover}}', '#34495e')
            ->setParam('{{text-content}}', '#000000')
            ->setParam('{{text-cta}}', '#ffffff')
        ;

        $mails
            ->setParam('event', 'account.recovery.create')
            ->setParam('from', ($project->getId() === 'console') ? '' : \sprintf($locale->getText('account.emails.team'), $project->getAttribute('name')))
            ->setParam('recipient', $profile->getAttribute('email', ''))
            ->setParam('name', $profile->getAttribute('name', ''))
            ->setParam('subject', $locale->getText('account.emails.recovery.title'))
            ->setParam('body', $body->render())
            ->trigger();
        ;

        $events
            ->setParam('payload',
                $response->output($recovery->setAttribute('secret', $secret),
                Response::MODEL_TOKEN
            ))
        ;

        $recovery  // Hide secret for clients, sp
            ->setAttribute('secret',
                ($isPreviliggedUser || $isAppUser) ? $secret : '');

        $audits
            ->setParam('userId', $profile->getId())
            ->setParam('event', 'account.recovery.create')
            ->setParam('resource', 'users/'.$profile->getId())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($recovery, Response::MODEL_TOKEN)
        ;
    });

App::put('/v1/account/recovery')
    ->desc('Complete Password Recovery')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'account.recovery.update')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateRecovery')
    ->label('sdk.description', '/docs/references/account/update-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new UID(), 'User account UID address.')
    ->param('secret', '', new Text(256), 'Valid reset token.')
    ->param('password', '', new Password(), 'New password. Must be between 6 to 32 chars.')
    ->param('passwordAgain', '', new Password(), 'New password again. Must be between 6 to 32 chars.')
    ->inject('response')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($userId, $secret, $password, $passwordAgain, $response, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */
    
        if ($password !== $passwordAgain) {
            throw new Exception('Passwords must match', 400);
        }

        $profile = $projectDB->getCollectionFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                '$id='.$userId,
            ],
        ]);

        if (empty($profile)) {
            throw new Exception('User not found', 404); // TODO maybe hide this
        }

        $recovery = Auth::tokenVerify($profile->getAttribute('tokens', []), Auth::TOKEN_TYPE_RECOVERY, $secret);

        if (!$recovery) {
            throw new Exception('Invalid recovery token', 401);
        }

        Authorization::setRole('user:'.$profile->getId());

        $profile = $projectDB->updateDocument(\array_merge($profile->getArrayCopy(), [
            'password' => Auth::passwordHash($password),
            'passwordUpdate' => \time(),
            'emailVerification' => true,
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

        $audits
            ->setParam('userId', $profile->getId())
            ->setParam('event', 'account.recovery.update')
            ->setParam('resource', 'users/'.$profile->getId())
        ;

        $recovery = $profile->search('$id', $recovery, $profile->getAttribute('tokens', []));

        $response->dynamic($recovery, Response::MODEL_TOKEN);
    });

App::post('/v1/account/verification')
    ->desc('Create Email Verification')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'account.verification.create')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createVerification')
    ->label('sdk.description', '/docs/references/account/create-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('url', '', function ($clients) { return new Host($clients); }, 'URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add built-in confirm page
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('projectDB')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('mails')
    ->action(function ($url, $request, $response, $project, $user, $projectDB, $locale, $audits, $events, $mails) {
        /** @var Utopia\Swoole\Request $request */
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $project */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Utopia\Locale\Locale $locale */
        /** @var Appwrite\Event\Event $audits */
        /** @var Appwrite\Event\Event $events */
        /** @var Appwrite\Event\Event $mails */

        $isPreviliggedUser = Auth::isPreviliggedUser(Authorization::$roles);
        $isAppUser = Auth::isAppUser(Authorization::$roles);

        $verificationSecret = Auth::tokenGenerator();
        
        $verification = new Document([
            '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
            '$permissions' => ['read' => ['user:'.$user->getId()], 'write' => ['user:'.$user->getId()]],
            'userId' => $user->getId(),
            'type' => Auth::TOKEN_TYPE_VERIFICATION,
            'secret' => Auth::hash($verificationSecret), // One way hash encryption to protect DB leak
            'expire' => \time() + Auth::TOKEN_EXPIRATION_CONFIRM,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);
            
        Authorization::setRole('user:'.$user->getId());

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
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $verificationSecret]);
        $url = Template::unParseURL($url);

        $body = new Template(__DIR__.'/../../config/locale/templates/email-base.tpl');
        $content = new Template(__DIR__.'/../../config/locale/translations/templates/'.$locale->getText('account.emails.verification.body'));
        $cta = new Template(__DIR__.'/../../config/locale/templates/email-cta.tpl');

        $body
            ->setParam('{{content}}', $content->render())
            ->setParam('{{cta}}', $cta->render())
            ->setParam('{{title}}', $locale->getText('account.emails.verification.title'))
            ->setParam('{{direction}}', $locale->getText('settings.direction'))
            ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
            ->setParam('{{name}}', $user->getAttribute('name'))
            ->setParam('{{redirect}}', $url)
            ->setParam('{{bg-body}}', '#f6f6f6')
            ->setParam('{{bg-content}}', '#ffffff')
            ->setParam('{{bg-cta}}', '#3498db')
            ->setParam('{{bg-cta-hover}}', '#34495e')
            ->setParam('{{text-content}}', '#000000')
            ->setParam('{{text-cta}}', '#ffffff')
        ;

        $mails
            ->setParam('event', 'account.verification.create')
            ->setParam('from', ($project->getId() === 'console') ? '' : \sprintf($locale->getText('account.emails.team'), $project->getAttribute('name')))
            ->setParam('recipient', $user->getAttribute('email'))
            ->setParam('name', $user->getAttribute('name'))
            ->setParam('subject', $locale->getText('account.emails.verification.title'))
            ->setParam('body', $body->render())
            ->trigger()
        ;

        $events
            ->setParam('payload',
                $response->output($verification->setAttribute('secret', $verificationSecret),
                Response::MODEL_TOKEN
            ))
        ;

        $verification  // Hide secret for clients, sp
            ->setAttribute('secret',
                ($isPreviliggedUser || $isAppUser) ? $verificationSecret : '');

        $audits
            ->setParam('userId', $user->getId())
            ->setParam('event', 'account.verification.create')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN)
        ;
    });

App::put('/v1/account/verification')
    ->desc('Complete Email Verification')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'account.verification.update')
    ->label('sdk.platform', [APP_PLATFORM_CLIENT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateVerification')
    ->label('sdk.description', '/docs/references/account/update-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new UID(), 'User unique ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('projectDB')
    ->inject('audits')
    ->action(function ($userId, $secret, $response, $user, $projectDB, $audits) {
        /** @var Appwrite\Utopia\Response $response */
        /** @var Appwrite\Database\Document $user */
        /** @var Appwrite\Database\Database $projectDB */
        /** @var Appwrite\Event\Event $audits */

        $profile = $projectDB->getCollectionFirst([ // Get user by email address
            'limit' => 1,
            'filters' => [
                '$collection='.Database::SYSTEM_COLLECTION_USERS,
                '$id='.$userId,
            ],
        ]);

        if (empty($profile)) {
            throw new Exception('User not found', 404); // TODO maybe hide this
        }

        $verification = Auth::tokenVerify($profile->getAttribute('tokens', []), Auth::TOKEN_TYPE_VERIFICATION, $secret);

        if (!$verification) {
            throw new Exception('Invalid verification token', 401);
        }

        Authorization::setRole('user:'.$profile->getId());

        $profile = $projectDB->updateDocument(\array_merge($profile->getArrayCopy(), [
            'emailVerification' => true,
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

        $audits
            ->setParam('userId', $profile->getId())
            ->setParam('event', 'account.verification.update')
            ->setParam('resource', 'users/'.$user->getId())
        ;

        $verification = $profile->search('$id', $verification, $profile->getAttribute('tokens', []));

        $response->dynamic($verification, Response::MODEL_TOKEN);
    });