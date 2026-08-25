<?php

use Ahc\Jwt\JWT;
use Ahc\Jwt\JWTException;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;
use Utopia\System\System;

return function (Container $container): void {
    $container->set('authorization', function () {
        return new Authorization();
    }, []);

    $container->set('project', function (string $projectId, Document $console, Authorization $authorization) {
        if ($projectId === '' || $projectId === 'console') {
            return $console;
        }

        $dbForPlatform = getConsoleDB();
        $dbForPlatform->setAuthorization($authorization);

        return $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
    }, ['projectId', 'console', 'authorization']);

    $container->set('user', function (Document $project, string $authMethod, string $credential, Authorization $authorization) {
        if ($project->isEmpty() || $credential === '') {
            return new User([]);
        }

        $authorization->setDefaultStatus(true);

        $dbForProject = getProjectDB($project);
        $dbForProject->setAuthorization($authorization);

        if ($authMethod === 'appwrite-jwt') {
            $jwt = new JWT(System::getEnv('_APP_OPENSSL_KEY_V1'), 'HS256', 3600, 0);

            try {
                $payload = $jwt->decode($credential);
            } catch (JWTException) {
                return new User([]);
            }

            $userId = $payload['userId'] ?? '';
            $sessionId = $payload['sessionId'] ?? '';

            /** @var User $user */
            $user = $dbForProject->getDocument('users', $userId);

            if (
                $user->isEmpty()
                || $user->getAttribute('status', true) === false // blocked account
                || ($sessionId !== '' && !$user->sessionActive($sessionId))
            ) {
                return new User([]);
            }

            return $user;
        }

        $store = new Store();
        $store->decode($credential);

        $proofForToken = new Token();
        $proofForToken->setHash(new Sha());

        /** @var User $user */
        $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));

        if (
            $user->isEmpty()
            || $user->getAttribute('status', true) === false // blocked account
            || !$user->sessionVerify($store->getProperty('secret', ''), $proofForToken)
        ) {
            return new User([]);
        }

        return $user;
    }, ['project', 'authMethod', 'credential', 'authorization']);
};
