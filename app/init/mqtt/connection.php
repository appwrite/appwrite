<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Config\Config;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\System\System;

return function (Container $container): void {
    $getProject = function (string $projectId, Authorization $authorization) use ($container): Document {
        if ($projectId === '' || $projectId === 'console') {
            return new Document(Config::getParam('console'));
        }

        $getConsoleDB = $container->get('getConsoleDB');
        $dbForPlatform = $getConsoleDB();
        $dbForPlatform->setAuthorization($authorization);

        return $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
    };

    // A session can end or lose its factors without a revoke reaching the broker (an MFA
    // requirement raised by verifying an email or phone), so delivery checks it again at least
    // this often, and once it is due to expire.
    $getRecheckAt = static function (User $user, string $sessionId): int {
        $recheckAt = \time() + APP_MQTT_SESSION_RECHECK;
        $expire = $user->getSessionExpiry($sessionId);

        return $expire === null ? $recheckAt : \min($recheckAt, $expire);
    };

    /**
     * The user a credential authenticates, with the session it runs on, when the token itself
     * expires and when that session is next due to be checked, or [] when it authenticates nobody.
     *
     * @return array{user?: User, sessionId?: string, expire?: ?int, sessionExpire?: ?int, recheckAt?: ?int}
     */
    $getUser = function (Document $project, string $authMethod, string $credential, Authorization $authorization) use ($container, $getRecheckAt): array {
        if ($project->isEmpty() || $credential === '') {
            return [];
        }

        $authorization->setStatus(true);

        $getProjectDB = $container->get('getProjectDB');
        $dbForProject = $getProjectDB($project);
        $dbForProject->setAuthorization($authorization);

        switch ($authMethod) {
            case 'appwrite-jwt':
                $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);

                try {
                    $payload = $jwt->decode($credential);
                } catch (JWTException) {
                    return [];
                }

                $userId = $payload['userId'] ?? '';
                $sessionId = $payload['sessionId'] ?? '';

                // Same binding as the HTTP and realtime user resources: a JWT is only good for
                // the project that minted it, and one minted before the projectId claim existed
                // only when it names a session.
                $jwtProjectId = $payload['projectId'] ?? '';
                $bound = $jwtProjectId !== '' ? $jwtProjectId === $project->getId() : $sessionId !== '';
                if (!$bound) {
                    return [];
                }

                /** @var User $user */
                $user = $dbForProject->getDocument('users', $userId);

                if (
                    $user->isEmpty()
                    || $user->getAttribute('status', true) === false // blocked account
                    || ($sessionId !== '' && (!$user->sessionActive($sessionId) || !$user->sessionFactorsMet($sessionId)))
                ) {
                    return [];
                }

                return [
                    'user' => $user,
                    'sessionId' => $sessionId,
                    'expire' => \is_int($payload['exp'] ?? null) ? $payload['exp'] : null,
                    'sessionExpire' => $sessionId !== '' ? $user->getSessionExpiry($sessionId) : null,
                    'recheckAt' => $sessionId !== '' ? $getRecheckAt($user, $sessionId) : null,
                ];

            case 'appwrite-session':
                $store = new Store();
                $store->decode($credential);

                $proofForToken = new Token();
                $proofForToken->setHash(new Sha());

                /** @var User $user */
                $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));
                $sessionId = $user->sessionVerify($store->getProperty('secret', ''), $proofForToken);

                if (
                    $user->isEmpty()
                    || $user->getAttribute('status', true) === false // blocked account
                    || !$sessionId
                    || !$user->sessionFactorsMet($sessionId)
                ) {
                    return [];
                }

                return [
                    'user' => $user,
                    'sessionId' => $sessionId,
                    'expire' => null,
                    'sessionExpire' => $user->getSessionExpiry($sessionId),
                    'recheckAt' => $getRecheckAt($user, $sessionId),
                ];

            default:
                return [];
        }
    };

    $container->set('authenticator', function () use ($getProject, $getUser): callable {
        return function (string $projectId, string $authMethod, string $credential) use ($getProject, $getUser): array {
            $authorization = new Authorization();

            $project = $getProject($projectId, $authorization);
            if ($project->isEmpty()) {
                return [];
            }

            $authenticated = $getUser($project, $authMethod, $credential, $authorization);
            if (!isset($authenticated['user'])) {
                return [];
            }

            // The session and expiry are kept so the connection ends with the credential it was opened with.
            return [
                'projectId' => $project->getId(),
                'userId' => $authenticated['user']->getId(),
                'sessionId' => $authenticated['sessionId'] ?? '',
                'expire' => $authenticated['expire'] ?? null,
                'sessionExpire' => $authenticated['sessionExpire'] ?? null,
                'recheckAt' => $authenticated['recheckAt'] ?? null,
            ];
        };
    }, []);

    $container->set('authorizer', function () use ($getProject, $container): callable {
        return function (array $identity) use ($getProject, $container): bool {
            $userId = $identity['userId'] ?? '';
            $projectId = $identity['projectId'] ?? '';
            if ($userId === '' || $projectId === '') {
                return false;
            }

            $expire = $identity['expire'] ?? null;
            if (\is_int($expire) && $expire <= \time()) {
                return false;
            }

            $authorization = new Authorization();

            $project = $getProject($projectId, $authorization);
            if ($project->isEmpty()) {
                return false;
            }

            $getProjectDB = $container->get('getProjectDB');
            $dbForProject = $getProjectDB($project);
            $dbForProject->setAuthorization($authorization);
            /** @var User $user */
            $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

            // status: true = enabled, false = blocked.
            if ($user->isEmpty() || $user->getAttribute('status', true) === false) {
                return false;
            }

            $sessionId = $identity['sessionId'] ?? '';

            return $sessionId === '' || ($user->sessionActive($sessionId) && $user->sessionFactorsMet($sessionId));
        };
    }, []);
};
