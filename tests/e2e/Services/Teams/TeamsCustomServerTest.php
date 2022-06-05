<?php

namespace Tests\E2E\Services\Teams;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;
use Tests\E2E\Client;

class TeamsCustomServerTest extends Scope
{
    use TeamsBase;
    use TeamsBaseServer;
    use ProjectCustom;
    use SideServer;
}
