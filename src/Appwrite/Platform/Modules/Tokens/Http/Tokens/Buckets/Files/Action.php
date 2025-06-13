<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files;

use Appwrite\Auth\Auth;
use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Platform\Action as UtopiaAction;

class Action extends UtopiaAction
{
    protected function getFileAndBucket(Database $dbForProject, string $bucketId, string $fileId): array
    {
        $bucket = Authorization::skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = Auth::isAppUser(Authorization::getRoles());
        $isPrivilegedUser = Auth::isPrivilegedUser(Authorization::getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        $validator = new Authorization(Database::PERMISSION_READ);
        $valid = $validator->isValid($bucket->getRead());
        if (!$valid) {
            throw new Exception(Exception::USER_UNAUTHORIZED);
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        if ($fileSecurity) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            $file = Authorization::skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
        }

        if ($file->isEmpty()) {
            throw new Exception(Exception::STORAGE_FILE_NOT_FOUND);
        }
        return [
            'bucket' => $bucket,
            'file' => $file,
        ];
    }
}
