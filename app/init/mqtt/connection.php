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
    $getProject = function (string $projectId, Authorization $authorization): Document {
        if ($projectId === '' || $projectId === 'console') {
            return new Document(Config::getParam('console'));
        }

        $dbForPlatform = getConsoleDB();
        $dbForPlatform->setAuthorization($authorization);

        return $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
    };

    $getUser = function (Document $project, string $authMethod, string $credential, Authorization $authorization): User {
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
    };

    $container->set('authenticator', function () use ($getProject, $getUser): callable {
        return function (string $projectId, string $authMethod, string $credential) use ($getProject, $getUser): array {
            $authorization = new Authorization();

            $project = $getProject($projectId, $authorization);
            if ($project->isEmpty()) {
                return [];
            }

            $user = $getUser($project, $authMethod, $credential, $authorization);
            if ($user->isEmpty()) {
                return [];
            }

            return [
                'projectId' => $project->getId(),
                'userId' => $user->getId(),
            ];
        };
    }, []);

    $container->set('authorizer', function () use ($getProject): callable {
        return function (array $identity, string $topic) use ($getProject): bool {
            $userId = $identity['userId'] ?? '';
            $projectId = $identity['projectId'] ?? '';
            if ($userId === '' || $projectId === '') {
                return false;
            }

            $authorization = new Authorization();

            $project = $getProject($projectId, $authorization);
            if ($project->isEmpty()) {
                return false;
            }

            $dbForProject = getProjectDB($project);
            $dbForProject->setAuthorization($authorization);
            $user = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

            // status: true = enabled, false = blocked.
            return !$user->isEmpty() && $user->getAttribute('status', true) !== false;
        };
    }, []);
};
