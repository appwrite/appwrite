<?php

namespace Tests\E2E\Services\Health;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class HealthCustomServerTest extends Scope
{
    use HealthBase;
    use ProjectCustom;
    use SideServer;
}