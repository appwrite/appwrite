<?php

use Ahc\Jwt\JWT;
use Appwrite\Auth\Auth;
use Appwrite\Auth\MFA\Challenge;
use Appwrite\Auth\MFA\Type;
use Appwrite\Auth\MFA\Type\TOTP;
use Appwrite\Auth\OAuth2\Exception as OAuth2Exception;
use Appwrite\Auth\Phrase;
use Appwrite\Auth\Validator\Password;
use Appwrite\Auth\Validator\PasswordDictionary;
use Appwrite\Auth\Validator\PasswordHistory;
use Appwrite\Auth\Validator\PersonalData;
use Appwrite\Auth\Validator\Phone;
use Appwrite\Detector\Detector;
use Appwrite\Event\Delete;
use Appwrite\Event\Event;
use Appwrite\Event\Mail;
use Appwrite\Event\Messaging;
use Appwrite\Extend\Exception;
use Appwrite\Hooks\Hooks;
use Appwrite\Network\Validator\Email;
use Appwrite\OpenSSL\OpenSSL;
use Appwrite\Template\Template;
use Appwrite\URL\URL as URLParser;
use Appwrite\Utopia\Database\Validator\CustomId;
use Appwrite\Utopia\Database\Validator\Queries\Identities;
use Appwrite\Utopia\Request;
use Appwrite\Utopia\Response;
use MaxMind\Db\Reader;
use Utopia\Audit\Audit as EventAudit;
use Utopia\Config\Config;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception\Duplicate;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Queries;
use Utopia\Database\Validator\Query\Limit;
use Utopia\Database\Validator\Query\Offset;
use Utopia\Database\Validator\UID;
use Utopia\Http\Http;
use Utopia\Http\Validator\ArrayList;
use Utopia\Http\Validator\Assoc;
use Utopia\Http\Validator\Boolean;
use Utopia\Http\Validator\Host;
use Utopia\Http\Validator\Text;
use Utopia\Http\Validator\URL;
use Utopia\Http\Validator\WhiteList;
use Utopia\Locale\Locale;
use Utopia\System\System;

$oauthDefaultSuccess = '/auth/oauth2/success';
$oauthDefaultFailure = '/auth/oauth2/failure';

$createSession = function (string $userId, string $secret, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Reader $geodb, Event $queueForEvents, Authorization $authorization) {
    $roles = $authorization->getRoles();
    $isPrivilegedUser = Auth::isPrivilegedUser($roles);
    $isAppUser = Auth::isAppUser($roles);

    /** @var Utopia\Database\Document $user */
    $userFromRequest = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

    if ($userFromRequest->isEmpty()) {
        throw new Exception(Exception::USER_INVALID_TOKEN);
    }

    $verifiedToken = Auth::tokenVerify($userFromRequest->getAttribute('tokens', []), null, $secret);

    if (!$verifiedToken) {
        throw new Exception(Exception::USER_INVALID_TOKEN);
    }

    $user->setAttributes($userFromRequest->getArrayCopy());

    $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
    $detector = new Detector($request->getUserAgent('UNKNOWN'));
    $record = $geodb->get($request->getIP());
    $sessionSecret = Auth::tokenGenerator(Auth::TOKEN_LENGTH_SESSION);

    $factor = (match ($verifiedToken->getAttribute('type')) {
        Auth::TOKEN_TYPE_MAGIC_URL,
        Auth::TOKEN_TYPE_OAUTH2,
        Auth::TOKEN_TYPE_EMAIL => 'email',
        Auth::TOKEN_TYPE_PHONE => 'phone',
        Auth::TOKEN_TYPE_GENERIC => 'token',
        default => throw new Exception(Exception::USER_INVALID_TOKEN)
    });

    $session = new Document(array_merge(
        [
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'provider' => Auth::getSessionProviderByTokenType($verifiedToken->getAttribute('type')),
            'secret' => Auth::hash($sessionSecret), // One way hash encryption to protect DB leak
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
            'factors' => [$factor],
            'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
            'expire' => DateTime::addSeconds(new \DateTime(), $duration)
        ],
        $detector->getOS(),
        $detector->getClient(),
        $detector->getDevice()
    ));

    $authorization->addRole(Role::user($user->getId())->toString());

    $session = $dbForProject->createDocument('sessions', $session
        ->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]));

    $dbForProject->purgeCachedDocument('users', $user->getId());
    $authorization->skip(fn () => $dbForProject->deleteDocument('tokens', $verifiedToken->getId()));
    $dbForProject->purgeCachedDocument('users', $user->getId());

    if ($verifiedToken->getAttribute('type') === Auth::TOKEN_TYPE_MAGIC_URL) {
        $user->setAttribute('emailVerification', true);
    }

    if ($verifiedToken->getAttribute('type') === Auth::TOKEN_TYPE_PHONE) {
        $user->setAttribute('phoneVerification', true);
    }

    try {
        $dbForProject->updateDocument('users', $user->getId(), $user);
    } catch (\Throwable $th) {
        throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed saving user to DB');
    }

    $queueForEvents
        ->setParam('userId', $user->getId())
        ->setParam('sessionId', $session->getId());

    if (!Config::getParam('domainVerification')) {
        $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $sessionSecret)]));
    }

    $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));
    $protocol = $request->getProtocol();

    $response
        ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $sessionSecret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
        ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $sessionSecret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
        ->setStatusCode(Response::STATUS_CODE_CREATED);

    $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

    $session
        ->setAttribute('current', true)
        ->setAttribute('countryName', $countryName)
        ->setAttribute('expire', $expire)
        ->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? Auth::encodeSession($user->getId(), $sessionSecret) : '')
    ;

    $response->dynamic($session, Response::MODEL_SESSION);
};

Http::post('/v1/account')
    ->desc('Create account')
    ->groups(['api', 'account', 'auth'])
    ->label('event', 'users.[userId].create')
    ->label('scope', 'sessions.write')
    ->label('auth.type', 'emailPassword')
    ->label('audits.event', 'user.create')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'create')
    ->label('sdk.description', '/docs/references/account/create.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('abuse-limit', 10)
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordDictionary($passwordsDictionary, $project->getAttribute('auths', [])['passwordDictionary'] ?? false), 'New user password. Must be between 8 and 256 chars.', false, ['project', 'passwordsDictionary'])
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.', true)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->inject('authorization')
    ->action(function (string $userId, string $email, string $password, string $name, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks, Authorization $authorization) {

        $email = \strtolower($email);
        if ('console' === $project->getId()) {
            $whitelistEmails = $project->getAttribute('authWhitelistEmails');
            $whitelistIPs = $project->getAttribute('authWhitelistIPs');

            if (!empty($whitelistEmails) && !\in_array($email, $whitelistEmails) && !\in_array(strtoupper($email), $whitelistEmails)) {
                throw new Exception(Exception::USER_EMAIL_NOT_WHITELISTED);
            }

            if (!empty($whitelistIPs) && !\in_array($request->getIP(), $whitelistIPs)) {
                throw new Exception(Exception::USER_IP_NOT_WHITELISTED);
            }
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception(Exception::USER_COUNT_EXCEEDED);
            }
        }

        // Makes sure this email is not already used in another identity
        $identityWithMatchingEmail = $dbForProject->findOne('identities', [
            Query::equal('providerEmail', [$email]),
        ]);
        if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST); /** Return a generic bad request to prevent exposing existing accounts */
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($userId, $email, $name, null);
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, true]);

        $passwordHistory = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $password = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
        try {
            $userId = $userId == 'unique()' ? ID::unique() : $userId;
            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => $password,
                'passwordHistory' => $passwordHistory > 0 ? [$password] : [],
                'passwordUpdate' => DateTime::now(),
                'hash' => Auth::DEFAULT_ALGO,
                'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
                'registration' => DateTime::now(),
                'reset' => false,
                'name' => $name,
                'mfa' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'authenticators' => null,
                'search' => implode(' ', [$userId, $email, $name]),
                'accessedAt' => DateTime::now(),
            ]);
            $user->removeAttribute('$internalId');
            $user = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));
            try {
                $target = $authorization->skip(fn () => $dbForProject->createDocument('targets', new Document([
                    '$permissions' => [
                        Permission::read(Role::user($user->getId())),
                        Permission::update(Role::user($user->getId())),
                        Permission::delete(Role::user($user->getId())),
                    ],
                    'userId' => $user->getId(),
                    'userInternalId' => $user->getInternalId(),
                    'providerType' => MESSAGE_TYPE_EMAIL,
                    'identifier' => $email,
                ])));
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
            } catch (Duplicate) {
                $existingTarget = $dbForProject->findOne('targets', [
                    Query::equal('identifier', [$email]),
                ]);
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $existingTarget]);
            }

            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate) {
            throw new Exception(Exception::USER_ALREADY_EXISTS);
        }

        $authorization->removeRole(Role::guests()->toString());
        $authorization->addRole(Role::user($user->getId())->toString());
        $authorization->addRole(Role::users()->toString());

        $queueForEvents->setParam('userId', $user->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::get('/v1/account')
    ->desc('Get account')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'get')
    ->label('sdk.description', '/docs/references/account/get.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->inject('response')
    ->inject('user')
    ->action(function (Response $response, Document $user) {
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::delete('/v1/account')
    ->desc('Delete account')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].delete')
    ->label('scope', 'account')
    ->label('audits.event', 'user.delete')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_ADMIN])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'delete')
    ->label('sdk.description', '/docs/references/account/delete.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->inject('user')
    ->inject('project')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForDeletes')
    ->action(function (Document $user, Document $project, Response $response, Database $dbForProject, Event $queueForEvents, Delete $queueForDeletes) {
        if ($user->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        if ($project->getId() === 'console') {
            // get all memberships
            $memberships = $user->getAttribute('memberships', []);
            foreach ($memberships as $membership) {
                // prevent deletion if at least one active membership
                if ($membership->getAttribute('confirm', false)) {
                    throw new Exception(Exception::USER_DELETION_PROHIBITED);
                }
            }
        }

        $dbForProject->deleteDocument('users', $user->getId());

        $queueForDeletes
            ->setType(DELETE_TYPE_DOCUMENT)
            ->setDocument($user);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_USER));

        $response->noContent();
    });

Http::get('/v1/account/sessions')
    ->desc('List sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'listSessions')
    ->label('sdk.description', '/docs/references/account/list-sessions.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION_LIST)
    ->label('sdk.offline.model', '/account/sessions')
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('authorization')
    ->action(function (Response $response, Document $user, Locale $locale, Authorization $authorization) {

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $sessions = $user->getAttribute('sessions', []);
        $current = Auth::sessionVerify($sessions, Auth::$secret);

        foreach ($sessions as $key => $session) {/** @var Document $session */
            $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

            $session->setAttribute('countryName', $countryName);
            $session->setAttribute('current', ($current == $session->getId()) ? true : false);
            $session->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $session->getAttribute('secret', '') : '');

            $sessions[$key] = $session;
        }

        $response->dynamic(new Document([
            'sessions' => $sessions,
            'total' => count($sessions),
        ]), Response::MODEL_SESSION_LIST);
    });

Http::delete('/v1/account/sessions')
    ->desc('Delete sessions')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('audits.event', 'session.delete')
    ->label('audits.resource', 'user/{user.$id}')
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
    ->inject('queueForEvents')
    ->inject('queueForDeletes')
    ->action(function (Request $request, Response $response, Document $user, Database $dbForProject, Locale $locale, Event $queueForEvents, Delete $queueForDeletes) {

        $protocol = $request->getProtocol();
        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $session) {/** @var Document $session */
            $dbForProject->deleteDocument('sessions', $session->getId());

            if (!Config::getParam('domainVerification')) {
                $response->addHeader('X-Fallback-Cookies', \json_encode([]));
            }

            $session
                ->setAttribute('current', false)
                ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')));

            if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) {
                $session->setAttribute('current', true);

                // If current session delete the cookies too
                $response
                    ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                    ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'));

                // Use current session for events.
                $queueForEvents
                    ->setPayload($response->output($session, Response::MODEL_SESSION));

                $queueForDeletes
                    ->setType(DELETE_TYPE_SESSION_TARGETS)
                    ->setDocument($session)
                    ->trigger();
            }
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId());

        $response->noContent();
    });

Http::get('/v1/account/sessions/:sessionId')
    ->desc('Get session')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getSession')
    ->label('sdk.description', '/docs/references/account/get-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('sdk.offline.model', '/account/sessions')
    ->label('sdk.offline.key', '{sessionId}')
    ->param('sessionId', '', new UID(), 'Session ID. Use the string \'current\' to get the current device session.')
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('authorization')
    ->action(function (?string $sessionId, Response $response, Document $user, Locale $locale, Authorization $authorization) {

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $sessions = $user->getAttribute('sessions', []);
        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
            : $sessionId;

        foreach ($sessions as $session) {/** @var Document $session */
            if ($sessionId === $session->getId()) {
                $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

                $session
                    ->setAttribute('current', ($session->getAttribute('secret') == Auth::hash(Auth::$secret)))
                    ->setAttribute('countryName', $countryName)
                    ->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $session->getAttribute('secret', '') : '')
                ;

                return $response->dynamic($session, Response::MODEL_SESSION);
            }
        }

        throw new Exception(Exception::USER_SESSION_NOT_FOUND);
    });

Http::delete('/v1/account/sessions/:sessionId')
    ->desc('Delete session')
    ->groups(['api', 'account', 'mfa'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].delete')
    ->label('audits.event', 'session.delete')
    ->label('audits.resource', 'user/{user.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteSession')
    ->label('sdk.description', '/docs/references/account/delete-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->label('abuse-limit', 100)
    ->param('sessionId', '', new UID(), 'Session ID. Use the string \'current\' to delete the current device session.')
    ->inject('requestTimestamp')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('queueForEvents')
    ->inject('queueForDeletes')
    ->action(function (?string $sessionId, ?\DateTime $requestTimestamp, Request $request, Response $response, Document $user, Database $dbForProject, Locale $locale, Event $queueForEvents, Delete $queueForDeletes) {

        $protocol = $request->getProtocol();
        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
            : $sessionId;

        $sessions = $user->getAttribute('sessions', []);

        foreach ($sessions as $key => $session) {
            /** @var Document $session */
            if ($sessionId !== $session->getId()) {
                continue;
            }

            $dbForProject->withRequestTimestamp($requestTimestamp, function () use ($dbForProject, $session) {
                return $dbForProject->deleteDocument('sessions', $session->getId());
            });

            unset($sessions[$key]);

            $session->setAttribute('current', false);

            if ($session->getAttribute('secret') == Auth::hash(Auth::$secret)) { // If current session delete the cookies too
                $session
                    ->setAttribute('current', true)
                    ->setAttribute('countryName', $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown')));

                if (!Config::getParam('domainVerification')) {
                    $response->addHeader('X-Fallback-Cookies', \json_encode([]));
                }

                $response
                    ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                    ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'));
            }

            $dbForProject->purgeCachedDocument('users', $user->getId());

            $queueForEvents
                ->setParam('userId', $user->getId())
                ->setParam('sessionId', $session->getId())
                ->setPayload($response->output($session, Response::MODEL_SESSION));

            $queueForDeletes
                ->setType(DELETE_TYPE_SESSION_TARGETS)
                ->setDocument($session)
                ->trigger();

            $response->noContent();
            return;
        }

        throw new Exception(Exception::USER_SESSION_NOT_FOUND);
    });

Http::patch('/v1/account/sessions/:sessionId')
    ->desc('Update session')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].update')
    ->label('audits.event', 'session.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateSession')
    ->label('sdk.description', '/docs/references/account/update-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->param('sessionId', '', new UID(), 'Session ID. Use the string \'current\' to update the current device session.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForEvents')
    ->action(function (?string $sessionId, Response $response, Document $user, Database $dbForProject, Document $project, Event $queueForEvents) {

        $sessionId = ($sessionId === 'current')
            ? Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret)
            : $sessionId;
        $sessions = $user->getAttribute('sessions', []);

        $session = null;
        foreach ($sessions as $key => $value) {
            if ($sessionId === $value->getId()) {
                $session = $value;
                break;
            }
        }

        if ($session === null) {
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        // Extend session
        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $session->setAttribute('expire', DateTime::addSeconds(new \DateTime(), $authDuration));

        // Refresh OAuth access token
        $provider = $session->getAttribute('provider', '');
        $refreshToken = $session->getAttribute('providerRefreshToken', '');
        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!empty($provider) && \class_exists($className)) {
            $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
            $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';

            $oauth2 = new $className($appId, $appSecret, '', [], []);
            $oauth2->refreshTokens($refreshToken);

            $session
                ->setAttribute('providerAccessToken', $oauth2->getAccessToken(''))
                ->setAttribute('providerRefreshToken', $oauth2->getRefreshToken(''))
                ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$oauth2->getAccessTokenExpiry('')));
        }

        // Save changes
        $dbForProject->updateDocument('sessions', $sessionId, $session);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
            ->setPayload($response->output($session, Response::MODEL_SESSION))
        ;

        return $response->dynamic($session, Response::MODEL_SESSION);
    });

Http::post('/v1/account/sessions/email')
    ->alias('/v1/account/sessions')
    ->desc('Create email password session')
    ->groups(['api', 'account', 'auth', 'session'])
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'sessions.write')
    ->label('auth.type', 'emailPassword')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createEmailPasswordSession')
    ->label('sdk.description', '/docs/references/account/create-session-email-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->inject('authorization')
    ->action(function (string $email, string $password, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Reader $geodb, Event $queueForEvents, Hooks $hooks, Authorization $authorization) {
        $email = \strtolower($email);
        $protocol = $request->getProtocol();

        $profile = $dbForProject->findOne('users', [
            Query::equal('email', [$email]),
        ]);

        if (!$profile || empty($profile->getAttribute('passwordUpdate')) || !Auth::passwordVerify($password, $profile->getAttribute('password'), $profile->getAttribute('hash'), $profile->getAttribute('hashOptions'))) {
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED); // User is in status blocked
        }

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $user->setAttributes($profile->getArrayCopy());

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, false]);

        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator(Auth::TOKEN_LENGTH_SESSION);
        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_EMAIL,
                'providerUid' => $email,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'factors' => ['password'],
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
                'expire' => DateTime::addSeconds(new \DateTime(), $duration)
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $authorization->addRole(Role::user($user->getId())->toString());

        // Re-hash if not using recommended algo
        if ($user->getAttribute('hash') !== Auth::DEFAULT_ALGO) {
            $user
                ->setAttribute('password', Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS))
                ->setAttribute('hash', Auth::DEFAULT_ALGO)
                ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS);
            $dbForProject->updateDocument('users', $user->getId(), $user);
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $session = $dbForProject->createDocument('sessions', $session->setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]));

        if (!Config::getParam('domainVerification')) {
            $response
                ->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]))
            ;
        }

        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? Auth::encodeSession($user->getId(), $secret) : '')
        ;

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

Http::post('/v1/account/sessions/anonymous')
    ->desc('Create anonymous session')
    ->groups(['api', 'account', 'auth', 'session'])
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'sessions.write')
    ->label('auth.type', 'anonymous')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
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
    ->inject('queueForEvents')
    ->inject('authorization')
    ->action(function (Request $request, Response $response, Locale $locale, Document $user, Document $project, Database $dbForProject, Reader $geodb, Event $queueForEvents, Authorization $authorization) {
        $protocol = $request->getProtocol();
        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        if ('console' === $project->getId()) {
            throw new Exception(Exception::USER_ANONYMOUS_CONSOLE_PROHIBITED, 'Failed to create anonymous user');
        }

        $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

        if ($limit !== 0) {
            $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

            if ($total >= $limit) {
                throw new Exception(Exception::USER_COUNT_EXCEEDED);
            }
        }

        $userId = ID::unique();
        $user->setAttributes([
            '$id' => $userId,
            '$permissions' => [
                Permission::read(Role::any()),
                Permission::update(Role::user($userId)),
                Permission::delete(Role::user($userId)),
            ],
            'email' => null,
            'emailVerification' => false,
            'status' => true,
            'password' => null,
            'hash' => Auth::DEFAULT_ALGO,
            'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
            'passwordUpdate' => null,
            'registration' => DateTime::now(),
            'reset' => false,
            'name' => null,
            'mfa' => false,
            'prefs' => new \stdClass(),
            'sessions' => null,
            'tokens' => null,
            'memberships' => null,
            'authenticators' => null,
            'search' => $userId,
            'accessedAt' => DateTime::now(),
        ]);
        $user->removeAttribute('$internalId');
        $authorization->skip(fn () => $dbForProject->createDocument('users', $user));

        // Create session token
        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $record = $geodb->get($request->getIP());
        $secret = Auth::tokenGenerator(Auth::TOKEN_LENGTH_SESSION);

        $session = new Document(array_merge(
            [
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => Auth::SESSION_PROVIDER_ANONYMOUS,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'factors' => ['anonymous'],
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
                'expire' => DateTime::addSeconds(new \DateTime(), $duration)
            ],
            $detector->getOS(),
            $detector->getClient(),
            $detector->getDevice()
        ));

        $authorization->addRole(Role::user($user->getId())->toString());

        $session = $dbForProject->createDocument('sessions', $session-> setAttribute('$permissions', [
            Permission::read(Role::user($user->getId())),
            Permission::update(Role::user($user->getId())),
            Permission::delete(Role::user($user->getId())),
        ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('sessionId', $session->getId())
        ;

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
        }

        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $response
            ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
            ->setStatusCode(Response::STATUS_CODE_CREATED)
        ;

        $countryName = $locale->getText('countries.' . strtolower($session->getAttribute('countryCode')), $locale->getText('locale.country.unknown'));

        $session
            ->setAttribute('current', true)
            ->setAttribute('countryName', $countryName)
            ->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? Auth::encodeSession($user->getId(), $secret) : '')
        ;

        $response->dynamic($session, Response::MODEL_SESSION);
    });

Http::post('/v1/account/sessions/token')
    ->desc('Create session')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->groups(['api', 'account', 'session'])
    ->label('scope', 'sessions.write')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createSession')
    ->label('sdk.description', '/docs/references/account/create-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'ip:{ip},userId:{param-userId}')
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('secret', '', new Text(256), 'Secret of a token generated by login methods. For example, the `createMagicURLToken` or `createPhoneToken` methods.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('queueForEvents')
    ->action($createSession);

Http::get('/v1/account/sessions/oauth2/:provider')
    ->desc('Create OAuth2 session')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'sessions.write')
    ->label('sdk.auth', [])
    ->label('sdk.hide', [APP_PLATFORM_SERVER])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createOAuth2Session')
    ->label('sdk.description', '/docs/references/account/create-session-oauth2.md')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 Provider. Currently, supported providers are: ' . \implode(', ', \array_keys(\array_filter(Config::getParam('oAuthProviders'), fn ($node) => (!$node['mock'])))) . '.')
    ->param('success', '', fn ($clients) => new Host($clients), 'URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('failure', '', fn ($clients) => new Host($clients), 'URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('scopes', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'A list of custom OAuth2 scopes. Check each provider internal docs for a list of supported scopes. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->action(function (string $provider, string $success, string $failure, array $scopes, Request $request, Response $response, Document $project) use ($oauthDefaultSuccess, $oauthDefaultFailure) {
        $protocol = $request->getProtocol();

        $callback = $protocol . '://' . $request->getHostname() . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $providerEnabled = $project->getAttribute('oAuthProviders', [])[$provider . 'Enabled'] ?? false;

        if (!$providerEnabled) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please enable the provider from your ' . APP_NAME . ' console to continue.');
        }

        $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = System::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        if (empty($appId) || empty($appSecret)) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please configure the provider app ID and app secret key from your ' . APP_NAME . ' console to continue.');
        }

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        if (empty($success)) {
            $success = $protocol . '://' . $request->getHostname() . $oauthDefaultSuccess;
        }

        if (empty($failure)) {
            $failure = $protocol . '://' . $request->getHostname() . $oauthDefaultFailure;
        }

        $oauth2 = new $className($appId, $appSecret, $callback, [
            'success' => $success,
            'failure' => $failure,
            'token' => false,
        ], $scopes);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    });

Http::get('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 callback')
    ->groups(['account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
    ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
    ->inject('request')
    ->inject('response')
    ->action(function (string $projectId, string $provider, string $code, string $state, string $error, string $error_description, Request $request, Response $response) {

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $domain . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query([
                    'project' => $projectId,
                    'code' => $code,
                    'state' => $state,
                    'error' => $error,
                    'error_description' => $error_description
                ]));
    });

Http::post('/v1/account/sessions/oauth2/callback/:provider/:projectId')
    ->desc('OAuth2 callback')
    ->groups(['account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'public')
    ->label('origin', '*')
    ->label('docs', false)
    ->param('projectId', '', new Text(1024), 'Project ID.')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
    ->param('state', '', new Text(2048), 'Login state params.', true)
    ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
    ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
    ->inject('request')
    ->inject('response')
    ->action(function (string $projectId, string $provider, string $code, string $state, string $error, string $error_description, Request $request, Response $response) {

        $domain = $request->getHostname();
        $protocol = $request->getProtocol();

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($protocol . '://' . $domain . '/v1/account/sessions/oauth2/' . $provider . '/redirect?'
                . \http_build_query([
                    'project' => $projectId,
                    'code' => $code,
                    'state' => $state,
                    'error' => $error,
                    'error_description' => $error_description
                ]));
    });

Http::get('/v1/account/sessions/oauth2/:provider/redirect')
    ->desc('OAuth2 redirect')
    ->groups(['api', 'account', 'session'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('scope', 'public')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{user.$id}')
    ->label('audits.userId', '{user.$id}')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->label('docs', false)
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 provider.')
    ->param('code', '', new Text(2048, 0), 'OAuth2 code. This is a temporary code that the will be later exchanged for an access token.', true)
    ->param('state', '', new Text(2048), 'OAuth2 state params.', true)
    ->param('error', '', new Text(2048, 0), 'Error code returned from the OAuth2 provider.', true)
    ->param('error_description', '', new Text(2048, 0), 'Human-readable text providing additional information about the error returned from the OAuth2 provider.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('geodb')
    ->inject('queueForEvents')
    ->inject('authorization')
    ->action(function (string $provider, string $code, string $state, string $error, string $error_description, Request $request, Response $response, Document $project, Document $user, Database $dbForProject, Reader $geodb, Event $queueForEvents, Authorization $authorization) use ($oauthDefaultSuccess) {
        $protocol = $request->getProtocol();
        $callback = $protocol . '://' . $request->getHostname() . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $defaultState = ['success' => $project->getAttribute('url', ''), 'failure' => ''];
        $validateURL = new URL();
        $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';
        $providerEnabled = $project->getAttribute('oAuthProviders', [])[$provider . 'Enabled'] ?? false;

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        $providers = Config::getParam('oAuthProviders');
        $providerName = $providers[$provider]['name'] ?? '';

        /** @var Appwrite\Auth\OAuth2 $oauth2 */
        $oauth2 = new $className($appId, $appSecret, $callback);

        if (!empty($state)) {
            try {
                $state = \array_merge($defaultState, $oauth2->parseState($state));
            } catch (\Throwable $exception) {
                throw new Exception(Exception::GENERAL_SERVER_ERROR, 'Failed to parse login state params as passed from OAuth2 provider');
            }
        } else {
            $state = $defaultState;
        }

        if (!$validateURL->isValid($state['success'])) {
            throw new Exception(Exception::PROJECT_INVALID_SUCCESS_URL);
        }

        if (!empty($state['failure']) && !$validateURL->isValid($state['failure'])) {
            throw new Exception(Exception::PROJECT_INVALID_FAILURE_URL);
        }
        $failure = [];
        if (!empty($state['failure'])) {
            $failure = URLParser::parse($state['failure']);
        }
        $failureRedirect = (function (string $type, ?string $message = null, ?int $code = null) use ($failure, $response) {
            $exception = new Exception($type, $message, $code);
            if (!empty($failure)) {
                $query = URLParser::parseQuery($failure['query']);
                $query['error'] = json_encode([
                    'message' => $exception->getMessage(),
                    'type' => $exception->getType(),
                    'code' => !\is_null($code) ? $code : $exception->getCode(),
                ]);
                $failure['query'] = URLParser::unparseQuery($query);
                $response->redirect(URLParser::unparse($failure), 301);
            }

            throw $exception;
        });

        if (!$providerEnabled) {
            $failureRedirect(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please enable the provider from your ' . APP_NAME . ' console to continue.');
        }

        if (!empty($error)) {
            $message = 'The ' . $providerName . ' OAuth2 provider returned an error: ' . $error;
            if (!empty($error_description)) {
                $message .= ': ' . $error_description;
            }
            $failureRedirect(Exception::USER_OAUTH2_PROVIDER_ERROR, $message);
        }

        if (empty($code)) {
            $failureRedirect(Exception::USER_OAUTH2_PROVIDER_ERROR, 'Missing OAuth2 code. Please contact the Appwrite team for additional support.');
        }

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = System::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        $accessToken = '';
        $refreshToken = '';
        $accessTokenExpiry = 0;

        try {
            $accessToken = $oauth2->getAccessToken($code);
            $refreshToken = $oauth2->getRefreshToken($code);
            $accessTokenExpiry = $oauth2->getAccessTokenExpiry($code);
        } catch (OAuth2Exception $ex) {
            $failureRedirect(
                $ex->getType(),
                'Failed to obtain access token. The ' . $providerName . ' OAuth2 provider returned an error: ' . $ex->getMessage(),
                $ex->getCode(),
            );
        }

        $oauth2ID = $oauth2->getUserID($accessToken);
        if (empty($oauth2ID)) {
            $failureRedirect(Exception::USER_MISSING_ID);
        }

        $name = $oauth2->getUserName($accessToken);
        $email = $oauth2->getUserEmail($accessToken);

        // Check if this identity is connected to a different user
        if (!$user->isEmpty()) {
            $userId = $user->getId();

            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
                Query::notEqual('userInternalId', $user->getInternalId()),
            ]);
            if (!empty($identityWithMatchingEmail)) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }

            $userWithMatchingEmail = $dbForProject->find('users', [
                Query::equal('email', [$email]),
                Query::notEqual('$id', $userId),
            ]);
            if (!empty($userWithMatchingEmail)) {
                throw new Exception(Exception::USER_ALREADY_EXISTS);
            }

            $sessionUpgrade = true;
        }

        $sessions = $user->getAttribute('sessions', []);
        $current = Auth::sessionVerify($sessions, Auth::$secret);

        if ($current) { // Delete current session of new one.
            $currentDocument = $dbForProject->getDocument('sessions', $current);
            if (!$currentDocument->isEmpty()) {
                $dbForProject->deleteDocument('sessions', $currentDocument->getId());
                $dbForProject->purgeCachedDocument('users', $user->getId());
            }
        }

        if ($user->isEmpty()) {
            $session = $dbForProject->findOne('sessions', [ // Get user by provider id
                Query::equal('provider', [$provider]),
                Query::equal('providerUid', [$oauth2ID]),
            ]);
            if ($session !== false && !$session->isEmpty()) {
                $user->setAttributes($dbForProject->getDocument('users', $session->getAttribute('userId'))->getArrayCopy());
            }
        }

        if ($user === false || $user->isEmpty()) { // No user logged in or with OAuth2 provider ID, create new one or connect with account with same email
            if (empty($email)) {
                throw new Exception(Exception::USER_UNAUTHORIZED, 'OAuth provider failed to return email.');
            }

            /**
             * Is verified is not used yet, since we don't know after an account is created anymore if it was verified or not.
             */
            $isVerified = $oauth2->isEmailVerified($accessToken);

            $userWithEmail = $dbForProject->findOne('users', [
                Query::equal('email', [$email]),
            ]);
            if ($userWithEmail !== false && !$userWithEmail->isEmpty()) {
                $user->setAttributes($userWithEmail->getArrayCopy());
            }

            // If user is not found, check if there is an identity with the same provider user ID
            if ($user === false || $user->isEmpty()) {
                $identity = $dbForProject->findOne('identities', [
                    Query::equal('provider', [$provider]),
                    Query::equal('providerUid', [$oauth2ID]),
                ]);

                if ($identity !== false && !$identity->isEmpty()) {
                    $user = $dbForProject->getDocument('users', $identity->getAttribute('userId'));
                }
            }

            if ($user === false || $user->isEmpty()) { // Last option -> create the user
                $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

                if ($limit !== 0) {
                    $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                    if ($total >= $limit) {
                        $failureRedirect(Exception::USER_COUNT_EXCEEDED);
                    }
                }

                // Makes sure this email is not already used in another identity
                $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                    Query::equal('providerEmail', [$email]),
                ]);
                if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
                    throw new Exception(Exception::GENERAL_BAD_REQUEST); /** Return a generic bad request to prevent exposing existing accounts */
                }

                try {
                    $userId = ID::unique();
                    $user->setAttributes([
                        '$id' => $userId,
                        '$permissions' => [
                            Permission::read(Role::any()),
                            Permission::update(Role::user($userId)),
                            Permission::delete(Role::user($userId)),
                        ],
                        'email' => $email,
                        'emailVerification' => true,
                        'status' => true, // Email should already be authenticated by OAuth2 provider
                        'password' => null,
                        'hash' => Auth::DEFAULT_ALGO,
                        'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
                        'passwordUpdate' => null,
                        'registration' => DateTime::now(),
                        'reset' => false,
                        'name' => $name,
                        'mfa' => false,
                        'prefs' => new \stdClass(),
                        'sessions' => null,
                        'tokens' => null,
                        'memberships' => null,
                        'authenticators' => null,
                        'search' => implode(' ', [$userId, $email, $name]),
                        'accessedAt' => DateTime::now(),
                    ]);
                    $user->removeAttribute('$internalId');
                    $user = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));

                    $dbForProject->createDocument('targets', new Document([
                        '$permissions' => [
                            Permission::read(Role::user($user->getId())),
                            Permission::update(Role::user($user->getId())),
                            Permission::delete(Role::user($user->getId())),
                        ],
                        'userId' => $user->getId(),
                        'userInternalId' => $user->getInternalId(),
                        'providerType' => MESSAGE_TYPE_EMAIL,
                        'identifier' => $email,
                    ]));
                } catch (Duplicate) {
                    $failureRedirect(Exception::USER_ALREADY_EXISTS);
                }
            }
        }

        $authorization->addRole(Role::user($user->getId())->toString());
        $authorization->addRole(Role::users()->toString());

        if (false === $user->getAttribute('status')) { // Account is blocked
            $failureRedirect(Exception::USER_BLOCKED); // User is in status blocked
        }

        $identity = $dbForProject->findOne('identities', [
            Query::equal('userInternalId', [$user->getInternalId()]),
            Query::equal('provider', [$provider]),
            Query::equal('providerUid', [$oauth2ID]),
        ]);
        if ($identity === false || $identity->isEmpty()) {
            // Before creating the identity, check if the email is already associated with another user
            $userId = $user->getId();

            $identitiesWithMatchingEmail = $dbForProject->find('identities', [
                Query::equal('providerEmail', [$email]),
                Query::notEqual('userInternalId', $user->getInternalId()),
            ]);
            if (!empty($identitiesWithMatchingEmail)) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST); /** Return a generic bad request to prevent exposing existing accounts */
            }

            $dbForProject->createDocument('identities', new Document([
                '$id' => ID::unique(),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'userInternalId' => $user->getInternalId(),
                'userId' => $userId,
                'provider' => $provider,
                'providerUid' => $oauth2ID,
                'providerEmail' => $email,
                'providerAccessToken' => $accessToken,
                'providerRefreshToken' => $refreshToken,
                'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry),
            ]));
        } else {
            $identity
                ->setAttribute('providerAccessToken', $accessToken)
                ->setAttribute('providerRefreshToken', $refreshToken)
                ->setAttribute('providerAccessTokenExpiry', DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry));
            $dbForProject->updateDocument('identities', $identity->getId(), $identity);
        }

        if (empty($user->getAttribute('email'))) {
            $user->setAttribute('email', $oauth2->getUserEmail($accessToken));
        }

        if (empty($user->getAttribute('name'))) {
            $user->setAttribute('name', $oauth2->getUserName($accessToken));
        }

        $user->setAttribute('status', true);

        $dbForProject->updateDocument('users', $user->getId(), $user);

        $authorization->addRole(Role::user($user->getId())->toString());

        $state['success'] = URLParser::parse($state['success']);
        $query = URLParser::parseQuery($state['success']['query']);

        $duration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        // If the `token` param is set, we will return the token in the query string
        if ($state['token']) {
            $secret = Auth::tokenGenerator(Auth::TOKEN_LENGTH_OAUTH2);
            $token = new Document([
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'type' => Auth::TOKEN_TYPE_OAUTH2,
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'expire' => $expire,
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
            ]);

            $authorization->addRole(Role::user($user->getId())->toString());

            $token = $dbForProject->createDocument('tokens', $token
                ->setAttribute('$permissions', [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ]));

            $queueForEvents
                ->setEvent('users.[userId].tokens.[tokenId].create')
                ->setParam('userId', $user->getId())
                ->setParam('tokenId', $token->getId())
            ;

            $query['secret'] = $secret;
            $query['userId'] = $user->getId();

            // If the `token` param is not set, we persist the session in a cookie
        } else {
            $detector = new Detector($request->getUserAgent('UNKNOWN'));
            $record = $geodb->get($request->getIP());
            $secret = Auth::tokenGenerator(Auth::TOKEN_LENGTH_SESSION);

            $session = new Document(array_merge([
                '$id' => ID::unique(),
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'provider' => $provider,
                'providerUid' => $oauth2ID,
                'providerAccessToken' => $accessToken,
                'providerRefreshToken' => $refreshToken,
                'providerAccessTokenExpiry' => DateTime::addSeconds(new \DateTime(), (int)$accessTokenExpiry),
                'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
                'userAgent' => $request->getUserAgent('UNKNOWN'),
                'ip' => $request->getIP(),
                'factors' => ['email'],
                'countryCode' => ($record) ? \strtolower($record['country']['iso_code']) : '--',
                'expire' => DateTime::addSeconds(new \DateTime(), $duration)
            ], $detector->getOS(), $detector->getClient(), $detector->getDevice()));

            $session = $dbForProject->createDocument('sessions', $session->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

            $session->setAttribute('expire', $expire);

            if (!Config::getParam('domainVerification')) {
                $response->addHeader('X-Fallback-Cookies', \json_encode([Auth::$cookieName => Auth::encodeSession($user->getId(), $secret)]));
            }

            $queueForEvents
                ->setParam('userId', $user->getId())
                ->setParam('sessionId', $session->getId())
                ->setPayload($response->output($session, Response::MODEL_SESSION))
            ;

            // TODO: Remove this deprecated workaround - support only token
            if ($state['success']['path'] == $oauthDefaultSuccess) {
                $query['project'] = $project->getId();
                $query['domain'] = Config::getParam('cookieDomain');
                $query['key'] = Auth::$cookieName;
                $query['secret'] = Auth::encodeSession($user->getId(), $secret);
            }

            $response
                ->addCookie(Auth::$cookieName . '_legacy', Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
                ->addCookie(Auth::$cookieName, Auth::encodeSession($user->getId(), $secret), (new \DateTime($expire))->getTimestamp(), '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'));
        }

        if (isset($sessionUpgrade) && $sessionUpgrade) {
            foreach ($user->getAttribute('targets', []) as $target) {
                if ($target->getAttribute('providerType') !== MESSAGE_TYPE_PUSH) {
                    continue;
                }

                $target
                    ->setAttribute('sessionId', $session->getId())
                    ->setAttrubte('sessionInternalId', $session->getInternalId());

                $dbForProject->updateDocument('targets', $target->getId(), $target);
            }
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $state['success']['query'] = URLParser::unparseQuery($query);
        $state['success'] = URLParser::unparse($state['success']);

        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), $duration));

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($state['success'])
        ;
    });

Http::get('/v1/account/tokens/oauth2/:provider')
    ->desc('Create OAuth2 token')
    ->groups(['api', 'account'])
    ->label('error', __DIR__ . '/../../views/general/error.phtml')
    ->label('scope', 'sessions.write')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createOAuth2Token')
    ->label('sdk.description', '/docs/references/account/create-token-oauth2.md')
    ->label('sdk.response.code', Response::STATUS_CODE_MOVED_PERMANENTLY)
    ->label('sdk.response.type', Response::CONTENT_TYPE_HTML)
    ->label('sdk.methodType', 'webAuth')
    ->label('abuse-limit', 50)
    ->label('abuse-key', 'ip:{ip}')
    ->param('provider', '', new WhiteList(\array_keys(Config::getParam('oAuthProviders')), true), 'OAuth2 Provider. Currently, supported providers are: ' . \implode(', ', \array_keys(\array_filter(Config::getParam('oAuthProviders'), fn ($node) => (!$node['mock'])))) . '.')
    ->param('success', '', fn ($clients) => new Host($clients), 'URL to redirect back to your app after a successful login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('failure', '', fn ($clients) => new Host($clients), 'URL to redirect back to your app after a failed login attempt.  Only URLs from hostnames in your project\'s platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('scopes', [], new ArrayList(new Text(APP_LIMIT_ARRAY_ELEMENT_SIZE), APP_LIMIT_ARRAY_PARAMS_SIZE), 'A list of custom OAuth2 scopes. Check each provider internal docs for a list of supported scopes. Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' scopes are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long.', true)
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->action(function (string $provider, string $success, string $failure, array $scopes, Request $request, Response $response, Document $project) use ($oauthDefaultSuccess, $oauthDefaultFailure) {
        $protocol = $request->getProtocol();

        $callback = $protocol . '://' . $request->getHostname() . '/v1/account/sessions/oauth2/callback/' . $provider . '/' . $project->getId();
        $providerEnabled = $project->getAttribute('oAuthProviders', [])[$provider . 'Enabled'] ?? false;

        if (!$providerEnabled) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please enable the provider from your ' . APP_NAME . ' console to continue.');
        }

        $appId = $project->getAttribute('oAuthProviders', [])[$provider . 'Appid'] ?? '';
        $appSecret = $project->getAttribute('oAuthProviders', [])[$provider . 'Secret'] ?? '{}';

        if (!empty($appSecret) && isset($appSecret['version'])) {
            $key = System::getEnv('_APP_OPENSSL_KEY_V' . $appSecret['version']);
            $appSecret = OpenSSL::decrypt($appSecret['data'], $appSecret['method'], $key, 0, \hex2bin($appSecret['iv']), \hex2bin($appSecret['tag']));
        }

        if (empty($appId) || empty($appSecret)) {
            throw new Exception(Exception::PROJECT_PROVIDER_DISABLED, 'This provider is disabled. Please configure the provider app ID and app secret key from your ' . APP_NAME . ' console to continue.');
        }

        $className = 'Appwrite\\Auth\\OAuth2\\' . \ucfirst($provider);

        if (!\class_exists($className)) {
            throw new Exception(Exception::PROJECT_PROVIDER_UNSUPPORTED);
        }

        if (empty($success)) {
            $success = $protocol . '://' . $request->getHostname() . $oauthDefaultSuccess;
        }

        if (empty($failure)) {
            $failure = $protocol . '://' . $request->getHostname() . $oauthDefaultFailure;
        }

        $oauth2 = new $className($appId, $appSecret, $callback, [
            'success' => $success,
            'failure' => $failure,
            'token' => true,
        ], $scopes);

        $response
            ->addHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->addHeader('Pragma', 'no-cache')
            ->redirect($oauth2->getLoginURL());
    });

Http::post('/v1/account/tokens/magic-url')
    ->alias('/v1/account/sessions/magic-url')
    ->desc('Create magic URL token')
    ->groups(['api', 'account', 'auth'])
    ->label('scope', 'sessions.write')
    ->label('auth.type', 'magic-url')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createMagicURLToken')
    ->label('sdk.description', '/docs/references/account/create-token-magic-url.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 60)
    ->label('abuse-key', ['url:{url},email:{param-email}', 'url:{url},ip:{ip}'])
    ->param('userId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', fn ($clients) => new Host($clients), 'URL to redirect the user back to your app from the magic URL login. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', true, ['clients'])
    ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be send with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('queueForEvents')
    ->inject('queueForMails')
    ->inject('authorization')
    ->action(function (string $userId, string $email, string $url, bool $phrase, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Locale $locale, Event $queueForEvents, Mail $queueForMails, Authorization $authorization) {

        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP disabled');
        }

        if ($phrase === true) {
            $phrase = Phrase::generate();
        }

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $result = $dbForProject->findOne('users', [Query::equal('email', [$email])]);
        if ($result !== false && !$result->isEmpty()) {
            $user->setAttributes($result->getArrayCopy());
        } else {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED);
                }
            }

            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
            ]);
            if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::USER_EMAIL_ALREADY_EXISTS);
            }

            $userId = $userId === 'unique()' ? ID::unique() : $userId;

            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => null,
                'hash' => Auth::DEFAULT_ALGO,
                'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'mfa' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'authenticators' => null,
                'search' => implode(' ', [$userId, $email]),
                'accessedAt' => DateTime::now(),
            ]);

            $user->removeAttribute('$internalId');
            $user = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));
        }

        $tokenSecret = Auth::tokenGenerator(Auth::TOKEN_LENGTH_MAGIC_URL);
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_CONFIRM));

        $token = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_MAGIC_URL,
            'secret' => Auth::hash($tokenSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $token = $dbForProject->createDocument('tokens', $token
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        if (empty($url)) {
            $url = $request->getProtocol() . '://' . $request->getHostname() . '/auth/magic-url';
        }

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $tokenSecret, 'expire' => $expire, 'project' => $project->getId()]);
        $url = Template::unParseURL($url);

        $subject = $locale->getText("emails.magicSession.subject");
        $customTemplate = $project->getAttribute('templates', [])['email.magicSession-' . $locale->default] ?? [];

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $agentOs = $detector->getOS();
        $agentClient = $detector->getClient();
        $agentDevice = $detector->getDevice();

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-magic-url.tpl');
        $message
            ->setParam('{{hello}}', $locale->getText("emails.magicSession.hello"))
            ->setParam('{{optionButton}}', $locale->getText("emails.magicSession.optionButton"))
            ->setParam('{{buttonText}}', $locale->getText("emails.magicSession.buttonText"))
            ->setParam('{{optionUrl}}', $locale->getText("emails.magicSession.optionUrl"))
            ->setParam('{{clientInfo}}', $locale->getText("emails.magicSession.clientInfo"))
            ->setParam('{{thanks}}', $locale->getText("emails.magicSession.thanks"))
            ->setParam('{{signature}}', $locale->getText("emails.magicSession.signature"));

        if (!empty($phrase)) {
            $message->setParam('{{securityPhrase}}', $locale->getText("emails.magicSession.securityPhrase"));
        } else {
            $message->setParam('{{securityPhrase}}', '');
        }

        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyTo = "";

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            if (!empty($smtp['replyTo'])) {
                $replyTo = $smtp['replyTo'];
            }

            $queueForMails
                ->setSmtpHost($smtp['host'] ?? '')
                ->setSmtpPort($smtp['port'] ?? '')
                ->setSmtpUsername($smtp['username'] ?? '')
                ->setSmtpPassword($smtp['password'] ?? '')
                ->setSmtpSecure($smtp['secure'] ?? '');

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                if (!empty($customTemplate['replyTo'])) {
                    $replyTo = $customTemplate['replyTo'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $queueForMails
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName);
        }

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{redirect}} and {{project}} are required in default and custom templates
            'user' => $user->getAttribute('name'),
            'project' => $project->getAttribute('name'),
            'redirect' => $url,
            'agentDevice' => $agentDevice['deviceBrand'] ?? $agentDevice['deviceBrand'] ?? 'UNKNOWN',
            'agentClient' => $agentClient['clientName'] ?? 'UNKNOWN',
            'agentOs' => $agentOs['osName'] ?? 'UNKNOWN',
            'phrase' => !empty($phrase) ? $phrase : '',
            // TODO: remove unnecessary team variable from this email
            'team' => '',
        ];

        $queueForMails
            ->setSubject($subject)
            ->setBody($body)
            ->setVariables($emailVariables)
            ->setRecipient($email)
            ->trigger();

        $queueForEvents->setPayload(
            $response->output(
                $token->setAttribute('secret', $tokenSecret),
                Response::MODEL_TOKEN
            )
        );

        // Hide secret for clients
        $token->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $tokenSecret : '');

        if (!empty($phrase)) {
            $token->setAttribute('phrase', $phrase);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN)
        ;
    });

Http::post('/v1/account/tokens/email')
    ->desc('Create email token (OTP)')
    ->groups(['api', 'account', 'auth'])
    ->label('scope', 'sessions.write')
    ->label('auth.type', 'email-otp')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createEmailToken')
    ->label('sdk.description', '/docs/references/account/create-token-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},email:{param-email}')
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('email', '', new Email(), 'User email.')
    ->param('phrase', false, new Boolean(), 'Toggle for security phrase. If enabled, email will be send with a randomly generated phrase and the phrase will also be included in the response. Confirming phrases match increases the security of your authentication flow.', true)
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('queueForEvents')
    ->inject('queueForMails')
    ->inject('authorization')
    ->action(function (string $userId, string $email, bool $phrase, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Locale $locale, Event $queueForEvents, Mail $queueForMails, Authorization $authorization) {
        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP disabled');
        }

        if ($phrase === true) {
            $phrase = Phrase::generate();
        }

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $result = $dbForProject->findOne('users', [Query::equal('email', [$email])]);
        if ($result !== false && !$result->isEmpty()) {
            $user->setAttributes($result->getArrayCopy());
        } else {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED);
                }
            }

            // Makes sure this email is not already used in another identity
            $identityWithMatchingEmail = $dbForProject->findOne('identities', [
                Query::equal('providerEmail', [$email]),
            ]);
            if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
                throw new Exception(Exception::GENERAL_BAD_REQUEST); /** Return a generic bad request to prevent exposing existing accounts */
            }

            $userId = $userId === 'unique()' ? ID::unique() : $userId;

            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => $email,
                'emailVerification' => false,
                'status' => true,
                'password' => null,
                'hash' => Auth::DEFAULT_ALGO,
                'hashOptions' => Auth::DEFAULT_ALGO_OPTIONS,
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $email]),
                'accessedAt' => DateTime::now(),
            ]);

            $user->removeAttribute('$internalId');
            $user = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));
        }

        $tokenSecret = Auth::codeGenerator(6);
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_OTP));

        $token = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_EMAIL,
            'secret' => Auth::hash($tokenSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $token = $dbForProject->createDocument('tokens', $token
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $subject = $locale->getText("emails.otpSession.subject");
        $customTemplate = $project->getAttribute('templates', [])['email.otpSession-' . $locale->default] ?? [];

        $detector = new Detector($request->getUserAgent('UNKNOWN'));
        $agentOs = $detector->getOS();
        $agentClient = $detector->getClient();
        $agentDevice = $detector->getDevice();

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-otp.tpl');
        $message
            ->setParam('{{hello}}', $locale->getText("emails.otpSession.hello"))
            ->setParam('{{description}}', $locale->getText("emails.otpSession.description"))
            ->setParam('{{clientInfo}}', $locale->getText("emails.otpSession.clientInfo"))
            ->setParam('{{thanks}}', $locale->getText("emails.otpSession.thanks"))
            ->setParam('{{signature}}', $locale->getText("emails.otpSession.signature"));

        if (!empty($phrase)) {
            $message->setParam('{{securityPhrase}}', $locale->getText("emails.otpSession.securityPhrase"));
        } else {
            $message->setParam('{{securityPhrase}}', '');
        }

        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyTo = "";

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            if (!empty($smtp['replyTo'])) {
                $replyTo = $smtp['replyTo'];
            }

            $queueForMails
                ->setSmtpHost($smtp['host'] ?? '')
                ->setSmtpPort($smtp['port'] ?? '')
                ->setSmtpUsername($smtp['username'] ?? '')
                ->setSmtpPassword($smtp['password'] ?? '')
                ->setSmtpSecure($smtp['secure'] ?? '');

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                if (!empty($customTemplate['replyTo'])) {
                    $replyTo = $customTemplate['replyTo'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $queueForMails
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName);
        }

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{project}} and {{otp}} are required in the templates
            'user' => $user->getAttribute('name'),
            'project' => $project->getAttribute('name'),
            'otp' => $tokenSecret,
            'agentDevice' => $agentDevice['deviceBrand'] ?? $agentDevice['deviceBrand'] ?? 'UNKNOWN',
            'agentClient' => $agentClient['clientName'] ?? 'UNKNOWN',
            'agentOs' => $agentOs['osName'] ?? 'UNKNOWN',
            'phrase' => !empty($phrase) ? $phrase : '',
            // TODO: remove unnecessary team variable from this email
            'team' => '',
        ];

        $queueForMails
            ->setSubject($subject)
            ->setBody($body)
            ->setVariables($emailVariables)
            ->setRecipient($email)
            ->trigger();

        $queueForEvents->setPayload(
            $response->output(
                $token->setAttribute('secret', $tokenSecret),
                Response::MODEL_TOKEN
            )
        );

        // Hide secret for clients
        $token->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $tokenSecret : '');

        if (!empty($phrase)) {
            $token->setAttribute('phrase', $phrase);
        }

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN)
        ;
    });

Http::put('/v1/account/sessions/magic-url')
    ->desc('Update magic URL session')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->groups(['api', 'account', 'session'])
    ->label('scope', 'sessions.write')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.deprecated', true)
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateMagicURLSession')
    ->label('sdk.description', '/docs/references/account/create-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'ip:{ip},userId:{param-userId}')
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('queueForEvents')
    ->inject('authorization')
    ->action($createSession);

Http::put('/v1/account/sessions/phone')
    ->desc('Update phone session')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->groups(['api', 'account', 'session'])
    ->label('scope', 'sessions.write')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.deprecated', true)
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePhoneSession')
    ->label('sdk.description', '/docs/references/account/create-session.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'ip:{ip},userId:{param-userId}')
    ->param('userId', '', new CustomId(), 'User ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('secret', '', new Text(256), 'Valid verification token.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('geodb')
    ->inject('queueForEvents')
    ->inject('authorization')
    ->action($createSession);

Http::post('/v1/account/tokens/phone')
    ->alias('/v1/account/sessions/phone')
    ->desc('Create phone token')
    ->groups(['api', 'account'])
    ->label('scope', 'sessions.write')
    ->label('auth.type', 'phone')
    ->label('audits.event', 'session.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createPhoneToken')
    ->label('sdk.description', '/docs/references/account/create-token-phone.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},phone:{param-phone}', 'url:{url},ip:{ip}'])
    ->param('userId', '', new CustomId(), 'Unique Id. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForMessaging')
    ->inject('locale')
    ->inject('authorization')
    ->action(function (string $userId, string $phone, Request $request, Response $response, Document $user, Document $project, Database $dbForProject, Event $queueForEvents, Messaging $queueForMessaging, Locale $locale, Authorization $authorization) {
        if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
            throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
        }

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $result = $dbForProject->findOne('users', [Query::equal('phone', [$phone])]);
        if ($result !== false && !$result->isEmpty()) {
            $user->setAttributes($result->getArrayCopy());
        } else {
            $limit = $project->getAttribute('auths', [])['limit'] ?? 0;

            if ($limit !== 0) {
                $total = $dbForProject->count('users', max: APP_LIMIT_USERS);

                if ($total >= $limit) {
                    throw new Exception(Exception::USER_COUNT_EXCEEDED);
                }
            }

            $userId = $userId == 'unique()' ? ID::unique() : $userId;
            $user->setAttributes([
                '$id' => $userId,
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::user($userId)),
                    Permission::delete(Role::user($userId)),
                ],
                'email' => null,
                'phone' => $phone,
                'emailVerification' => false,
                'phoneVerification' => false,
                'status' => true,
                'password' => null,
                'passwordUpdate' => null,
                'registration' => DateTime::now(),
                'reset' => false,
                'prefs' => new \stdClass(),
                'sessions' => null,
                'tokens' => null,
                'memberships' => null,
                'search' => implode(' ', [$userId, $phone]),
                'accessedAt' => DateTime::now(),
            ]);

            $user->removeAttribute('$internalId');
            $user = $authorization->skip(fn () => $dbForProject->createDocument('users', $user));
            try {
                $target = $authorization->skip(fn () => $dbForProject->createDocument('targets', new Document([
                    '$permissions' => [
                        Permission::read(Role::user($user->getId())),
                        Permission::update(Role::user($user->getId())),
                        Permission::delete(Role::user($user->getId())),
                    ],
                    'userId' => $user->getId(),
                    'userInternalId' => $user->getInternalId(),
                    'providerType' => MESSAGE_TYPE_SMS,
                    'identifier' => $phone,
                ])));
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $target]);
            } catch (Duplicate) {
                $existingTarget = $dbForProject->findOne('targets', [
                    Query::equal('identifier', [$phone]),
                ]);
                $user->setAttribute('targets', [...$user->getAttribute('targets', []), $existingTarget]);
            }
            $dbForProject->purgeCachedDocument('users', $user->getId());
        }

        $secret = Auth::codeGenerator();
        $expire = DateTime::formatTz(DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_OTP));

        $token = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_PHONE,
            'secret' => Auth::hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $token = $dbForProject->createDocument('tokens', $token
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/sms-base.tpl');

        $customTemplate = $project->getAttribute('templates', [])['sms.login-' . $locale->default] ?? [];
        if (!empty($customTemplate)) {
            $message = $customTemplate['message'] ?? $message;
        }

        $messageContent = Template::fromString($locale->getText("sms.verification.body"));
        $messageContent
            ->setParam('{{project}}', $project->getAttribute('name'))
            ->setParam('{{secret}}', $secret);
        $messageContent = \strip_tags($messageContent->render());
        $message = $message->setParam('{{token}}', $messageContent);

        $message = $message->render();

        $messageDoc = new Document([
            '$id' => $token->getId(),
            'data' => [
                'content' => $message,
            ],
        ]);

        $queueForMessaging
            ->setType(MESSAGE_SEND_TYPE_INTERNAL)
            ->setMessage($messageDoc)
            ->setRecipients([$phone])
            ->setProviderType(MESSAGE_TYPE_SMS);

        $queueForEvents->setPayload(
            $response->output(
                $token->setAttribute('secret', $secret),
                Response::MODEL_TOKEN
            )
        );

        // Hide secret for clients
        $token->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? Auth::encodeSession($user->getId(), $secret) : '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($token, Response::MODEL_TOKEN)
        ;
    });

Http::post('/v1/account/jwt')
    ->desc('Create JWT')
    ->groups(['api', 'account', 'auth'])
    ->label('scope', 'account')
    ->label('auth.type', 'jwt')
    ->label('sdk.auth', [])
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
            throw new Exception(Exception::USER_SESSION_NOT_FOUND);
        }

        $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 900, 10); // Instantiate with key, algo, maxAge and leeway.

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic(new Document(['jwt' => $jwt->encode([
                // 'uid'    => 1,
                // 'aud'    => 'http://site.com',
                // 'scopes' => ['user'],
                // 'iss'    => 'http://api.mysite.com',
                'userId' => $user->getId(),
                'sessionId' => $current->getId(),
            ])]), Response::MODEL_JWT);
    });

Http::get('/v1/account/prefs')
    ->desc('Get account preferences')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getPrefs')
    ->label('sdk.description', '/docs/references/account/get-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_PREFERENCES)
    ->label('sdk.offline.model', '/account/prefs')
    ->label('sdk.offline.key', 'current')
    ->inject('response')
    ->inject('user')
    ->action(function (Response $response, Document $user) {

        $prefs = $user->getAttribute('prefs', []);

        $response->dynamic(new Document($prefs), Response::MODEL_PREFERENCES);
    });

Http::get('/v1/account/logs')
    ->desc('List logs')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'listLogs')
    ->label('sdk.description', '/docs/references/account/list-logs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_LOG_LIST)
    ->param('queries', [], new Queries([new Limit(), new Offset()]), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Only supported methods are limit and offset', true)
    ->inject('response')
    ->inject('user')
    ->inject('locale')
    ->inject('geodb')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (array $queries, Response $response, Document $user, Locale $locale, Reader $geodb, Database $dbForProject, Authorization $authorization) {

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'] ?? APP_LIMIT_COUNT;
        $offset = $grouped['offset'] ?? 0;

        $audit = new EventAudit($dbForProject, $authorization);

        $logs = $audit->getLogsByUser($user->getInternalId(), $limit, $offset);

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

        $response->dynamic(new Document([
            'total' => $audit->countLogsByUser($user->getInternalId()),
            'logs' => $output,
        ]), Response::MODEL_LOG_LIST);
    });

Http::patch('/v1/account/name')
    ->desc('Update name')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.name')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateName')
    ->label('sdk.description', '/docs/references/account/update-name.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('name', '', new Text(128), 'User name. Max length: 128 chars.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $name, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $queueForEvents) {

        $user->setAttribute('name', $name);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::patch('/v1/account/password')
    ->desc('Update password')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.password')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePassword')
    ->label('sdk.description', '/docs/references/account/update-password.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordDictionary($passwordsDictionary, $project->getAttribute('auths', [])['passwordDictionary'] ?? false), 'New user password. Must be at least 8 chars.', false, ['project', 'passwordsDictionary'])
    ->param('oldPassword', '', new Password(), 'Current user password. Must be at least 8 chars.', true)
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->action(function (string $password, string $oldPassword, ?\DateTime $requestTimestamp, Response $response, Document $user, Document $project, Database $dbForProject, Event $queueForEvents, Hooks $hooks) {

        // Check old password only if its an existing user.
        if (!empty($user->getAttribute('passwordUpdate')) && !Auth::passwordVerify($oldPassword, $user->getAttribute('password'), $user->getAttribute('hash'), $user->getAttribute('hashOptions'))) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $newPassword = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);
        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $user->getAttribute('passwordHistory', []);
        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $user->getAttribute('hash'), $user->getAttribute('hashOptions'));
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
        }

        if ($project->getAttribute('auths', [])['personalDataCheck'] ?? false) {
            $personalDataValidator = new PersonalData($user->getId(), $user->getAttribute('email'), $user->getAttribute('name'), $user->getAttribute('phone'));
            if (!$personalDataValidator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_PERSONAL_DATA);
            }
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, true]);

        $user
            ->setAttribute('password', $newPassword)
            ->setAttribute('passwordHistory', $history)
            ->setAttribute('passwordUpdate', DateTime::now())
            ->setAttribute('hash', Auth::DEFAULT_ALGO)
            ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::patch('/v1/account/email')
    ->desc('Update email')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.email')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateEmail')
    ->label('sdk.description', '/docs/references/account/update-email.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('email', '', new Email(), 'User email.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('project')
    ->inject('hooks')
    ->inject('authorization')
    ->action(function (string $email, string $password, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $queueForEvents, Document $project, Hooks $hooks, Authorization $authorization) {
        // passwordUpdate will be empty if the user has never set a password
        $passwordUpdate = $user->getAttribute('passwordUpdate');

        if (
            !empty($passwordUpdate) &&
            !Auth::passwordVerify($password, $user->getAttribute('password'), $user->getAttribute('hash'), $user->getAttribute('hashOptions'))
        ) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, false]);

        $oldEmail = $user->getAttribute('email');

        $email = \strtolower($email);

        // Makes sure this email is not already used in another identity
        $identityWithMatchingEmail = $dbForProject->findOne('identities', [
            Query::equal('providerEmail', [$email]),
            Query::notEqual('userId', $user->getId()),
        ]);
        if ($identityWithMatchingEmail !== false && !$identityWithMatchingEmail->isEmpty()) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST); /** Return a generic bad request to prevent exposing existing accounts */
        }

        $user
            ->setAttribute('email', $email)
            ->setAttribute('emailVerification', false) // After this user needs to confirm mail again
        ;

        if (empty($passwordUpdate)) {
            $user
                ->setAttribute('password', Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS))
                ->setAttribute('hash', Auth::DEFAULT_ALGO)
                ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS)
                ->setAttribute('passwordUpdate', DateTime::now());
        }

        $target = $authorization->skip(fn () => $dbForProject->findOne('targets', [
            Query::equal('identifier', [$email]),
        ]));

        if ($target instanceof Document && !$target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        try {
            $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));
            /**
             * @var Document $oldTarget
             */
            $oldTarget = $user->find('identifier', $oldEmail, 'targets');

            if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                $authorization->skip(fn () => $dbForProject->updateDocument('targets', $oldTarget->getId(), $oldTarget->setAttribute('identifier', $email)));
            }
            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate) {
            throw new Exception(Exception::GENERAL_BAD_REQUEST); /** Return a generic bad request to prevent exposing existing accounts */
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::patch('/v1/account/phone')
    ->desc('Update phone')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.phone')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePhone')
    ->label('sdk.description', '/docs/references/account/update-phone.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('phone', '', new Phone(), 'Phone number. Format this number with a leading \'+\' and a country code, e.g., +16175551212.')
    ->param('password', '', new Password(), 'User password. Must be at least 8 chars.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('project')
    ->inject('hooks')
    ->inject('authorization')
    ->action(function (string $phone, string $password, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $queueForEvents, Document $project, Hooks $hooks, Authorization $authorization) {
        // passwordUpdate will be empty if the user has never set a password
        $passwordUpdate = $user->getAttribute('passwordUpdate');

        if (
            !empty($passwordUpdate) &&
            !Auth::passwordVerify($password, $user->getAttribute('password'), $user->getAttribute('hash'), $user->getAttribute('hashOptions'))
        ) { // Double check user password
            throw new Exception(Exception::USER_INVALID_CREDENTIALS);
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, false]);

        $target = $authorization->skip(fn () => $dbForProject->findOne('targets', [
            Query::equal('identifier', [$phone]),
        ]));

        if ($target instanceof Document && !$target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        $oldPhone = $user->getAttribute('phone');

        $user
            ->setAttribute('phone', $phone)
            ->setAttribute('phoneVerification', false) // After this user needs to confirm phone number again
        ;

        if (empty($passwordUpdate)) {
            $user
                ->setAttribute('password', Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS))
                ->setAttribute('hash', Auth::DEFAULT_ALGO)
                ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS)
                ->setAttribute('passwordUpdate', DateTime::now());
        }

        try {
            $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));
            /**
             * @var Document $oldTarget
             */
            $oldTarget = $user->find('identifier', $oldPhone, 'targets');

            if ($oldTarget instanceof Document && !$oldTarget->isEmpty()) {
                $authorization->skip(fn () => $dbForProject->updateDocument('targets', $oldTarget->getId(), $oldTarget->setAttribute('identifier', $phone)));
            }
            $dbForProject->purgeCachedDocument('users', $user->getId());
        } catch (Duplicate $th) {
            throw new Exception(Exception::USER_PHONE_ALREADY_EXISTS);
        }

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::patch('/v1/account/prefs')
    ->desc('Update preferences')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.prefs')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePrefs')
    ->label('sdk.description', '/docs/references/account/update-prefs.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account/prefs')
    ->label('sdk.offline.key', 'current')
    ->param('prefs', [], new Assoc(), 'Prefs key-value JSON object.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (array $prefs, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $queueForEvents) {

        $user->setAttribute('prefs', $prefs);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::patch('/v1/account/status')
    ->desc('Update status')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.status')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateStatus')
    ->label('sdk.description', '/docs/references/account/update-status.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->inject('requestTimestamp')
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (?\DateTime $requestTimestamp, Request $request, Response $response, Document $user, Database $dbForProject, Event $queueForEvents) {

        $user->setAttribute('status', false);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setPayload($response->output($user, Response::MODEL_ACCOUNT));

        if (!Config::getParam('domainVerification')) {
            $response->addHeader('X-Fallback-Cookies', \json_encode([]));
        }

        $protocol = $request->getProtocol();
        $response
            ->addCookie(Auth::$cookieName . '_legacy', '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, null)
            ->addCookie(Auth::$cookieName, '', \time() - 3600, '/', Config::getParam('cookieDomain'), ('https' == $protocol), true, Config::getParam('cookieSamesite'))
        ;

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::post('/v1/account/recovery')
    ->desc('Create password recovery')
    ->groups(['api', 'account'])
    ->label('scope', 'sessions.write')
    ->label('event', 'users.[userId].recovery.[tokenId].create')
    ->label('audits.event', 'recovery.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createRecovery')
    ->label('sdk.description', '/docs/references/account/create-recovery.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},email:{param-email}', 'url:{url},ip:{ip}'])
    ->param('email', '', new Email(), 'User email.')
    ->param('url', '', fn ($clients) => new Host($clients), 'URL to redirect the user back to your app from the recovery email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients'])
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('locale')
    ->inject('queueForMails')
    ->inject('queueForEvents')
    ->inject('authorization')
    ->action(function (string $email, string $url, Request $request, Response $response, Document $user, Database $dbForProject, Document $project, Locale $locale, Mail $queueForMails, Event $queueForEvents, Authorization $authorization) {

        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);

        $email = \strtolower($email);

        $profile = $dbForProject->findOne('users', [
            Query::equal('email', [$email]),
        ]);

        if (!$profile) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $user->setAttributes($profile->getArrayCopy());

        if (false === $profile->getAttribute('status')) { // Account is blocked
            throw new Exception(Exception::USER_BLOCKED);
        }

        $expire = DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_RECOVERY);

        $secret = Auth::tokenGenerator(Auth::TOKEN_LENGTH_RECOVERY);
        $recovery = new Document([
            '$id' => ID::unique(),
            'userId' => $profile->getId(),
            'userInternalId' => $profile->getInternalId(),
            'type' => Auth::TOKEN_TYPE_RECOVERY,
            'secret' => Auth::hash($secret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($profile->getId())->toString());

        $recovery = $dbForProject->createDocument('tokens', $recovery
            ->setAttribute('$permissions', [
                Permission::read(Role::user($profile->getId())),
                Permission::update(Role::user($profile->getId())),
                Permission::delete(Role::user($profile->getId())),
            ]));

        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $profile->getId(), 'secret' => $secret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $projectName = $project->isEmpty() ? 'Console' : $project->getAttribute('name', '[APP-NAME]');
        $body = $locale->getText("emails.recovery.body");
        $subject = $locale->getText("emails.recovery.subject");
        $customTemplate = $project->getAttribute('templates', [])['email.recovery-' . $locale->default] ?? [];

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
        $message
            ->setParam('{{body}}', $body, escape: false)
            ->setParam('{{hello}}', $locale->getText("emails.recovery.hello"))
            ->setParam('{{footer}}', $locale->getText("emails.recovery.footer"))
            ->setParam('{{thanks}}', $locale->getText("emails.recovery.thanks"))
            ->setParam('{{signature}}', $locale->getText("emails.recovery.signature"));
        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyTo = "";

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            if (!empty($smtp['replyTo'])) {
                $replyTo = $smtp['replyTo'];
            }

            $queueForMails
                ->setSmtpHost($smtp['host'] ?? '')
                ->setSmtpPort($smtp['port'] ?? '')
                ->setSmtpUsername($smtp['username'] ?? '')
                ->setSmtpPassword($smtp['password'] ?? '')
                ->setSmtpSecure($smtp['secure'] ?? '');

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                if (!empty($customTemplate['replyTo'])) {
                    $replyTo = $customTemplate['replyTo'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $queueForMails
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName);
        }

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{redirect}} and {{project}} are required in default and custom templates
            'user' => $profile->getAttribute('name'),
            'redirect' => $url,
            'project' => $projectName,
            // TODO: remove unnecessary team variable from this email
            'team' => ''
        ];

        $queueForMails
            ->setRecipient($profile->getAttribute('email', ''))
            ->setName($profile->getAttribute('name', ''))
            ->setBody($body)
            ->setVariables($emailVariables)
            ->setSubject($subject)
            ->trigger();

        $queueForEvents
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

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($recovery, Response::MODEL_TOKEN);
    });

Http::put('/v1/account/recovery')
    ->desc('Create password recovery (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'sessions.write')
    ->label('event', 'users.[userId].recovery.[tokenId].update')
    ->label('audits.event', 'recovery.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
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
    ->param('password', '', fn ($project, $passwordsDictionary) => new PasswordDictionary($passwordsDictionary, $project->getAttribute('auths', [])['passwordDictionary'] ?? false), 'New user password. Must be between 8 and 256 chars.', false, ['project', 'passwordsDictionary'])
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('project')
    ->inject('queueForEvents')
    ->inject('hooks')
    ->inject('authorization')
    ->action(function (string $userId, string $secret, string $password, Response $response, Document $user, Database $dbForProject, Document $project, Event $queueForEvents, Hooks $hooks, Authorization $authorization) {
        $profile = $dbForProject->getDocument('users', $userId);

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $tokens = $profile->getAttribute('tokens', []);
        $verifiedToken = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_RECOVERY, $secret);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $newPassword = Auth::passwordHash($password, Auth::DEFAULT_ALGO, Auth::DEFAULT_ALGO_OPTIONS);

        $historyLimit = $project->getAttribute('auths', [])['passwordHistory'] ?? 0;
        $history = $profile->getAttribute('passwordHistory', []);
        if ($historyLimit > 0) {
            $validator = new PasswordHistory($history, $profile->getAttribute('hash'), $profile->getAttribute('hashOptions'));
            if (!$validator->isValid($password)) {
                throw new Exception(Exception::USER_PASSWORD_RECENTLY_USED);
            }

            $history[] = $newPassword;
            $history = array_slice($history, (count($history) - $historyLimit), $historyLimit);
        }

        $hooks->trigger('passwordValidator', [$dbForProject, $project, $password, &$user, true]);

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile
                ->setAttribute('password', $newPassword)
                ->setAttribute('passwordHistory', $history)
                ->setAttribute('passwordUpdate', DateTime::now())
                ->setAttribute('hash', Auth::DEFAULT_ALGO)
                ->setAttribute('hashOptions', Auth::DEFAULT_ALGO_OPTIONS)
                ->setAttribute('emailVerification', true));

        $user->setAttributes($profile->getArrayCopy());

        $recoveryDocument = $dbForProject->getDocument('tokens', $verifiedToken->getId());

        /**
         * We act like we're updating and validating
         *  the recovery token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $verifiedToken->getId());
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $profile->getId())
            ->setParam('tokenId', $recoveryDocument->getId())
        ;

        $response->dynamic($recoveryDocument, Response::MODEL_TOKEN);
    });

Http::post('/v1/account/verification')
    ->desc('Create email verification')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('audits.event', 'verification.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createVerification')
    ->label('sdk.description', '/docs/references/account/create-email-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},userId:{userId}')
    ->param('url', '', fn ($clients) => new Host($clients), 'URL to redirect the user back to your app from the verification email. Only URLs from hostnames in your project platform list are allowed. This requirement helps to prevent an [open redirect](https://cheatsheetseries.owasp.org/cheatsheets/Unvalidated_Redirects_and_Forwards_Cheat_Sheet.html) attack against your project API.', false, ['clients']) // TODO add built-in confirm page
    ->inject('request')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('locale')
    ->inject('queueForEvents')
    ->inject('queueForMails')
    ->inject('authorization')
    ->action(function (string $url, Request $request, Response $response, Document $project, Document $user, Database $dbForProject, Locale $locale, Event $queueForEvents, Mail $queueForMails, Authorization $authorization) {

        if (empty(System::getEnv('_APP_SMTP_HOST'))) {
            throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP Disabled');
        }

        if ($user->getAttribute('emailVerification')) {
            throw new Exception(Exception::USER_EMAIL_ALREADY_VERIFIED);
        }

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        $verificationSecret = Auth::tokenGenerator(Auth::TOKEN_LENGTH_VERIFICATION);
        $expire = DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_CONFIRM);

        $verification = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_VERIFICATION,
            'secret' => Auth::hash($verificationSecret), // One way hash encryption to protect DB leak
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $verification = $dbForProject->createDocument('tokens', $verification
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $url = Template::parseURL($url);
        $url['query'] = Template::mergeQuery(((isset($url['query'])) ? $url['query'] : ''), ['userId' => $user->getId(), 'secret' => $verificationSecret, 'expire' => $expire]);
        $url = Template::unParseURL($url);

        $projectName = $project->isEmpty() ? 'Console' : $project->getAttribute('name', '[APP-NAME]');
        $body = $locale->getText("emails.verification.body");
        $subject = $locale->getText("emails.verification.subject");
        $customTemplate = $project->getAttribute('templates', [])['email.verification-' . $locale->default] ?? [];

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-inner-base.tpl');
        $message
            ->setParam('{{body}}', $body, escape: false)
            ->setParam('{{hello}}', $locale->getText("emails.verification.hello"))
            ->setParam('{{footer}}', $locale->getText("emails.verification.footer"))
            ->setParam('{{thanks}}', $locale->getText("emails.verification.thanks"))
            ->setParam('{{signature}}', $locale->getText("emails.verification.signature"));
        $body = $message->render();

        $smtp = $project->getAttribute('smtp', []);
        $smtpEnabled = $smtp['enabled'] ?? false;

        $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
        $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
        $replyTo = "";

        if ($smtpEnabled) {
            if (!empty($smtp['senderEmail'])) {
                $senderEmail = $smtp['senderEmail'];
            }
            if (!empty($smtp['senderName'])) {
                $senderName = $smtp['senderName'];
            }
            if (!empty($smtp['replyTo'])) {
                $replyTo = $smtp['replyTo'];
            }

            $queueForMails
                ->setSmtpHost($smtp['host'] ?? '')
                ->setSmtpPort($smtp['port'] ?? '')
                ->setSmtpUsername($smtp['username'] ?? '')
                ->setSmtpPassword($smtp['password'] ?? '')
                ->setSmtpSecure($smtp['secure'] ?? '');

            if (!empty($customTemplate)) {
                if (!empty($customTemplate['senderEmail'])) {
                    $senderEmail = $customTemplate['senderEmail'];
                }
                if (!empty($customTemplate['senderName'])) {
                    $senderName = $customTemplate['senderName'];
                }
                if (!empty($customTemplate['replyTo'])) {
                    $replyTo = $customTemplate['replyTo'];
                }

                $body = $customTemplate['message'] ?? '';
                $subject = $customTemplate['subject'] ?? $subject;
            }

            $queueForMails
                ->setSmtpReplyTo($replyTo)
                ->setSmtpSenderEmail($senderEmail)
                ->setSmtpSenderName($senderName);
        }

        $emailVariables = [
            'direction' => $locale->getText('settings.direction'),
            // {{user}}, {{redirect}} and {{project}} are required in default and custom templates
            'user' => $user->getAttribute('name'),
            'redirect' => $url,
            'project' => $projectName,
            // TODO: remove unnecessary team variable from this email
            'team' => '',
        ];

        $queueForMails
            ->setSubject($subject)
            ->setBody($body)
            ->setVariables($emailVariables)
            ->setRecipient($user->getAttribute('email'))
            ->setName($user->getAttribute('name') ?? '')
            ->trigger();

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->output(
                $verification->setAttribute('secret', $verificationSecret),
                Response::MODEL_TOKEN
            ));

        // Hide secret for clients
        $verification->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $verificationSecret : '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    });

Http::put('/v1/account/verification')
    ->desc('Create email verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.userId}')
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
    ->inject('queueForEvents')
    ->inject('authorization')
    ->action(function (string $userId, string $secret, Response $response, Document $user, Database $dbForProject, Event $queueForEvents, Authorization $authorization) {

        $profile = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $tokens = $profile->getAttribute('tokens', []);
        $verifiedToken = Auth::tokenVerify($tokens, Auth::TOKEN_TYPE_VERIFICATION, $secret);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('emailVerification', true));

        $user->setAttributes($profile->getArrayCopy());

        $verificationDocument = $dbForProject->getDocument('tokens', $verifiedToken->getId());

        /**
         * We act like we're updating and validating
         *  the verification token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $verifiedToken->getId());
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $userId)
            ->setParam('tokenId', $verificationDocument->getId())
        ;

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    });

Http::post('/v1/account/verification/phone')
    ->desc('Create phone verification')
    ->groups(['api', 'account', 'auth'])
    ->label('scope', 'account')
    ->label('auth.type', 'phone')
    ->label('event', 'users.[userId].verification.[tokenId].create')
    ->label('audits.event', 'verification.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createPhoneVerification')
    ->label('sdk.description', '/docs/references/account/create-phone-verification.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TOKEN)
    ->label('abuse-limit', 10)
    ->label('abuse-key', ['url:{url},userId:{userId}', 'url:{url},ip:{ip}'])
    ->inject('request')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->inject('queueForMessaging')
    ->inject('project')
    ->inject('locale')
    ->inject('authorization')
    ->action(function (Request $request, Response $response, Document $user, Database $dbForProject, Event $queueForEvents, Messaging $queueForMessaging, Document $project, Locale $locale, Authorization $authorization) {
        if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
            throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
        }

        if (empty($user->getAttribute('phone'))) {
            throw new Exception(Exception::USER_PHONE_NOT_FOUND);
        }

        if ($user->getAttribute('phoneVerification')) {
            throw new Exception(Exception::USER_PHONE_ALREADY_VERIFIED);
        }

        $roles = $authorization->getRoles();
        $isPrivilegedUser = Auth::isPrivilegedUser($roles);
        $isAppUser = Auth::isAppUser($roles);
        $secret = Auth::codeGenerator();
        $expire = DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_CONFIRM);

        $verification = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Auth::TOKEN_TYPE_PHONE,
            'secret' => Auth::hash($secret),
            'expire' => $expire,
            'userAgent' => $request->getUserAgent('UNKNOWN'),
            'ip' => $request->getIP(),
        ]);

        $authorization->addRole(Role::user($user->getId())->toString());

        $verification = $dbForProject->createDocument('tokens', $verification
            ->setAttribute('$permissions', [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]));

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/sms-base.tpl');

        $customTemplate = $project->getAttribute('templates', [])['sms.verification-' . $locale->default] ?? [];
        if (!empty($customTemplate)) {
            $message = $customTemplate['message'] ?? $message;
        }

        $messageContent = Template::fromString($locale->getText("sms.verification.body"));
        $messageContent
            ->setParam('{{project}}', $project->getAttribute('name'))
            ->setParam('{{secret}}', $secret);
        $messageContent = \strip_tags($messageContent->render());
        $message = $message->setParam('{{token}}', $messageContent);

        $message = $message->render();

        $messageDoc = new Document([
            '$id' => $verification->getId(),
            'data' => [
                'content' => $message,
            ],
        ]);

        $queueForMessaging
            ->setType(MESSAGE_SEND_TYPE_INTERNAL)
            ->setMessage($messageDoc)
            ->setRecipients([$user->getAttribute('phone')])
            ->setProviderType(MESSAGE_TYPE_SMS);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verification->getId())
            ->setPayload($response->output(
                $verification->setAttribute('secret', $secret),
                Response::MODEL_TOKEN
            ))
        ;

        // Hide secret for clients
        $verification->setAttribute('secret', ($isPrivilegedUser || $isAppUser) ? $secret : '');

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($verification, Response::MODEL_TOKEN);
    });

Http::put('/v1/account/verification/phone')
    ->desc('Create phone verification (confirmation)')
    ->groups(['api', 'account'])
    ->label('scope', 'public')
    ->label('event', 'users.[userId].verification.[tokenId].update')
    ->label('audits.event', 'verification.update')
    ->label('audits.resource', 'user/{response.userId}')
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
    ->inject('queueForEvents')
    ->inject('authorization')
    ->action(function (string $userId, string $secret, Response $response, Document $user, Database $dbForProject, Event $queueForEvents, Authorization $authorization) {

        $profile = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

        if ($profile->isEmpty()) {
            throw new Exception(Exception::USER_NOT_FOUND);
        }

        $verifiedToken = Auth::tokenVerify($user->getAttribute('tokens', []), Auth::TOKEN_TYPE_PHONE, $secret);

        if (!$verifiedToken) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authorization->addRole(Role::user($profile->getId())->toString());

        $profile = $dbForProject->updateDocument('users', $profile->getId(), $profile->setAttribute('phoneVerification', true));

        $user->setAttributes($profile->getArrayCopy());

        $verificationDocument = $dbForProject->getDocument('tokens', $verifiedToken->getId());

        /**
         * We act like we're updating and validating the verification token but actually we don't need it anymore.
         */
        $dbForProject->deleteDocument('tokens', $verifiedToken->getId());
        $dbForProject->purgeCachedDocument('users', $profile->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('tokenId', $verificationDocument->getId())
        ;

        $response->dynamic($verificationDocument, Response::MODEL_TOKEN);
    });

Http::patch('/v1/account/mfa')
    ->desc('Update MFA')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.mfa')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateMFA')
    ->label('sdk.description', '/docs/references/account/update-mfa.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('mfa', null, new Boolean(), 'Enable or disable MFA.')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (bool $mfa, ?\DateTime $requestTimestamp, Response $response, Document $user, Database $dbForProject, Event $queueForEvents) {

        $user->setAttribute('mfa', $mfa);

        $user = $dbForProject->withRequestTimestamp($requestTimestamp, fn () => $dbForProject->updateDocument('users', $user->getId(), $user));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::get('/v1/account/mfa/factors')
    ->desc('List Factors')
    ->groups(['api', 'account', 'mfa'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'listMfaFactors')
    ->label('sdk.description', '/docs/references/account/list-mfa-factors.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_FACTORS)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->inject('response')
    ->inject('user')
    ->action(function (Response $response, Document $user) {

        $totp = TOTP::getAuthenticatorFromUser($user);

        $factors = new Document([
            Type::TOTP => $totp !== null && $totp->getAttribute('verified', false),
            Type::EMAIL => $user->getAttribute('email', false) && $user->getAttribute('emailVerification', false),
            Type::PHONE => $user->getAttribute('phone', false) && $user->getAttribute('phoneVerification', false)
        ]);

        $response->dynamic($factors, Response::MODEL_MFA_FACTORS);
    });

Http::post('/v1/account/mfa/authenticators/:type')
    ->desc('Add Authenticator')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.mfa')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createMfaAuthenticator')
    ->label('sdk.description', '/docs/references/account/create-mfa-authenticator.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_TYPE)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('type', null, new WhiteList([Type::TOTP]), 'Type of authenticator. Must be `' . Type::TOTP . '`')
    ->inject('requestTimestamp')
    ->inject('response')
    ->inject('project')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $type, ?\DateTime $requestTimestamp, Response $response, Document $project, Document $user, Database $dbForProject, Event $queueForEvents) {

        $otp = (match ($type) {
            Type::TOTP => new TOTP(),
            default => throw new Exception(Exception::GENERAL_ARGUMENT_INVALID, 'Unknown type.') // Ideally never happens if param validator stays always in sync
        });

        $otp->setLabel($user->getAttribute('email'));
        $otp->setIssuer($project->getAttribute('name'));

        $authenticator = TOTP::getAuthenticatorFromUser($user);

        if ($authenticator) {
            if ($authenticator->getAttribute('verified')) {
                throw new Exception(Exception::USER_AUTHENTICATOR_ALREADY_VERIFIED);
            }
            $dbForProject->deleteDocument('authenticators', $authenticator->getId());
        }

        $authenticator = new Document([
            '$id' => ID::unique(),
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => Type::TOTP,
            'verified' => false,
            'data' => [
                'secret' => $otp->getSecret(),
            ],
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ]
        ]);

        $model = new Document([
            'secret' => $otp->getSecret(),
            'uri' => $otp->getProvisioningUri()
        ]);

        $authenticator = $dbForProject->createDocument('authenticators', $authenticator);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($model, Response::MODEL_MFA_TYPE);
    });

Http::put('/v1/account/mfa/authenticators/:type')
    ->desc('Verify Authenticator')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.mfa')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateMfaAuthenticator')
    ->label('sdk.description', '/docs/references/account/update-mfa-authenticator.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->param('type', null, new WhiteList([Type::TOTP]), 'Type of authenticator.')
    ->param('otp', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('project')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $type, string $otp, Response $response, Document $user, Document $project, Database $dbForProject, Event $queueForEvents) {

        $authenticator = (match ($type) {
            Type::TOTP => TOTP::getAuthenticatorFromUser($user),
            default => null
        });

        if ($authenticator === null) {
            throw new Exception(Exception::USER_AUTHENTICATOR_NOT_FOUND);
        }

        if ($authenticator->getAttribute('verified')) {
            throw new Exception(Exception::USER_AUTHENTICATOR_ALREADY_VERIFIED);
        }

        $success = (match ($type) {
            Type::TOTP => Challenge\TOTP::verify($user, $otp),
            default => false
        });

        if (!$success) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $authenticator->setAttribute('verified', true);

        $dbForProject->updateDocument('authenticators', $authenticator->getId(), $authenticator);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $sessionId = Auth::sessionVerify($user->getAttribute('sessions', []), Auth::$secret, $authDuration);
        $session = $dbForProject->getDocument('sessions', $sessionId);
        $dbForProject->updateDocument('sessions', $sessionId, $session->setAttribute('factors', $type, Document::SET_TYPE_APPEND));

        $queueForEvents->setParam('userId', $user->getId());

        $response->dynamic($user, Response::MODEL_ACCOUNT);
    });

Http::post('/v1/account/mfa/recovery-codes')
    ->desc('Create MFA Recovery Codes')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].update.mfa')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createMfaRecoveryCodes')
    ->label('sdk.description', '/docs/references/account/create-mfa-recovery-codes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_RECOVERY_CODES)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (Response $response, Document $user, Database $dbForProject, Event $queueForEvents) {

        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);

        if (!empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_ALREADY_EXISTS);
        }

        $mfaRecoveryCodes = Type::generateBackupCodes();
        $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
        $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    });

Http::patch('/v1/account/mfa/recovery-codes')
    ->desc('Regenerate MFA Recovery Codes')
    ->groups(['api', 'account', 'mfaProtected'])
    ->label('event', 'users.[userId].update.mfa')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateMfaRecoveryCodes')
    ->label('sdk.description', '/docs/references/account/update-mfa-recovery-codes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_RECOVERY_CODES)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->inject('dbForProject')
    ->inject('response')
    ->inject('user')
    ->inject('queueForEvents')
    ->action(function (Database $dbForProject, Response $response, Document $user, Event $queueForEvents) {

        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);
        if (empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_NOT_FOUND);
        }

        $mfaRecoveryCodes = Type::generateBackupCodes();
        $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
        $dbForProject->updateDocument('users', $user->getId(), $user);

        $queueForEvents->setParam('userId', $user->getId());

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    });

Http::get('/v1/account/mfa/recovery-codes')
    ->desc('Get MFA Recovery Codes')
    ->groups(['api', 'account', 'mfaProtected'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'getMfaRecoveryCodes')
    ->label('sdk.description', '/docs/references/account/get-mfa-recovery-codes.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_RECOVERY_CODES)
    ->label('sdk.offline.model', '/account')
    ->label('sdk.offline.key', 'current')
    ->inject('response')
    ->inject('user')
    ->action(function (Response $response, Document $user) {

        $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);

        if (empty($mfaRecoveryCodes)) {
            throw new Exception(Exception::USER_RECOVERY_CODES_NOT_FOUND);
        }

        $document = new Document([
            'recoveryCodes' => $mfaRecoveryCodes
        ]);

        $response->dynamic($document, Response::MODEL_MFA_RECOVERY_CODES);
    });

Http::delete('/v1/account/mfa/authenticators/:type')
    ->desc('Delete Authenticator')
    ->groups(['api', 'account'])
    ->label('event', 'users.[userId].delete.mfa')
    ->label('scope', 'account')
    ->label('audits.event', 'user.update')
    ->label('audits.resource', 'user/{response.$id}')
    ->label('audits.userId', '{response.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteMfaAuthenticator')
    ->label('sdk.description', '/docs/references/account/delete-mfa-authenticator.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_USER)
    ->param('type', null, new WhiteList([Type::TOTP]), 'Type of authenticator.')
    ->param('otp', '', new Text(256), 'Valid verification token.')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $type, string $otp, Response $response, Document $user, Database $dbForProject, Event $queueForEvents) {

        $authenticator = (match ($type) {
            Type::TOTP => TOTP::getAuthenticatorFromUser($user),
            default => null
        });

        if (!$authenticator) {
            throw new Exception(Exception::USER_AUTHENTICATOR_NOT_FOUND);
        }

        $success = (match ($type) {
            Type::TOTP => Challenge\TOTP::verify($user, $otp),
            default => false
        });

        if (!$success) {
            $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);
            if (in_array($otp, $mfaRecoveryCodes)) {
                $mfaRecoveryCodes = array_diff($mfaRecoveryCodes, [$otp]);
                $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
                $dbForProject->updateDocument('users', $user->getId(), $user);

                $success = true;
            }
        }

        if (!$success) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $dbForProject->deleteDocument('authenticators', $authenticator->getId());
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents->setParam('userId', $user->getId());

        $response->noContent();
    });

Http::post('/v1/account/mfa/challenge')
    ->desc('Create 2FA Challenge')
    ->groups(['api', 'account', 'mfa'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].challenges.[challengeId].create')
    ->label('audits.event', 'challenge.create')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createMfaChallenge')
    ->label('sdk.description', '/docs/references/account/create-mfa-challenge.md')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_MFA_CHALLENGE)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'url:{url},token:{param-token}')
    ->param('factor', '', new WhiteList([Type::EMAIL, Type::PHONE, Type::TOTP, Type::RECOVERY_CODE]), 'Factor used for verification. Must be one of following: `' . Type::EMAIL . '`, `' . Type::PHONE . '`, `' . Type::TOTP . '`, `' . Type::RECOVERY_CODE . '`.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('user')
    ->inject('locale')
    ->inject('project')
    ->inject('request')
    ->inject('queueForEvents')
    ->inject('queueForMessaging')
    ->inject('queueForMails')
    ->action(function (string $factor, Response $response, Database $dbForProject, Document $user, Locale $locale, Document $project, Request $request, Event $queueForEvents, Messaging $queueForMessaging, Mail $queueForMails) {

        $expire = DateTime::addSeconds(new \DateTime(), Auth::TOKEN_EXPIRATION_CONFIRM);
        $code = Auth::codeGenerator();
        $challenge = new Document([
            'userId' => $user->getId(),
            'userInternalId' => $user->getInternalId(),
            'type' => $factor,
            'token' => Auth::tokenGenerator(),
            'code' => $code,
            'expire' => $expire,
            '$permissions' => [
                Permission::read(Role::user($user->getId())),
                Permission::update(Role::user($user->getId())),
                Permission::delete(Role::user($user->getId())),
            ],
        ]);

        $challenge = $dbForProject->createDocument('challenges', $challenge);

        switch ($factor) {
            case Type::PHONE:
                if (empty(System::getEnv('_APP_SMS_PROVIDER'))) {
                    throw new Exception(Exception::GENERAL_PHONE_DISABLED, 'Phone provider not configured');
                }
                if (empty($user->getAttribute('phone'))) {
                    throw new Exception(Exception::USER_PHONE_NOT_FOUND);
                }
                if (!$user->getAttribute('phoneVerification')) {
                    throw new Exception(Exception::USER_PHONE_NOT_VERIFIED);
                }

                $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/sms-base.tpl');

                $customTemplate = $project->getAttribute('templates', [])['sms.mfaChallenge-' . $locale->default] ?? [];
                if (!empty($customTemplate)) {
                    $message = $customTemplate['message'] ?? $message;
                }

                $messageContent = Template::fromString($locale->getText("sms.verification.body"));
                $messageContent
                    ->setParam('{{project}}', $project->getAttribute('name'))
                    ->setParam('{{secret}}', $code);
                $messageContent = \strip_tags($messageContent->render());
                $message = $message->setParam('{{token}}', $messageContent);

                $message = $message->render();

                $queueForMessaging
                    ->setType(MESSAGE_SEND_TYPE_INTERNAL)
                    ->setMessage(new Document([
                        '$id' => $challenge->getId(),
                        'data' => [
                            'content' => $code,
                        ],
                    ]))
                    ->setRecipients([$user->getAttribute('phone')])
                    ->setProviderType(MESSAGE_TYPE_SMS);
                break;
            case Type::EMAIL:
                if (empty(System::getEnv('_APP_SMTP_HOST'))) {
                    throw new Exception(Exception::GENERAL_SMTP_DISABLED, 'SMTP disabled');
                }
                if (empty($user->getAttribute('email'))) {
                    throw new Exception(Exception::USER_EMAIL_NOT_FOUND);
                }
                if (!$user->getAttribute('emailVerification')) {
                    throw new Exception(Exception::USER_EMAIL_NOT_VERIFIED);
                }

                $subject = $locale->getText("emails.mfaChallenge.subject");
                $customTemplate = $project->getAttribute('templates', [])['email.mfaChallenge-' . $locale->default] ?? [];

                $detector = new Detector($request->getUserAgent('UNKNOWN'));
                $agentOs = $detector->getOS();
                $agentClient = $detector->getClient();
                $agentDevice = $detector->getDevice();

                $message = Template::fromFile(__DIR__ . '/../../config/locale/templates/email-mfa-challenge.tpl');
                $message
                    ->setParam('{{hello}}', $locale->getText("emails.mfaChallenge.hello"))
                    ->setParam('{{description}}', $locale->getText("emails.mfaChallenge.description"))
                    ->setParam('{{clientInfo}}', $locale->getText("emails.mfaChallenge.clientInfo"))
                    ->setParam('{{thanks}}', $locale->getText("emails.mfaChallenge.thanks"))
                    ->setParam('{{signature}}', $locale->getText("emails.mfaChallenge.signature"));

                $body = $message->render();

                $smtp = $project->getAttribute('smtp', []);
                $smtpEnabled = $smtp['enabled'] ?? false;

                $senderEmail = System::getEnv('_APP_SYSTEM_EMAIL_ADDRESS', APP_EMAIL_TEAM);
                $senderName = System::getEnv('_APP_SYSTEM_EMAIL_NAME', APP_NAME . ' Server');
                $replyTo = "";

                if ($smtpEnabled) {
                    if (!empty($smtp['senderEmail'])) {
                        $senderEmail = $smtp['senderEmail'];
                    }
                    if (!empty($smtp['senderName'])) {
                        $senderName = $smtp['senderName'];
                    }
                    if (!empty($smtp['replyTo'])) {
                        $replyTo = $smtp['replyTo'];
                    }

                    $queueForMails
                        ->setSmtpHost($smtp['host'] ?? '')
                        ->setSmtpPort($smtp['port'] ?? '')
                        ->setSmtpUsername($smtp['username'] ?? '')
                        ->setSmtpPassword($smtp['password'] ?? '')
                        ->setSmtpSecure($smtp['secure'] ?? '');

                    if (!empty($customTemplate)) {
                        if (!empty($customTemplate['senderEmail'])) {
                            $senderEmail = $customTemplate['senderEmail'];
                        }
                        if (!empty($customTemplate['senderName'])) {
                            $senderName = $customTemplate['senderName'];
                        }
                        if (!empty($customTemplate['replyTo'])) {
                            $replyTo = $customTemplate['replyTo'];
                        }

                        $body = $customTemplate['message'] ?? '';
                        $subject = $customTemplate['subject'] ?? $subject;
                    }

                    $queueForMails
                        ->setSmtpReplyTo($replyTo)
                        ->setSmtpSenderEmail($senderEmail)
                        ->setSmtpSenderName($senderName);
                }

                $emailVariables = [
                    'direction' => $locale->getText('settings.direction'),
                    // {{user}}, {{project}} and {{otp}} are required in the templates
                    'user' => $user->getAttribute('name'),
                    'project' => $project->getAttribute('name'),
                    'otp' => $code,
                    'agentDevice' => $agentDevice['deviceBrand'] ?? $agentDevice['deviceBrand'] ?? 'UNKNOWN',
                    'agentClient' => $agentClient['clientName'] ?? 'UNKNOWN',
                    'agentOs' => $agentOs['osName'] ?? 'UNKNOWN'
                ];

                $queueForMails
                    ->setSubject($subject)
                    ->setBody($body)
                    ->setVariables($emailVariables)
                    ->setRecipient($user->getAttribute('email'))
                    ->trigger();
                break;
        }

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('challengeId', $challenge->getId());

        $response->dynamic($challenge, Response::MODEL_MFA_CHALLENGE);
    });

Http::put('/v1/account/mfa/challenge')
    ->desc('Create MFA Challenge (confirmation)')
    ->groups(['api', 'account', 'mfa'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].sessions.[sessionId].create')
    ->label('audits.event', 'challenges.update')
    ->label('audits.resource', 'user/{response.userId}')
    ->label('audits.userId', '{response.userId}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updateMfaChallenge')
    ->label('sdk.description', '/docs/references/account/update-mfa-challenge.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_SESSION)
    ->label('abuse-limit', 10)
    ->label('abuse-key', 'userId:{param-userId}')
    ->param('challengeId', '', new Text(256), 'ID of the challenge.')
    ->param('otp', '', new Text(256), 'Valid verification token.')
    ->inject('project')
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $challengeId, string $otp, Document $project, Response $response, Document $user, Database $dbForProject, Event $queueForEvents) {

        $challenge = $dbForProject->getDocument('challenges', $challengeId);

        if ($challenge->isEmpty()) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $type = $challenge->getAttribute('type');

        $recoveryCodeChallenge = function (Document $challenge, Document $user, string $otp) use ($dbForProject) {
            if (
                $challenge->isSet('type') &&
                $challenge->getAttribute('type') === Type::RECOVERY_CODE
            ) {
                $mfaRecoveryCodes = $user->getAttribute('mfaRecoveryCodes', []);
                if (in_array($otp, $mfaRecoveryCodes)) {
                    $mfaRecoveryCodes = array_diff($mfaRecoveryCodes, [$otp]);
                    $user->setAttribute('mfaRecoveryCodes', $mfaRecoveryCodes);
                    $dbForProject->updateDocument('users', $user->getId(), $user);

                    return true;
                }

                return false;
            }

            return false;
        };

        $success = (match ($type) {
            Type::TOTP => Challenge\TOTP::challenge($challenge, $user, $otp),
            Type::PHONE => Challenge\Phone::challenge($challenge, $user, $otp),
            Type::EMAIL => Challenge\Email::challenge($challenge, $user, $otp),
            Type::RECOVERY_CODE => $recoveryCodeChallenge($challenge, $user, $otp),
            default => false
        });

        if (!$success) {
            throw new Exception(Exception::USER_INVALID_TOKEN);
        }

        $dbForProject->deleteDocument('challenges', $challengeId);
        $dbForProject->purgeCachedDocument('users', $user->getId());

        $authDuration = $project->getAttribute('auths', [])['duration'] ?? Auth::TOKEN_EXPIRATION_LOGIN_LONG;
        $sessionId = Auth::sessionVerify($user->getAttribute('sessions', []), Auth::$secret, $authDuration);
        $session = $dbForProject->getDocument('sessions', $sessionId);

        $session = $session
            ->setAttribute('factors', $type, Document::SET_TYPE_APPEND)
            ->setAttribute('mfaUpdatedAt', DateTime::now());

        $dbForProject->updateDocument('sessions', $sessionId, $session);

        $queueForEvents
                    ->setParam('userId', $user->getId())
                    ->setParam('sessionId', $session->getId());

        $response->dynamic($session, Response::MODEL_SESSION);
    });

Http::post('/v1/account/targets/push')
    ->desc('Create push target')
    ->groups(['api', 'account'])
    ->label('scope', 'targets.write')
    ->label('audits.event', 'target.create')
    ->label('audits.resource', 'target/response.$id')
    ->label('event', 'users.[userId].targets.[targetId].create')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'createPushTarget')
    ->label('sdk.response.code', Response::STATUS_CODE_CREATED)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TARGET)
    ->param('targetId', '', new CustomId(), 'Target ID. Choose a custom ID or generate a random ID with `ID.unique()`. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can\'t start with a special char. Max length is 36 chars.')
    ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)')
    ->param('providerId', '', new UID(), 'Provider ID. Message will be sent to this target from the specified provider ID. If no provider ID is set the first setup provider will be used.', true)
    ->inject('queueForEvents')
    ->inject('user')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (string $targetId, string $identifier, string $providerId, Event $queueForEvents, Document $user, Request $request, Response $response, Database $dbForProject, Authorization $authorization) {
        $targetId = $targetId == 'unique()' ? ID::unique() : $targetId;

        $provider = $authorization->skip(fn () => $dbForProject->getDocument('providers', $providerId));

        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if (!$target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        $detector = new Detector($request->getUserAgent());
        $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

        $device = $detector->getDevice();

        $sessionId = Auth::sessionVerify($user->getAttribute('sessions'), Auth::$secret);
        $session = $dbForProject->getDocument('sessions', $sessionId);

        try {
            $target = $dbForProject->createDocument('targets', new Document([
                '$id' => $targetId,
                '$permissions' => [
                    Permission::read(Role::user($user->getId())),
                    Permission::update(Role::user($user->getId())),
                    Permission::delete(Role::user($user->getId())),
                ],
                'providerId' => !empty($providerId) ? $providerId : null,
                'providerInternalId' => !empty($providerId) ? $provider->getInternalId() : null,
                'providerType' =>  MESSAGE_TYPE_PUSH,
                'userId' => $user->getId(),
                'userInternalId' => $user->getInternalId(),
                'sessionId' => $session->getId(),
                'sessionInternalId' => $session->getInternalId(),
                'identifier' => $identifier,
                'name' => "{$device['deviceBrand']} {$device['deviceModel']}"
            ]));
        } catch (Duplicate) {
            throw new Exception(Exception::USER_TARGET_ALREADY_EXISTS);
        }

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->setStatusCode(Response::STATUS_CODE_CREATED)
            ->dynamic($target, Response::MODEL_TARGET);
    });

Http::put('/v1/account/targets/:targetId/push')
    ->desc('Update push target')
    ->groups(['api', 'account'])
    ->label('scope', 'targets.write')
    ->label('audits.event', 'target.update')
    ->label('audits.resource', 'target/response.$id')
    ->label('event', 'users.[userId].targets.[targetId].update')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'updatePushTarget')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TARGET)
    ->param('targetId', '', new UID(), 'Target ID.')
    ->param('identifier', '', new Text(Database::LENGTH_KEY), 'The target identifier (token, email, phone etc.)')
    ->inject('queueForEvents')
    ->inject('user')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (string $targetId, string $identifier, Event $queueForEvents, Document $user, Request $request, Response $response, Database $dbForProject, Authorization $authorization) {

        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($user->getId() !== $target->getAttribute('userId')) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($identifier) {
            $target->setAttribute('identifier', $identifier);
        }

        $detector = new Detector($request->getUserAgent());
        $detector->skipBotDetection(); // OPTIONAL: If called, bot detection will completely be skipped (bots will be detected as regular devices then)

        $device = $detector->getDevice();

        $target->setAttribute('name', "{$device['deviceBrand']} {$device['deviceModel']}");

        $target = $dbForProject->updateDocument('targets', $target->getId(), $target);

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId());

        $response
            ->dynamic($target, Response::MODEL_TARGET);
    });

Http::delete('/v1/account/targets/:targetId/push')
    ->desc('Delete push target')
    ->groups(['api', 'account'])
    ->label('scope', 'targets.write')
    ->label('audits.event', 'target.delete')
    ->label('audits.resource', 'target/response.$id')
    ->label('event', 'users.[userId].targets.[targetId].delete')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deletePushTarget')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_TARGET)
    ->param('targetId', '', new UID(), 'Target ID.')
    ->inject('queueForEvents')
    ->inject('queueForDeletes')
    ->inject('user')
    ->inject('request')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('authorization')
    ->action(function (string $targetId, Event $queueForEvents, Delete $queueForDeletes, Document $user, Request $request, Response $response, Database $dbForProject, Authorization $authorization) {
        $target = $authorization->skip(fn () => $dbForProject->getDocument('targets', $targetId));

        if ($target->isEmpty()) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        if ($user->getInternalId() !== $target->getAttribute('userInternalId')) {
            throw new Exception(Exception::USER_TARGET_NOT_FOUND);
        }

        $dbForProject->deleteDocument('targets', $target->getId());

        $dbForProject->purgeCachedDocument('users', $user->getId());

        $queueForDeletes
            ->setType(DELETE_TYPE_TARGET)
            ->setDocument($target);

        $queueForEvents
            ->setParam('userId', $user->getId())
            ->setParam('targetId', $target->getId())
            ->setPayload($response->output($target, Response::MODEL_TARGET));

        $response->noContent();
    });
Http::get('/v1/account/identities')
    ->desc('List Identities')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'listIdentities')
    ->label('sdk.description', '/docs/references/account/list-identities.md')
    ->label('sdk.response.code', Response::STATUS_CODE_OK)
    ->label('sdk.response.type', Response::CONTENT_TYPE_JSON)
    ->label('sdk.response.model', Response::MODEL_IDENTITY_LIST)
    ->label('sdk.offline.model', '/account/identities')
    ->param('queries', [], new Identities(), 'Array of query strings generated using the Query class provided by the SDK. [Learn more about queries](https://appwrite.io/docs/queries). Maximum of ' . APP_LIMIT_ARRAY_PARAMS_SIZE . ' queries are allowed, each ' . APP_LIMIT_ARRAY_ELEMENT_SIZE . ' characters long. You may filter on the following attributes: ' . implode(', ', Identities::ALLOWED_ATTRIBUTES), true)
    ->inject('response')
    ->inject('user')
    ->inject('dbForProject')
    ->action(function (array $queries, Response $response, Document $user, Database $dbForProject) {

        try {
            $queries = Query::parseQueries($queries);
        } catch (QueryException $e) {
            throw new Exception(Exception::GENERAL_QUERY_INVALID, $e->getMessage());
        }

        $queries[] = Query::equal('userInternalId', [$user->getInternalId()]);

        /**
            * Get cursor document if there was a cursor query, we use array_filter and reset for reference $cursor to $queries
            */
        $cursor = \array_filter($queries, function ($query) {
            return \in_array($query->getMethod(), [Query::TYPE_CURSOR_AFTER, Query::TYPE_CURSOR_BEFORE]);
        });
        $cursor = reset($cursor);
        if ($cursor) {
            /** @var Query $cursor */
            $identityId = $cursor->getValue();
            $cursorDocument = $dbForProject->getDocument('identities', $identityId);

            if ($cursorDocument->isEmpty()) {
                throw new Exception(Exception::GENERAL_CURSOR_NOT_FOUND, "Identity '{$identityId}' for the 'cursor' value not found.");
            }

            $cursor->setValue($cursorDocument);
        }

        $filterQueries = Query::groupByType($queries)['filters'];

        $results = $dbForProject->find('identities', $queries);
        $total = $dbForProject->count('identities', $filterQueries, APP_LIMIT_COUNT);

        $response->dynamic(new Document([
            'identities' => $results,
            'total' => $total,
        ]), Response::MODEL_IDENTITY_LIST);
    });

Http::delete('/v1/account/identities/:identityId')
    ->desc('Delete identity')
    ->groups(['api', 'account'])
    ->label('scope', 'account')
    ->label('event', 'users.[userId].identities.[identityId].delete')
    ->label('audits.event', 'identity.delete')
    ->label('audits.resource', 'identity/{request.$identityId}')
    ->label('audits.userId', '{user.$id}')
    ->label('sdk.auth', [APP_AUTH_TYPE_SESSION, APP_AUTH_TYPE_JWT])
    ->label('sdk.namespace', 'account')
    ->label('sdk.method', 'deleteIdentity')
    ->label('sdk.description', '/docs/references/account/delete-identity.md')
    ->label('sdk.response.code', Response::STATUS_CODE_NOCONTENT)
    ->label('sdk.response.model', Response::MODEL_NONE)
    ->param('identityId', '', new UID(), 'Identity ID.')
    ->inject('response')
    ->inject('dbForProject')
    ->inject('queueForEvents')
    ->action(function (string $identityId, Response $response, Database $dbForProject, Event $queueForEvents) {

        $identity = $dbForProject->getDocument('identities', $identityId);

        if ($identity->isEmpty()) {
            throw new Exception(Exception::USER_IDENTITY_NOT_FOUND);
        }

        $dbForProject->deleteDocument('identities', $identityId);

        $queueForEvents
            ->setParam('userId', $identity->getAttribute('userId'))
            ->setParam('identityId', $identity->getId())
            ->setPayload($response->output($identity, Response::MODEL_IDENTITY));

        return $response->noContent();
    });
