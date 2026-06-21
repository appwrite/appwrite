<?php

namespace Appwrite\Platform\Modules\Storage\Config;

use Appwrite\Utopia\Database\Documents\User;
use Utopia\Http\Route;

class CacheControl
{
    public const SOURCE_ACTION = 'action';
    public const SOURCE_CACHE = 'cache';

    public function __construct(
        public readonly string $source,
        public readonly User $user,
        public readonly int $maxAge,
        public readonly ?Route $route = null,
    ) {
    }
}
