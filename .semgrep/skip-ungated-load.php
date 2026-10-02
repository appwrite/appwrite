<?php

// ruleid: php.appwrite.skip-ungated-load
$user = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

// ruleid: php.appwrite.skip-ungated-load
$membership = $authorization->skip(fn () => $dbForProject->findOne('memberships', []));

// ruleid: php.appwrite.skip-ungated-load
$team = $authorization->skip(fn () => $dbForProject->getDocument('teams', $teamId));

// ruleid: php.appwrite.skip-ungated-load
$resource = $authorization->skip(fn () => $dbForProject->getDocument($collection, $resourceId));

// ok: php.appwrite.skip-ungated-load
$user = ($isAPIKey || $isPrivilegedUser)
    ? $authorization->skip(fn () => $dbForProject->getDocument('users', $userId))
    : $dbForProject->getDocument('users', $userId);

// ok: php.appwrite.skip-ungated-load
$database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

// ok: php.appwrite.skip-ungated-load
$file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));

// ok: php.appwrite.skip-ungated-load
$cacheLog = $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));
