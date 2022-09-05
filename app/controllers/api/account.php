<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Auth\Phone;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\Phone as ValidatorPhone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Network\Validator\Email;
use Appwrite\Network\Validator\Host;
use Appwrite\Network\Validator\URL;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Stats\Stats;
use Appwrite\Template\Template;
use Appwrite\URL\URL as URLParser;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Database\Validator\CustomId;
use MaxMind\Db\Reader;
use Utopia\App;
use Appwrite\Event\Audit;
use Appwrite\Event\Phone as EventPhone;
use Utopia\Audit\Audit as EventAudit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\UID;
use Utopia\Locale\Locale;
use Appwrite\Extend\Exception;
use Utopia\Validator\ArrayList;
use Utopia\Validator\Assoc;
use Utopia\Validator\Range;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;

$oauthDefaultSuccess = '/v1/auth/oauth2/success';
$oauthDefaultFailure = '/v1/auth/oauth2/failure';

App::post('/v1/account')
    ->desc('Create Account')
    ->groups(['api', 'account', 'auth'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'public')
    ->label('auth.type', 'emailPassword')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/account/create.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('abuse-limit', 10)
    ->param('userId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, string $email, string $password, string $name, Request $request, Response $response, Document $project, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        $email = \strtolower($email);
        if ('console' === $project->getId()) {
            $whitelistEmails = $project->getAttribute('authWhitelistEmails');
            $whitelistIPs = $project->getAttribute('authWhitelistIPs');

            if (!empty($whitelistEmails) && !\in_array($email, $whitelistEmails)) {
                throw new Exception('Console registration is restricted to specific emails. Contact your administrator for more information.', 401, Exception::USER_EMAIL_NOT_WHITELISTED);
            }

            if (!empty($whitelistIPs) && !\in_array($request->getIP(), $whitelistIPs)) {
                throw new Exception('Console registration is restricted to specific IPs. Contact your administrator for more information.', 401, Exception::USER_IP_NOT_WHITELISTED);
            }
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
            }
        }

        try {
            $userId = $userId == 'unique()' ? $dbForProject->getId() : $userId;
            $user = Authorization::skip(fn() => $dbForProject->createDocument('users', new Document([
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
            ])));
        } catch (Duplicate $th) {
            throw new Exception('Account already exists', 409, Exception::USER_ALREADY_EXISTS);
        }

        Authorization::unsetRole('role:' . Auth::USER_ROLE_GUEST);
        Authorization::setRole('user:' . $user->getId());
        Authorization::setRole('role:' . Auth::USER_ROLE_MEMBER);

        $audits
            ->setResource('user/' . $user->getId())
            ->setUser($user)
        ;

        $usage->setParam('users.create', 1);
        $events->setParam('userId', $user->getId());

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($user, Response::MODEL_USER);
    });

App::post('/v1/account/sessions/email')
    ->alias('/v1/account/sessions')
    ->desc('Create Account Session with Email')
    ->groups(['api', 'account', 'auth'])
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'public')
    ->label('auth.type', 'emailPassword')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createEmailSession')
    ->label('sdk.description', '/docs/references/account/create-session-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $email, string $password, Request $request, Response $response, Database $dbForProject, Locale $locale, Reader $geodb, Audit $audits, Stats $usage, Event $events) {

        $email = \strtolower($email);
        $protocol = $request->getProtocol();

        $profile = $dbForProject->findOne('users', [
            new Query('email', Query::TYPE_EQUAL, [$email])]);

        if (!$profile || !Auth::passwordVerify($password, $profile->getAttribute('password'))) {
            throw new Exception('Invalid credentials', 401, Exception::USER_INVALID_CREDENTIALS); // Wrong password or username
        }

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401, Exception::USER_BLOCKED); // User is in status blocked
        }

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $secret = Auth::tokenGenerator();
        $session = new Document(array_merge(
            [
                '$id' => $dbForProject->getId(),
                'userId' => $profile->getId(),
                'userInternalId' => $profile->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_EMAIL,
                'providerUid' => $email,
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

        Authorization::setRole('user:' . $profile->getId());

        $session = $dbForProject->createDocument('sessions', $session
            ->setAttribute('$read', ['user:' . $profile->getId()])
            ->setAttribute('$write', ['user:' . $profile->getId()]));

        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $audits
            ->setResource('user/' . $profile->getId())
            ->setUser($profile)
        ;

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($profile->getId(), $secret)]))
            ;
        }

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($profile->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($profile->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
        ;

        $usage
            ->setParam('users.update', 1)
            ->setParam('users.sessions.create', 1)
            ->setParam('provider', 'email')
        ;

        $events
            ->setParam('userId', $profile->getId())
            ->setParam('sessionId', $session->getId())
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::get('/v1/account/sessions/oauth2/:provider')
    ->desc('Create Account Session with OAuth2')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createOAuth2Session')
    ->label('sdk.description', '/docs/references/account/create-session-oauth2.md')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 Provider. Currently, supported providers are: ' . \implode(', ', \array_keys(\array_filter(Config::getParam('providers'), fn($node) => (!$node['mock'])))) . '.')
    ->param('success', '', fn($clients) => new Host($clients), 'URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('failure', '', fn($clients) => new Host($clients), 'URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('scopes', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'A list of custom OAuth2 scopes. Check each provider internal docs for a list of supported scopes. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->action(function (string $provider, string $success, string $failure, array $scopes, Request $request, Response $response, Document $project) use ($oauthDefaultSuccess, $oauthDefaultFailure) {

        $protocol = $request->getProtocol();
        $callback = $protocol . '://' . $request->getHostname() . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $appId = $project->getAttribute('authProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('authProviders', [])[$provider . 'Secret'] ?? '{}';

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = App::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        if (empty($appId) || empty($appSecret)) {
            throw new Exception('This provider is disabled. Please configure the provider app ID and app secret key from your ' . APP_NAME . ' console to continue.', 412, Exception::PROJECT_PROVIDER_DISABLED);
        }

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception('Provider is not supported', 501, Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        if (empty($success)) {
            $success = $protocol . '://' . $request->getHostname() . $oauthDefaultSuccess;
        }

        if (empty($failure)) {
            $failure = $protocol . '://' . $request->getHostname() . $oauthDefaultFailure;
        }

        $oauth2 = new $className($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure], $scopes);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    });

App::get('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 Callback')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->inject('request')
    ->inject('response')
    ->action(function (string $projectId, string $provider, string $code, string $state, Request $request, Response $response) {

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $domain . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
    });

App::post('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 Callback')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('origin', '*')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->inject('request')
    ->inject('response')
    ->action(function (string $projectId, string $provider, string $code, string $state, Request $request, Response $response) {

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $domain . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
    });

App::get('/v1/account/sessions/oauth2/:provider/redirect')
    ->desc('OAuth2 Redirect')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'public')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->label('docs', false)
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('providers')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048), 'OAuth2 code.')
    ->param('state', '', new Text(2048), 'OAuth2 state params.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function (string $provider, string $code, string $state, Request $request, Response $response, Document $project, Document $user, Database $dbForProject, Reader $geodb, Audit $audits, Event $events, Stats $usage) use ($oauthDefaultSuccess) {

        $protocol = $request->getProtocol();
        $callback = $protocol . '://' . $request->getHostname() . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $defaultState = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
        $validateURL = new URL();
        $appId = $project->getAttribute('authProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('authProviders', [])[$provider . 'Secret'] ?? '{}';

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = App::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception('Provider is not supported', 501, Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $oauth2 = new $className($appId, $appSecret, $callback);

        if (!empty($state)) {
            try {
                $state = \array_merge($defaultState, $oauth2->parseState($state));
            } catch (\Exception$exception) {
                throw new Exception('Failed to parse login state params as passed from OAuth2 provider', 500, Exception::GENERAL_SERVER_ERROR);
            }
        } else {
            $state = $defaultState;
        }

        if (!$validateURL->isValid($state['success'])) {
            throw new Exception('Invalid redirect URL for success login', 400, Exception::PROJECT_INVALID_SUCCESS_URL);
        }

        if (!empty($state['failure']) && !$validateURL->isValid($state['failure'])) {
            throw new Exception('Invalid redirect URL for failure login', 400, Exception::PROJECT_INVALID_FAILURE_URL);
        }

        $state['failure'] = null;

        $accessToken = $oauth2->getAccessToken($code);
        $refreshToken = $oauth2->getRefreshToken($code);
        $accessTokenExpiry = $oauth2->getAccessTokenExpiry($code);

        if (empty($accessToken)) {
            if (!empty($state['failure'])) {
                $response->redirect($state['failure'], 301, 0);
            }

            throw new Exception('Failed to obtain access token', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $oauth2ID = $oauth2->getUserID($accessToken);

        if (empty($oauth2ID)) {
            if (!empty($state['failure'])) {
                $response->redirect($state['failure'], 301, 0);
            }

            throw new Exception('Missing ID from OAuth2 provider', 400, Exception::PROJECT_MISSING_USER_ID);
        }

        $sessions = $user->getAttribute('sessions', []);
        $current = Auth::sessionVerify($sessions, Auth::$secret);

        if ($current) { // Delete current session of new one.
            $currentDocument = $dbForProject->getDocument('sessions', $current);
            if (!$currentDocument->isEmpty()) {
                $dbForProject->deleteDocument('sessions', $currentDocument->getId());
                $dbForProject->deleteCachedDocument('users', $user->getId());
            }
        }

        $user = ($user->isEmpty()) ? $dbForProject->findOne('sessions', [ // Get user by provider id
            new Query('provider', QUERY::TYPE_EQUAL, [$provider]),
            new Query('providerUid', QUERY::TYPE_EQUAL, [$oauth2ID]),
        ]) : $user;

        if ($user === false || $user->isEmpty()) { // No user logged in or with OAuth2 provider ID, create new one or connect with account with same email
            $name = $oauth2->getUserName($accessToken);
            $email = $oauth2->getUserEmail($accessToken);

            /**
             * Is verified is not used yet, since we don't know after an accout is created anymore if it was verified or not.
             */
            $isVerified = $oauth2->isEmailVerified($accessToken);

            $user = $dbForProject->findOne('users', [
                new Query('email', Query::TYPE_EQUAL, [$email])]);

            if ($user === false || $user->isEmpty()) { // Last option -> create the user, generate random password
                $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

                if ($limit !== 0) {
                    $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                    if ($total >= $limit) {
                        throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
                    }
                }

                try {
                    $userId = $dbForProject->getId();
                    $user = Authorization::skip(fn() => $dbForProject->createDocument('users', new Document([
                        '$id' => $userId,
                        '$read' => ['role:all'],
                        '$write' => ['user:' . $userId],
                        'email' => $email,
                        'emailVerification' => true,
                        'status' => true, // Email should already be authenticated by OAuth2 provider
                        'password' => Auth::passwordHash(Auth::passwordGenerator()),
                        'passwordUpdate' => 0,
                        'registration' => \time(),
                        'reset' => false,
                        'name' => $name,
                        'prefs' => new \stdClass(),
                        'sessions' => null,
                        'tokens' => null,
                        'memberships' => null,
                        'search' => implode(' ', [$userId, $email, $name])
                    ])));
                } catch (Duplicate $th) {
                    throw new Exception('Account already exists', 409, Exception::USER_ALREADY_EXISTS);
                }
            }
        }

        if (false === $user->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401, Exception::USER_BLOCKED); // User is in status blocked
        }

        // Create session token, verify user account and update OAuth2 ID and Access Token
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'provider' => $provider,
            'providerUid' => $oauth2ID,
            'providerAccessToken' => $accessToken,
            'providerRefreshToken' => $refreshToken,
            'providerAccessTokenExpiry' => \time() + (int) $accessTokenExpiry,
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expiry,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
            'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
        ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

        $isAnonymousUser = Auth::isAnonymousUser($user);

        if ($isAnonymousUser) {
            $user
                ->setAttribute('name', $oauth2->getUserName($accessToken))
                ->setAttribute('email', $oauth2->getUserEmail($accessToken))
            ;
        }

        $user
            ->setAttribute('status', true)
        ;

        Authorization::setRole('user:' . $user->getId());

        $dbForProject->updateDocument('users', $user->getId(), $user);

        $session = $dbForProject->createDocument('sessions', $session
            ->setAttribute('$read', ['user:' . $user->getId()])
            ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $audits
            ->setResource('user/' . $user->getId())
            ->setUser($user)
        ;

        $usage
            ->setParam('users.sessions.create', 1)
            ->setParam('projectId', $project->getId())
            ->setParam('provider', 'oauth2-' . $provider)
        ;

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION))
        ;

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        // Add keys for non-web platforms - TODO - add verification phase to aviod session sniffing
        if (parse_url($state['success'], PHP_URL_PATH) === $oauthDefaultSuccess) {
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
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->redirect($state['success'])
        ;
    });


App::post('/v1/account/sessions/magic-url')
    ->desc('Create Magic URL session')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].sessions.[tokenId].magic.create')
    ->label('auth.type', 'magic-url')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createMagicURLSession')
    ->label('sdk.description', '/docs/references/account/create-magic-url-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('userId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', fn($clients) => new Host($clients), 'URL to redirect the user back to your app from the magic URL login. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('mails')
    ->action(function (string $userId, string $email, string $url, Request $request, Response $response, Document $project, Database $dbForProject, Locale $locale, Audit $audits, Event $events, Mail $mails) {

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('SMTP Disabled', 503, Exception::GENERAL_SMTP_DISABLED);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        $useEmail = App::getEnv('_APP_SMTP_USE_EMAIL', true);

        $user = $dbForProject->findOne('users', [new Query('email', Query::TYPE_EQUAL, [$email])]);

        if (!$user) {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
                }
            }

            $userId = $userId == 'unique()' ? $dbForProject->getId() : $userId;

            $user = Authorization::skip(fn () => $dbForProject->createDocument('users', new Document([
                '$id' => $userId,
                '$read' => ['role:all'],
                '$write' => ['user:' . $userId],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => null,
                'passwordUpdate' => 0,
                'registration' => \time(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email])
            ])));
        }

        $loginSecret = Auth::tokenGenerator();

        $expire = \time() + Auth::TOKEN_EXPIRATION_CONFIRM;

        $token = new Document([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_MAGIC_URL,
            'secret' => Auth::hash($loginSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole('user:' . $user->getId());

        $token = $dbForProject->createDocument('tokens', $token
            ->setAttribute('$read', ['user:' . $user->getId()])
            ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        if (empty($url)) {
            $url = $request->getProtocol() . '://' . $request->getHostname() . '/auth/magic-url';
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $loginSecret, 'expire' => $expire, 'project' => $project->getId()]);
        $url = Template::unParseURL($url);

        if ($useEmail) {
            $mails
                ->setType(MAIL_TYPE_MAGIC_SESSION)
                ->setRecipient($user->getAttribute('email'))
                ->setUrl($url)
                ->setLocale($locale->default)
                ->trigger()
            ;
        }

        $events->setPayload(
            $response->output(
                $token->setAttribute('secret', $loginSecret),
                Response::MODEL_TOKEN
            )
        );

        // Hide secret for clients
        $token->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $loginSecret : '');

        $audits
            ->setResource('user/' . $user->getId())
            ->setUser($user)
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN)
        ;
    });

App::put('/v1/account/sessions/magic-url')
    ->desc('Create Magic URL session (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateMagicURLSession')
    ->label('sdk.description', '/docs/references/account/update-magic-url-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new CustomId(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $userId, string $secret, Request $request, Response $response, Database $dbForProject, Locale $locale, Reader $geodb, Audit $audits, Event $events) {

        /** @var Utopia\Database\Document $user */

        $user = Authorization::skip(fn() => $dbForProject->getDocument('users', $userId));

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $token = Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_MAGIC_URL, $secret);

        if (!$token) {
            throw new Exception('Invalid login token', 401, Exception::USER_INVALID_TOKEN);
        }

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge(
            [
                '$id' => $dbForProject->getId(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_MAGIC_URL,
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

        Authorization::setRole('user:' . $user->getId());

        $session = $dbForProject->createDocument('sessions', $session
                ->setAttribute('$read', ['user:' . $user->getId()])
                ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $tokens = $user->getAttribute('tokens', []);

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $token);
        $dbForProject->deleteCachedDocument('users', $user->getId());

        $user->setAttribute('emailVerification', true);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $audits->setResource('user/' . $user->getId());

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        $protocol = $request->getProtocol();

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/sessions/phone')
    ->desc('Create Phone session')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('auth.type', 'phone')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createPhoneSession')
    ->label('sdk.description', '/docs/references/account/create-phone-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('userId', '', new CustomId(), 'Unique Id. Choose your own unique ID or pass the string "unique()" to auto generate it. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('number', '', new ValidatorPhone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->inject('messaging')
    ->inject('phone')
    ->action(function (string $userId, string $number, Request $request, Response $response, Document $project, Database $dbForProject, Audit $audits, Event $events, EventPhone $messaging, Phone $phone) {
        if (empty(App::getEnv('_APP_PHONE_PROVIDER'))) {
            throw new Exception('Phone provider not configured', 503, Exception::GENERAL_PHONE_DISABLED);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $user = $dbForProject->findOne('users', [new Query('phone', Query::TYPE_EQUAL, [$number])]);

        if (!$user) {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
                }
            }

            $userId = $userId == 'unique()' ? $dbForProject->getId() : $userId;

            $user = Authorization::skip(fn () => $dbForProject->createDocument('users', new Document([
                '$id' => $userId,
                '$read' => ['role:all'],
                '$write' => ['user:' . $userId],
                'email' => null,
                'phone' => $number,
                'emailVerification' => false,
                'phoneVerification' => false,
                'status' => true,
                'password' => null,
                'passwordUpdate' => 0,
                'registration' => \time(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $number])
            ])));
        }

        $secret = $phone->generateSecretDigits();

        $expire = \time() + Auth::TOKEN_EXPIRATION_PHONE;

        $token = new Document([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_PHONE,
            'secret' => $secret,
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole('user:' . $user->getId());

        $token = $dbForProject->createDocument('tokens', $token
            ->setAttribute('$read', ['user:' . $user->getId()])
            ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $messaging
            ->setRecipient($number)
            ->setMessage($secret)
            ->trigger();

        $events->setPayload(
            $response->output(
                $token->setAttribute('secret', $secret),
                Response::MODEL_TOKEN
            )
        );

        // Hide secret for clients
        $token->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $secret : '');

        $audits
            ->setResource('user/' . $user->getId())
            ->setUser($user)
        ;

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN)
        ;
    });

App::put('/v1/account/sessions/phone')
    ->desc('Create Phone session (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePhoneSession')
    ->label('sdk.description', '/docs/references/account/update-phone-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new CustomId(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('geodb')
    ->inject('audits')
    ->inject('events')
    ->action(function (string $userId, string $secret, Request $request, Response $response, Database $dbForProject, Locale $locale, Reader $geodb, Audit $audits, Event $events) {

        $user = Authorization::skip(fn() => $dbForProject->getDocument('users', $userId));

        if ($user->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $token = Auth::phoneTokenVerify($user->getAttribute('tokens', []), $secret);

        if (!$token) {
            throw new Exception('Invalid login token', 401, Exception::USER_INVALID_TOKEN);
        }

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge(
            [
                '$id' => $dbForProject->getId(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_PHONE,
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

        Authorization::setRole('user:' . $user->getId());

        $session = $dbForProject->createDocument('sessions', $session
                ->setAttribute('$read', ['user:' . $user->getId()])
                ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $token);
        $dbForProject->deleteCachedDocument('users', $user->getId());

        $user->setAttribute('phoneVerification', true);

        $user = $dbForProject->updateDocument('users', $user->getId(), $user);

        if (false === $user) {
            throw new Exception('Failed saving user to DB', 500, Exception::GENERAL_SERVER_ERROR);
        }

        $audits->setResource('user/' . $user->getId());

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        $protocol = $request->getProtocol();

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/sessions/anonymous')
    ->desc('Create Anonymous Session')
    ->groups(['api', 'account', 'auth'])
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'public')
    ->label('auth.type', 'anonymous')
    ->label('sdk.auth', [])
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
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (Request $request, Response $response, Locale $locale, Document $user, Document $project, Database $dbForProject, Reader $geodb, Audit $audits, Stats $usage, Event $events) {

        $protocol = $request->getProtocol();

        if ('console' === $project->getId()) {
            throw new Exception('Failed to create anonymous user.', 401, Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED);
        }

        if (!$user->isEmpty()) {
            throw new Exception('Cannot create an anonymous user when logged in.', 401, Exception::USER_SESSION_ALREADY_EXISTS);
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception('Project registration is restricted. Contact your administrator for more information.', 501, Exception::USER_COUNT_EXCEEDED);
            }
        }

        $userId = $dbForProject->getId();
        $user = Authorization::skip(fn() => $dbForProject->createDocument('users', new Document([
            '$id' => $userId,
            '$read' => ['role:all'],
            '$write' => ['user:' . $userId],
            'email' => null,
            'emailVerification' => false,
            'status' => true,
            'password' => null,
            'passwordUpdate' => 0,
            'registration' => \time(),
            'reset' => false,
            'name' => null,
            'prefs' => new \stdClass(),
            'sessions' => null,
            'tokens' => null,
            'memberships' => null,
            'search' => $userId
        ])));

        // Create session token

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator();
        $expiry = \time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session = new Document(array_merge(
            [
                '$id' => $dbForProject->getId(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_ANONYMOUS,
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

        Authorization::setRole('user:' . $user->getId());

        $session = $dbForProject->createDocument('sessions', $session
                ->setAttribute('$read', ['user:' . $user->getId()])
                ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $audits->setResource('user/' . $user->getId());

        $usage
            ->setParam('users.sessions.create', 1)
            ->setParam('provider', 'anonymous')
        ;

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), $expiry, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

App::post('/v1/account/jwt')
    ->desc('Create Account JWT')
    ->groups(['api', 'account', 'auth'])
    ->label('scope', 'account')
    ->label('auth.type', 'jwt')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createJWT')
    ->label('sdk.description', '/docs/references/account/create-jwt.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_JWT)
    ->label('abuse-limit', 100)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->action(function (Response $response, Document $user, Database $dbForProject) {


        $sessions = $user->getAttribute('sessions', []);
        $current = new Document();

        foreach ($sessions as $session) { /** @var Utopia\Database\Document $session */
            if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                $current = $session;
            }
        }

        if ($current->isEmpty()) {
            throw new Exception('No valid session found', 404, Exception::USER_SESSION_NOT_FOUND);
        }

        $jwt = new JWT(App::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic(new Document(['jwt' => $jwt->encode([
            // 'uid'    => 1,
            // 'aud'    => 'http://site.com',
            // 'scopes' => ['user'],
            // 'iss'    => 'http://api.mysite.com',
            'userId' => $user->getId(),
            'sessionId' => $current->getId(),
        ])]), Response::MODEL_JWT);
    });

App::get('/v1/account')
    ->desc('Get Account')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/account/get.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->inject('response')
    ->inject('user')
    ->inject('usage')
    ->action(function (Response $response, Document $user, Stats $usage) {

        $usage->setParam('users.read', 1);

        $response->dynamic($user, Response::MODEL_USER);
    });

App::get('/v1/account/prefs')
    ->desc('Get Account Preferences')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/account/get-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->inject('response')
    ->inject('user')
    ->inject('usage')
    ->action(function (Response $response, Document $user, Stats $usage) {

        $prefs = $user->getAttribute('prefs', new \stdClass());

        $usage->setParam('users.read', 1);

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

App::get('/v1/account/sessions')
    ->desc('Get Account Sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSessions')
    ->label('sdk.description', '/docs/references/account/get-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION_LIST)
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('usage')
    ->action(function (Response $response, Document $user, Locale $locale, Stats $usage) {

        $sessions = $user->getAttribute('sessions', []);
        $current = Auth::sessionVerify($sessions, Auth::$secret);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', ($current == $session->getId()) ? true : false);

            $sessions[$key] = $session;
        }

        $usage->setParam('users.read', 1);

        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => count($sessions),
        ]), Response::MODEL_SESSION_LIST);
    });

App::get('/v1/account/logs')
    ->desc('Get Account Logs')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getLogs')
    ->label('sdk.description', '/docs/references/account/get-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('limit', 25, new Range(0, 100), 'Maximum number of logs to return in response. By default will return maximum 25 results. Maximum of 100 results allowed per request.', true)
    ->param('offset', 0, new Range(0, APP_LIMIT_COUNT), 'Offset value. The default value is 0. Use this value to manage pagination. [learn more about pagination](https://appwrite.io/docs/pagination)', true)
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('geodb')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (int $limit, int $offset, Response $response, Document $user, Locale $locale, Reader $geodb, Database $dbForProject, Stats $usage) {

        $audit = new EventAudit($dbForProject);

        $logs = $audit->getLogsByUser($user->getId(), $limit, $offset);

        $output = [];

        foreach ($logs as $i => &$log) {
            $log['userAgent'] = (!empty($log['userAgent'])) ? $log['userAgent'] : 'UNKNOWN';

            $detector = new Detector($log['userAgent']);

            $output[$i] = new Document(array_merge(
                $log->getArrayCopy(),
                $log['data'],
                $detector->getOS(),
                $detector->getClient(),
                $detector->getDevice()
            ));

            $record = $geodb->get($log['ip']);

            if ($record) {
                $output[$i]['countryCode'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), false) ? \strtolower($record['country']['iso_code']) : '--';
                $output[$i]['countryName'] = $locale->getText('countries.' . strtolower($record['country']['iso_code']), $locale->getText('locale.country.unknown'));
            } else {
                $output[$i]['countryCode'] = '--';
                $output[$i]['countryName'] = $locale->getText('locale.country.unknown');
            }
        }

        $usage->setParam('users.read', 1);

        $response->dynamic(new Document([
            'total' => $audit->countLogsByUser($user->getId()),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

App::get('/v1/account/sessions/:sessionId')
    ->desc('Get Session By ID')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSession')
    ->label('sdk.description', '/docs/references/account/get-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->param('sessionId', null, new UID(), 'Session ID. Use the string \'current\' to get the current device session.')
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('dbForProject')
    ->inject('usage')
    ->action(function (?string $sessionId, Response $response, Document $user, Locale $locale, Database $dbForProject, Stats $usage) {

        $sessions = $user->getAttribute('sessions', []);
        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
            : $sessionId;

        foreach ($sessions as $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {
                $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

                $session
                    ->setAttribute('current', ($session->getAttribute('secret') == Auth::hash(Auth::$secret)))
                    ->setAttribute('countryName', $countryName)
                ;

                $usage->setParam('users.read', 1);

                return $response->dynamic($session, Response::MODEL_SESSION);
            }
        }

        throw new Exception('Session not found', 404, Exception::USER_SESSION_NOT_FOUND);
    });

App::patch('/v1/account/name')
    ->desc('Update Account Name')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.name')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/account/update-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $name, Response $response, Document $user, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        $user = $dbForProject->updateDocument('users', $user->getId(), $user
            ->setAttribute('name', $name)
            ->setAttribute('search', implode(' ', [$user->getId(), $name, $user->getAttribute('email', ''), $user->getAttribute('phone', '')])));

        $audits
            ->setResource('user/' . $user->getId())
            ->setUser($user)
        ;

        $usage->setParam('users.update', 1);
        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/password')
    ->desc('Update Account Password')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.password')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/account/update-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('password', '', new Password(), 'New user password. Must be at least 8 chars.')
    ->param('oldPassword', '', new Password(), 'Current user password. Must be at least 8 chars.', true)
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $password, string $oldPassword, Response $response, Document $user, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        // Check old password only if its an existing user.
        if ($user->getAttribute('passwordUpdate') !== 0 && !Auth::passwordVerify($oldPassword, $user->getAttribute('password'))) { // Double check user password
            throw new Exception('Invalid credentials', 401, Exception::USER_INVALID_CREDENTIALS);
        }

        $user = $dbForProject->updateDocument(
            'users',
            $user->getId(),
            $user
                ->setAttribute('password', Auth::passwordHash($password))
                ->setAttribute('passwordUpdate', \time())
        );

        $audits
            ->setResource('user/' . $user->getId())
            ->setUser($user)
        ;

        $usage->setParam('users.update', 1);
        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/email')
    ->desc('Update Account Email')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.email')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/account/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $email, string $password, Response $response, Document $user, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        $isAnonymousUser = Auth::isAnonymousUser($user); // Check if request is from an anonymous account for converting

        if (
            !$isAnonymousUser &&
            !Auth::passwordVerify($password, $user->getAttribute('password'))
        ) { // Double check user password
            throw new Exception('Invalid credentials', 401, Exception::USER_INVALID_CREDENTIALS);
        }

        $email = \strtolower($email);

        $user
            ->setAttribute('password', $isAnonymousUser ? Auth::passwordHash($password) : $user->getAttribute('password', ''))
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false) // After this user needs to confirm mail again
            ->setAttribute('search', implode(' ', [$user->getId(), $user->getAttribute('name', ''), $email, $user->getAttribute('phone', '')]));

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
        } catch (Duplicate $th) {
            throw new Exception('Email already exists', 409, Exception::USER_EMAIL_ALREADY_EXISTS);
        }

        $audits
            ->setResource('user/' . $user->getId())
            ->setUser($user)
        ;

        $usage->setParam('users.update', 1);
        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/phone')
    ->desc('Update Account Phone')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.phone')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePhone')
    ->label('sdk.description', '/docs/references/account/update-phone.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('number', '', new ValidatorPhone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $phone, string $password, Response $response, Document $user, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        $isAnonymousUser = Auth::isAnonymousUser($user); // Check if request is from an anonymous account for converting

        if (
            !$isAnonymousUser &&
            !Auth::passwordVerify($password, $user->getAttribute('password'))
        ) { // Double check user password
            throw new Exception('Invalid credentials', 401, Exception::USER_INVALID_CREDENTIALS);
        }

        $user
            ->setAttribute('phone', $phone)
            ->setAttribute('phoneVerification', false) // After this user needs to confirm phone number again
            ->setAttribute('search', implode(' ', [$user->getId(), $user->getAttribute('name', ''), $user->getAttribute('email', ''), $phone]));

        try {
            $user = $dbForProject->updateDocument('users', $user->getId(), $user);
        } catch (Duplicate $th) {
            throw new Exception('Phone number already exists', 409, Exception::USER_PHONE_ALREADY_EXISTS);
        }

        $audits
            ->setResource('user/' . $user->getId())
            ->setUser($user)
        ;

        $usage->setParam('users.update', 1);
        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/prefs')
    ->desc('Update Account Preferences')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.prefs')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/account/update-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('prefs', [], new Assoc(), 'Prefs key-value JSON object.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (array $prefs, Response $response, Document $user, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('prefs', $prefs));

        $audits->setResource('user/' . $user->getId());
        $usage->setParam('users.update', 1);
        $events->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_USER);
    });

App::patch('/v1/account/status')
    ->desc('Update Account Status')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.status')
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/account/update-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function (Request $request, Response $response, Document $user, Database $dbForProject, Audit $audits, Event $events, Stats $usage) {

        $user = $dbForProject->updateDocument('users', $user->getId(), $user->setAttribute('status', false));

        $audits
            ->setResource('user/' . $user->getId())
            ->setPayload($response->output($user, Response::MODEL_USER));

        $events
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_USER));

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([]));
        }

        $usage->setParam('users.delete', 1);

        $response->dynamic($user, Response::MODEL_USER);
    });

App::delete('/v1/account/sessions/:sessionId')
    ->desc('Delete Account Session')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/account/delete-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->param('sessionId', null, new UID(), 'Session ID. Use the string \'current\' to delete the current device session.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function (?string $sessionId, Request $request, Response $response, Document $user, Database $dbForProject, Locale $locale, Audit $audits, Event $events, Stats $usage) {

        $protocol = $request->getProtocol();
        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
            : $sessionId;

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {
                unset($sessions[$key]);

                $dbForProject->deleteDocument('sessions', $session->getId());

                $audits->setResource('user/' . $user->getId());

                $session->setAttribute('current', false);

                if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                    $session
                        ->setAttribute('current', true)
                        ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')))
                    ;

                    if (!Config::getParam('domainVerification')) {
                        $response
                            ->addHeader('X-Fallback-Cookies', \json_encode([]))
                        ;
                    }

                    $response
                        ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                        ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
                    ;
                }

                $dbForProject->deleteCachedDocument('users', $user->getId());

                $events
                    ->setParam('userId', $user->getId())
                    ->setParam('sessionId', $session->getId())
                    ->setPayload($response->output($session, Response::MODEL_SESSION))
                ;

                $usage
                    ->setParam('users.sessions.delete', 1)
                    ->setParam('users.update', 1)
                ;
                return $response->noContent();
            }
        }

        throw new Exception('Session not found', 404, Exception::USER_SESSION_NOT_FOUND);
    });

App::patch('/v1/account/sessions/:sessionId')
    ->desc('Update Session (Refresh Tokens)')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateSession')
    ->label('sdk.description', '/docs/references/account/update-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->param('sessionId', null, new UID(), 'Session ID. Use the string \'current\' to update the current device session.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function (?string $sessionId, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Audit $audits, Event $events, Stats $usage) {

        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
            : $sessionId;

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            if ($sessionId == $session->getId()) {
                // Comment below would skip re-generation if token is still valid
                // We decided to not include this because developer can get expiration date from the session
                // I kept code in comment because it might become relevant in the future

                // $expireAt = (int) $session->getAttribute('providerAccessTokenExpiry');
                // if(\time() < $expireAt - 5) { // 5 seconds time-sync and networking gap, to be safe
                //     return $response->noContent();
                // }

                $provider = $session->getAttribute('provider');
                $refreshToken = $session->getAttribute('providerRefreshToken');

                $appId = $project->getAttribute('authProviders', [])[$provider . 'Appid'] ?? '';
                $appSecret = $project->getAttribute('authProviders', [])[$provider . 'Secret'] ?? '{}';

                $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

                if (!\class_exists($className)) {
                    throw new Exception('Provider is not supported', 501, Exception::PROJECT_PROVIDER_UNSUPPORTED);
                }

                $oauth2 = new $className($appId, $appSecret, '', [], []);

                $oauth2->refreshTokens($refreshToken);

                $session
                    ->setAttribute('providerAccessToken', $oauth2->getAccessToken(''))
                    ->setAttribute('providerRefreshToken', $oauth2->getRefreshToken(''))
                    ->setAttribute('providerAccessTokenExpiry', \time() + (int) $oauth2->getAccessTokenExpiry(''));

                $dbForProject->updateDocument('sessions', $sessionId, $session);

                $dbForProject->deleteCachedDocument('users', $user->getId());

                $audits->setResource('user/' . $user->getId());

                $events
                    ->setParam('userId', $user->getId())
                    ->setParam('sessionId', $session->getId())
                    ->setPayload($response->output($session, Response::MODEL_SESSION))
                ;

                $usage
                    ->setParam('users.sessions.update', 1)
                    ->setParam('users.update', 1)
                ;

                return $response->dynamic($session, Response::MODEL_SESSION);
            }
        }

        throw new Exception('Session not found', 404, Exception::USER_SESSION_NOT_FOUND);
    });

App::delete('/v1/account/sessions')
    ->desc('Delete All Account Sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSessions')
    ->label('sdk.description', '/docs/references/account/delete-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function (Request $request, Response $response, Document $user, Database $dbForProject, Locale $locale, Audit $audits, Event $events, Stats $usage) {

        $protocol = $request->getProtocol();
        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $session) {/** @var Document $session */
            $dbForProject->deleteDocument('sessions', $session->getId());

            $audits->setResource('user/' . $user->getId());

            if (!Config::getParam('domainVerification')) {
                $response->addHeader('X-Fallback-Cookies', \json_encode([]));
            }

            $session
                ->setAttribute('current', false)
                ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')))
            ;

            if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) {
                $session->setAttribute('current', true);

                 // If current session delete the cookies too
                $response
                    ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                    ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'));

                // Use current session for events.
                $events->setPayload($response->output($session, Response::MODEL_SESSION));
            }
        }

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $numOfSessions = count($sessions);

        $events
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId());

        $usage
            ->setParam('users.sessions.delete', $numOfSessions)
            ->setParam('users.update', 1)
        ;

        $response->noContent();
    });

App::post('/v1/account/recovery')
    ->desc('Create Password Recovery')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].recovery.[tokenId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createRecovery')
    ->label('sdk.description', '/docs/references/account/create-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},email:{param-email}', 'ip:{ip}'])
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', fn ($clients) => new Host($clients), 'URL to redirect the user back to your app from the recovery email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('mails')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->action(function (string $email, string $url, Request $request, Response $response, Database $dbForProject, Document $project, Locale $locale, Mail $mails, Audit $audits, Event $events, Stats $usage) {

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('SMTP Disabled', 503, Exception::GENERAL_SMTP_DISABLED);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        $useEmail = App::getEnv('_APP_SMTP_USE_EMAIL', true);

        $email = \strtolower($email);

        $profile = $dbForProject->findOne('users', [
            new Query('email', Query::TYPE_EQUAL, [$email])
        ]);

        if (!$profile) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception('Invalid credentials. User is blocked', 401, Exception::USER_BLOCKED);
        }

        $expire = \time() + Auth::TOKEN_EXPIRATION_RECOVERY;

        $secret = Auth::tokenGenerator();
        $recovery = new Document([
            '$id' => $dbForProject->getId(),
            'userId' => $profile->getId(),
            'userInternalId' => $profile->getInternalId(),
            'type' => Auth::TOKEN_TYPE_RECOVERY,
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole('user:' . $profile->getId());

        $recovery = $dbForProject->createDocument('tokens', $recovery
            ->setAttribute('$read', ['user:' . $profile->getId()])
            ->setAttribute('$write', ['user:' . $profile->getId()]));

        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $profile->getId(), 'secret' => $secret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        if ($useEmail) {
            $mails
                ->setType(MAIL_TYPE_RECOVERY)
                ->setRecipient($profile->getAttribute('email', ''))
                ->setUrl($url)
                ->setLocale($locale->default)
                ->setName($profile->getAttribute('name'))
                ->trigger();
            ;
        }

        $events
            ->setParam('userId', $profile->getId())
            ->setParam('tokenId', $recovery->getId())
            ->setUser($profile)
            ->setPayload($response->output(
                $recovery->setAttribute('secret', $secret),
                Response::MODEL_TOKEN
            ))
        ;

        // Hide secret for clients
        $recovery->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $secret : '');

        $audits->setResource('user/' . $profile->getId());
        $usage->setParam('users.update', 1);

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($recovery, Response::MODEL_TOKEN);
    });

App::put('/v1/account/recovery')
    ->desc('Create Password Recovery (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].recovery.[tokenId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateRecovery')
    ->label('sdk.description', '/docs/references/account/update-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid reset token.')
    ->param('password', '', new Password(), 'New user password. Must be at least 8 chars.')
    ->param('passwordAgain', '', new Password(), 'Repeat new user password. Must be at least 8 chars.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, string $secret, string $password, string $passwordAgain, Response $response, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        if ($password !== $passwordAgain) {
            throw new Exception('Passwords must match', 400, Exception::USER_PASSWORD_MISMATCH);
        }

        $profile = $dbForProject->getDocument('users', $userId);

        if ($profile->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $tokens = $profile->getAttribute('tokens', []);
        $recovery = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_RECOVERY, $secret);

        if (!$recovery) {
            throw new Exception('Invalid recovery token', 401, Exception::USER_INVALID_TOKEN);
        }

        Authorization::setRole('user:' . $profile->getId());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile
                ->setAttribute('password', Auth::passwordHash($password))
                ->setAttribute('passwordUpdate', \time())
                ->setAttribute('emailVerification', true));

        $recoveryDocument = $dbForProject->getDocument('tokens', $recovery);

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $recovery);
        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $audits->setResource('user/' . $profile->getId());

        $usage->setParam('users.update', 1);

        $events
            ->setParam('userId', $profile->getId())
            ->setParam('tokenId', $recoveryDocument->getId())
        ;

        $response->dynamic($recoveryDocument, Response::MODEL_TOKEN);
    });

App::post('/v1/account/verification')
    ->desc('Create Email Verification')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createVerification')
    ->label('sdk.description', '/docs/references/account/create-email-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->param('url', '', fn($clients) => new Host($clients), 'URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add built-in confirm page
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('audits')
    ->inject('events')
    ->inject('mails')
    ->inject('usage')
    ->action(function (string $url, Request $request, Response $response, Document $project, Document $user, Database $dbForProject, Locale $locale, Audit $audits, Event $events, Mail $mails, Stats $usage) {

        if (empty(App::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception('SMTP Disabled', 503, Exception::GENERAL_SMTP_DISABLED);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        $useEmail = App::getEnv('_APP_SMTP_USE_EMAIL', true);

        $verificationSecret = Auth::tokenGenerator();

        $expire = \time() + Auth::TOKEN_EXPIRATION_CONFIRM;

        $verification = new Document([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_VERIFICATION,
            'secret' => Auth::hash($verificationSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole('user:' . $user->getId());

        $verification = $dbForProject->createDocument('tokens', $verification
            ->setAttribute('$read', ['user:' . $user->getId()])
            ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $verificationSecret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        if ($useEmail) {
            $mails
                ->setType(MAIL_TYPE_VERIFICATION)
                ->setRecipient($user->getAttribute('email'))
                ->setUrl($url)
                ->setLocale($locale->default)
                ->setName($user->getAttribute('name'))
                ->trigger()
            ;
        }

        $events
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->output(
                $verification->setAttribute('secret', $verificationSecret),
                Response::MODEL_TOKEN
            ))
        ;

        // Hide secret for clients
        $verification->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $verificationSecret : '');

        $audits->setResource('user/' . $user->getId());
        $usage->setParam('users.update', 1);

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($verification, Response::MODEL_TOKEN);
    });

App::put('/v1/account/verification')
    ->desc('Create Email Verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateVerification')
    ->label('sdk.description', '/docs/references/account/update-email-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, string $secret, Response $response, Document $user, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $tokens = $profile->getAttribute('tokens', []);
        $verification = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_VERIFICATION, $secret);

        if (!$verification) {
            throw new Exception('Invalid verification token', 401, Exception::USER_INVALID_TOKEN);
        }

        Authorization::setRole('user:' . $profile->getId());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('emailVerification', true));

        $verificationDocument = $dbForProject->getDocument('tokens', $verification);

        /**
         * We act like we're updating and validating
         *  the verification token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $verification);
        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $audits->setResource('user/' . $user->getId());

        $usage->setParam('users.update', 1);

        $events
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verificationDocument->getId())
        ;

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    });

App::post('/v1/account/verification/phone')
    ->desc('Create Phone Verification')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createPhoneVerification')
    ->label('sdk.description', '/docs/references/account/create-phone-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'userId:{userId}')
    ->inject('request')
    ->inject('response')
    ->inject('phone')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('events')
    ->inject('usage')
    ->inject('messaging')
    ->action(function (Request $request, Response $response, Phone $phone, Document $user, Database $dbForProject, Audit $audits, Event $events, Stats $usage, EventPhone $messaging) {

        if (empty(App::getEnv('_APP_PHONE_PROVIDER'))) {
            throw new Exception('Phone provider not configured', 503, Exception::GENERAL_PHONE_DISABLED);
        }

        if (empty($user->getAttribute('phone'))) {
            throw new Exception('User has no phone number.', 400, Exception::USER_PHONE_NOT_FOUND);
        }

        $roles = Authorization::getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $verificationSecret = Auth::tokenGenerator();

        $secret = $phone->generateSecretDigits();
        $expire = \time() + Auth::TOKEN_EXPIRATION_CONFIRM;

        $verification = new Document([
            '$id' => $dbForProject->getId(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_PHONE,
            'secret' => $secret,
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        Authorization::setRole('user:' . $user->getId());

        $verification = $dbForProject->createDocument('tokens', $verification
            ->setAttribute('$read', ['user:' . $user->getId()])
            ->setAttribute('$write', ['user:' . $user->getId()]));

        $dbForProject->deleteCachedDocument('users', $user->getId());

        $messaging
            ->setRecipient($user->getAttribute('phone'))
            ->setMessage($secret)
            ->trigger()
        ;

        $events
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->output(
                $verification->setAttribute('secret', $verificationSecret),
                Response::MODEL_TOKEN
            ))
        ;

        // Hide secret for clients
        $verification->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $verificationSecret : '');

        $audits->setResource('user/' . $user->getId());
        $usage->setParam('users.update', 1);

        $response->setStatusCode(Response::STATUS_CODE_CREATED);
        $response->dynamic($verification, Response::MODEL_TOKEN);
    });

App::put('/v1/account/verification/phone')
    ->desc('Create Phone Verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePhoneVerification')
    ->label('sdk.description', '/docs/references/account/update-phone-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'userId:{param-userId}')
    ->param('userId', '', new UID(), 'User ID.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('audits')
    ->inject('usage')
    ->inject('events')
    ->action(function (string $userId, string $secret, Response $response, Document $user, Database $dbForProject, Audit $audits, Stats $usage, Event $events) {

        $profile = Authorization::skip(fn() => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception('User not found', 404, Exception::USER_NOT_FOUND);
        }

        $verification = Auth::phoneTokenVerify($user->getAttribute('tokens', []), $secret);

        if (!$verification) {
            throw new Exception('Invalid verification token', 401, Exception::USER_INVALID_TOKEN);
        }

        Authorization::setRole('user:' . $profile->getId());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('phoneVerification', true));

        $verificationDocument = $dbForProject->getDocument('tokens', $verification);

        /**
         * We act like we're updating and validating the verification token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $verification);
        $dbForProject->deleteCachedDocument('users', $profile->getId());

        $audits->setResource('user/' . $user->getId());

        $usage->setParam('users.update', 1);

        $events
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verificationDocument->getId())
        ;

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    });
