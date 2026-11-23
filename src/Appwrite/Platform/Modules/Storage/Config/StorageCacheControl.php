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
        /**
         * The response format was negotiated from the request's Accept header (`output=auto`).
         * The response varies by Accept, so a shared cache that ignores `Vary` must not store it.
         */
        public readonly bool $negotiated = false,
    ) {
        parent::__construct(
            source: $source,
            user: $user,
            maxAge: $maxAge,
            route: $route,
        );
    }
}
