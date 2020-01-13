<?php

namespace Tests\E2E\Services\Database;

use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\SideClient;

class DatabaseCustomClientTest extends Scope
{
    use DatabaseBase;
    use ProjectCustom;
    use SideClient;
}