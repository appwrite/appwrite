<?php

namespace Tests\E2E\Services\Presences;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class PresenceCustomClientTest extends Scope
{
    use PresenceBase;
    use ProjectCustom;
    use SideClient;
}
