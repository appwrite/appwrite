<?php

// ruleid: php.appwrite.global-authorization-disable
$authorization->disable();

// ruleid: php.appwrite.global-authorization-disable
$dbForProject->getAuthorization()->disable();

// ruleid: php.appwrite.global-authorization-disable
$authorization->setStatus(false);

// ruleid: php.appwrite.global-authorization-disable
Authorization::disable();

// ok: php.appwrite.global-authorization-disable
$authorization->reset();

// ok: php.appwrite.global-authorization-disable
$authorization->setStatus(true);

// ok: php.appwrite.global-authorization-disable
$file = ($isAPIKey || $isPrivilegedUser)
    ? $authorization->skip(fn () => $dbForProject->getDocument('files', $fileId))
    : $dbForProject->getDocument('files', $fileId);
