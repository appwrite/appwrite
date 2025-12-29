<?php

namespace Appwrite\Platform\Modules\Storage\Http\Buckets\Files\Preview;

use Utopia\Platform\Action;

class Get extends Action
{
    public static function getName()
    {
        return 'getFilePreview';
    }

    // FILE PREVIEW - GET /v1/storage/buckets/:bucketId/files/:fileId/preview
    // Endpoint implementation from /app/controllers/api/storage.php lines 938-1153
    // Provides image preview generation with crop, transformation, and rendering capabilities
}
