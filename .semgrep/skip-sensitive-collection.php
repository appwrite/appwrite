<?php

// ruleid: php.appwrite.skip-sensitive-collection
$transaction = $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId));

// ruleid: php.appwrite.skip-sensitive-collection
$session = $authorization->skip(fn () => $dbForProject->getDocument('sessions', $sessionId));

// ruleid: php.appwrite.skip-sensitive-collection
$token = $dbForProject->getAuthorization()->skip(fn () => $dbForProject->find('tokens', []));

// ruleid: php.appwrite.skip-sensitive-collection
$file = $authorization->skip(fn () => $dbForProject->findOne('files', []));

// ruleid: php.appwrite.skip-sensitive-collection
$legacy = Authorization::skip(fn () => $dbForProject->getDocument('sessions', $sessionId));

// ok: php.appwrite.skip-sensitive-collection
$transaction = ($isAPIKey || $isPrivilegedUser)
    ? $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId))
    : $dbForProject->getDocument('transactions', $transactionId);

// ok: php.appwrite.skip-sensitive-collection
if ($isAPIKey || $isPrivilegedUser) {
    $session = $authorization->skip(fn () => $dbForProject->getDocument('sessions', $sessionId));
} else {
    $session = $dbForProject->getDocument('sessions', $sessionId);
}

// ok: php.appwrite.skip-sensitive-collection
$database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
