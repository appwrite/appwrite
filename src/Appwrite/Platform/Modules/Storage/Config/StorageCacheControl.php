<?php

namespace Appwrite\Platform\Modules\Storage\Config;

use Appwrite\Utopia\Database\Documents\User;
use Utopia\Database\Document;
use Utopia\Http\Route;

final class StorageCacheControl extends CacheControl
{
    public function __construct(
        string $source,
        User $user,
        int $maxAge,
        public readonly Document $project,
        public readonly Document $bucket,
        public readonly Document $file,
        public readonly Document $resourceToken,
        public readonly bool $fileSecurity,
        public readonly ?Document $cacheLog = null,
        ?Route $route = null,
    ) {
        parent::__construct(
            source: $source,
            user: $user,
            maxAge: $maxAge,
            route: $route,
        );
    }
}
