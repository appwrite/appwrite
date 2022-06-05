<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Client;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideServer;

class UsersCustomServerTest extends Scope
{
    use UsersBase;
    use ProjectCustom;
    use SideServer;
}
