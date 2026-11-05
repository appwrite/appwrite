<?php

namespace Utopia\Migration\Tests\Destinations;

use Utopia\Migration\Destinations\Appwrite as AppwriteDestination;
use Utopia\Migration\Resources\Auth\Policies;

final class PoliciesDestination extends AppwriteDestination
{
    public function importPolicies(Policies $resource): void
    {
        $this->createPolicies($resource);
    }
}
