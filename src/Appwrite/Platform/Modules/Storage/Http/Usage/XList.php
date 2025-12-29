<?php

namespace Appwrite\Platform\Modules\Storage\Http\Usage;

use Utopia\Platform\Action;

class XList extends Action
{
    public static function getName()
    {
        return 'getUsage';
    }

    // STORAGE USAGE - GET /v1/storage/usage
    // Endpoint implementation from /app/controllers/api/storage.php lines 1865-1951
    // Returns global storage usage statistics
}
