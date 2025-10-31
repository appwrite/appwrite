<?php

namespace Tests\E2E\Services\Databases\VectorDB;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class DatabasesCustomClientTest extends Scope
{
    use DatabasesBase;
    use ProjectCustom;
    use SideClient;
}
