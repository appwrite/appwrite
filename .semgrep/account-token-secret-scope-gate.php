<?php

use Appwrite\Auth\Key;
use Appwrite\Utopia\Response;
use Utopia\Database\Document;

$bad = function (Document $token, Response $response, ?Key $apiKey) {
    $token->setAttribute('secret', 'minted');
    // ruleid: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($token, Response::MODEL_TOKEN);
};

$good = function (Document $token, Response $response, ?Key $apiKey) {
    $token->setAttribute('secret', 'minted');
    if ($apiKey !== null && !\in_array('users.write', $apiKey->getScopes())) {
        $token->setAttribute('secret', '');
    }
    // ok: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($token, Response::MODEL_TOKEN);
};

$confirmation = function (Document $token, Response $response) {
    // ok: php.appwrite.account-token-secret-scope-gate
    $response->dynamic($token, Response::MODEL_TOKEN);
};
