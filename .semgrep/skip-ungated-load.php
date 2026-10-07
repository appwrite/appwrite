<?php

// Non-allowlisted names — the class, not a ticket leftover.
// ruleid: php.appwrite.skip-ungated-load
$transaction = $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId));

// ruleid: php.appwrite.skip-ungated-load
$file = $authorization->skip(fn () => $dbForProject->findOne('files', []));

// ruleid: php.appwrite.skip-ungated-load
$legacy = Authorization::skip(fn () => $dbForProject->getDocument('transactions', $transactionId));

// ruleid: php.appwrite.skip-ungated-load
$novel = $authorization->skip(fn () => $dbForProject->getDocument('privateKeys', $id));

// ok: php.appwrite.skip-ungated-load
$transaction = ($isAPIKey || $isPrivilegedUser)
    ? $authorization->skip(fn () => $dbForProject->getDocument('transactions', $transactionId))
    : $dbForProject->getDocument('transactions', $transactionId);

// ok: php.appwrite.skip-ungated-load
if ($isAPIKey || $isPrivilegedUser) {
    $file = $authorization->skip(fn () => $dbForProject->getDocument('files', $fileId));
} else {
    $file = $dbForProject->getDocument('files', $fileId);
}

// ok: php.appwrite.skip-ungated-load
$database = $authorization->skip(fn () => $dbForProject->getDocument('databases', $databaseId));

// ok: php.appwrite.skip-ungated-load
$file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));

// ok: php.appwrite.skip-ungated-load
$cacheLog = $authorization->skip(fn () => $dbForProject->getDocument('cache', $key));

// Intentional lookup / subquery collections (allowlisted).
// ok: php.appwrite.skip-ungated-load
$user = $authorization->skip(fn () => $dbForProject->getDocument('users', $userId));

// ok: php.appwrite.skip-ungated-load
$session = $authorization->skip(fn () => $dbForProject->find('sessions', []));

// Generated / variable table name.
// ok: php.appwrite.skip-ungated-load
$resource = $authorization->skip(fn () => $dbForProject->getDocument($collection, $resourceId));

// ruleid: php.appwrite.skip-ungated-load
$transaction = $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $transactionId) {
    return $dbForProject->getDocument('transactions', $transactionId, forUpdate: true);
}));

// ok: php.appwrite.skip-ungated-load
$transaction = ($isAPIKey || $isPrivilegedUser)
    ? $authorization->skip(fn () => $dbForProject->withTransaction(function () use ($dbForProject, $transactionId) {
        return $dbForProject->getDocument('transactions', $transactionId, forUpdate: true);
    }))
    : $dbForProject->withTransaction(function () use ($dbForProject, $transactionId) {
        return $dbForProject->getDocument('transactions', $transactionId, forUpdate: true);
    });

// Video child / profile collections (no per-document ACL; parent is gated first).
// ok: php.appwrite.skip-ungated-load
$profile = $authorization->skip(fn () => $dbForProject->getDocument('videos_profiles', $profileId));

// ok: php.appwrite.skip-ungated-load
$rendition = $authorization->skip(fn () => $dbForProject->getDocument('videos_renditions', $renditionId));

// Bare videos without assertFileAccess stays an error.
// ruleid: php.appwrite.skip-ungated-load
$video = $authorization->skip(fn () => $dbForProject->getDocument('videos', $videoId));

// Play / cache: skip videos ACL then gate on the source file (same function).
function playWithSelfAssert($authorization, $dbForProject, $videoId, $user, $bucketId, $fileId)
{
    // ok: php.appwrite.skip-ungated-load
    $video = $authorization->skip(fn () => $dbForProject->getDocument('videos', $videoId));
    self::assertFileAccess($dbForProject, $authorization, $user, $bucketId, $fileId);
}

function playWithClassAssert($authorization, $dbForProject, $videoId, $user, $bucketId, $fileId)
{
    // ok: php.appwrite.skip-ungated-load
    $video = $authorization->skip(fn () => $dbForProject->getDocument('videos', $videoId));
    VideosBase::assertFileAccess($dbForProject, $authorization, $user, $bucketId, $fileId);
}
