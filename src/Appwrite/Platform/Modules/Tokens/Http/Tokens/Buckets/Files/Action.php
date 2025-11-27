<?php

namespace Appwrite\Platform\Modules\Tokens\Http\Tokens\Buckets\Files;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Database;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Platform\Action as UtopiaAction;

class Action extends UtopiaAction
{
    protected function getFileAndBucket(Database $dbForProject, Authorization $authorization, string $bucketId, string $fileId): array
    {
        $bucket = $authorization->skip(fn () => $dbForProject->getDocument('buckets', $bucketId));

        $isAPIKey = User::isApp($authorization->getRoles());
        $isPrivilegedUser = User::isPrivileged($authorization->getRoles());

        if ($bucket->isEmpty() || (!$bucket->getAttribute('enabled') && !$isAPIKey && !$isPrivilegedUser)) {
            throw new Exception(Exception::STORAGE_BUCKET_NOT_FOUND);
        }

        if (!$authorization->isValid(new Input(Database::PERMISSION_READ, $bucket->getRead()))) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $authorization->getDescription());
        }

        $fileSecurity = $bucket->getAttribute('fileSecurity', false);
        if ($fileSecurity) {
            $file = $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId);
        } else {
            $file = $authorization->skip(fn () => $dbForProject->getDocument('bucket_' . $bucket->getSequence(), $fileId));
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
