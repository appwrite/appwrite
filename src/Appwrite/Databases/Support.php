<?php

namespace Appwrite\Databases;

use Utopia\Database\Adapter;
use Utopia\Database\Adapter\Feature\Spatial;

/**
 * Spatial types exist where the adapter implements the library's spatial feature, which a pool answers for the
 * adapter it pools; the spatial capabilities only describe how such an adapter behaves.
 */
final class Support
{
    public static function spatial(Adapter $adapter): bool
    {
        return $adapter->hasFeature(Spatial::class);
    }
}
