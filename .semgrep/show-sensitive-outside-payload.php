<?php

// ruleid: php.appwrite.show-sensitive-outside-payload
$response->dynamic(new Document($response->showSensitive(fn () => $response->output($token, Response::MODEL_TOKEN))), Response::MODEL_ANY);

// ruleid: php.appwrite.show-sensitive-outside-payload
$payload = $response->showSensitive(fn () => $response->output($session, Response::MODEL_SESSION));

// ok: php.appwrite.show-sensitive-outside-payload
$queueForEvents
    ->setParam('userId', $user->getId())
    ->setPayload($response->showSensitive(fn () => $response->output($recovery, Response::MODEL_TOKEN)), sensitive: ['secret']);
