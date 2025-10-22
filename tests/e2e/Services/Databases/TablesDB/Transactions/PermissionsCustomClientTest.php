<?php

namespace Tests\E2E\Services\Databases\TablesDB\Transactions;

use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class PermissionsCustomClientTest extends Scope
{
    use PermissionsBase;
    use ProjectCustom;
    use SideClient;
}
