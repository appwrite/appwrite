<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files;

use Utopia\Platform\Action;

class Delete extends Action
{
    public static function getName()
    {
        return 'deleteFile';
    }

    // FILE DELETE - DELETE /v1/storage/buckets/:bucketId/files/:fileId
    // Endpoint implementation from /app/controllers/api/storage.php lines 1758-1864
    // Deletes file from storage device and database with proper cleanup
}
