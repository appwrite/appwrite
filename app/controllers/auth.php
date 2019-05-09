
<?php

global $utopia, $register, $request, $response, $user, $audit, $webhook, $project, $domain, $projectDB, $providers;

use Utopia\Exception;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Text;
use Utopia\Validator\Email;
use Utopia\Validator\Host;
use Utopia\Validator\URL;
use Utopia\Locale\Locale;
use Auth\Auth;
use Auth\OAuth\Bitbucket;
use Auth\OAuth\Facebook;
use Auth\OAuth\GitHub;
use Auth\OAuth\Gitlab;
use Auth\OAuth\Google;
use Auth\OAuth\Instagram;
use Auth\OAuth\LinkedIn;
use Auth\OAuth\Microsoft;
use Auth\OAuth\Twitter;
use Auth\Validator\Password;
use Database\Database;
use Database\Document;
use Database\Validator\Authorization;
use Database\Validator\UID;
use Template\Template;
use OpenSSL\OpenSSL;

$utopia->post('/v1/auth/register')
    ->desc('Register User')
    ->label('webhook', 'auth.register')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'register')
    ->label('sdk.description', "Use this endpoint to allow a new user to register an account in your project. Use the success and failure URL's to redirect users back to your application after signup completes.\n\nIf registration completes successfully user will be sent with a confirmation email in order to confirm he is the owner of the account email address. Use the redirect parameter to redirect the user from the confirmation email back to your app. When the user is redirected, use the /auth/confirm endpoint to complete the account confirmation.\n\nPlease notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL's are the once from domains you have set when added your platforms in the console interface.\n\nWhen not using the success or failure redirect arguments this endpoint will result with a 200 status code and the user account object on success and with 401 status error on failure. This behavior was applied to help the web clients deal with browsers who don't allow to set 3rd party HTTP cookies needed for saving the account session token.")
    ->label('sdk.cookies', true)
    ->label('abuse-limit', 10)
    ->param('email', '', function () {return new Email();}, 'Account email')
    ->param('password', '', function () {return new Password();}, 'User password')
    ->param('name', '', function () {return new Text(100);}, 'User name', true)
    ->param('redirect', '', function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Confirmation page to redirect user after confirm token has been sent to user email')
    ->param('success', null, function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Redirect when registration succeed', true)
    ->param('failure', null, function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Redirect when registration failed', true)
    ->action(
        function($email, $password, $name, $redirect, $success, $failure) use ($request, $response, $register, $audit, $projectDB, $project, $webhook)
        {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                    'email=' . $email
                ]
            ]);

            if(!empty($profile)) {
                if($failure) {
                    $response->redirect($failure);
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

            if(false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            Authorization::setRole('user:' . $user->getUid());

            $user
                ->setAttribute('tokens', new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    '$permissions' => ['read' => ['user:' . $user->getUid()], 'write' => ['user:' . $user->getUid()]],
                    'type' => Auth::TOKEN_TYPE_CONFIRM,
                    'secret' => Auth::hash($confirmSecret), // On way hash encryption to protect DB leak
                    'expire' => time() + Auth::TOKEN_EXPIRATION_CONFIRM,
                    'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                    'ip' => $request->getIP(),
                ]),Document::SET_TYPE_APPEND)
                ->setAttribute('tokens', new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    '$permissions' => ['read' => ['user:' . $user->getUid()], 'write' => ['user:' . $user->getUid()]],
                    'type' => Auth::TOKEN_TYPE_LOGIN,
                    'secret' => Auth::hash($loginSecret), // On way hash encryption to protect DB leak
                    'expire' => $expiry,
                    'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                    'ip' => $request->getIP(),
                ]),Document::SET_TYPE_APPEND)
            ;

            $user = $projectDB->createDocument($user->getArrayCopy());

            if(false === $user) {
                throw new Exception('Failed saving tokens to DB', 500);
            }

            // Send email address confirmation email

            $redirect = Template::parseURL($redirect);
            $redirect['query'] = Template::mergeQuery(((isset($redirect['query'])) ? $redirect['query'] : ''), ['userId' => $user->getUid(), 'token' => $confirmSecret]);
            $redirect = Template::unParseURL($redirect);

            $body = new Template(__DIR__ . '/../config/locale/templates/' . Locale::getText('auth.emails.confirm.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{name}}', $name)
                ->setParam('{{redirect}}', $redirect)
            ;

            $mail = $register->get('mailgun'); /* @var $mail \MailgunLite\MailgunLite */

            $mail
                ->addRecipient($email, $name)
                ->setSubject(Locale::getText('auth.emails.confirm.title'))
                ->setText(strip_tags($body->render()))
                ->setHTML($body->render())
            ;

            if(!$mail->send()) {
                if($failure) {
                    $response->redirect($failure);
                    return;
                }

                throw new Exception('Problem sending mail: ' . $mail->getError(), 500);
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

            $response->addCookie(Auth::$cookieName, Auth::encodeSession($user->getUid(), $loginSecret), $expiry, '/', COOKIE_DOMAIN, ('https' == APP_PROTOCOL), true);

            if($success) {
                $response->redirect($success);
            }

            $response->json(array('result' => 'success'));
        }
    );

$utopia->post('/v1/auth/register/confirm')
    ->desc('Confirm User')
    ->label('webhook', 'auth.confirm')
    ->label('scope', 'public')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'confirm')
    ->label('sdk.description', "Use this endpoint to complete the confirmation of the user account email address. Both the **userId** and **token** arguments will be passed as query parameters to the redirect URL you have provided when sending your request to the /auth/register endpoint.")
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', function () {return new UID();}, 'User unique ID')
    ->param('token', '', function () {return new Text(256);}, 'Confirmation secret token')
    ->action(
        function($userId, $token) use ($response, $request, $projectDB, $audit)
        {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                    '$uid=' . $userId
                ]
            ]);

            if(empty($profile)) {
                throw new Exception('User not found', 404); // TODO maybe hide this
            }

            $token = Auth::tokenVerify($profile->getAttribute('tokens', []), Auth::TOKEN_TYPE_CONFIRM, $token);

            if(!$token) {
                throw new Exception('Confirmation token is not valid', 401);
            }

            $profile = $projectDB->updateDocument(array_merge($profile->getArrayCopy(), [
                'status' => Auth::USER_STATUS_ACTIVATED,
                'confirm' => true,
            ]));

            if(false === $profile) {
                throw new Exception('Failed saving user to DB', 500);
            }

            if(!$projectDB->deleteDocument($token)) {
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
    ->label('sdk.description', "This endpoint allows the user to request your app to resend him his email confirmation message. The redirect arguments acts the same way as in /auth/register endpoint.\n\nPlease notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL's are the once from domains you have set when added your platforms in the console interface.")
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('redirect', '', function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Confirmation page to redirect user to your app after confirm token has been sent to user email.')
    ->action(
        function($redirect) use ($response, $request, $projectDB, $user, $register, $project)
        {
            if($user->getAttribute('confirm', false)) {
                throw new Exception('Email address is already confirmed', 400);
            }

            $secret = Auth::tokenGenerator();

            $user->setAttribute('tokens', new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:' . $user->getUid()], 'write' => ['user:' . $user->getUid()]],
                'type' => Auth::TOKEN_TYPE_CONFIRM,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'expire' => time() + Auth::TOKEN_EXPIRATION_CONFIRM,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]), Document::SET_TYPE_APPEND);

            $user = $projectDB->updateDocument($user->getArrayCopy());

            if(false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $redirect = Template::parseURL($redirect);
            $redirect['query'] = Template::mergeQuery(((isset($redirect['query'])) ? $redirect['query'] : ''), ['userId' => $user->getUid(), 'token' => $secret]);
            $redirect = Template::unParseURL($redirect);

            $body = new Template(__DIR__ . '/../config/locale/templates/' . Locale::getText('auth.emails.confirm.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{name}}', $user->getAttribute('name'))
                ->setParam('{{redirect}}', $redirect)
            ;

            $mail = $register->get('mailgun'); /* @var $mail \MailgunLite\MailgunLite */

            $mail
                ->addRecipient($user->getAttribute('email'), $user->getAttribute('name'))
                ->setSubject(Locale::getText('auth.emails.confirm.title'))
                ->setText(strip_tags($body->render()))
                ->setHTML($body->render())
            ;

            if(!$mail->send()) {
                throw new Exception('Problem sending mail: ' . $mail->getError(), 500);
            }

            $response->json(array('result' => 'success'));
        }
    );

$utopia->post('/v1/auth/login')
    ->desc('Login User')
    ->label('webhook', 'auth.login')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'login')
    ->label('sdk.description', "Allow the user to login into his account by providing a valid email and password combination. Use the success and failure arguments to provide a redirect URL\'s back to your app when login is completed. \n\nPlease notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL's are the once from domains you have set when added your platforms in the console interface.\n\nWhen not using the success or failure redirect arguments this endpoint will result with a 200 status code and the user account object on success and with 401 status error on failure. This behavior was applied to help the web clients deal with browsers who don't allow to set 3rd party HTTP cookies needed for saving the account session token.")
    ->label('sdk.cookies', true)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', function () {return new Email();}, 'User account email address')
    ->param('password', '', function () {return new Password();}, 'User account password')
    ->param('success', null, function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'URL to redirect back to your app after a successful login attempt.', true)
    ->param('failure', null, function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'URL to redirect back to your app after a failed login attempt.', true)
    ->action(
        function($email, $password, $success, $failure) use ($response, $request, $projectDB, $audit, $webhook)
        {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                    'email=' . $email
                ]
            ]);

            if(!$profile || !Auth::passwordVerify($password, $profile->getAttribute('password'))) {

                $audit
                    //->setParam('userId', $profile->getUid())
                    ->setParam('event', 'auth.failure')
                ;

                if($failure) {
                    $response->redirect($failure);
                    return;
                }

                throw new Exception('Invalid credentials', 401); // Wrong password or username
            }

            $expiry = time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;
            $secret = Auth::tokenGenerator();

            $profile->setAttribute('tokens', new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:' . $profile->getUid()], 'write' => ['user:' . $profile->getUid()]],
                'type' => Auth::TOKEN_TYPE_LOGIN,
                'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                'expire' => $expiry,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]),Document::SET_TYPE_APPEND);

            Authorization::setRole('user:' . $profile->getUid());

            $profile = $projectDB->updateDocument($profile->getArrayCopy());

            if(false === $profile) {
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
                ->addCookie(Auth::$cookieName, Auth::encodeSession($profile->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == APP_PROTOCOL), true);

            if($success) {
                $response->redirect($success);
            }

            $response
                ->json(array('result' => 'success'));
            ;
        }
    );

$utopia->delete('/v1/auth/logout')
    ->desc('Logout Current Session')
    ->label('webhook', 'auth.logout')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'logout')
    ->label('sdk.description', 'Use this endpoint to log out the currently logged in user from his account. When succeed this endpoint will delete the user session and remove the session secret cookie.')
    ->label('abuse-limit', 100)
    ->action(
        function() use ($response, $request, $user, $projectDB, $audit, $webhook)
        {
            $token = Auth::tokenVerify($user->getAttribute('tokens'), Auth::TOKEN_TYPE_LOGIN, Auth::$secret);

            if(!$projectDB->deleteDocument($token)) {
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
                ->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == APP_PROTOCOL), true)
                ->json(array('result' => 'success'))
            ;
        }
    );

$utopia->delete('/v1/auth/logout/:userId')
    ->desc('Logout Specific Session')
    ->label('scope', 'account')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'logoutBySession')
    ->label('sdk.description', 'Use this endpoint to log out the currently logged in user from all his account sessions across all his different devices. When using the option id argument, only the session unique ID provider will be deleted.')
    ->label('abuse-limit', 100)
    ->param('userId', null, function () {return new UID();}, 'User specific session unique ID number. if 0 delete all sessions.')
    ->action(
        function($userId) use ($response, $request, $user, $projectDB, $audit)
        {
            $tokens = $user->getAttribute('tokens', []);

            foreach($tokens as $token) { /* @var $token Document */
                if(($userId == $token->getUid() || ($userId == 0)) && Auth::TOKEN_TYPE_LOGIN == $token->getAttribute('type')) {

                    if(!$projectDB->deleteDocument($token->getUid())) {
                        throw new Exception('Failed to remove token from DB', 500);
                    }

                    $audit
                        ->setParam('event', 'auth.logout')
                        ->setParam('resource', '/auth/token/' . $token->getUid())
                    ;

                    if($token->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete cookies
                        $response->addCookie(Auth::$cookieName, '', time() - 3600, '/', COOKIE_DOMAIN, ('https' == APP_PROTOCOL), true);
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
    ->label('sdk.description', 'Sends the user an email with a temporary secret token for password reset. When the user clicks the confirmation link he is redirected back to your app password reset redirect URL with a secret token and email address values attached to the URL query string. Use the query string params to submit a request to the /auth/password/reset endpoint to complete the process.')
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', function () {return new Email();}, 'User account email address.')
    ->param('redirect', '', function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'Reset page in your app to redirect user after reset token has been sent to user email.')
    ->action(
        function($email, $redirect) use ($request, $response, $projectDB, $register, $audit, $project)
        {
            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                    'email=' . $email
                ]
            ]);

            if(empty($profile)) {
                throw new Exception('User not found', 404); // TODO maybe hide this
            }

            $secret = Auth::tokenGenerator();

            $profile->setAttribute('tokens', new Document([
                '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                '$permissions' => ['read' => ['user:' . $profile->getUid()], 'write' => ['user:' . $profile->getUid()]],
                'type' => Auth::TOKEN_TYPE_RECOVERY,
                'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                'expire' => time() + Auth::TOKEN_EXPIRATION_RECOVERY,
                'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                'ip' => $request->getIP(),
            ]), Document::SET_TYPE_APPEND);

            Authorization::setRole('user:' . $profile->getUid());

            $profile = $projectDB->updateDocument($profile->getArrayCopy());

            if(false === $profile) {
                throw new Exception('Failed to save user to DB', 500);
            }

            $redirect = Template::parseURL($redirect);
            $redirect['query'] = Template::mergeQuery(((isset($redirect['query'])) ? $redirect['query'] : ''), ['userId' => $profile->getUid(), 'token' => $secret]);
            $redirect = Template::unParseURL($redirect);

            $body = new Template(__DIR__ . '/../config/locale/templates/' . Locale::getText('auth.emails.recovery.body'));
            $body
                ->setParam('{{direction}}', Locale::getText('settings.direction'))
                ->setParam('{{project}}', $project->getAttribute('name', ['[APP-NAME]']))
                ->setParam('{{name}}', $profile->getAttribute('name'))
                ->setParam('{{redirect}}', $redirect)
            ;

            $mail = $register->get('mailgun'); /* @var $mail \MailgunLite\MailgunLite */

            $mail
                ->addRecipient($profile->getAttribute('email', ''), $profile->getAttribute('name', ''))
                ->setSubject(Locale::getText('auth.emails.recovery.title'))
                ->setText(strip_tags($body->render()))
                ->setHTML($body->render())
            ;

            if(!$mail->send()) {
                throw new Exception('Problem sending mail: ' . $mail->getError(), 500);
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
    ->label('sdk.description', "Use this endpoint to complete the user account password reset. Both the **userId** and **token** arguments will be passed as query parameters to the redirect URL you have provided when sending your request to the /auth/recovery endpoint.\n\nPlease notice that in order to avoid a [Redirect Attacks](https://github.com/OWASP/CheatSheetSeries/blob/master/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.md) the only valid redirect URL's are the once from domains you have set when added your platforms in the console interface.")
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{param-userId}')
    ->param('userId', '', function () {return new UID();}, 'User account email address.')
    ->param('token', '', function () {return new Text(256);}, 'Valid reset token.')
    ->param('password-a', '', function () {return new Password();}, 'New password.')
    ->param('password-b', '', function () {return new Password();}, 'New password again.')
    ->action(
        function($userId, $token, $passwordA, $passwordB) use ($response, $projectDB, $audit)
        {
            if($passwordA !== $passwordB) {
                throw new Exception('Passwords must match', 400);
            }

            $profile = $projectDB->getCollection([ // Get user by email address
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                    '$uid=' . $userId
                ]
            ]);

            if(empty($profile)) {
                throw new Exception('User not found', 404); // TODO maybe hide this
            }

            $token = Auth::tokenVerify($profile->getAttribute('tokens', []), Auth::TOKEN_TYPE_RECOVERY, $token);

            if(!$token) {
                throw new Exception('Recovery token is not valid', 401);
            }

            Authorization::setRole('user:' . $profile->getUid());

            $profile = $projectDB->updateDocument(array_merge($profile->getArrayCopy(), [
                'password' => Auth::passwordHash($passwordA),
                'password-update' => time(),
                'confirm' => true,
            ]));

            if(false === $profile) {
                throw new Exception('Failed saving user to DB', 500);
            }

            if(!$projectDB->deleteDocument($token)) {
                throw new Exception('Failed to remove token from DB', 500);
            }

            $audit
                ->setParam('userId', $profile->getUid())
                ->setParam('event', 'auth.recovery.reset')
            ;

            $response->json(array('result' => 'success'));
        }
    );

$utopia->get('/v1/oauth/:provider')
    ->desc('OAuth Login')
    ->label('error', __DIR__ . '/../views/general/error.phtml')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'oauth')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', function () use ($providers) {return new WhiteList(array_keys($providers));}, 'OAuth Provider')
    ->param('success', '', function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'URL to redirect back to your app after a successful login attempt.', true)
    ->param('failure', '', function () use ($project) {return new Host($project->getAttribute('clients', []));}, 'URL to redirect back to your app after a failed login attempt.', true)
    ->action(
        function($provider, $success, $failure) use ($response, $request, $project)
        {
            $callback   = APP_PROTOCOL . '://' . $request->getServer('HTTP_HOST') . '/v1/oauth/callback/' . $provider . '/' . $project->getUid();
            $appId      = $project->getAttribute('usersOauth' . ucfirst($provider) . 'Appid', '');
            $appSecret  = $project->getAttribute('usersOauth' . ucfirst($provider) . 'Secret', '{}');

            $appSecret  = json_decode($appSecret, true);

            if(!empty($appSecret) && isset($appSecret['version'])) {
                $key        = $request->getServer('_APP_OPENSSL_KEY_V' . $appSecret['version']);
                $appSecret  = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key,0, hex2bin($appSecret['iv']), hex2bin($appSecret['tag']));
            }

            if(empty($appId) || empty($appSecret)) {
                throw new Exception('Provider is undefined, configure provider app ID and app secret key to continue', 412);
            }

            switch($provider) {
                case 'bitbucket':
                    $oauth = new Bitbucket($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                case 'facebook':
                    $oauth = new Facebook($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                case 'github':
                    $oauth = new GitHub($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                case 'gitlab':
                    $oauth = new Gitlab($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                case 'google':
                    $oauth = new Google($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                case 'instagram':
                    $oauth = new Instagram($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                case 'linkedin':
                    $oauth = new LinkedIn($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                case 'microsoft':
                    $oauth = new Microsoft($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                case 'twitter':
                    $oauth = new Twitter($appId, $appSecret, $callback, ['success' => $success, 'failure' => $failure]);
                    break;
                default:
                    throw new Exception('Provider is not supported', 501);
            }

            $response->redirect($oauth->getLoginURL());
        }
    );

$utopia->get('/v1/oauth/callback/:provider/:projectId')
    ->desc('OAuth Callback')
    ->label('error', __DIR__ . '/../views/general/error.phtml')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'oauthCallback')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('projectId', '', function () {return new Text(1024);}, 'Project unique ID')
    ->param('provider', '', function () use ($providers) {return new WhiteList(array_keys($providers));}, 'OAuth provider')
    ->param('code', '', function () {return new Text(1024);}, 'OAuth code')
    ->param('state', '', function () {return new Text(2048);}, 'Login state params', true)
    ->action(
        function($projectId, $provider, $code, $state) use ($response, $domain)
        {
            $response->redirect(APP_PROTOCOL . '://' . $domain . '/v1/oauth/' . $provider . '/redirect?'
                . http_build_query(['project' => $projectId, 'code' => $code, 'state' => $state]));
        }
    );

$utopia->get('/v1/oauth/:provider/redirect')
    ->desc('OAuth Redirect')
    ->label('error', __DIR__ . '/../views/general/error.phtml')
    ->label('webhook', 'auth.oauth')
    ->label('scope', 'auth')
    ->label('sdk.namespace', 'auth')
    ->label('sdk.method', 'oauthRedirect')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->label('docs', false)
    ->param('provider', '', function () use ($providers) {return new WhiteList(array_keys($providers));}, 'OAuth provider')
    ->param('code', '', function () {return new Text(1024);}, 'OAuth code')
    ->param('state', '', function () {return new Text(2048);}, 'OAuth state params', true)
    ->action(
        function($provider, $code, $state) use ($response, $request, $user, $projectDB, $project, $audit)
        {
            $callback       = APP_PROTOCOL . '://' . $request->getServer('HTTP_HOST') . '/v1/oauth/callback/' . $provider . '/' . $project->getUid();
            $defaultState   = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
            $validateURL    = new URL();

            if(!empty($state)) {
                try {
                    $state = array_merge($defaultState, json_decode($state, true));
                }
                catch (\Exception $exception) {
                    throw new Exception('Failed to parse login state params as passed from OAuth provider');
                }
            }
            else {
                $state = $defaultState;
            }

            if(!$validateURL->isValid($state['success'])) {
                throw new Exception('Invalid redirect URL for success login', 400);
            }

            if(!empty($state['failure']) && !$validateURL->isValid($state['failure'])) {
                throw new Exception('Invalid redirect URL for failure login', 400);
            }

            $appId      = $project->getAttribute('usersOauth' . ucfirst($provider) . 'Appid', '');
            $appSecret  = $project->getAttribute('usersOauth' . ucfirst($provider) . 'Secret', '{}');

            $appSecret  = json_decode($appSecret, true);

            if(!empty($appSecret) && isset($appSecret['version'])) {
                $key        = $request->getServer('_APP_OPENSSL_KEY_V' . $appSecret['version']);
                $appSecret  = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key,0, hex2bin($appSecret['iv']), hex2bin($appSecret['tag']));
            }

            switch($provider) {
                case 'bitbucket':
                    $oauth = new Bitbucket($appId, $appSecret, $callback);
                    break;
                case 'facebook':
                    $oauth = new Facebook($appId, $appSecret, $callback);
                    break;
                case 'github':
                    $oauth = new GitHub($appId, $appSecret, $callback);
                    break;
                case 'gitlab':
                    $oauth = new Gitlab($appId, $appSecret, $callback);
                    break;
                case 'google':
                    $oauth = new Google($appId, $appSecret, $callback);
                    break;
                case 'instagram':
                    $oauth = new Instagram($appId, $appSecret, $callback);
                    break;
                case 'linkedin':
                    $oauth = new LinkedIn($appId, $appSecret, $callback);
                    break;
                case 'microsoft':
                    $oauth = new Microsoft($appId, $appSecret, $callback);
                    break;
                case 'twitter':
                    $oauth = new LinkedIn($appId, $appSecret, $callback);
                    break;
                default:
                    throw new Exception('Provider is not supported', 501);
            }

            $accessToken = $oauth->getAccessToken($code);

            if(empty($accessToken)) {
                if(!empty($state['failure'])) {
                    $response->redirect($state['failure'], 301, 0);
                }

                throw new Exception('Failed to obtain access token');
            }

            $oauthID = $oauth->getUserID($accessToken);

            if(empty($oauthID)) {
                if(!empty($state['failure'])) {
                    $response->redirect($state['failure'], 301, 0);
                }

                throw new Exception('Missing ID from OAuth provider', 400);
            }

            $current = Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_LOGIN, Auth::$secret);

            if($current) {
                $projectDB->deleteDocument($current); //throw new Exception('User already logged in', 401);
            }

            $user = (empty($user->getUid())) ? $projectDB->getCollection([ // Get user by provider id
                'limit' => 1,
                'first' => true,
                'filters' => [
                    '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                    'oauth' . ucfirst($provider) . '=' . $oauthID
                ]
            ]) : $user;

            if(empty($user)) { // No user logged in or with oauth provider ID, create new one or connect with account with same email
                $name     = $oauth->getUserName($accessToken);
                $email    = $oauth->getUserEmail($accessToken);

                $user = $projectDB->getCollection([ // Get user by provider email address
                    'limit' => 1,
                    'first' => true,
                    'filters' => [
                        '$collection=' . Database::SYSTEM_COLLECTION_USERS,
                        'email=' . $email
                    ]
                ]);

                if(empty($user->getUid())) { // Last option -> create user alone, generate random password
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

                    if(false === $user) {
                        throw new Exception('Failed saving user to DB', 500);
                    }
                }
            }

            // Create login token, confirm user account and update OAuth ID and Access Token

            $secret = Auth::tokenGenerator();
            $expiry = time() + Auth::TOKEN_EXPIRATION_LOGIN_LONG;

            $user
                ->setAttribute('oauth' . ucfirst($provider), $oauthID)
                ->setAttribute('oauth' . ucfirst($provider) . 'AccessToken', $accessToken)
                ->setAttribute('status', Auth::USER_STATUS_ACTIVATED)
                ->setAttribute('tokens', new Document([
                    '$collection' => Database::SYSTEM_COLLECTION_TOKENS,
                    '$permissions' => ['read' => ['user:' . $user['$uid']], 'write' => ['user:' . $user['$uid']]],
                    'type' => Auth::TOKEN_TYPE_LOGIN,
                    'secret' => Auth::hash($secret), // On way hash encryption to protect DB leak
                    'expire' => $expiry,
                    'userAgent' => $request->getServer('HTTP_USER_AGENT', 'UNKNOWN'),
                    'ip' => $request->getIP(),
                ]), Document::SET_TYPE_APPEND)
            ;

            Authorization::setRole('user:' . $user->getUid());

            $user = $projectDB->updateDocument($user->getArrayCopy());

            if(false === $user) {
                throw new Exception('Failed saving user to DB', 500);
            }

            $audit
                ->setParam('userId', $user->getUid())
                ->setParam('event', 'auth.oauth.login')
                ->setParam('data', ['provider' => $provider])
            ;

            $response
                ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getUid(), $secret), $expiry, '/', COOKIE_DOMAIN, ('https' == APP_PROTOCOL), true)
            ;

            $response->redirect($state['success']);
        }
    );