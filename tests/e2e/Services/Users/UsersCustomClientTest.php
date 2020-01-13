<?php

namespace Tests\E2E\Services\Users;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class UsersCustomClientTest extends Scope
{
    use UsersBase;
    use ProjectCustom;
    use SideClient;
}