<?php

namespace Tests\E2E;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class AccountCustomClientTest extends Scope
{
    use AccountBase;
    use ProjectCustom;
    use SideClient;
}