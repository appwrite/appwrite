<?php

global $utopia, $register, $request, $response, $user, $audit, $webhook, $project, $domain, $projectDB, $providers, $clients;

use Utopia\Exception;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\Email;
use Utopia\Validator\Host;
use Utopia\Validator\URL;
use Utopia\Locale\Locale;
use Auth\Auth;
use Auth\Validator\Password;
use Database\Database;
use Database\Document;
use Database\Validator\Authorization;
use Database\Validator\UID;
use Template\Template;
use OpenSSL\OpenSSL;

include_once __DIR__ . '/../shared/api.php';

$utopia->post('/v1/auth/register')
    ->desc('Register')
    ->label('webhook', 'auth.register')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'register')
    ->label('sdk.description', '/docs/references/auth/register.md')
    ->label('sdk.cookies', true)
    ->label('abuse-limit', 10)
    ->param('email', '', function () { return new Email(); }, 'Account email')
    ->param('password', '', function () { return new Password(); }, 'User password')
    ->param('confirm', '', function () use ($clients) { return new Host($clients); }, 'Confirmation URL to redirect user after confirm token has been sent to user email') // TODO add our own built-in confirm page
    ->param('success', null, function () use ($clients) { return new Host($clients); }, 'Redirect when registration succeed', true)
    ->param('failure', null, function () use ($clients) { return new Host($clients); }, 'Redirect when registration failed', true)
    ->param('name', '', function () { return new Text(100); }, 'User name', true)
    ->action(
        function ($email, $password, $confirm, $success, $failure, $name) use ($request, $response, $register, $audit, $projectDB, $project, $webhook) {
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
                if ($failure) {
                    $response->redirect($failure); // .'?message=User already registered'

                    return;
                }

                throw new Exception('User already registered', 400);
            }

            $expiry = time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
            $confirmSecret = Auth::tokenGenerator();
            $loginSecret = Auth::tokenGenerator();

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

            Authorization::setRole('user:'.$user->getUid());

            $user
                ->setAttribute('tokens', new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    '$permissions' => ['read' => ['user:'.$user->getUid()], 'write' => ['user:'.$user->getUid()]],
                    'type' => Auth::TOKEN_TYPE_VERIFICATION,
                    'secret' => Auth::hash($confirmSecret), // On way hash encryption to protect DB leak
                    'expire' => time() + Auth::TOKEN_EXPIRATION_CONFIRM,
                    'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                    'ip' => $request->getIP(),
                ]), Document::SET_TYPE_APPEND)
                ->setAttribute('tokens', new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    '$permissions' => ['read' => ['user:'.$user->getUid()], 'write' => ['user:'.$user->getUid()]],
                    'type' => Auth::TOKEN_TYPE_LOGIN,
                    'secret' => Auth::hash($loginSecret), // On way hash encryption to protect DB leak
                    'expire' => $expiry,
                    'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                    'ip' => $request->getIP(),
                ]), Document::SET_TYPE_APPEND)
            ;

            $user = $projectDB->createDocument($user->getArrayCopy());

            if (false === $user) {
                throw new Exception('Failed saving tokens to DB', 500);
            }

            // Send email address confirmation email

            $confirm = Template::parseURL($confirm);
            $confirm['query'] = Template::mergeQuery(((isset($confirm['query'])) ? $confirm['query'] : ''), ['userId' => $user->getUid(), 'token' => $confirmSecret]);
            $confirm = Template::unParseURL($confirm);

            $body = new Template(__DIR__.'/../../config/locales/templates/'.Locale::getText('auth.emails.confirm.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{name}}', $name)
                ->setParam('{{redirect}}', $confirm)
            ;

            $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */

            $mail->addAddress($email, $name);

            $mail->Subject = Locale::getText('auth.emails.confirm.title');
            $mail->Body = $body->render();
            $mail->AltBody = strip_tags($body->render());

            try {
                $mail->send();
            } catch (\Exception $error) {
                // if($failure) {
                //     $response->redirect($failure);
                //     return;
                // }

                // throw new Exception('Problem sending mail: ' . $error->getMessage(), 500);
            }

            $webhook
                ->setParam('payload', [
                    'name' => $name,
                    'email' => $email,
                ])
            ;

            $audit
                ->setParam('userId', $user->getUid())
                ->setParam('event', 'auth.register')
            ;

            $response
                ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getUid(), $loginSecret), $expiry, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null);

            if ($success) {
                $response->redirect($success);
            }

            $response->json(array('result' => 'success'));
        }
    );

$utopia->post('/v1/auth/register/confirm')
    ->desc('Confirmation')
    ->label('webhook', 'auth.confirm')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'confirm')
    ->label('sdk.description', '/docs/references/auth/confirm.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', function () { return new UID(); }, 'User unique ID')
    ->param('token', '', function () { return new Text(256); }, 'Confirmation secret token')
    ->action(
        function ($userId, $token) use ($response, $request, $projectDB, $audit) {
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

            $token = Auth::tokenVerify($profile->getAttribute('tokens', []), Auth::TOKEN_TYPE_VERIFICATION, $token);

            if (!$token) {
                throw new Exception('Confirmation token is not valid', 401);
            }

            $profile = $projectDB->updateDocument(array_merge($profile->getArrayCopy(), [
                'status' => Auth::USER_STATUS_ACTIVATED,
                'confirm' => true,
            ]));

            if (false === $profile) {
                throw new Exception('Failed saving user to DB', 500);
            }

            if (!$projectDB->deleteDocument($token)) {
                throw new Exception('Failed to remove token from DB', 500);
            }

            $audit->setParam('event', 'auth.confirm');

            $response->json(array('result' => 'success'));
        }
    );

$utopia->post('/v1/auth/register/confirm/resend')
    ->desc('Resend Confirmation')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'confirmResend')
    ->label('sdk.description', '/docs/references/auth/confirm-resend.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('confirm', '', function () use ($clients) { return new Host($clients); }, 'Confirmation URL to redirect user to your app after confirm token has been sent to user email.')
    ->action(
        function ($confirm) use ($response, $request, $projectDB, $user, $register, $project) {
            if ($user->getAttribute('confirm', false)) {
                throw new Exception('Email address is already confirmed', 400);
            }

            $secret = Auth::tokenGenerator();

            $user->setAttribute('tokens', new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:'.$user->getUid()], 'write' => ['user:'.$user->getUid()]],
                'type' => Auth::TOKEN_TYPE_VERIFICATION,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'expire' => time() + Auth::TOKEN_EXPIRATION_CONFIRM,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]), Document::SET_TYPE_APPEND);

            $user = $projectDB->updateDocument($user->getArrayCopy());

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $confirm = Template::parseURL($confirm);
            $confirm['query'] = Template::mergeQuery(((isset($confirm['query'])) ? $confirm['query'] : ''), ['userId' => $user->getUid(), 'token' => $secret]);
            $confirm = Template::unParseURL($confirm);

            $body = new Template(__DIR__.'/../../config/locales/templates/'.Locale::getText('auth.emails.confirm.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{name}}', $user->getAttribute('name'))
                ->setParam('{{redirect}}', $confirm)
            ;

            $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */

            $mail->addAddress($user->getAttribute('email'), $user->getAttribute('name'));

            $mail->Subject = Locale::getText('auth.emails.confirm.title');
            $mail->Body = $body->render();
            $mail->AltBody = strip_tags($body->render());

            try {
                $mail->send();
            } catch (\Exception $error) {
                //throw new Exception('Problem sending mail: ' . $error->getMessage(), 500);
            }

            $response->json(array('result' => 'success'));
        }
    );

$utopia->post('/v1/auth/login')
    ->desc('Login')
    ->label('webhook', 'auth.login')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'login')
    ->label('sdk.description', '/docs/references/auth/login.md')
    ->label('sdk.cookies', true)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', function () { return new Email(); }, 'User account email address')
    ->param('password', '', function () { return new Password(); }, 'User account password')
    ->param('success', null, function () use ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a successful login attempt.', true)
    ->param('failure', null, function () use ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a failed login attempt.', true)
    ->action(
        function ($email, $password, $success, $failure) use ($response, $request, $projectDB, $audit, $webhook) {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection='.Database::SYSTEM_COLLECTION_USERS,
                    'email='.$email,
                ],
            ]);

            if (!$profile || !Auth::passwordVerify($password, $profile->getAttribute('password'))) {
                $audit
                    //->setParam('userId', $profile->getUid())
                    ->setParam('event', 'auth.failure')
                ;

                if ($failure) {
                    $response->redirect($failure);

                    return;
                }

                throw new Exception('Invalid credentials', 401); // Wrong password or username
            }

            $expiry = time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
            $secret = Auth::tokenGenerator();

            $profile->setAttribute('tokens', new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:'.$profile->getUid()], 'write' => ['user:'.$profile->getUid()]],
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]), Document::SET_TYPE_APPEND);

            Authorization::setRole('user:'.$profile->getUid());

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
                ->setParam('event', 'auth.login')
            ;

            $response
                ->addCookie(Auth::$cookieName, Auth::encodeSession($profile->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null);

            if ($success) {
                $response->redirect($success);
            }

            $response
                ->json(array('result' => 'success'));
        }
    );

$utopia->get('/v1/auth/login/oauth/:provider')
    ->desc('Login with OAuth')
    ->label('error', __DIR__.'/../views/general/error.phtml')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'oauth')
    ->label('sdk.description', '/docs/references/auth/login-oauth.md')
    ->label('sdk.location', true)
    ->label('sdk.cookies', true)
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', function () use ($providers) { return new WhiteList(array_keys($providers)); }, 'OAuth Provider. Currently, supported providers are: ' . implode(', ', array_keys($providers)))
    ->param('success', '', function () use ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a successful login attempt.')
    ->param('failure', '', function () use ($clients) { return new Host($clients); }, 'URL to redirect back to your app after a failed login attempt.')
    ->action(
        function ($provider, $success, $failure) use ($response, $request, $project) {
            $callback = $request->getServer('REQUEST_SCHEME', 'https').'://'.$request->getServer('HTTP_HOST').'/v1/auth/login/oauth/callback/'.$provider.'/'.$project->getUid();
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

$utopia->get('/v1/auth/login/oauth/callback/:provider/:projectId')
    ->desc('OAuth Callback')
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('scope', 'auth')
    ->label('docs', false)
    ->param('projectId', '', function () { return new Text(1024); }, 'Project unique ID')
    ->param('provider', '', function () use ($providers) { return new WhiteList(array_keys($providers)); }, 'OAuth provider')
    ->param('code', '', function () { return new Text(1024); }, 'OAuth code')
    ->param('state', '', function () { return new Text(2048); }, 'Login state params', true)
    ->action(
        function ($projectId, $provider, $code, $state) use ($response, $request, $domain) {
            $response->redirect($request->getServer('REQUEST_SCHEME', 'https').'://'.$domain.'/v1/auth/login/oauth/'.$provider.'/redirect?'
                .http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
        }
    );

$utopia->get('/v1/auth/login/oauth/:provider/redirect')
    ->desc('OAuth Redirect')
    ->label('error', __DIR__.'/../../views/general/error.phtml')
    ->label('webhook', 'auth.oauth')
    ->label('scope', 'auth')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->label('docs', false)
    ->param('provider', '', function () use ($providers) { return new WhiteList(array_keys($providers)); }, 'OAuth provider')
    ->param('code', '', function () { return new Text(1024); }, 'OAuth code')
    ->param('state', '', function () { return new Text(2048); }, 'OAuth state params', true)
    ->action(
        function ($provider, $code, $state) use ($response, $request, $user, $projectDB, $project, $audit) {
            $callback = $request->getServer('REQUEST_SCHEME', 'https').'://'.$request->getServer('HTTP_HOST').'/v1/auth/login/oauth/callback/'.$provider.'/'.$project->getUid();
            $defaultState = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
            $validateURL = new URL();

            // Uncomment this while testing amazon oAuth
            // $state = html_entity_decode($state);

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

            // Create login token, confirm user account and update OAuth ID and Access Token

            $secret = Auth::tokenGenerator();
            $expiry = time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;

            $user
                ->setAttribute('oauth'.ucfirst($provider), $oauthID)
                ->setAttribute('oauth'.ucfirst($provider).'AccessToken', $accessToken)
                ->setAttribute('status', Auth::USER_STATUS_ACTIVATED)
                ->setAttribute('tokens', new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    '$permissions' => ['read' => ['user:'.$user['$uid']], 'write' => ['user:'.$user['$uid']]],
                    'type' => Auth::TOKEN_TYPE_LOGIN,
                    'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                    'expire' => $expiry,
                    'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                    'ip' => $request->getIP(),
                ]), Document::SET_TYPE_APPEND)
            ;

            Authorization::setRole('user:'.$user->getUid());

            $user = $projectDB->updateDocument($user->getArrayCopy());

            if (false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit
                ->setParam('userId', $user->getUid())
                ->setParam('event', 'auth.oauth.login')
                ->setParam('data', ['provider' => $provider])
            ;

            $response
                ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null)
            ;

            $response->redirect($state['success']);
        }
    );

$utopia->delete('/v1/auth/logout')
    ->desc('Logout Current Session')
    ->label('webhook', 'auth.logout')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'logout')
    ->label('sdk.description', '/docs/references/auth/logout.md')
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

            $audit->setParam('event', 'auth.logout');

            $response
                ->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null)
                ->json(array('result' => 'success'))
            ;
        }
    );

$utopia->delete('/v1/auth/logout/:id')
    ->desc('Logout Specific Session')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'logoutBySession')
    ->label('sdk.description', '/docs/references/auth/logout-by-session.md')
    ->label('abuse-limit', 100)
    ->param('id', null, function () { return new UID(); }, 'User specific session unique ID number. if 0 delete all sessions.')
    ->action(
        function ($id) use ($response, $request, $user, $projectDB, $audit) {
            $tokens = $user->getAttribute('tokens', []);

            foreach ($tokens as $token) { /* @var $token Document */
                if (($id == $token->getUid() || ($id == 0)) && Auth::TOKEN_TYPE_LOGIN == $token->getAttribute('type')) {
                    if (!$projectDB->deleteDocument($token->getUid())) {
                        throw new Exception('Failed to remove token from DB', 500);
                    }

                    $audit
                        ->setParam('event', 'auth.logout')
                        ->setParam('resource', '/auth/token/'.$token->getUid())
                    ;

                    if ($token->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete cookies
                        $response->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == $request->getServer('REQUEST_SCHEME', 'https')), true, null);
                    }
                }
            }

            $response->json(array('result' => 'success'));
        }
    );

$utopia->post('/v1/auth/recovery')
    ->desc('Password Recovery')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'recovery')
    ->label('sdk.description', '/docs/references/auth/recovery.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', function () { return new Email(); }, 'User account email address.')
    ->param('reset', '', function () use ($clients) { return new Host($clients); }, 'Reset URL in your app to redirect the user after the reset token has been sent to the user email.')
    ->action(
        function ($email, $reset) use ($request, $response, $projectDB, $register, $audit, $project) {
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

            $profile->setAttribute('tokens', new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:'.$profile->getUid()], 'write' => ['user:'.$profile->getUid()]],
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                'expire' => time() + Auth::TOKEN_EXPIRATION_RECOVERY,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]), Document::SET_TYPE_APPEND);

            Authorization::setRole('user:'.$profile->getUid());

            $profile = $projectDB->updateDocument($profile->getArrayCopy());

            if (false === $profile) {
                throw new Exception('Failed to save user to DB', 500);
            }

            $reset = Template::parseURL($reset);
            $reset['query'] = Template::mergeQuery(((isset($reset['query'])) ? $reset['query'] : ''), ['userId' => $profile->getUid(), 'token' => $secret]);
            $reset = Template::unParseURL($reset);

            $body = new Template(__DIR__.'/../../config/locales/templates/'.Locale::getText('auth.emails.recovery.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{name}}', $profile->getAttribute('name'))
                ->setParam('{{redirect}}', $reset)
            ;

            $mail = $register->get('smtp'); /* @var $mail \PHPMailer\PHPMailer\PHPMailer */

            $mail->addAddress($profile->getAttribute('email', ''), $profile->getAttribute('name', ''));

            $mail->Subject = Locale::getText('auth.emails.recovery.title');
            $mail->Body = $body->render();
            $mail->AltBody = strip_tags($body->render());

            try {
                $mail->send();
            } catch (\Exception $error) {
                //throw new Exception('Problem sending mail: ' . $error->getMessage(), 500);
            }

            $audit
                ->setParam('userId', $profile->getUid())
                ->setParam('event', 'auth.recovery')
            ;

            $response->json(array('result' => 'success'));
        }
    );

$utopia->put('/v1/auth/recovery/reset')
    ->desc('Password Reset')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'recoveryReset')
    ->label('sdk.description', '/docs/references/auth/recovery-reset.md')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', function () { return new UID(); }, 'User account email address.')
    ->param('token', '', function () { return new Text(256); }, 'Valid reset token.')
    ->param('password-a', '', function () { return new Password(); }, 'New password.')
    ->param('password-b', '', function () {return new Password(); }, 'New password again.')
    ->action(
        function ($userId, $token, $passwordA, $passwordB) use ($response, $projectDB, $audit) {
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

            $token = Auth::tokenVerify($profile->getAttribute('tokens', []), Auth::TOKEN_TYPE_RECOVERY, $token);

            if (!$token) {
                throw new Exception('Recovery token is not valid', 401);
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

            if (!$projectDB->deleteDocument($token)) {
                throw new Exception('Failed to remove token from DB', 500);
            }

            $audit
                ->setParam('userId', $profile->getUid())
                ->setParam('event', 'auth.recovery.reset')
            ;

            $response->json(array('result' => 'success'));
        }
    );