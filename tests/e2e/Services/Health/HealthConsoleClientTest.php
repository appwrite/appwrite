<?php

namespace Tests\E2E\Services\Health;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectConsole;
use Tests\E2E\Scopes\SideClient;

class HealthConsoleClientTest extends Scope
{
    use HealthBase;
    use ProjectConsole;
    use SideClient;
}