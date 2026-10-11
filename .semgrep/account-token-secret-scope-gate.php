<?php

use Appwrite\Auth\Key;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;

$badToken = function (Document $token, Response $response, ?Key $apiKey) {
    $token->setAttribute('secret', 'minted');
    // ruleid: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($token, Response::MODEL_TOKEN);
};

$badSession = function (Document $session, Response $response, Key $apiKey) {
    $session->setAttribute('secret', 'minted');
    // ruleid: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($session, Response::MODEL_SESSION);
};

$badJwt = function (Document $jwt, Response $response, ?Key $apiKey) {
    // ruleid: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($jwt, Response::MODEL_JWT);
};

$badOutput = function (Document $token, Response $response, ?Key $apiKey) {
    // ruleid: php.appwrite.account-token-secret-scope-gate
    $response->output($token, Response::MODEL_TOKEN);
};

$good = function (Document $token, Response $response, ?Key $apiKey) {
    $token->setAttribute('secret', 'minted');
    if ($apiKey !== null && !\in_array('users.write', $apiKey->getScopes())) {
        $token->setAttribute('secret', '');
    }
    // ok: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($token, Response::MODEL_TOKEN);
};

$confirmationWithoutKey = function (Document $token, Response $response) {
    // ok: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($token, Response::MODEL_TOKEN);
};

$badKeyMidSignature = function (Document $token, ?Key $apiKey, Response $response) {
    // ruleid: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($token, Response::MODEL_TOKEN);
};

$goodStrict = function (Document $session, Response $response, Key $apiKey) {
    if (!in_array('users.write', $apiKey->getScopes(), true)) {
        $session->setAttribute('secret', '');
    }
    // ok: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($session, Response::MODEL_SESSION);
};

class TokenActionFixture
{
    public function action(Document $user, ?Key $apiKey, Response $response): void
    {
        // ruleid: php.appwrite.account-token-secret-scope-gate
        $response->dynamic($token, Response::MODEL_TOKEN);
    }

    public function gatedAction(Document $user, Response $response, ?Key $apiKey): void
    {
        if ($apiKey !== null && !\in_array('users.write', $apiKey->getScopes())) {
            $token->setAttribute('secret', '');
        }
        // ok: php.appwrite.account-token-secret-scope-gate
        $response->dynamic($token, Response::MODEL_TOKEN);
    }

    public function unrelated(Document $user, Response $response): void
    {
        // ok: php.appwrite.account-token-secret-scope-gate
        $response->dynamic($user, Response::MODEL_USER);
    }
}

$badWithUse = function (Document $token, Response $response, ?Key $apiKey) use ($project) {
    // ruleid: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($token, Response::MODEL_TOKEN);
};

$eventPayload = function (Document $token, Response $response, ?Key $apiKey) {
    $queueForEvents
        // ok: php.appwrite.account-token-secret-scope-gate
        ->setPayload($response->output($token, Response::MODEL_TOKEN), sensitive: ['secret']);
};
