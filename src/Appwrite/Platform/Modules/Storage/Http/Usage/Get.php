<?php

namespace Appwrite\Platform\Modules\Storage\Http\Usage;

use Utopia\Platform\Action;

class Get extends Action
{
    public static function getName()
    {
        return 'getBucketUsage';
    }

    // BUCKET USAGE - GET /v1/storage/:bucketId/usage
    // Endpoint implementation from /app/controllers/api/storage.php lines 1952-2053
    // Returns bucket-specific usage statistics
}
