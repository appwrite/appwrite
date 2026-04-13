<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Extend\Exception;
use Appwrite\Network\Platform;
use Appwrite\Network\Validator\Origin;
use Appwrite\Utopia\Database\Documents\User;
use Appwrite\Utopia\Request;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Database\DateTime as DatabaseDateTime;
use Utopia\Database\Document;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\System\System;
use Utopia\Validator\URL;
use Utopia\Validator\WhiteList;

/**
 * Register the minimal per-connection resources required by realtime.
 */
return function (Container $container): void {
    $getProjectId = static function (Request $request): string {
        $projectId = $request->getHeader('x-appwrite-project', '');

        if (!empty($projectId)) {
            return $projectId;
        }

        $projectId = $request->getParam('project', '');

        return \is_string($projectId) ? $projectId : '';
    };

    $getMode = static function (Request $request, Document $project) use ($getProjectId): string {
        $mode = $request->getParam('mode', $request->getHeader('x-appwrite-mode', APP_MODE_DEFAULT));
        $projectId = $getProjectId($request);

        if (!empty($projectId) && $project->getId() !== $projectId) {
            $mode = APP_MODE_ADMIN;
        }

        return $mode;
    };

    $getDbForPlatform = static function (Authorization $authorization) {
        $database = getConsoleDB();
        $database->setAuthorization($authorization);

        return $database;
    };

    $getDbForProject = static function (Document $project, Authorization $authorization) use ($getDbForPlatform) {
        if ($project->isEmpty() || $project->getId() === 'console') {
            return $getDbForPlatform($authorization);
        }

        $database = getProjectDB($project);
        $database->setAuthorization($authorization);

        return $database;
    };

    $findRule = static function (Request $request, Document $project, Authorization $authorization) use ($getDbForPlatform): Document {
        $domain = \parse_url($request->getOrigin(), PHP_URL_HOST);

        if (empty($domain)) {
            $domain = \parse_url($request->getReferer(), PHP_URL_HOST);
        }

        if (empty($domain)) {
            return new Document();
        }

        $dbForPlatform = $getDbForPlatform($authorization);
        $isMd5 = System::getEnv('_APP_RULES_FORMAT') === 'md5';

        $rule = $authorization->skip(function () use ($dbForPlatform, $domain, $isMd5) {
            if ($isMd5) {
                return $dbForPlatform->getDocument('rules', md5($domain));
            }

            return $dbForPlatform->findOne('rules', [
                Query::equal('domain', [$domain]),
            ]) ?? new Document();
        });

        $permitsCurrentProject = $rule->getAttribute('projectInternalId', '') === $project->getSequence();

        if (!$permitsCurrentProject && !$rule->isEmpty() && !empty($rule->getAttribute('projectId', ''))) {
            $trustedProjects = [];
            foreach (\explode(',', System::getEnv('_APP_CONSOLE_TRUSTED_PROJECTS', '')) as $trustedProject) {
                if (empty($trustedProject)) {
                    continue;
                }

                $trustedProjects[] = $trustedProject;
            }

            if (\in_array($rule->getAttribute('projectId', ''), $trustedProjects, true)) {
                $permitsCurrentProject = true;
            }
        }

        if (!$permitsCurrentProject) {
            return new Document();
        }

        return $rule;
    };

    $findDevKey = static function (Request $request, Document $project, array $servers, Authorization $authorization) use ($getDbForPlatform): Document {
        $devKey = $request->getHeader('x-appwrite-dev-key', $request->getParam('devKey', ''));
        $key = $project->find('secret', $devKey, 'devKeys');

        if (!$key) {
            return new Document([]);
        }

        $expire = $key->getAttribute('expire');
        if (!empty($expire) && $expire < DatabaseDateTime::formatTz(DatabaseDateTime::now())) {
            return new Document([]);
        }

        $dbForPlatform = $getDbForPlatform($authorization);
        $accessedAt = $key->getAttribute('accessedAt', 0);

        if (empty($accessedAt) || DatabaseDateTime::formatTz(DatabaseDateTime::addSeconds(new \DateTime(), -APP_KEY_ACCESS)) > $accessedAt) {
            $key->setAttribute('accessedAt', DatabaseDateTime::now());
            $authorization->skip(fn () => $dbForPlatform->updateDocument('devKeys', $key->getId(), new Document([
                'accessedAt' => $key->getAttribute('accessedAt'),
            ])));
            $dbForPlatform->purgeCachedDocument('projects', $project->getId());
        }

        $sdkValidator = new WhiteList($servers, true);
        $sdk = \strtolower($request->getHeader('x-sdk-name', 'UNKNOWN'));

        if ($sdk !== 'UNKNOWN' && $sdkValidator->isValid($sdk)) {
            $sdks = $key->getAttribute('sdks', []);

            if (!\in_array($sdk, $sdks, true)) {
                $sdks[] = $sdk;
                $key->setAttribute('sdks', $sdks);
                $key->setAttribute('accessedAt', DatabaseDateTime::now());

                $key = $authorization->skip(fn () => $dbForPlatform->updateDocument('devKeys', $key->getId(), new Document([
                    'sdks' => $key->getAttribute('sdks'),
                    'accessedAt' => $key->getAttribute('accessedAt'),
                ])));
                $dbForPlatform->purgeCachedDocument('projects', $project->getId());
            }
        }

        return $key;
    };

    $container->set('authorization', function () {
        return new Authorization();
    }, []);

    $container->set('project', function (Request $request, Document $console, Authorization $authorization) use ($getProjectId, $getDbForPlatform) {
        $projectId = $getProjectId($request);

        if (empty($projectId) || $projectId === 'console') {
            return $console;
        }

        $dbForPlatform = $getDbForPlatform($authorization);

        return $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
    }, ['request', 'console', 'authorization']);

    $container->set('originValidator', function (array $platform, Request $request, Document $project, array $servers, Authorization $authorization) use ($findDevKey, $findRule) {
        $devKey = $findDevKey($request, $project, $servers, $authorization);

        if (!$devKey->isEmpty()) {
            return new URL();
        }

        $allowedHostnames = [...($platform['hostnames'] ?? [])];
        if (!$project->isEmpty() && $project->getId() !== 'console') {
            $allowedHostnames = [...$allowedHostnames, ...Platform::getHostnames($project->getAttribute('platforms', []))];
        }

        $rule = $findRule($request, $project, $authorization);
        if (!$rule->isEmpty() && !empty($rule->getAttribute('domain', ''))) {
            $allowedHostnames[] = $rule->getAttribute('domain', '');
        }

        $originHostname = \parse_url($request->getOrigin(), PHP_URL_HOST);
        $refererHostname = \parse_url($request->getReferer(), PHP_URL_HOST);
        $hostname = $originHostname ?: $refererHostname;

        if ($request->getMethod() === 'OPTIONS' && !empty($hostname)) {
            $allowedHostnames[] = $hostname;
        }

        $allowedSchemes = [...($platform['schemas'] ?? [])];
        if (!$project->isEmpty() && $project->getId() !== 'console') {
            $allowedSchemes[] = 'exp';
            $allowedSchemes[] = 'appwrite-callback-' . $project->getId();
            $allowedSchemes = [...$allowedSchemes, ...Platform::getSchemes($project->getAttribute('platforms', []))];
        }

        return new Origin(\array_unique($allowedHostnames), \array_unique($allowedSchemes));
    }, ['platform', 'request', 'project', 'servers', 'authorization']);

    $container->set('user', function (Request $request, Document $project, Document $console, Authorization $authorization) use ($getMode, $getDbForPlatform, $getDbForProject) {
        $mode = $getMode($request, $project);
        $store = new Store();
        $proofForToken = new Token();
        $proofForToken->setHash(new Sha());

        $authorization->setDefaultStatus(true);

        $dbForPlatform = $getDbForPlatform($authorization);
        $dbForProject = $getDbForProject($project, $authorization);

        $store->setKey('a_session_' . $project->getId());
        if ($mode === APP_MODE_ADMIN) {
            $store->setKey('a_session_' . $console->getId());
        }

        $store->decode(
            $request->getCookie(
                $store->getKey(),
                $request->getCookie($store->getKey() . '_legacy', '')
            )
        );

        if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
            $sessionHeader = $request->getHeader('x-appwrite-session', '');

            if (!empty($sessionHeader)) {
                $store->decode($sessionHeader);
            }
        }

        if (empty($store->getProperty('id', '')) && empty($store->getProperty('secret', ''))) {
            $fallback = \json_decode($request->getHeader('x-fallback-cookies', ''), true);
            $store->decode((\is_array($fallback) && isset($fallback[$store->getKey()])) ? $fallback[$store->getKey()] : '');
        }

        $user = null;
        if ($mode === APP_MODE_ADMIN) {
            /** @var User $user */
            $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
        } else {
            if ($project->isEmpty()) {
                $user = new User([]);
            } elseif (!empty($store->getProperty('id', ''))) {
                if ($project->getId() === 'console') {
                    /** @var User $user */
                    $user = $dbForPlatform->getDocument('users', $store->getProperty('id', ''));
                } else {
                    /** @var User $user */
                    $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));
                }
            }
        }

        if (
            !$user
            || $user->isEmpty()
            || !$user->sessionVerify($store->getProperty('secret', ''), $proofForToken)
        ) {
            $user = new User([]);
        }

        $authJWT = $request->getHeader('x-appwrite-jwt', '');
        if (!empty($authJWT) && !$project->isEmpty()) {
            if (!$user->isEmpty()) {
                throw new Exception(Exception::USER_JWT_AND_COOKIE_SET);
            }

            $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);

            try {
                $payload = $jwt->decode($authJWT);
            } catch (JWTException $error) {
                throw new Exception(Exception::USER_JWT_INVALID, 'Failed to verify JWT. ' . $error->getMessage());
            }

            $jwtUserId = $payload['userId'] ?? '';
            if (!empty($jwtUserId)) {
                if ($mode === APP_MODE_ADMIN) {
                    $user = $dbForPlatform->getDocument('users', $jwtUserId);
                } else {
                    $user = $dbForProject->getDocument('users', $jwtUserId);
                }
            }

            $jwtSessionId = $payload['sessionId'] ?? '';
            if (!empty($jwtSessionId) && empty($user->find('$id', $jwtSessionId, 'sessions'))) {
                $user = new User([]);
            }
        }

        $accountKey = $request->getHeader('x-appwrite-key', '');
        $accountKeyUserId = $request->getHeader('x-appwrite-user', '');

        if (!empty($accountKeyUserId) && !empty($accountKey)) {
            if (!$user->isEmpty()) {
                throw new Exception(Exception::USER_API_KEY_AND_SESSION_SET);
            }

            $accountKeyUser = $authorization->skip(fn () => $dbForPlatform->getDocument('users', $accountKeyUserId));
            if (!$accountKeyUser->isEmpty()) {
                $key = $accountKeyUser->find(
                    key: 'secret',
                    find: $accountKey,
                    subject: 'keys'
                );

                if (!empty($key)) {
                    $expire = $key->getAttribute('expire');
                    if (!empty($expire) && $expire < DatabaseDateTime::formatTz(DatabaseDateTime::now())) {
                        throw new Exception(Exception::ACCOUNT_KEY_EXPIRED);
                    }

                    $user = $accountKeyUser;
                }
            }
        }

        $impersonateUserId = $request->getHeader('x-appwrite-impersonate-user-id', '');
        $impersonateEmail = $request->getHeader('x-appwrite-impersonate-user-email', '');
        $impersonatePhone = $request->getHeader('x-appwrite-impersonate-user-phone', '');

        if (!$user->isEmpty() && $user->getAttribute('impersonator', false)) {
            $userDb = ($mode === APP_MODE_ADMIN || $project->getId() === 'console') ? $dbForPlatform : $dbForProject;
            $targetUser = null;

            if (!empty($impersonateUserId)) {
                $targetUser = $authorization->skip(fn () => $userDb->getDocument('users', $impersonateUserId));
            } elseif (!empty($impersonateEmail)) {
                $targetUser = $authorization->skip(fn () => $userDb->findOne('users', [
                    Query::equal('email', [\strtolower($impersonateEmail)]),
                ]));
            } elseif (!empty($impersonatePhone)) {
                $targetUser = $authorization->skip(fn () => $userDb->findOne('users', [
                    Query::equal('phone', [$impersonatePhone]),
                ]));
            }

            if ($targetUser !== null && !$targetUser->isEmpty()) {
                $impersonator = clone $user;
                $user = clone $targetUser;
                $user->setAttribute('impersonatorUserId', $impersonator->getId());
                $user->setAttribute('impersonatorUserInternalId', $impersonator->getSequence());
                $user->setAttribute('impersonatorUserName', $impersonator->getAttribute('name', ''));
                $user->setAttribute('impersonatorUserEmail', $impersonator->getAttribute('email', ''));
                $user->setAttribute('impersonatorAccessedAt', $impersonator->getAttribute('accessedAt', 0));
            }
        }

        $dbForPlatform->setMetadata('user', $user->getId());
        $dbForProject->setMetadata('user', $user->getId());

        return $user;
    }, ['request', 'project', 'console', 'authorization']);
};
