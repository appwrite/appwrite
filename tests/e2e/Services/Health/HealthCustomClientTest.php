<?php

namespace Tests\E2E\Services\Health;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class HealthCustomClientTest extends Scope
{
    use HealthBase;
    use ProjectCustom;
    use SideClient;
}