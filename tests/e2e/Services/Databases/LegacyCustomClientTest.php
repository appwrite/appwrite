<?php

namespace Tests\E2E\Services\Databases;

use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class LegacyCustomClientTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideClient;
    use ApiLegacy;
}
