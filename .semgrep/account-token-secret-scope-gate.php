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
