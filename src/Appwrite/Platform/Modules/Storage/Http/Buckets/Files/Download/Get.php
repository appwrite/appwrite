<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Download;

use Utopia\Platform\Action;

class Get extends Action
{
    public static function getName()
    {
        return 'getFileDownload';
    }

    // FILE DOWNLOAD - GET /v1/storage/buckets/:bucketId/files/:fileId/download
    // Endpoint implementation from /app/controllers/api/storage.php lines 1154-1314
    // Provides file download with range request support and proper headers
}
