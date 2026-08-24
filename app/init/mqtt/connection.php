<?php

use Appwrite\Utopia\Database\Documents\User;
use Utopia\Auth\Hashes\Sha;
use Utopia\Auth\Proofs\Token;
use Utopia\Auth\Store;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;
use Utopia\DI\Container;

return function (Container $container): void {
    $container->set('authorization', function () {
        return new Authorization();
    }, []);

    $container->set('project', function (string $projectId, Document $console, Authorization $authorization) {
        if ($projectId === '' || $projectId === 'console') {
            return $console;
        }

        $dbForPlatform = getConsoleDB();

        return $authorization->skip(fn () => $dbForPlatform->getDocument('projects', $projectId));
    }, ['projectId', 'console', 'authorization']);

    $container->set('user', function (Document $project, string $sessionSecret, Authorization $authorization) {
        if ($project->isEmpty() || $sessionSecret === '') {
            return new User([]);
        }

        $authorization->setDefaultStatus(true);

        // The session secret arrives as the same Store-encoded "id:secret" blob the
        // SDK sends over x-appwrite-session; decode it to recover both halves.
        $store = new Store();
        $store->decode($sessionSecret);

        $proofForToken = new Token();
        $proofForToken->setHash(new Sha());

        $dbForProject = getProjectDB($project);
        $dbForProject->setAuthorization($authorization);

        /** @var User $user */
        $user = $dbForProject->getDocument('users', $store->getProperty('id', ''));

        if (
            $user->isEmpty()
            || !$user->sessionVerify($store->getProperty('secret', ''), $proofForToken)
        ) {
            return new User([]);
        }

        return $user;
    }, ['project', 'sessionSecret', 'authorization']);
};
