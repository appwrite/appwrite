<?php

namespace Tests\E2E\Services\Databases\Transactions;

use Tests\E2E\Scopes\ApiLegacy;
use Tests\E2E\Scopes\ProjectCustom;
use Tests\E2E\Scopes\Scope;
use Tests\E2E\Scopes\SideClient;

class LegacyTransactionPermissionsCustomClientTest extends Scope
{
    use TransactionPermissionsBase;
    use ProjectCustom;
    use SideClient;
    use ApiLegacy;
}
