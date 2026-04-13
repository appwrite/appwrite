<?php

namespace Tests\E2E\Services\Presence;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class PresenceCustomServerTest extends Scope
{
    use PresenceBase;
    use ProjectCustom;
    use SideServer;
}
