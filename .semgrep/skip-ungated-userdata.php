<?php

// ruleid: php.appwrite.skip-ungated-userdata
$user = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

// ruleid: php.appwrite.skip-ungated-userdata
$membership = $authorization->skip(fn () => $dbForProject->findOne('memberships', []));

// ok: php.appwrite.skip-ungated-userdata
$user = ($isAPIKey || $isPrivilegedUser)
    ? $authorization->skip(fn () => $dbForProject->getDocument('users', $userId))
    : $dbForProject->getDocument('users', $userId);

// ok: php.appwrite.skip-ungated-userdata
$database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));
