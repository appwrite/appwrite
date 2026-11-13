<?php

namespace Utopia\Migration\Tests\Destinations;

use Appwrite\Services\Functions;
use Appwrite\Services\Sites;
use Appwrite\Services\Storage;
use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;

/**
 * An Appwrite destination that creates functions, sites and buckets through the services it is
 * given, which stand in for the API. Nothing else is set up: the imports these tests drive reach
 * no other service and no database.
 */
final class RecordingDestination extends AppwriteDestination
{
    public function __construct(?Functions $functions = null, ?Sites $sites = null, ?Storage $storage = null)
    {
        if ($functions !== null) {
            $this->functions = $functions;
        }

        if ($sites !== null) {
            $this->sites = $sites;
        }

        if ($storage !== null) {
            $this->storage = $storage;
        }
    }
}
